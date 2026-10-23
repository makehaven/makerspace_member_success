<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\makerspace_member_success\Support\OutreachDecision;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\OutreachHoldout;

/**
 * Stores and transitions outreach queue records.
 */
class OutreachQueueService implements OutreachQueueServiceInterface {

  /**
   * Constructs an outreach queue service.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected OutreachPolicyDeciderInterface $policyDecider,
    protected ConfigFactoryInterface $configFactory,
    protected OutreachMessageBuilderInterface $messageBuilder,
    protected OutreachSenderInterface $sender,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OutreachService $outreachService,
    protected OutreachSuppressionCheckerInterface $suppressionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueueCandidate(int $uid, string $stage, array $snapshot): int {
    $now = $this->time->getCurrentTime();
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $risk_score = (int) ($snapshot['risk_score'] ?? 0);
    $min_risk = (int) ($config->get("stage_{$stage}_min_risk_to_contact") ?? 20);
    $max_attempts = (int) ($config->get("stage_{$stage}_max_attempts") ?? 3);
    $cooldown_days = (int) ($config->get("stage_{$stage}_cooldown_days") ?? 7);

    $last_contact_date = (string) ($snapshot['last_contact_date'] ?? '');
    $contact_count = (int) ($snapshot['contact_count'] ?? 0);
    if ($last_contact_date === '' || $contact_count === 0) {
      $latest = $this->loadLatestSnapshotOutreachState($uid);
      $last_contact_date = $last_contact_date !== '' ? $last_contact_date : (string) ($latest['last_contact_date'] ?? '');
      $contact_count = $contact_count > 0 ? $contact_count : (int) ($latest['contact_count'] ?? 0);
    }

    $decision = $this->policyDecider->decide($uid, $snapshot);
    $status = $decision->channel === 'manual_only' ? 'suppressed' : 'queued';
    $suppression_reason = $decision->channel === 'manual_only' ? $decision->reasonCode : NULL;

    // Automation logic: auto-approve if enabled and stage allows.
    if ($status === 'queued' && (bool) $config->get('automation_enabled')) {
      $require_manual = (bool) $config->get("stage_{$stage}_require_manual_approval");
      if (!$require_manual) {
        $status = 'approved';
      }
    }

    // Dedicated onboarding auto-nudge dark switch. When on, onboarding-stage
    // candidates auto-approve independently of the global automation_enabled
    // flag and the generic per-stage manual-approval gate, so the join-funnel
    // recovery nudge can be enabled (and dry-run reviewed) on its own.
    if ($status === 'queued'
      && $stage === 'onboarding'
      && (bool) $config->get('onboarding_auto_nudge_enabled')) {
      $status = 'approved';
    }

    // The pause-reason question sends on its own switch the same way: it asks
    // something rather than offering anything, and it is only useful near
    // the moment of pausing, so waiting for a staff approval would spoil it.
    if ($status === 'queued'
      && $stage === 'pause_reason'
      && (bool) $config->get('pause_reason_enabled')) {
      $status = 'approved';
    }

    // Policy gates. These override any earlier decision (including a
    // manual_only channel suppression) so the recorded reason is the gate
    // that actually blocked the send. Gate suppressions flow through the
    // same dedup below as every other outcome: a member re-suppressed on
    // every cron run must update their one open row, not append a new row
    // per run.
    if ($risk_score < $min_risk) {
      $status = 'suppressed';
      $suppression_reason = 'suppressed_below_threshold';
    }
    elseif ($contact_count >= $max_attempts && $max_attempts > 0) {
      $status = 'suppressed';
      $suppression_reason = 'suppressed_max_attempts';
    }
    elseif ($cooldown_days > 0 && $last_contact_date !== '') {
      $next_allowed_ts = strtotime($last_contact_date . ' +' . $cooldown_days . ' days');
      if ($next_allowed_ts !== FALSE && $next_allowed_ts > $now) {
        $status = 'suppressed';
        $suppression_reason = 'suppressed_cooldown';
      }
    }

    // Silent holdout. Only a member who passed every gate is assigned, so the
    // two arms are the same population and differ only in being contacted.
    // A held-out row is never approved or sent, and it still counts as "had
    // a row" for the per-pause and re-engagement cooldown checks in
    // OutreachCandidateGenerator, so the member is not re-offered next run.
    $holdout_arm = NULL;
    if (in_array($status, ['queued', 'approved'], TRUE)) {
      $holdout_arm = OutreachHoldout::arm(
        $uid,
        $stage,
        (int) ($config->get('holdout_percent') ?? 0),
        (array) ($config->get('holdout_stages') ?? []),
      );
      if ($holdout_arm === OutreachHoldout::ARM_HOLDOUT) {
        $status = OutreachHoldout::STATUS_HELD_OUT;
        $suppression_reason = 'holdout';
      }
    }

    $scheduled_at = isset($snapshot['scheduled_at'])
      ? (int) $snapshot['scheduled_at']
      : ($status === 'suppressed' ? $now : $this->computeDefaultScheduledAt($now, $config));
    $existing = $this->findExistingOpenRow($uid, $stage);
    if (!empty($existing['id'])) {
      $existing_id = (int) $existing['id'];
      $existing_status = (string) ($existing['status'] ?? '');
      if (in_array($existing_status, ['queued', 'suppressed', 'failed', 'cancelled'], TRUE)) {
        $fields = [
          'risk_score' => $risk_score,
          'risk_reasons' => $this->normalizeRiskReasons($snapshot['risk_reasons'] ?? NULL),
          'recommended_channel' => $decision->channel,
          'recommended_template_id' => $decision->templateId,
          'recommended_reason_code' => $decision->reasonCode,
          'destination_email' => (string) ($snapshot['email'] ?? '') ?: NULL,
          'destination_phone' => (string) ($snapshot['phone'] ?? '') ?: NULL,
          'status' => $status,
          'suppression_reason_code' => $suppression_reason,
          'scheduled_at' => $scheduled_at,
          'updated_at' => $now,
        ];
        // Keep an earlier assignment when a later run stops at a gate: the
        // report counts every member assigned to the offer, contacted or not.
        if ($holdout_arm !== NULL) {
          $fields['holdout_arm'] = $holdout_arm;
        }
        $this->database->update('ms_member_outreach_queue')
          ->fields($fields)
          ->condition('id', $existing_id)
          ->execute();
      }
      return $existing_id;
    }

    $consent_snapshot = [
      'is_opt_out' => (int) ($snapshot['is_opt_out'] ?? 0),
      'do_not_email' => (int) ($snapshot['do_not_email'] ?? 0),
      'do_not_sms' => (int) ($snapshot['do_not_sms'] ?? 0),
      'sms_consent' => $snapshot['sms_consent'] ?? NULL,
      'preferred_outreach_method' => (string) ($snapshot['preferred_outreach_method'] ?? ''),
    ];
    return (int) $this->database->insert('ms_member_outreach_queue')
      ->fields([
        'uid' => $uid,
        'civicrm_contact_id' => (int) ($snapshot['civicrm_contact_id'] ?? $snapshot['contact_id_raw'] ?? 0) ?: NULL,
        'stage' => $stage,
        'risk_score' => $risk_score,
        'risk_reasons' => $this->normalizeRiskReasons($snapshot['risk_reasons'] ?? NULL),
        'recommended_channel' => $decision->channel,
        'recommended_template_id' => $decision->templateId,
        'recommended_reason_code' => $decision->reasonCode,
        'destination_email' => (string) ($snapshot['email'] ?? '') ?: NULL,
        'destination_phone' => (string) ($snapshot['phone'] ?? '') ?: NULL,
        'consent_snapshot' => !empty($consent_snapshot) ? serialize($consent_snapshot) : NULL,
        'policy_version' => (string) ($snapshot['policy_version'] ?? 'v1'),
        'status' => $status,
        'suppression_reason_code' => $suppression_reason,
        'holdout_arm' => $holdout_arm,
        'scheduled_at' => $scheduled_at,
        'created_at' => $now,
        'updated_at' => $now,
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function approve(
    int $queueId,
    int $staffUid,
    ?string $channel = NULL,
    ?string $templateId = NULL,
    ?string $overrideReason = NULL,
  ): void {
    $now = $this->time->getCurrentTime();
    $fields = [
      'status' => 'approved',
      'approved_by_uid' => $staffUid,
      'approved_at' => $now,
      'updated_at' => $now,
    ];
    if ($channel !== NULL) {
      $fields['actual_channel'] = $channel;
    }
    if ($templateId !== NULL) {
      $fields['actual_template_id'] = $templateId;
    }
    if ($overrideReason !== NULL) {
      $fields['override_reason_code'] = $overrideReason;
    }

    // A held-out row stays unsent even under a bulk "approve selected":
    // sending it would put the member in both arms.
    $this->database->update('ms_member_outreach_queue')
      ->fields($fields)
      ->condition('id', $queueId)
      ->condition('status', OutreachHoldout::STATUS_HELD_OUT, '<>')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function suppress(int $queueId, string $reasonCode): void {
    $this->database->update('ms_member_outreach_queue')
      ->fields([
        'status' => 'suppressed',
        'suppression_reason_code' => $reasonCode,
        'updated_at' => $this->time->getCurrentTime(),
      ])
      ->condition('id', $queueId)
      ->execute();
  }

  /**
   * Cancels queued rows nobody has acted on or refreshed in $days days.
   *
   * A queued row waits for a person to approve it. Rows that cron keeps
   * re-evaluating (recovery, onboarding, badge, pause-end) are refreshed on
   * every run, so their updated_at stays current; what goes stale is a row
   * from a one-off "Generate candidates" press that nobody ever worked. On
   * 2026-10-22 that was 198 retention rows queued in March — before the
   * 10-14 change that stopped calling long-absent members, so many of them
   * now point at exactly the people we decided to leave alone, and one bulk
   * approve would have contacted all of them.
   *
   * Cancelled rows are not lost: they stay in the table with reason
   * expired_stale, and enqueueCandidate() reopens one if the member qualifies
   * again.
   *
   * @return int
   *   Rows cancelled.
   */
  public function expireStaleQueued(int $days): int {
    if ($days <= 0) {
      return 0;
    }
    $now = $this->time->getCurrentTime();
    return (int) $this->database->update('ms_member_outreach_queue')
      ->fields([
        'status' => 'cancelled',
        'suppression_reason_code' => 'expired_stale',
        'updated_at' => $now,
      ])
      ->condition('status', 'queued')
      ->condition('updated_at', $now - $days * 86400, '<')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function markSent(int $queueId, array $providerMeta = []): void {
    $fields = [
      'status' => 'sent',
      'sent_at' => $this->time->getCurrentTime(),
      'updated_at' => $this->time->getCurrentTime(),
      'failure_code' => NULL,
      'failure_message' => NULL,
      'provider_message_id' => (string) ($providerMeta['provider_message_id'] ?? '') ?: NULL,
    ];

    $this->database->update('ms_member_outreach_queue')
      ->fields($fields)
      ->condition('id', $queueId)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function markFailed(int $queueId, string $failureCode, string $message): void {
    $this->database->update('ms_member_outreach_queue')
      ->fields([
        'status' => 'failed',
        'failure_code' => $failureCode,
        'failure_message' => $message,
        'updated_at' => $this->time->getCurrentTime(),
      ])
      ->condition('id', $queueId)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function processApprovedItems(array $stages = []): int {
    $now = $this->time->getCurrentTime();
    $query = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q', ['id', 'uid', 'stage', 'recommended_channel', 'destination_email', 'destination_phone', 'consent_snapshot'])
      ->condition('status', 'approved')
      ->condition('scheduled_at', $now, '<=');
    if (!empty($stages)) {
      $query->condition('stage', $stages, 'IN');
    }
    $rows = $query->execute()->fetchAll();

    $processed = 0;
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $uid = (int) $row->uid;
      $stage = (string) $row->stage;
      $channel = (string) $row->recommended_channel;

      try {
        // Pre-send safety check: re-verify consent and risk.
        $context = @unserialize($row->consent_snapshot) ?: [];
        $context['email'] = $row->destination_email;
        $context['phone'] = $row->destination_phone;
        $context['stage'] = $stage;

        $check = $this->suppressionChecker->check($uid, $channel, $context);
        if (!$check->allowed) {
          $this->markFailed($id, $check->reasonCode, 'Suppressed during pre-send safety check: ' . $check->reasonCode);
          continue;
        }

        $message = $this->messageBuilder->build($id);
        $result = $this->sender->send($message);
        if ($result->success) {
          $this->markSent($id, ['provider_message_id' => $result->providerMessageId]);

          // Log interaction to update snooze/history.
          $user = $this->entityTypeManager->getStorage('user')->load($uid);
          if ($user) {
            $outcome = $channel === 'sms' ? MemberSuccessLifecycle::OUTCOME_SMS_SENT : MemberSuccessLifecycle::OUTCOME_EMAIL_SENT;
            $this->outreachService->recordContact(
              $user,
              $stage,
              $channel,
              $outcome,
              'Automated outreach: ' . ($result->providerMessageId ?? ''),
            // mark_exhausted.
              FALSE,
            // log_in_civicrm.
              TRUE
            );
          }
          $processed++;
        }
        else {
          $this->markFailed($id, $result->failureCode, $result->failureMessage);
        }
      }
      catch (\Exception $e) {
        $this->markFailed($id, 'exception', $e->getMessage());
      }
    }
    return $processed;
  }

  /**
   * Finds the most recent non-terminal queue row for a member/stage pair.
   *
   * The dedup scope is intentionally broad (any open row for the uid+stage,
   * regardless of scheduled time) so that re-running candidate generation does
   * not create duplicate rows.
   */
  protected function findExistingOpenRow(int $uid, string $stage): ?array {
    $query = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q', ['id', 'status'])
      ->condition('q.uid', $uid)
      ->condition('q.stage', $stage)
      ->condition('q.status', ['queued', 'approved', 'suppressed', 'failed', 'cancelled'], 'IN')
      ->orderBy('q.id', 'DESC')
      ->range(0, 1);

    $row = $query->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Loads latest contact metadata for cooldown/attempt checks.
   */
  protected function loadLatestSnapshotOutreachState(int $uid): array {
    $row = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['last_contact_date', 'contact_count'])
      ->condition('ms.uid', $uid)
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $state = is_array($row) ? $row : [];
    if (empty($state['last_contact_date'])) {
      $last_logged_date = $this->database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['contact_date'])
        ->condition('log.uid', $uid)
        ->orderBy('log.contact_date', 'DESC')
        ->orderBy('log.id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if (!empty($last_logged_date)) {
        $state['last_contact_date'] = (string) $last_logged_date;
      }
    }

    return $state;
  }

  /**
   * Returns the next default queue execution timestamp in local site time.
   */
  protected function computeDefaultScheduledAt(int $now, ImmutableConfig $config): int {
    $hour = (int) ($config->get('outreach_send_hour_local') ?? 10);
    if ($hour < 0 || $hour > 23) {
      $hour = 10;
    }

    $tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $current = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
    $target = $current->setTime($hour, 0, 0);
    if ($target <= $current) {
      $target = $target->modify('+1 day');
    }
    return $target->getTimestamp();
  }

  /**
   * Normalizes risk reasons to array shape for schema-level serialization.
   */
  protected function normalizeRiskReasons(mixed $raw): ?string {
    if (is_array($raw)) {
      return serialize($raw);
    }
    if (!is_string($raw) || trim($raw) === '') {
      return NULL;
    }

    $parsed = @unserialize($raw);
    if (is_array($parsed)) {
      return serialize($parsed);
    }
    if (is_string($parsed) && $parsed !== '') {
      $parsed_nested = @unserialize($parsed);
      if (is_array($parsed_nested)) {
        return serialize($parsed_nested);
      }
    }

    return NULL;
  }

}
