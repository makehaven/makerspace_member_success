<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;
use Drupal\makerspace_member_success\Support\MemberSuccessRiskScorer;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Psr\Log\LoggerInterface;
use Drupal\civicrm\Civicrm;

/**
 * Builds member success snapshot data.
 */
class MemberSuccessSnapshotBuilder {

  protected Connection $database;

  protected ConfigFactoryInterface $configFactory;

  protected TimeInterface $time;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected LoggerInterface $logger;

  protected Civicrm $civicrm;

  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, TimeInterface $time, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, Civicrm $civicrm, ?FollowupStatusManager $followup_status_manager = NULL, ?StateInterface $state = NULL) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('makerspace_member_success');
    $this->civicrm = $civicrm;
    $this->followupStatusManager = $followup_status_manager;
    $this->state = $state;
  }

  /**
   * Followup status manager, used to clear statuses on a new episode.
   *
   * Optional so long-lived callers constructing the builder directly keep
   * working; without it, episode resets still clear snapshot tracking but
   * leave the user-level followup status untouched.
   */
  protected ?FollowupStatusManager $followupStatusManager = NULL;

  /**
   * State service, used to record when a full snapshot pass last completed.
   *
   * Optional so callers constructing the builder directly (and unit tests)
   * keep working; without it, slicing still resumes correctly — only the
   * staleness stamp is skipped.
   */
  protected ?StateInterface $state = NULL;

  /**
   * State key holding the timestamp of the last completed full pass.
   */
  public const STATE_LAST_COMPLETE = 'makerspace_member_success.snapshot_last_complete';

  /**
   * State key holding the snapshot date of the last completed full pass.
   */
  public const STATE_LAST_COMPLETE_DATE = 'makerspace_member_success.snapshot_last_complete_date';

  /**
   * Builds daily snapshots for active members.
   *
   * Each member costs several queries plus a CiviCRM lookup, so a full pass
   * over ~870 members does not fit inside Pantheon's 120-second web request
   * ceiling — and cron runs as a web request. When the pass overran, PHP was
   * killed mid-loop with no exception to catch, which silently took down every
   * later stage of hook_cron() with it (the join-form lead scan and the
   * onboarding nudge stopped running entirely, 2026-08-19 → 2026-08-20).
   *
   * Passing a positive $time_budget_seconds switches to resumable slicing:
   * snapshot as many members as fit in the budget, then return. The next cron
   * picks up exactly the members that have no row for this date yet, so a full
   * day completes across a few runs. Work already done is never redone and the
   * end-of-pass bookkeeping only fires once the whole roster is covered.
   *
   * Drush and any caller that passes no budget keep the original run-to-
   * completion behaviour — the CLI has no request timeout.
   *
   * @param \DateTimeInterface|null $date
   *   Snapshot date; defaults to today.
   * @param string $snapshot_type
   *   Snapshot type, e.g. 'daily'.
   * @param int $time_budget_seconds
   *   Wall-clock budget for this pass. 0 (default) means no limit.
   *
   * @return int
   *   Number of member snapshots written in THIS pass.
   */
  public function buildDailySnapshots(?\DateTimeInterface $date = NULL, string $snapshot_type = 'daily', int $time_budget_seconds = 0): int {
    $date = $date ?: new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $snapshot_date = $date->format('Y-m-d');
    $now_ts = (int) $this->time->getRequestTime();
    $uids = $this->loadActiveMemberIds();
    $count = 0;
    $failed = [];
    $sliced = $time_budget_seconds > 0;
    $already_complete = FALSE;

    $todo = $uids;
    if ($sliced) {
      // Resume: skip members already snapshotted for this date. Derived from
      // the snapshot table itself rather than a stored cursor, so it stays
      // correct even if the roster changes between passes or a pass dies.
      $done = $this->loadSnapshottedUids($snapshot_date, $snapshot_type);
      if (!empty($done)) {
        $todo = array_values(array_diff($uids, $done));
      }
      $already_complete = $this->lastCompletedDate() === $snapshot_date;
      if (empty($todo) && $already_complete) {
        // Roster fully covered and the end-of-pass bookkeeping already ran for
        // this date: nothing left to do until tomorrow.
        return 0;
      }
    }

    // NOTE: is_latest is reset per-user inside upsertSnapshot() before each
    // merge, so we do NOT do a bulk reset here. A bulk reset before the loop
    // would leave all members with is_latest=0 if the process crashes or times
    // out mid-run, causing queues to appear empty until the next full run.
    $deadline = $sliced ? microtime(TRUE) + $time_budget_seconds : NULL;
    $remaining = 0;
    foreach (array_values($todo) as $index => $uid) {
      if ($deadline !== NULL && microtime(TRUE) >= $deadline) {
        $remaining = count($todo) - $index;
        break;
      }
      // Contain per-member failures. One member must never be able to end the
      // pass — and \Error (not just \Exception) has to be caught here, because
      // core's Cron::invokeCronHandlers() only catches \Exception, so a
      // TypeError thrown in here escapes as a fatal and kills the whole cron
      // request along with every later hook_cron() stage.
      //
      // That is exactly what happened on 2026-08-19: uid 4706 rejoined the
      // roster with no is_latest snapshot row, isNewPaymentEpisode() received
      // FALSE instead of an array, and the pass died at that member on every
      // run from then on (snapshots stuck at ~420 of 868, no join-form lead
      // recorded, no onboarding nudge sent, and nothing logged anywhere).
      try {
        $row = $this->buildSnapshotForUser($uid, $snapshot_date, $snapshot_type, $now_ts);
        $row['is_latest'] = 1;
        $this->upsertSnapshot($row);
        $count++;
      }
      catch (\Throwable $e) {
        $failed[] = $uid;
        $this->logger->error('Member success snapshot failed for uid @uid (@type: @msg). Skipping this member and continuing the pass.', [
          '@uid' => $uid,
          '@type' => get_class($e),
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($failed)) {
      $this->logger->warning('Member success snapshots for @date: @count skipped member(s) failed to build (uids: @uids).', [
        '@date' => $snapshot_date,
        '@count' => count($failed),
        '@uids' => implode(', ', array_slice($failed, 0, 25)),
      ]);
    }

    if ($remaining > 0) {
      // Partial pass. Skip the end-of-pass bookkeeping below: clearing
      // is_latest against a roster we have not finished would blank the queues.
      $this->logger->info('Member success snapshots for @date: @count written this pass, @remaining still to go (budget @budget s). Resuming next cron.', [
        '@date' => $snapshot_date,
        '@count' => $count,
        '@remaining' => $remaining,
        '@budget' => $time_budget_seconds,
      ]);
      return $count;
    }

    if ($already_complete) {
      // A later slice picked up members who joined after the pass completed
      // (or retried one that failed). Their rows are written, but the
      // end-of-pass bookkeeping below already ran for this date and must not
      // run twice — recordDepartedRecoveryCancellations() would double-log
      // the same departures on every subsequent cron.
      return $count;
    }

    // Members whose latest snapshot says "recovery" but who are no longer
    // active members departed while their payment was failing — in practice
    // the Chargebee dunning window ran out and the subscription cancelled.
    // Log that loss before the is_latest flags are cleared below, or the
    // departure becomes invisible: successes were already auto-logged on
    // recovery exit, but cancellations silently vanished from the outreach
    // log (and the Intervention Performance page showed 0 confirmed cancels
    // for everyone — Kate/Christina, 2026-08-20).
    $this->recordDepartedRecoveryCancellations($uids, $snapshot_type, $snapshot_date, $now_ts);

    // After a successful pass, clear is_latest on any rows belonging to users
    // who are no longer active members. Done at the end (not before the loop)
    // so a mid-run crash leaves prior is_latest rows intact.
    $cleared = $this->clearStaleLatestFlags($uids, $snapshot_type);

    // Stamp the completed pass so cron can alarm when snapshots go stale. The
    // 2026-08-19 breakage was invisible for two days precisely because a
    // half-built roster looks identical to a healthy one from the outside.
    if ($this->state !== NULL) {
      $this->state->set(self::STATE_LAST_COMPLETE, $now_ts);
      $this->state->set(self::STATE_LAST_COMPLETE_DATE, $snapshot_date);
    }

    $this->logger->info('Generated @count member success snapshots for @date (cleared @stale stale is_latest rows).', [
      '@count' => $count,
      '@date' => $snapshot_date,
      '@stale' => $cleared,
    ]);

    return $count;
  }

  /**
   * Returns the snapshot date of the last completed full pass, if any.
   */
  protected function lastCompletedDate(): ?string {
    if ($this->state === NULL) {
      return NULL;
    }
    $value = (string) $this->state->get(self::STATE_LAST_COMPLETE_DATE, '');
    return $value !== '' ? $value : NULL;
  }

  /**
   * Loads the uids that already have a snapshot row for a date.
   *
   * @param string $snapshot_date
   *   Snapshot date (Y-m-d).
   * @param string $snapshot_type
   *   Snapshot type to scope to.
   *
   * @return int[]
   *   User ids already snapshotted for that date.
   */
  protected function loadSnapshottedUids(string $snapshot_date, string $snapshot_type): array {
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->addField('ms', 'uid');
    $query->condition('ms.snapshot_date', $snapshot_date);
    $query->condition('ms.snapshot_type', $snapshot_type);
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Clears is_latest on snapshots whose uid is no longer an active member.
   *
   * @param int[] $active_uids
   *   User ids that were just snapshotted.
   * @param string $snapshot_type
   *   Snapshot type to scope the cleanup to (e.g. 'daily').
   *
   * @return int
   *   Number of rows updated.
   */
  protected function clearStaleLatestFlags(array $active_uids, string $snapshot_type): int {
    $update = $this->database->update('ms_member_success_snapshot')
      ->fields(['is_latest' => 0])
      ->condition('snapshot_type', $snapshot_type)
      ->condition('is_latest', 1);
    if (!empty($active_uids)) {
      $update->condition('uid', $active_uids, 'NOT IN');
    }
    return (int) $update->execute();
  }

  /**
   * Computes a snapshot row for a user.
   */
  public function buildSnapshotForUser(int $uid, string $snapshot_date, string $snapshot_type, int $now_ts): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $door_badge_tid = (int) ($config->get('door_badge_tid') ?? 1519);
    $badge_one_days = (int) ($config->get('badge_one_days') ?? 60);
    $badge_four_days = (int) ($config->get('badge_four_days') ?? 180);
    $badge_watch_days = (int) ($config->get('badge_watch_days') ?? 30);
    $new_member_days = (int) ($config->get('new_member_days') ?? 180);
    $recency_days = (array) ($config->get('retention_recency_days') ?? [30, 60, 90]);
    $orientation_quiz_nid = (int) ($config->get('orientation_quiz_nid') ?? 1);

    $profile = $this->loadProfileData($uid);
    $user_flags = $this->loadUserFlags($uid);
    $door_badge = $this->loadDoorBadgeStatus($uid, $door_badge_tid);
    $badge_stats = $this->loadBadgeStats($uid, $door_badge_tid, $badge_four_days, $now_ts);
    $visit_stats = $this->loadVisitStats($uid, $now_ts);
    $civi_data = $this->loadCiviCrmData($uid);
    $first_card_scan_date = $this->loadFirstCardScan($uid);
    $quiz = $this->loadQuizPassed($uid, $orientation_quiz_nid);

    // Load previous snapshot to preserve outreach tracking fields. Coerce the
    // no-row FALSE to [] — isNewPaymentEpisode() takes array and a member's
    // first-ever build would otherwise fatal the whole daily run.
    $previous = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['stage', 'outreach_status', 'last_contact_date', 'next_followup_date', 'contact_count', 'last_outreach_ts', 'payment_pause', 'pause_start_date', 'payment_failed', 'payment_failed_since'])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->condition('snapshot_type', 'daily')
      ->execute()
      ->fetchAssoc() ?: [];

    // Fallback: if CiviCRM doesn't have it, check the Drupal field.
    $member_followup_status = $civi_data['member_followup_status'];
    if (empty($member_followup_status)) {
      $member_followup_status = $this->database->select('user__field_member_followup_status', 'f')
        ->fields('f', ['field_member_followup_status_value'])
        ->condition('f.entity_id', $uid)
        ->range(0, 1)
        ->execute()
        ->fetchField() ?: NULL;
    }

    $serial_present = $profile['serial_present'] || $user_flags['serial_present'];
    $payment_failed = $user_flags['payment_failed'];
    $payment_pause = $user_flags['payment_pause'];

    // Use profile created timestamp as the primary lifecycle anchor because
    // legacy join-date field data can be inconsistent.
    $activation_ts = $profile['profile_created_ts'] ?? NULL;
    if ($activation_ts === NULL && $profile['join_date']) {
      $activation_ts = strtotime($profile['join_date'] . ' 00:00:00');
    }
    if ($activation_ts === NULL) {
      $activation_ts = $door_badge['created'] ?? NULL;
    }

    $stage = MemberSuccessLifecycle::STAGE_ONBOARDING;
    // A pause is an agreed break, so it wins over a lingering payment-failed
    // flag: a member whose failed invoice was written off before pausing
    // must not sit in the recovery queue for their whole pause (Kate,
    // 2026-08-17 — Azrael case). When the pause ends, a still-set failed
    // flag puts them straight back into recovery.
    if ($payment_pause) {
      $stage = MemberSuccessLifecycle::STAGE_PAUSED;
    }
    elseif ($payment_failed) {
      $stage = MemberSuccessLifecycle::STAGE_RECOVERY;
    }
    elseif ($door_badge['status'] === 'active' && $serial_present) {
      $engagement_window = $badge_four_days * 86400;
      if ($activation_ts !== NULL && $now_ts - $activation_ts <= $engagement_window) {
        $stage = MemberSuccessLifecycle::STAGE_ENGAGEMENT;
      }
      else {
        $stage = MemberSuccessLifecycle::STAGE_RETENTION;
      }
    }

    // Assemble onboarding funnel signals and resolve which step the member is
    // stuck at. Only meaningful while in the onboarding stage; once the door
    // badge is active the funnel reports complete.
    $funnel_signals = self::assembleFunnelSignals($profile, $quiz, $door_badge, $serial_present, $civi_data['orientation_scheduled']);
    $onboarding_step = NULL;
    $onboarding_step_ts = NULL;
    if ($stage === MemberSuccessLifecycle::STAGE_ONBOARDING) {
      $onboarding_step = OnboardingFunnel::nextStepId($funnel_signals);
      $onboarding_step_ts = OnboardingFunnel::currentStepSince($funnel_signals);
    }

    $tenure_bucket = NULL;
    if ($stage === MemberSuccessLifecycle::STAGE_ONBOARDING) {
      $tenure_bucket = MemberSuccessLifecycle::STAGE_ONBOARDING;
    }
    elseif ($activation_ts !== NULL) {
      $tenure_days = (int) floor(($now_ts - $activation_ts) / 86400);
      if ($tenure_days >= 0) {
        $tenure_bucket = $tenure_days <= $new_member_days ? 'new_member' : 'sustaining';
      }
    }

    // Compute pause_start_date: carry forward from previous snapshot or set to
    // today as the first day of a new pause.
    $pause_start_date = NULL;
    if ($payment_pause) {
      if (!empty($previous['payment_pause']) && !empty($previous['pause_start_date'])) {
        $pause_start_date = $previous['pause_start_date'];
      }
      else {
        $pause_start_date = $snapshot_date;
      }
    }

    // Compute payment_failed_since: carry forward from previous snapshot if the
    // failure was already in progress yesterday; otherwise stamp today as the
    // first observed day of failure. Clear it when payment is no longer failed.
    // This drives the Chargebee dunning-window urgency UI (the ~8-day retry
    // window before Chargebee cancels the subscription).
    $payment_failed_since = NULL;
    if ($payment_failed) {
      if (!empty($previous['payment_failed']) && !empty($previous['payment_failed_since'])) {
        $payment_failed_since = $previous['payment_failed_since'];
      }
      else {
        $payment_failed_since = $snapshot_date;
      }
    }

    [$risk_score, $risk_reasons] = MemberSuccessRiskScorer::calculate([
      'stage' => $stage,
      'payment_failed' => $payment_failed,
      'payment_failed_since' => $payment_failed_since,
      'payment_pause' => $payment_pause,
      'door_badge_status' => $door_badge['status'],
      'serial_present' => $serial_present,
      'activation_ts' => $activation_ts,
      'badge_count_total' => $badge_stats['count_total'],
      'badge_count_window' => $badge_stats['count_window'],
      'last_visit_ts' => $visit_stats['last_visit_ts'],
      'tenure_bucket' => $tenure_bucket,
      'join_date' => $profile['join_date'],
      'pause_start_date' => $pause_start_date,
      'orientation_scheduled' => $civi_data['orientation_scheduled'],
      'onboarding_step' => $onboarding_step,
      'onboarding_step_ts' => $onboarding_step_ts,
      'onboarding_stall_hours' => (int) ($config->get('onboarding_stall_hours') ?? 48),
    ], $badge_one_days, $badge_four_days, $recency_days, $now_ts, $badge_watch_days);

    // Detect stage transition - if stage changed, reset outreach tracking
    // This ensures members appear in their new queue immediately.
    $previous_stage = $previous['stage'] ?? NULL;
    $stage_changed = $previous_stage && $previous_stage !== $stage;

    if ($stage_changed) {
      if (
        $previous_stage === MemberSuccessLifecycle::STAGE_RECOVERY
        && $stage !== MemberSuccessLifecycle::STAGE_RECOVERY
        && !empty($previous['contact_count'])
      ) {
        $this->recordAutomaticRecoverySuccess($uid, $snapshot_date, $now_ts);
      }

      // Stage transition detected - clear sleep period and reset tracking.
      $this->logger->info('Stage transition detected for user @uid: @old_stage → @new_stage. Clearing sleep period.', [
        '@uid' => $uid,
        '@old_stage' => $previous_stage,
        '@new_stage' => $stage,
      ]);

      // Reset outreach tracking for new stage.
      $previous['last_outreach_ts'] = NULL;
      $previous['outreach_status'] = NULL;
      $previous['last_contact_date'] = NULL;
      $previous['next_followup_date'] = NULL;
      $previous['contact_count'] = 0;

      // Suppression flags (outreach_exhausted, confirmed_cancellation,
      // no_action_needed, needs_review) are explicit operator decisions and
      // are NOT auto-reset on a stage change alone — staff feedback
      // (Christina 2026-04-28) confirmed that members marked exhausted or
      // cancelled popping back into the queue after any stage shift was
      // unwanted. The one event that DOES wipe them is the start of a new
      // payment-failure episode, handled in the dedicated block below
      // (Kate 2026-08-17). The helper currently always returns FALSE; the
      // call is kept so future policy can re-introduce conditional resets
      // without touching this branch.
      if (MemberSuccessQueueRules::shouldResetSuppressionOnStageChange($previous_stage, $stage, $member_followup_status)) {
        $member_followup_status = NULL;

        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user && $user->hasField('field_member_followup_status')) {
          $user->set('field_member_followup_status', NULL);
          $user->save();
        }

        $this->logger->info('Reset followup suppression flag for user @uid due to stage transition.', [
          '@uid' => $uid,
        ]);
      }
    }

    // A NEW payment-failure episode re-opens the member's outreach file
    // (Kate, 2026-08-17). "New episode" means the payment-failed flag went
    // 0 → 1 since the previous snapshot: the member had recovered (or never
    // failed) and a later charge failed. Card retries and second cards
    // inside one unresolved failure never re-trigger this, so snoozes staff
    // set during an episode survive. Snoozes, attempt counts, and
    // episode-scoped statuses (exhausted, confirmed cancellation, return
    // intent, no action needed) all clear; needs_review survives. The
    // membership guard is structural: only current members are snapshotted,
    // so a genuinely departed member cannot re-enter a queue this way.
    $episode_reset = MemberSuccessLifecycle::isNewPaymentEpisode($previous, $payment_failed);
    if ($episode_reset) {
      $this->logger->info('New payment-failure episode for user @uid (was clear, now failed). Re-opening outreach file.', [
        '@uid' => $uid,
      ]);

      $previous['last_outreach_ts'] = NULL;
      $previous['outreach_status'] = NULL;
      $previous['last_contact_date'] = NULL;
      $previous['next_followup_date'] = NULL;
      $previous['contact_count'] = 0;

      $this->followupStatusManager?->clearStatusForNewEpisode($uid);
      if (in_array($member_followup_status, MemberSuccessLifecycle::followupStatusesResetOnNewPaymentEpisode(), TRUE)) {
        $member_followup_status = NULL;
      }
    }

    // Log fallback: adopt outreach state from the log when the snapshot has
    // none. Never right after an episode reset, and — for a payment-failed
    // member — never from log rows that predate the current episode: both
    // would resurrect exactly the stale snooze the episode reset clears.
    if (
      !$episode_reset
      && empty($previous['last_contact_date'])
      && empty($previous['next_followup_date'])
      && empty($previous['contact_count'])
    ) {
      $log_state = $this->loadLatestOutreachLogState($uid);
      $log_contact_date = (string) ($log_state['last_contact_date'] ?? '');
      $predates_episode = $payment_failed
        && !empty($payment_failed_since)
        && $log_contact_date !== ''
        && $log_contact_date < $payment_failed_since;
      if (!empty($log_state) && !$predates_episode) {
        $previous['last_contact_date'] = $log_state['last_contact_date'] ?? NULL;
        $previous['next_followup_date'] = $log_state['next_followup_date'] ?? NULL;
        $previous['outreach_status'] = $log_state['outreach_status'] ?? ($previous['outreach_status'] ?? NULL);
        $previous['contact_count'] = max((int) ($previous['contact_count'] ?? 0), (int) ($log_state['contact_count'] ?? 0));
      }
    }

    $outreach_status = $previous['outreach_status'] ?? ($stage === MemberSuccessLifecycle::STAGE_RECOVERY ? MemberSuccessLifecycle::OUTREACH_STATUS_PENDING : NULL);
    // Keep queue-visible status populated even when the dedicated followup
    // profile field is unset.
    $effective_member_followup_status = $member_followup_status ?: $outreach_status;

    return [
      'uid' => $uid,
      'snapshot_date' => $snapshot_date,
      'snapshot_type' => $snapshot_type,
      'stage' => $stage,
      'risk_score' => $risk_score,
      'risk_reasons' => $risk_reasons,
      'join_date' => $profile['join_date'],
      'orientation_date' => $door_badge['created'] ? date('Y-m-d', $door_badge['created']) : NULL,
      'orientation_scheduled_date' => $civi_data['orientation_scheduled'],
      'first_card_scan_date' => $first_card_scan_date,
      'onboarding_step' => $onboarding_step,
      'onboarding_step_ts' => $onboarding_step_ts,
      'door_badge_status' => $door_badge['status'],
      'serial_number_present' => $serial_present ? 1 : 0,
      'badge_count_total' => $badge_stats['count_total'],
      'badge_count_window' => $badge_stats['count_window'],
      'tenure_bucket' => $tenure_bucket,
      'membership_type' => $profile['membership_type'],
      'last_badge_ts' => $badge_stats['last_badge_ts'],
      'last_visit_ts' => $visit_stats['last_visit_ts'],
      'visit_count_30d' => $visit_stats['visit_count_30d'],
      'payment_failed' => $payment_failed ? 1 : 0,
      'payment_failed_since' => $payment_failed_since,
      'payment_pause' => $payment_pause ? 1 : 0,
      'pause_start_date' => $pause_start_date,
      'payment_status' => $profile['payment_status'],
      'member_followup_status' => $effective_member_followup_status,
      'civicrm_do_not_phone' => $civi_data['do_not_phone'],
      'civicrm_do_not_email' => $civi_data['do_not_email'],
      'civicrm_do_not_sms' => $civi_data['do_not_sms'],
      'civicrm_do_not_mail' => $civi_data['do_not_mail'],
      'preferred_outreach_method' => $civi_data['preferred_outreach_method'],
      // Preserve outreach tracking from previous snapshot (set by LogContactForm)
      'last_outreach_ts' => $previous['last_outreach_ts'] ?? NULL,
      'outreach_status' => $outreach_status,
      'last_contact_date' => $previous['last_contact_date'] ?? NULL,
      'next_followup_date' => $previous['next_followup_date'] ?? NULL,
      'contact_count' => $previous['contact_count'] ?? 0,
      'created_at' => $now_ts,
    ];
  }

  /**
   * Returns the configured door badge term ID.
   */
  public function getDoorBadgeTermId(): int {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    return (int) ($config->get('door_badge_tid') ?? 1519);
  }

  /**
   * Loads CiviCRM data for a user.
   */
  protected function loadCiviCrmData(int $uid): array {
    $default = [
      'do_not_phone' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
      'do_not_mail' => 0,
      'preferred_outreach_method' => NULL,
      'member_followup_status' => NULL,
      'orientation_scheduled' => NULL,
    ];

    try {
      $this->civicrm->initialize();
      $uf_match = civicrm_api3('UFMatch', 'get', [
        'uf_id' => $uid,
        'sequential' => 1,
      ]);

      if (empty($uf_match['values'][0]['contact_id'])) {
        return $default;
      }
      $contact_id = $uf_match['values'][0]['contact_id'];

      $config = $this->configFactory->get('makerspace_member_success.settings');
      $pref_field = $config->get('civicrm_preferred_method_field') ?? 'preferred_communication_method';
      $followup_field = $config->get('civicrm_member_followup_field')
        ?? $config->get('civicrm_member_followup_status_field');

      $return_fields = ['do_not_phone', 'do_not_email', 'do_not_sms', 'do_not_mail', $pref_field];
      if ($followup_field) {
        $return_fields[] = $followup_field;
      }

      $params = [
        'id' => $contact_id,
        'return' => $return_fields,
      ];

      $contact = civicrm_api3('Contact', 'get', $params);
      if (!empty($contact['values'][$contact_id])) {
        $data = $contact['values'][$contact_id];
        $pref = $data[$pref_field] ?? NULL;
        if (is_array($pref)) {
          $pref = reset($pref);
        }

        $followup = NULL;
        if ($followup_field && isset($data[$followup_field])) {
          $followup = $data[$followup_field];
          // Handle pseudo-constants/option labels if needed,
          // but usually APIv3 returns values.
          // If we want labels, we might need another call or use APIv4.
        }

        return [
          'do_not_phone' => (int) ($data['do_not_phone'] ?? 0),
          'do_not_email' => (int) ($data['do_not_email'] ?? 0),
          'do_not_sms' => (int) ($data['do_not_sms'] ?? 0),
          'do_not_mail' => (int) ($data['do_not_mail'] ?? 0),
          'preferred_outreach_method' => (string) $pref,
          'member_followup_status' => $followup,
          'orientation_scheduled' => $this->loadOrientationScheduled($contact_id),
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching CiviCRM data for uid @uid: @message', ['@uid' => $uid, '@message' => $e->getMessage()]);
    }

    return $default;
  }

  /**
   * Records a one-time automatic "payment updated" outreach outcome.
   *
   * Back-attribution: when a recovery member quietly pays (Chargebee webhook
   * → role flip → stage transition out of recovery), we don't want all those
   * resolutions to land on a faceless "system" row with NULL staff. So we look
   * back 30 days for the most recent human outreach to this uid and copy its
   * contact_method and staff_uid onto the auto row. That credits the staff
   * member whose phone call / email actually drove the payment update. If no
   * recent human outreach exists, we fall back to the original system/NULL row.
   */
  protected function recordAutomaticRecoverySuccess(int $uid, string $contact_date, int $created_at): void {
    try {
      $existing = $this->database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['id'])
        ->condition('uid', $uid)
        ->condition('outcome', MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED)
        ->condition('contact_date', $contact_date)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if (!empty($existing)) {
        return;
      }

      // Back-attribute to the most recent human outreach within 30 days.
      // staff_uid IS NOT NULL excludes prior auto rows.
      $lookback_date = date('Y-m-d', strtotime($contact_date . ' -30 days'));
      $recent_human = $this->database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['contact_method', 'staff_uid'])
        ->condition('uid', $uid)
        ->condition('staff_uid', NULL, 'IS NOT NULL')
        ->condition('contact_date', $lookback_date, '>=')
        ->condition('contact_date', $contact_date, '<=')
        ->orderBy('contact_date', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if ($recent_human && !empty($recent_human['staff_uid'])) {
        $contact_method = (string) $recent_human['contact_method'];
        $staff_uid = (int) $recent_human['staff_uid'];
        $notes = 'Auto-confirmed: member exited recovery after payment resolved. Credit to most recent human outreach within 30 days.';
      }
      else {
        $contact_method = 'system';
        $staff_uid = NULL;
        $notes = 'Automatic success: member exited recovery after payment issue resolved. No human outreach in past 30 days.';
      }

      $this->database->insert('ms_member_outreach_log')
        ->fields([
          'uid' => $uid,
          'contact_date' => $contact_date,
          'contact_method' => $contact_method,
          'outcome' => MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          'notes' => $notes,
          'staff_uid' => $staff_uid,
          'sleep_days' => MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED),
          'created_at' => $created_at,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'Unable to record automatic recovery success for uid @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
    }
  }

  /**
   * Writes confirmed-cancel log rows for members who departed in recovery.
   *
   * @param int[] $active_uids
   *   User ids that were just snapshotted (still active members).
   * @param string $snapshot_type
   *   Snapshot type to scope the sweep to (e.g. 'daily').
   * @param string $contact_date
   *   Date to stamp on the auto rows (Y-m-d).
   * @param int $created_at
   *   Timestamp for the created_at column.
   *
   * @return int
   *   Number of members swept.
   */
  protected function recordDepartedRecoveryCancellations(array $active_uids, string $snapshot_type, string $contact_date, int $created_at): int {
    $query = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['uid'])
      ->condition('snapshot_type', $snapshot_type)
      ->condition('is_latest', 1)
      ->condition('stage', MemberSuccessLifecycle::STAGE_RECOVERY);
    if (!empty($active_uids)) {
      $query->condition('uid', $active_uids, 'NOT IN');
    }
    $departed = array_map('intval', $query->execute()->fetchCol());

    foreach ($departed as $uid) {
      $this->recordAutomaticCancellation($uid, $contact_date, $created_at);
    }

    return count($departed);
  }

  /**
   * Records a one-time automatic "confirmed cancel" outreach outcome.
   *
   * Mirror image of recordAutomaticRecoverySuccess(): when a recovery member
   * pays we auto-log the win, so when a recovery member's membership ends we
   * must auto-log the loss — otherwise the outreach log only ever contains
   * successes and the Confirmed Cancel columns read 0 for everyone. Uses the
   * same 30-day back-attribution so the staff member who worked the case gets
   * the closure credited to them.
   */
  public function recordAutomaticCancellation(int $uid, string $contact_date, int $created_at): void {
    try {
      // One cancellation per episode: skip if any confirmed-cancel row exists
      // in the past 90 days (manual or automatic).
      $lookback_guard = date('Y-m-d', strtotime($contact_date . ' -90 days'));
      $existing = $this->database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['id'])
        ->condition('uid', $uid)
        ->condition('outcome', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL)
        ->condition('contact_date', $lookback_guard, '>=')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if (!empty($existing)) {
        return;
      }

      // Back-attribute to the most recent human outreach within 30 days.
      $lookback_date = date('Y-m-d', strtotime($contact_date . ' -30 days'));
      $recent_human = $this->database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['contact_method', 'staff_uid'])
        ->condition('uid', $uid)
        ->condition('staff_uid', NULL, 'IS NOT NULL')
        ->condition('contact_date', $lookback_date, '>=')
        ->condition('contact_date', $contact_date, '<=')
        ->orderBy('contact_date', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if ($recent_human && !empty($recent_human['staff_uid'])) {
        $contact_method = (string) $recent_human['contact_method'];
        $staff_uid = (int) $recent_human['staff_uid'];
        $notes = 'Auto-confirmed cancellation: membership ended while in payment recovery (subscription cancelled). Credit to most recent human outreach within 30 days.';
      }
      else {
        $contact_method = 'system';
        $staff_uid = NULL;
        $notes = 'Automatic cancellation: membership ended while in payment recovery (subscription cancelled). No human outreach in past 30 days.';
      }

      $this->database->insert('ms_member_outreach_log')
        ->fields([
          'uid' => $uid,
          'contact_date' => $contact_date,
          'contact_method' => $contact_method,
          'outcome' => MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL,
          'notes' => $notes,
          'staff_uid' => $staff_uid,
          'sleep_days' => MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL),
          'created_at' => $created_at,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'Unable to record automatic cancellation for uid @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
    }
  }

  /**
   * Loads latest outreach-log state as a fallback for snapshot outreach fields.
   */
  protected function loadLatestOutreachLogState(int $uid): array {
    $row = $this->database->select('ms_member_outreach_log', 'log')
      ->fields('log', ['contact_date', 'followup_status', 'sleep_days', 'created_at'])
      ->condition('log.uid', $uid)
      ->orderBy('log.created_at', 'DESC')
      ->orderBy('log.id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!is_array($row) || empty($row['contact_date'])) {
      return [];
    }

    $contact_date = (string) $row['contact_date'];
    $sleep_days = isset($row['sleep_days']) ? (int) $row['sleep_days'] : 0;
    $next_followup_date = NULL;
    if ($sleep_days > 0) {
      $next_followup_date = date('Y-m-d', strtotime($contact_date . ' +' . $sleep_days . ' days'));
    }
    elseif ($sleep_days === -1) {
      $next_followup_date = '9999-12-31';
    }

    $contact_count = (int) $this->database->select('ms_member_outreach_log', 'log_count')
      ->condition('log_count.uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'last_contact_date' => $contact_date,
      'next_followup_date' => $next_followup_date,
      'outreach_status' => $row['followup_status'] ?? NULL,
      'contact_count' => max(1, $contact_count),
    ];
  }

  /**
   * Loads active member ids (including pending approvals).
   *
   * @return int[]
   *   User ids.
   */
  protected function loadActiveMemberIds(): array {
    $query = $this->database->select('users_field_data', 'u');
    $query->addField('u', 'uid');
    $query->innerJoin('user__roles', 'r', 'r.entity_id = u.uid');
    $query->condition('u.status', 1);
    $query->condition('r.roles_target_id', ['member', 'member_pending_approval'], 'IN');
    $query->distinct();
    // Deterministic order so sliced passes cover the roster predictably.
    $query->orderBy('u.uid', 'ASC');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Loads profile data for a member.
   */
  protected function loadProfileData(int $uid): array {
    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['profile_id', 'created']);
    $query->condition('p.uid', $uid);
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.status', 1);
    $query->range(0, 1);

    $query->leftJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->addField('join_date', 'field_member_join_date_value', 'join_date');
    $query->leftJoin('profile__field_card_serial_number', 'serial', 'serial.entity_id = p.profile_id AND serial.deleted = 0');
    $query->addField('serial', 'field_card_serial_number_value', 'profile_serial');
    $query->leftJoin('profile__field_member_payment_status', 'payment_status', 'payment_status.entity_id = p.profile_id AND payment_status.deleted = 0');
    $query->addField('payment_status', 'field_member_payment_status_target_id', 'payment_status');
    // Fetch Membership Type (Entity Reference)
    $query->leftJoin('profile__field_membership_type', 'type_ref', 'type_ref.entity_id = p.profile_id AND type_ref.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = type_ref.field_membership_type_target_id');
    $query->addField('term', 'name', 'membership_type');

    $record = $query->execute()->fetchAssoc() ?: [];
    // A real 'main' profile row exists (the member completed the profile step),
    // as opposed to falling back to the user-created timestamp below.
    $profile_present = !empty($record['profile_id']);
    $profile_created_ts = !empty($record['created']) ? (int) $record['created'] : NULL;
    $join_date = $record['join_date'] ?? NULL;
    if (empty($join_date) && !empty($record['created'])) {
      $join_date = date('Y-m-d', (int) $record['created']);
    }

    // Account creation timestamp = the /user/register step, which only happens
    // after Chargebee payment, so it is the funnel's "paid" anchor.
    $account_ts = $this->database->select('users_field_data', 'u')
      ->fields('u', ['created'])
      ->condition('u.uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $account_ts = !empty($account_ts) ? (int) $account_ts : NULL;

    if (empty($join_date) && $account_ts) {
      $join_date = date('Y-m-d', $account_ts);
      if ($profile_created_ts === NULL) {
        $profile_created_ts = $account_ts;
      }
    }

    return [
      'join_date' => $join_date,
      'profile_created_ts' => $profile_created_ts,
      'profile_present' => $profile_present,
      'account_ts' => $account_ts,
      'serial_present' => !empty($record['profile_serial']),
      'payment_status' => $record['payment_status'] ?? NULL,
      'membership_type' => $record['membership_type'] ?? NULL,
    ];
  }

  /**
   * Loads safety-quiz pass status for a member.
   *
   * "Passed" means an evaluated result at or above the quiz's own pass_rate, so
   * this stays correct if the threshold is ever lowered from 100%.
   *
   * @param int $uid
   *   User id.
   * @param int $quiz_qid
   *   The orientation safety quiz id.
   *
   * @return array
   *   ['passed' => bool, 'ts' => int|null] where ts is when they passed.
   */
  protected function loadQuizPassed(int $uid, int $quiz_qid): array {
    $query = $this->database->select('quiz_result', 'r');
    $query->addField('r', 'changed');
    $query->innerJoin('quiz', 'q', 'q.qid = r.qid');
    $query->where('r.score >= q.pass_rate');
    $query->condition('r.uid', $uid);
    $query->condition('r.qid', $quiz_qid);
    $query->condition('r.is_evaluated', 1);
    $query->orderBy('r.changed', 'ASC');
    $query->range(0, 1);
    $changed = $query->execute()->fetchField();

    return [
      'passed' => $changed !== FALSE && $changed !== NULL,
      'ts' => ($changed !== FALSE && $changed !== NULL) ? (int) $changed : NULL,
    ];
  }

  /**
   * Builds the OnboardingFunnel signal array from loaded member data.
   *
   * Shared by the daily snapshot build and the live member-facing progress
   * block so both compute the funnel identically.
   *
   * @param array $profile
   *   Result of loadProfileData().
   * @param array $quiz
   *   Result of loadQuizPassed().
   * @param array $door_badge
   *   Result of loadDoorBadgeStatus().
   * @param bool $serial_present
   *   Whether a card serial number is recorded.
   * @param string|null $orientation_scheduled_date
   *   YYYY-MM-DD of an upcoming orientation, or NULL.
   *
   * @return array
   *   Signal array consumable by OnboardingFunnel.
   */
  protected static function assembleFunnelSignals(array $profile, array $quiz, array $door_badge, bool $serial_present, ?string $orientation_scheduled_date): array {
    $orientation_scheduled = !empty($orientation_scheduled_date);
    return [
      'has_account' => TRUE,
      'account_ts' => $profile['account_ts'] ?? NULL,
      'profile_present' => !empty($profile['profile_present']),
      'profile_ts' => $profile['profile_created_ts'] ?? NULL,
      'quiz_passed' => !empty($quiz['passed']),
      'quiz_ts' => $quiz['ts'] ?? NULL,
      'orientation_scheduled' => $orientation_scheduled,
      'schedule_ts' => $orientation_scheduled ? strtotime($orientation_scheduled_date . ' 00:00:00') : NULL,
      'door_badge_active' => ($door_badge['status'] ?? NULL) === 'active' && $serial_present,
    ];
  }

  /**
   * Loads live onboarding funnel signals for a single member.
   *
   * Used by the member-facing progress timeline so the bar reflects the
   * member's real-time state (just-saved profile, just-passed quiz) rather than
   * waiting for the next daily snapshot. Uses fast DB lookups only; the
   * orientation-booked signal is read from the latest snapshot to avoid a live
   * CiviCRM call on every onboarding page load.
   *
   * @param int $uid
   *   User id.
   *
   * @return array
   *   Signal array consumable by OnboardingFunnel.
   */
  public function loadOnboardingSignals(int $uid): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $door_badge_tid = (int) ($config->get('door_badge_tid') ?? 1519);
    $orientation_quiz_nid = (int) ($config->get('orientation_quiz_nid') ?? 1);

    $profile = $this->loadProfileData($uid);
    $door_badge = $this->loadDoorBadgeStatus($uid, $door_badge_tid);
    $quiz = $this->loadQuizPassed($uid, $orientation_quiz_nid);
    $serial_present = $profile['serial_present'] || $this->loadUserFlags($uid)['serial_present'];

    $orientation_date = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['orientation_scheduled_date'])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->range(0, 1)
      ->execute()
      ->fetchField() ?: NULL;

    return self::assembleFunnelSignals($profile, $quiz, $door_badge, $serial_present, $orientation_date);
  }

  /**
   * Loads user-level flags and fields.
   */
  protected function loadUserFlags(int $uid): array {
    $serial = $this->database->select('user__field_card_serial_number', 'serial')
      ->fields('serial', ['field_card_serial_number_value'])
      ->condition('serial.entity_id', $uid)
      ->condition('serial.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $payment_failed = $this->database->select('user__field_payment_failed', 'failed')
      ->fields('failed', ['field_payment_failed_value'])
      ->condition('failed.entity_id', $uid)
      ->condition('failed.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $payment_pause = $this->database->select('user__field_chargebee_payment_pause', 'pause')
      ->fields('pause', ['field_chargebee_payment_pause_value'])
      ->condition('pause.entity_id', $uid)
      ->condition('pause.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return [
      'serial_present' => !empty($serial),
      'payment_failed' => !empty($payment_failed),
      'payment_pause' => !empty($payment_pause),
    ];
  }

  /**
   * Loads door badge status for a member.
   *
   * Requests marked "duplicate" are excluded: a duplicate-card request says
   * nothing about the member's door access, and because only the newest
   * request is read, a duplicate row would otherwise shadow the member's
   * real (usually active) badge — misclassifying long-standing members as
   * onboarding-stalled.
   */
  protected function loadDoorBadgeStatus(int $uid, int $door_badge_tid): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('n', 'created');
    $query->leftJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->addField('status', 'field_badge_status_value', 'status');
    $query->innerJoin('node__field_member_to_badge', 'member', 'member.entity_id = n.nid AND member.deleted = 0');
    $query->innerJoin('node__field_badge_requested', 'badge', 'badge.entity_id = n.nid AND badge.deleted = 0');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('member.field_member_to_badge_target_id', $uid);
    $query->condition('badge.field_badge_requested_target_id', $door_badge_tid);
    $query->condition($query->orConditionGroup()
      ->isNull('status.field_badge_status_value')
      ->condition('status.field_badge_status_value', 'duplicate', '<>'));
    $query->orderBy('n.created', 'DESC');
    $query->range(0, 1);

    $record = $query->execute()->fetchAssoc() ?: [];
    return [
      'status' => $record['status'] ?? NULL,
      'created' => isset($record['created']) ? (int) $record['created'] : NULL,
    ];
  }

  /**
   * Loads badge counts and last badge data for a member.
   */
  protected function loadBadgeStats(int $uid, int $door_badge_tid, int $window_days, int $now_ts): array {
    $window_start = $now_ts - ($window_days * 86400);

    $base = $this->database->select('node_field_data', 'n');
    $base->condition('n.type', 'badge_request');
    $base->condition('n.status', 1);
    $base->innerJoin('node__field_member_to_badge', 'member', 'member.entity_id = n.nid AND member.deleted = 0');
    $base->innerJoin('node__field_badge_requested', 'badge', 'badge.entity_id = n.nid AND badge.deleted = 0');
    $base->leftJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $base->condition('member.field_member_to_badge_target_id', $uid);
    $base->condition('badge.field_badge_requested_target_id', $door_badge_tid, '!=');
    $base->condition('status.field_badge_status_value', 'active');

    $total_query = clone $base;
    $total_query->addExpression('COUNT(n.nid)', 'count_total');
    $count_total = (int) $total_query->execute()->fetchField();

    $window_query = clone $base;
    $window_query->condition('n.created', $window_start, '>=');
    $window_query->addExpression('COUNT(n.nid)', 'count_window');
    $count_window = (int) $window_query->execute()->fetchField();

    $last_query = clone $base;
    $last_query->addExpression('MAX(n.created)', 'last_badge_ts');
    $last_badge_ts = $last_query->execute()->fetchField();

    return [
      'count_total' => $count_total,
      'count_window' => $count_window,
      'last_badge_ts' => $last_badge_ts ? (int) $last_badge_ts : NULL,
    ];
  }

  /**
   * Loads visit recency and frequency stats.
   */
  protected function loadVisitStats(int $uid, int $now_ts): array {
    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression('MAX(acl.created)', 'last_visit_ts');
    $query->condition('acl.type', 'access_control_request');
    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $query->condition('user_ref.field_access_request_user_target_id', $uid);
    $last_visit_ts = $query->execute()->fetchField();

    $window_start = $now_ts - (30 * 86400);
    $count_query = $this->database->select('access_control_log_field_data', 'acl');
    $count_query->addExpression('COUNT(DISTINCT DATE(FROM_UNIXTIME(acl.created)))', 'visit_days');
    $count_query->condition('acl.type', 'access_control_request');
    $count_query->condition('acl.created', $window_start, '>=');
    $count_query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $count_query->condition('user_ref.field_access_request_user_target_id', $uid);
    $visit_days = $count_query->execute()->fetchField();

    return [
      'last_visit_ts' => $last_visit_ts ? (int) $last_visit_ts : NULL,
      'visit_count_30d' => $visit_days ? (int) $visit_days : 0,
    ];
  }

  /**
   * Returns the date of the next scheduled orientation for a CiviCRM contact, or NULL.
   *
   * Looks for an "Attended Orientation" activity with status "Scheduled" and a
   * future activity date. Returns the date string (YYYY-MM-DD) if found.
   */
  protected function loadOrientationScheduled(int $contactId): ?string {
    try {
      $result = civicrm_api3('Activity', 'get', [
        'target_contact_id' => $contactId,
        'activity_type_id' => 'Attended Orientation',
        'status_id' => 'Scheduled',
        'return' => ['activity_date_time'],
        'sequential' => 1,
        'options' => ['limit' => 1, 'sort' => 'activity_date_time ASC'],
      ]);
      if (!empty($result['values'][0]['activity_date_time'])) {
        $date_str = substr($result['values'][0]['activity_date_time'], 0, 10);
        // Only return if today or in the future.
        if ($date_str >= date('Y-m-d')) {
          return $date_str;
        }
      }
    }
    catch (\Exception $e) {
      // Activity type may not exist in all environments; silently continue.
    }
    return NULL;
  }

  /**
   * Returns the date of the first successful door card scan for a user, or NULL.
   */
  protected function loadFirstCardScan(int $uid): ?string {
    try {
      $query = $this->database->select('access_control_log_field_data', 'acl');
      $query->addExpression('MIN(acl.created)', 'first_scan_ts');
      $query->condition('acl.type', 'access_control_request');
      $query->innerJoin(
        'access_control_log__field_access_request_user',
        'user_ref',
        'user_ref.entity_id = acl.id'
      );
      $query->condition('user_ref.field_access_request_user_target_id', $uid);
      $query->innerJoin(
        'access_control_log__field_access_request_result',
        'result_ref',
        'result_ref.entity_id = acl.id'
      );
      $query->condition('result_ref.field_access_request_result_value', 1);
      $ts = $query->execute()->fetchField();
      return $ts ? date('Y-m-d', (int) $ts) : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Inserts or updates a snapshot row.
   */
  public function upsertSnapshot(array $row): void {
    $keys = [
      'uid' => $row['uid'],
      'snapshot_date' => $row['snapshot_date'],
      'snapshot_type' => $row['snapshot_type'],
    ];

    if (!empty($row['is_latest'])) {
      $this->database->update('ms_member_success_snapshot')
        ->fields(['is_latest' => 0])
        ->condition('uid', $row['uid'])
        ->condition('snapshot_type', $row['snapshot_type'])
        ->execute();
    }

    $fields = $row;
    unset($fields['uid'], $fields['snapshot_date'], $fields['snapshot_type']);
    if (isset($fields['risk_reasons']) && is_array($fields['risk_reasons'])) {
      $fields['risk_reasons'] = serialize($fields['risk_reasons']);
    }

    $this->database->merge('ms_member_success_snapshot')
      ->keys($keys)
      ->fields($fields)
      ->execute();
  }

}
