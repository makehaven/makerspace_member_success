<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;

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
    protected OutreachSuppressionCheckerInterface $suppressionChecker
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

    if ($risk_score < $min_risk) {
      return $this->insertQueueRow($uid, $stage, $snapshot, $decision, 'suppressed', 'suppressed_below_threshold', $now);
    }

    if ($contact_count >= $max_attempts && $max_attempts > 0) {
      return $this->insertQueueRow($uid, $stage, $snapshot, $decision, 'suppressed', 'suppressed_max_attempts', $now);
    }

    if ($cooldown_days > 0 && $last_contact_date !== '') {
      $next_allowed_ts = strtotime($last_contact_date . ' +' . $cooldown_days . ' days');
      if ($next_allowed_ts !== FALSE && $next_allowed_ts > $now) {
        return $this->insertQueueRow($uid, $stage, $snapshot, $decision, 'suppressed', 'suppressed_cooldown', $now);
      }
    }

    $scheduled_at = isset($snapshot['scheduled_at'])
      ? (int) $snapshot['scheduled_at']
      : $this->computeDefaultScheduledAt($now, $config);
    $existing = $this->findExistingOpenRow($uid, $stage);
    if (!empty($existing['id'])) {
      $existing_id = (int) $existing['id'];
      $existing_status = (string) ($existing['status'] ?? '');
      if (in_array($existing_status, ['queued', 'suppressed', 'failed', 'cancelled'], TRUE)) {
        $this->database->update('ms_member_outreach_queue')
          ->fields([
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
          ])
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
    ?string $overrideReason = NULL
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

    $this->database->update('ms_member_outreach_queue')
      ->fields($fields)
      ->condition('id', $queueId)
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
  public function processApprovedItems(): int {
    $now = $this->time->getCurrentTime();
    $rows = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q', ['id', 'uid', 'stage', 'recommended_channel', 'destination_email', 'destination_phone', 'consent_snapshot'])
      ->condition('status', 'approved')
      ->condition('scheduled_at', $now, '<=')
      ->execute()
      ->fetchAll();

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
          
          // Log interaction to update snooze/history
          $user = $this->entityTypeManager->getStorage('user')->load($uid);
          if ($user) {
            $outcome = $channel === 'sms' ? MemberSuccessLifecycle::OUTCOME_SMS_SENT : MemberSuccessLifecycle::OUTCOME_EMAIL_SENT;
            $this->outreachService->recordContact(
              $user,
              $stage,
              $channel,
              $outcome,
              'Automated outreach: ' . ($result->providerMessageId ?? ''),
              FALSE, // mark_exhausted
              TRUE   // log_in_civicrm
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
   * Inserts a suppression row and returns its ID.
   */
  protected function insertQueueRow(
    int $uid,
    string $stage,
    array $snapshot,
    \Drupal\makerspace_member_success\Support\OutreachDecision $decision,
    string $status,
    ?string $suppressionReason,
    int $now
  ): int {
    return (int) $this->database->insert('ms_member_outreach_queue')
      ->fields([
        'uid' => $uid,
        'civicrm_contact_id' => (int) ($snapshot['civicrm_contact_id'] ?? $snapshot['contact_id_raw'] ?? 0) ?: NULL,
        'stage' => $stage,
        'risk_score' => (int) ($snapshot['risk_score'] ?? 0),
        'risk_reasons' => $this->normalizeRiskReasons($snapshot['risk_reasons'] ?? NULL),
        'recommended_channel' => $decision->channel,
        'recommended_template_id' => $decision->templateId,
        'recommended_reason_code' => $decision->reasonCode,
        'destination_email' => (string) ($snapshot['email'] ?? '') ?: NULL,
        'destination_phone' => (string) ($snapshot['phone'] ?? '') ?: NULL,
        'consent_snapshot' => !empty($snapshot) ? serialize([
          'is_opt_out' => (int) ($snapshot['is_opt_out'] ?? 0),
          'do_not_email' => (int) ($snapshot['do_not_email'] ?? 0),
          'do_not_sms' => (int) ($snapshot['do_not_sms'] ?? 0),
          'sms_consent' => $snapshot['sms_consent'] ?? NULL,
          'preferred_outreach_method' => (string) ($snapshot['preferred_outreach_method'] ?? ''),
        ]) : NULL,
        'policy_version' => (string) ($snapshot['policy_version'] ?? 'v1'),
        'status' => $status,
        'suppression_reason_code' => $suppressionReason,
        'scheduled_at' => (int) ($snapshot['scheduled_at'] ?? $now),
        'created_at' => $now,
        'updated_at' => $now,
      ])
      ->execute();
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
  protected function computeDefaultScheduledAt(int $now, \Drupal\Core\Config\ImmutableConfig $config): int {
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
