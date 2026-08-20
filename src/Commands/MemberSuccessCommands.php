<?php

namespace Drupal\makerspace_member_success\Commands;

use Drush\Commands\DrushCommands;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;
use Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface;
use Drupal\makerspace_member_success\Service\CiviCrmActivityBackfillService;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Drush commands for Makerspace Member Success.
 */
class MemberSuccessCommands extends DrushCommands {

  /**
   * The snapshot builder.
   *
   * @var \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder
   */
  protected $snapshotBuilder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The recovery metrics service.
   *
   * @var \Drupal\makerspace_member_success\Service\RecoveryMetrics
   */
  protected $recoveryMetrics;

  /**
   * Chargebee followup status sync service.
   *
   * @var \Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync|null
   */
  protected $chargebeeFollowupStatusSync;

  /**
   * The outreach queue service.
   *
   * @var \Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface
   */
  protected $queueService;

  /**
   * CiviCRM activity backfill service.
   *
   * @var \Drupal\makerspace_member_success\Service\CiviCrmActivityBackfillService
   */
  protected $civiBackfill;

  /**
   * Constructs a MemberSuccessCommands object.
   *
   * @param \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder $snapshot_builder
   *   The snapshot builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\makerspace_member_success\Service\RecoveryMetrics $recovery_metrics
   *   The recovery metrics service (optional for backwards compatibility).
   * @param \Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync|null $chargebee_followup_status_sync
   *   Chargebee followup status sync service.
   * @param \Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface|null $queue_service
   *   The outreach queue service.
   * @param \Drupal\makerspace_member_success\Service\CiviCrmActivityBackfillService|null $civi_backfill
   *   CiviCRM activity backfill service.
   */
  public function __construct(
    MemberSuccessSnapshotBuilder $snapshot_builder,
    EntityTypeManagerInterface $entity_type_manager,
    ?RecoveryMetrics $recovery_metrics = NULL,
    ?ChargebeeFollowupStatusSync $chargebee_followup_status_sync = NULL,
    ?OutreachQueueServiceInterface $queue_service = NULL,
    ?CiviCrmActivityBackfillService $civi_backfill = NULL,
  ) {
    parent::__construct();
    $this->snapshotBuilder = $snapshot_builder;
    $this->entityTypeManager = $entity_type_manager;
    $this->recoveryMetrics = $recovery_metrics;
    $this->chargebeeFollowupStatusSync = $chargebee_followup_status_sync;
    $this->queueService = $queue_service ?: \Drupal::service('makerspace_member_success.outreach_queue_service');
    $this->civiBackfill = $civi_backfill ?: \Drupal::service('makerspace_member_success.civi_backfill');
  }

  /**
   * Backfills outreach logs from CiviCRM activities.
   *
   * @param array $options
   *   Command options.
   *
   * @option days
   *   How many days back to look. Default 90.
   * @option types
   *   Comma-separated activity type IDs. Default 2,3 (Phone, Email).
   * @option dry-run
   *   Report counts without writing to database.
   *
   * @command ms:backfill-civi
   * @aliases ms-backfill
   */
  public function backfillCivi(array $options = ['days' => 90, 'types' => '2,3', 'dry-run' => FALSE]): void {
    $days = (int) $options['days'];
    $types = array_map('intval', explode(',', $options['types']));
    $dry_run = (bool) $options['dry-run'];

    $this->logger()->notice(dt('Starting CiviCRM activity backfill (@days days)...', ['@days' => $days]));
    $result = $this->civiBackfill->backfill($days, $types, $dry_run);

    $rows = [
      ['Activities examined', (string) $result['examined']],
      ['Logs imported', (string) $result['imported']],
      ['Skipped (already exists)', (string) $result['skipped_exists']],
      ['Skipped (no Drupal user)', (string) $result['skipped_no_user']],
      ['Errors', (string) $result['errors']],
    ];
    $this->io()->table(['Metric', 'Value'], $rows);

    if ($dry_run) {
      $this->logger()->info('Dry run complete. No data was saved.');
    }
    else {
      $this->logger()->success('Backfill complete.');
    }
  }

  /**
   * Processes approved outreach items from the queue.
   *
   * @command ms:outreach-process
   * @aliases ms-process
   */
  public function processOutreach(): void {
    $this->logger()->notice(dt('Processing approved outreach items...'));
    $count = $this->queueService->processApprovedItems();
    if ($count > 0) {
      $this->logger()->success(dt('Successfully processed @count outreach items.', ['@count' => $count]));
    }
    else {
      $this->logger()->notice(dt('No approved outreach items to process at this time.'));
    }
  }

  /**
   * Previews the onboarding auto-nudge: who would be emailed, without sending.
   *
   * Runs the REAL candidate generation + OutreachQueueService::enqueueCandidate
   * gates (risk threshold, max attempts, cooldown, consent, template presence)
   * inside a database transaction that is always rolled back — so the verdicts
   * exactly match what cron would do, but nothing is written and nothing is
   * sent. Run before enabling onboarding_auto_nudge_enabled to confirm range.
   *
   * @command ms:nudge-preview
   * @aliases ms-nudge-preview
   */
  public function nudgePreview(): void {
    $config = \Drupal::config('makerspace_member_success.settings');
    $generator = \Drupal::service('makerspace_member_success.outreach_candidate_generator');
    $queue = \Drupal::service('makerspace_member_success.outreach_queue_service');
    $database = \Drupal::database();

    $enabled = (bool) $config->get('onboarding_auto_nudge_enabled');
    $template = $config->get('template_onboarding');

    $this->io()->note(sprintf(
      'Auto-nudge is currently %s. Thresholds: min_risk=%d, max_attempts=%d, cooldown=%d days. Onboarding email template: %s.',
      $enabled ? 'ENABLED' : 'OFF (preview only)',
      (int) ($config->get('stage_onboarding_min_risk_to_contact') ?? 20),
      (int) ($config->get('stage_onboarding_max_attempts') ?? 3),
      (int) ($config->get('stage_onboarding_cooldown_days') ?? 7),
      $template ? 'set' : 'NOT SET — every nudge would be suppressed until set',
    ));

    $candidates = $generator->generate(['onboarding']);
    if (empty($candidates)) {
      $this->logger()->notice(dt('No onboarding members with risk score above 0 in the latest snapshot.'));
      return;
    }

    // Enqueue every candidate through the real gates inside a transaction we
    // always roll back. A "sendable" verdict means the row landed as queued or
    // approved rather than suppressed — i.e. it would go out once the flag is on
    // (the flag only flips queued -> approved; the suppression gates fire the
    // same either way, so the count is accurate regardless of the flag).
    // Mirror the cron's step scoping so the preview reflects who would really
    // be nudged, not every onboarding-stalled member.
    $nudge_steps = array_values(array_filter((array) ($config->get('onboarding_auto_nudge_steps') ?? [])));

    $rows = [];
    $would_send = 0;
    $transaction = $database->startTransaction();
    try {
      foreach ($candidates as $c) {
        $reasons_scope = @unserialize((string) ($c['risk_reasons'] ?? ''), ['allowed_classes' => FALSE]);
        if (!\Drupal\makerspace_member_success\Support\OnboardingFunnel::stuckStepAllowed(is_array($reasons_scope) ? $reasons_scope : [], $nudge_steps, (int) $c['uid'])) {
          continue;
        }
        $id = $queue->enqueueCandidate((int) $c['uid'], 'onboarding', $c);
        $result = $database->select('ms_member_outreach_queue', 'q')
          ->fields('q', ['status', 'suppression_reason_code'])
          ->condition('id', $id)
          ->execute()
          ->fetchAssoc();

        $status = (string) ($result['status'] ?? '');
        $sendable = in_array($status, ['queued', 'approved'], TRUE);
        if ($sendable) {
          $would_send++;
        }

        $reasons = @unserialize((string) ($c['risk_reasons'] ?? ''), ['allowed_classes' => FALSE]) ?: [];
        $stuck = 'n/a';
        foreach ((array) $reasons as $r) {
          if (is_string($r) && str_starts_with($r, 'stuck_at_')) {
            $stuck = $r;
            break;
          }
        }

        $verdict = $sendable
          ? 'WOULD SEND'
          : ('skip: ' . str_replace(['suppressed_', '_'], ['', ' '], (string) ($result['suppression_reason_code'] ?? $status)));

        $rows[] = [
          (string) $c['uid'],
          (string) ($c['email'] ?? ''),
          (string) $c['risk_score'],
          $stuck,
          (string) $c['contact_count'],
          (string) ($c['last_contact_date'] ?? '—') ?: '—',
          $verdict,
        ];
      }
    }
    finally {
      // Always undo every enqueue — this command persists nothing.
      $transaction->rollBack();
    }

    $this->io()->table(
      ['UID', 'Email', 'Risk', 'Stuck at', 'Prior contacts', 'Last contact', 'Verdict'],
      $rows,
    );
    $this->logger()->success(dt('@total onboarding candidate(s); @send would receive a nudge on the next cron run.', [
      '@total' => count($rows),
      '@send' => $would_send,
    ]));
  }

  /**
   * Builds member success snapshots.
   *
   * @param string|null $uid
   *   Optional user ID to build a snapshot for. If omitted, builds for all active members.
   *
   * @command ms-snapshot:build
   * @aliases ms-build
   * @usage ms-snapshot:build
   *   Builds snapshots for all active members.
   * @usage ms-snapshot:build 123
   *   Builds snapshot for user 123.
   */
  public function build($uid = NULL) {
    if ($uid) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user) {
        $this->logger()->error(dt('User @uid not found.', ['@uid' => $uid]));
        return;
      }

      $this->logger()->notice(dt('Building snapshot for user @uid...', ['@uid' => $uid]));
      $date = new \DateTimeImmutable('now');
      $snapshot_date = $date->format('Y-m-d');
      $now_ts = time();

      $row = $this->snapshotBuilder->buildSnapshotForUser((int) $uid, $snapshot_date, 'daily', $now_ts);
      $row['is_latest'] = 1;
      $this->snapshotBuilder->upsertSnapshot($row);

      $this->logger()->success(dt('Built snapshot for user @uid.', ['@uid' => $uid]));
    }
    else {
      $this->logger()->notice(dt('Building daily snapshots for all active members...'));
      $count = $this->snapshotBuilder->buildDailySnapshots();
      $this->logger()->success(dt('Built @count snapshots.', ['@count' => $count]));
    }
  }

  /**
   * Display intervention performance summary.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option start-date
   *   Start date for filtering (YYYY-MM-DD).
   * @option end-date
   *   End date for filtering (YYYY-MM-DD).
   * @option format
   *   Output format: table, json, or csv. Default: table.
   *
   * @command ms:performance
   * @aliases ms-perf,ms:stats
   * @usage ms:performance
   *   Show all-time intervention performance summary.
   * @usage ms:performance --start-date=2026-01-01 --end-date=2026-01-31
   *   Show performance for January 2026.
   * @usage ms:performance --format=json
   *   Output as JSON.
   */
  public function performanceSummary(array $options = ['start-date' => NULL, 'end-date' => NULL, 'format' => 'table']) {
    $start_date = $options['start-date'];
    $end_date = $options['end-date'];
    $format = $options['format'] ?? 'table';

    // Get recovery metrics service.
    if (!$this->recoveryMetrics) {
      $this->recoveryMetrics = \Drupal::service('makerspace_member_success.recovery_metrics');
    }

    // Get metrics.
    $roi = $this->recoveryMetrics->getRetentionValue($start_date, $end_date);
    $all_metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);
    $staff = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);

    $date_range = $start_date && $end_date ? " ($start_date to $end_date)" : " (all time)";

    if ($format === 'json') {
      $this->output()->writeln(json_encode([
        'roi' => $roi,
        'metrics' => $all_metrics,
        'staff' => $staff,
      ], JSON_PRETTY_PRINT));
      return;
    }

    if ($format === 'csv') {
      $this->output()->writeln('Metric,Value');
      $this->output()->writeln('Members at Risk,' . $roi['total_members_at_risk']);
      $this->output()->writeln('Annual Value Saved,$' . number_format($roi['annual_value_saved'], 0));
      $this->output()->writeln('Resolution Rate,' . $all_metrics['resolution_rate']['rate'] . '%');
      $this->output()->writeln('Avg Days to Resolution,' . round($all_metrics['avg_days_to_resolution'], 1));
      return;
    }

    // Table format (default)
    $this->output()->writeln('');
    $this->output()->writeln('<info>=== Intervention Performance Summary' . $date_range . ' ===</info>');
    $this->output()->writeln('');

    // ROI Summary.
    $this->output()->writeln('<comment>ROI SUMMARY:</comment>');
    $this->output()->writeln('  Members at Risk: ' . $roi['total_members_at_risk']);
    $this->output()->writeln('  Annual Value Saved: $' . number_format($roi['annual_value_saved'], 0));
    $this->output()->writeln('  Members Resolved: ' . $roi['members_resolved']);
    $this->output()->writeln('  Resolution Rate: ' . $all_metrics['resolution_rate']['rate'] . '%');
    $this->output()->writeln('  Avg Days to Resolution: ' . round($all_metrics['avg_days_to_resolution'], 1));
    $this->output()->writeln('');

    // Staff Performance.
    if (!empty($staff)) {
      $this->output()->writeln('<comment>TOP STAFF PERFORMANCE:</comment>');
      $rows = [];
      foreach (array_slice($staff, 0, 5) as $s) {
        $rows[] = [
          $s['staff_name'],
          $s['members_contacted'],
          $s['resolved'],
          $s['resolution_rate'] . '%',
        ];
      }
      $this->io()->table(['Staff', 'Contacted', 'Resolved', 'Rate'], $rows);
    }

    // Channel Effectiveness.
    if (!empty($all_metrics['channel_effectiveness'])) {
      $this->output()->writeln('<comment>CHANNEL EFFECTIVENESS:</comment>');
      $rows = [];
      foreach ($all_metrics['channel_effectiveness'] as $method => $stats) {
        $rows[] = [
          ucfirst($method),
          $stats['total'],
          $stats['resolved'],
          $stats['rate'] . '%',
        ];
      }
      $this->io()->table(['Method', 'Contacted', 'Resolved', 'Rate'], $rows);
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Dashboard: /admin/makerspace/member-success/contractor-performance</info>');
    $this->output()->writeln('');
  }

  /**
   * Audits followup status storage consistency.
   *
   * @command ms:followup-audit
   * @aliases ms-followup-audit
   */
  public function followupAudit(): void {
    $database = \Drupal::database();
    $schema = $database->schema();

    $hasCanonical = $schema->tableExists('user__field_member_followup_status');
    $hasLegacy = $schema->tableExists('user__field_chargebee_followup');

    $totalWithChargebeeId = (int) $database->select('user__field_user_chargebee_id', 'cb')
      ->condition('cb.field_user_chargebee_id_value', '', '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    $canonicalCount = 0;
    if ($hasCanonical) {
      $canonicalCount = (int) $database->select('user__field_member_followup_status', 'f')
        ->condition('f.field_member_followup_status_value', '', '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    $legacyCount = 0;
    if ($hasLegacy) {
      $legacyCount = (int) $database->select('user__field_chargebee_followup', 'f')
        ->condition('f.field_chargebee_followup_value', '', '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    $mismatchCount = 0;
    if ($hasCanonical && $hasLegacy) {
      $query = $database->select('user__field_member_followup_status', 'new');
      $query->innerJoin('user__field_chargebee_followup', 'old', 'old.entity_id = new.entity_id');
      $query->condition('new.field_member_followup_status_value', '', '<>');
      $query->condition('old.field_chargebee_followup_value', '', '<>');
      $query->where('new.field_member_followup_status_value <> old.field_chargebee_followup_value');
      $mismatchCount = (int) $query->countQuery()->execute()->fetchField();
    }

    $rows = [
      ['Users with Chargebee ID', (string) $totalWithChargebeeId],
      ['Canonical field present', $hasCanonical ? 'yes' : 'no'],
      ['Legacy field present', $hasLegacy ? 'yes' : 'no'],
      ['Canonical values populated', (string) $canonicalCount],
      ['Legacy values populated', (string) $legacyCount],
      ['Canonical vs legacy mismatches', (string) $mismatchCount],
    ];
    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Backfills canonical followup status from legacy field where missing.
   *
   * @command ms:followup-backfill-legacy
   * @aliases ms-followup-backfill
   */
  public function followupBackfillLegacy(): void {
    $database = \Drupal::database();
    $schema = $database->schema();
    if (
      !$schema->tableExists('user__field_member_followup_status')
      || !$schema->tableExists('user__field_chargebee_followup')
    ) {
      $this->logger()->warning('Backfill skipped: canonical or legacy followup field table is missing.');
      return;
    }

    $uids = $database->select('user__field_chargebee_followup', 'old')
      ->fields('old', ['entity_id'])
      ->condition('old.field_chargebee_followup_value', '', '<>')
      ->execute()
      ->fetchCol();
    if (empty($uids)) {
      $this->logger()->notice('No legacy followup values found to backfill.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $updated = 0;
    foreach (array_chunk(array_map('intval', $uids), 100) as $chunk) {
      $users = $storage->loadMultiple($chunk);
      foreach ($users as $user) {
        if (!$user->hasField('field_member_followup_status') || !$user->hasField('field_chargebee_followup')) {
          continue;
        }
        $legacy = trim((string) $user->get('field_chargebee_followup')->value);
        $canonical = trim((string) $user->get('field_member_followup_status')->value);
        if ($legacy !== '' && $canonical === '') {
          $user->set('field_member_followup_status', $legacy);
          $user->save();
          $updated++;
        }
      }
    }

    $this->logger()->success(dt('Backfill complete. Updated @count users.', ['@count' => $updated]));
  }

  /**
   * Pushes one user's followup status to Chargebee for testing/operations.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param array $options
   *   Command options.
   *
   * @option status
   *   Optional followup status override. If omitted, uses current user field.
   * @option force
   *   Force push even when push is disabled in config.
   * @option dry-run
   *   Do not POST updates; only report what would change.
   *
   * @command ms:chargebee-push-followup
   * @aliases ms-cb-push-followup
   */
  public function chargebeePushFollowup(
    int $uid,
    array $options = ['status' => NULL, 'force' => FALSE, 'dry-run' => FALSE],
  ): void {
    $sync = $this->chargebeeFollowupStatusSync ?: \Drupal::service('makerspace_member_success.chargebee_followup_status_sync');
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      $this->logger()->error(dt('User @uid not found.', ['@uid' => $uid]));
      return;
    }

    $status = $options['status'];
    if ($status === NULL || trim((string) $status) === '') {
      if ($user->hasField('field_member_followup_status')) {
        $status = $user->get('field_member_followup_status')->value;
      }
      elseif ($user->hasField('field_chargebee_followup')) {
        $status = $user->get('field_chargebee_followup')->value;
      }
      else {
        $status = NULL;
      }
    }

    $result = $sync->pushUserStatus(
      $user,
      $status !== NULL ? trim((string) $status) : NULL,
      (bool) $options['force'],
      (bool) $options['dry-run']
    );

    $rows = [
      ['UID', (string) $uid],
      ['Customer ID', (string) ($result['customer_id'] ?? '')],
      ['Mapped value', (string) ($result['mapped_value'] ?? '')],
      ['Subscriptions examined', (string) ($result['subscriptions_examined'] ?? 0)],
      ['Subscriptions updated', (string) ($result['subscriptions_updated'] ?? 0)],
      ['Subscriptions skipped (same)', (string) ($result['subscriptions_skipped_same'] ?? 0)],
      ['Dry run', !empty($result['dry_run']) ? 'yes' : 'no'],
      ['Skipped reason', (string) ($result['skipped_reason'] ?? '')],
    ];
    if (!empty($result['error'])) {
      $rows[] = ['Error', (string) $result['error']];
    }

    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Pulls one user's followup status from Chargebee into Drupal.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param array $options
   *   Command options.
   *
   * @option dry-run
   *   Do not save user changes; only report what would change.
   * @option overwrite
   *   Overwrite non-empty Drupal followup value when it differs.
   *
   * @command ms:chargebee-pull-followup
   * @aliases ms-cb-pull-followup
   */
  public function chargebeePullFollowup(
    int $uid,
    array $options = ['dry-run' => FALSE, 'overwrite' => FALSE],
  ): void {
    $sync = $this->chargebeeFollowupStatusSync ?: \Drupal::service('makerspace_member_success.chargebee_followup_status_sync');
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      $this->logger()->error(dt('User @uid not found.', ['@uid' => $uid]));
      return;
    }

    $result = $sync->pullUserStatus(
      $user,
      (bool) ($options['dry-run'] ?? FALSE),
      (bool) ($options['overwrite'] ?? FALSE)
    );

    $rows = [
      ['UID', (string) $uid],
      ['Customer ID', (string) ($result['customer_id'] ?? '')],
      ['Chargebee value', (string) ($result['chargebee_value'] ?? '')],
      ['Mapped status', (string) ($result['mapped_status'] ?? '')],
      ['Current status', (string) ($result['current_status'] ?? '')],
      ['Subscriptions examined', (string) ($result['subscriptions_examined'] ?? 0)],
      ['Changed', !empty($result['changed']) ? 'yes' : 'no'],
      ['Updated', !empty($result['updated']) ? 'yes' : 'no'],
      ['Dry run', !empty($result['dry_run']) ? 'yes' : 'no'],
      ['Skipped reason', (string) ($result['skipped_reason'] ?? '')],
    ];
    if (!empty($result['error'])) {
      $rows[] = ['Error', (string) $result['error']];
    }

    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Bulk-pulls followup status from Chargebee into Drupal.
   *
   * @param array $options
   *   Command options.
   *
   * @option uids
   *   Optional comma-separated user IDs to target.
   * @option limit
   *   Max users to process (default 200).
   * @option offset
   *   Starting offset for selected users (default 0).
   * @option only-empty
   *   Only process users with empty canonical followup status.
   * @option delay-ms
   *   Optional delay between users in milliseconds to reduce API rate limiting.
   * @option dry-run
   *   Do not save user changes; only report what would change.
   * @option overwrite
   *   Overwrite non-empty Drupal followup value when it differs.
   *
   * @command ms:chargebee-pull-followup-bulk
   * @aliases ms-cb-pull-followup-bulk
   */
  public function chargebeePullFollowupBulk(
    array $options = [
      'uids' => NULL,
      'limit' => 200,
      'offset' => 0,
      'only-empty' => FALSE,
      'delay-ms' => 0,
      'dry-run' => FALSE,
      'overwrite' => FALSE,
    ],
  ): void {
    $sync = $this->chargebeeFollowupStatusSync ?: \Drupal::service('makerspace_member_success.chargebee_followup_status_sync');
    $database = \Drupal::database();
    $schema = $database->schema();
    $storage = $this->entityTypeManager->getStorage('user');

    $limit = max(1, (int) ($options['limit'] ?? 200));
    $offset = max(0, (int) ($options['offset'] ?? 0));
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $overwrite = (bool) ($options['overwrite'] ?? FALSE);
    $only_empty = (bool) ($options['only-empty'] ?? FALSE);
    $delay_ms = max(0, (int) ($options['delay-ms'] ?? 0));

    $uids_option = trim((string) ($options['uids'] ?? ''));
    $uids = [];
    if ($uids_option !== '') {
      foreach (explode(',', $uids_option) as $part) {
        $uid = (int) trim($part);
        if ($uid > 0) {
          $uids[] = $uid;
        }
      }
      $uids = array_values(array_unique($uids));
      if (empty($uids)) {
        $this->logger()->warning('No valid UIDs parsed from --uids option.');
        return;
      }
      $uids = array_slice($uids, $offset, $limit);
    }
    else {
      if (!$schema->tableExists('user__field_user_chargebee_id')) {
        $this->logger()->error('Missing required table user__field_user_chargebee_id.');
        return;
      }

      $query = $database->select('user__field_user_chargebee_id', 'cb');
      $query->fields('cb', ['entity_id']);
      $query->condition('cb.field_user_chargebee_id_value', '', '<>');

      if ($only_empty) {
        if (!$schema->tableExists('user__field_member_followup_status')) {
          $this->logger()->error('Cannot use --only-empty: canonical followup field table is missing.');
          return;
        }
        $query->leftJoin('user__field_member_followup_status', 'mf', 'mf.entity_id = cb.entity_id');
        $or = $query->orConditionGroup();
        $or->isNull('mf.field_member_followup_status_value');
        $or->condition('mf.field_member_followup_status_value', '');
        $query->condition($or);
      }

      $query->orderBy('cb.entity_id', 'ASC');
      $query->range($offset, $limit);
      $uids = array_map('intval', $query->execute()->fetchCol());
    }

    if (empty($uids)) {
      $this->logger()->notice('No users matched the selection criteria.');
      return;
    }

    $summary = [
      'users_targeted' => count($uids),
      'users_processed' => 0,
      'users_changed' => 0,
      'users_updated' => 0,
      'subscriptions_examined' => 0,
    ];
    $skip_reasons = [];

    foreach (array_chunk($uids, 100) as $chunk) {
      $users = $storage->loadMultiple($chunk);
      foreach ($chunk as $uid) {
        if (empty($users[$uid])) {
          $skip_reasons['user_not_found'] = ($skip_reasons['user_not_found'] ?? 0) + 1;
          continue;
        }

        $result = $sync->pullUserStatus($users[$uid], $dry_run, $overwrite);
        $summary['users_processed']++;
        $summary['subscriptions_examined'] += (int) ($result['subscriptions_examined'] ?? 0);
        $summary['users_changed'] += !empty($result['changed']) ? 1 : 0;
        $summary['users_updated'] += (int) ($result['updated'] ?? 0);

        $reason = (string) ($result['skipped_reason'] ?? '');
        if ($reason !== '') {
          $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
        }
        if ($delay_ms > 0) {
          usleep($delay_ms * 1000);
        }
      }
    }

    $rows = [
      ['Users targeted', (string) $summary['users_targeted']],
      ['Users processed', (string) $summary['users_processed']],
      ['Users changed', (string) $summary['users_changed']],
      ['Users updated', (string) $summary['users_updated']],
      ['Subscriptions examined', (string) $summary['subscriptions_examined']],
      ['Dry run', $dry_run ? 'yes' : 'no'],
      ['Overwrite', $overwrite ? 'yes' : 'no'],
      ['Only empty', $only_empty ? 'yes' : 'no'],
      ['Delay ms', (string) $delay_ms],
    ];
    $this->io()->table(['Metric', 'Value'], $rows);

    if (!empty($skip_reasons)) {
      ksort($skip_reasons);
      $reason_rows = [];
      foreach ($skip_reasons as $reason => $count) {
        $reason_rows[] = [$reason, (string) $count];
      }
      $this->io()->table(['Skipped reason', 'Count'], $reason_rows);
    }
  }

  /**
   * Bulk-pushes followup status to Chargebee for cleanup/synchronization.
   *
   * @param array $options
   *   Command options.
   *
   * @option status
   *   Optional status override to push for all targeted users.
   * @option only-status
   *   Optional filter: only users currently at this followup status.
   * @option uids
   *   Optional comma-separated user IDs to target.
   * @option limit
   *   Max users to process (default 200).
   * @option offset
   *   Starting offset for selected users (default 0).
   * @option force
   *   Force push even when push is disabled in config.
   * @option dry-run
   *   Do not POST updates; only report what would change.
   *
   * @command ms:chargebee-push-followup-bulk
   * @aliases ms-cb-push-followup-bulk
   */
  public function chargebeePushFollowupBulk(
    array $options = [
      'status' => NULL,
      'only-status' => NULL,
      'uids' => NULL,
      'limit' => 200,
      'offset' => 0,
      'force' => FALSE,
      'dry-run' => FALSE,
    ],
  ): void {
    $sync = $this->chargebeeFollowupStatusSync ?: \Drupal::service('makerspace_member_success.chargebee_followup_status_sync');
    $database = \Drupal::database();
    $schema = $database->schema();
    $storage = $this->entityTypeManager->getStorage('user');

    $allowed_statuses = [
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT,
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED,
    ];

    $override_status = trim((string) ($options['status'] ?? ''));
    $override_status = $override_status !== '' ? $override_status : NULL;
    if ($override_status !== NULL && !in_array($override_status, $allowed_statuses, TRUE)) {
      $this->logger()->error(dt('Invalid --status value "@value".', ['@value' => $override_status]));
      return;
    }

    $only_status = trim((string) ($options['only-status'] ?? ''));
    $only_status = $only_status !== '' ? $only_status : NULL;
    if ($only_status !== NULL && !in_array($only_status, $allowed_statuses, TRUE)) {
      $this->logger()->error(dt('Invalid --only-status value "@value".', ['@value' => $only_status]));
      return;
    }

    $limit = max(1, (int) ($options['limit'] ?? 200));
    $offset = max(0, (int) ($options['offset'] ?? 0));
    $force = (bool) ($options['force'] ?? FALSE);
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);

    $uids_option = trim((string) ($options['uids'] ?? ''));
    $uids = [];
    if ($uids_option !== '') {
      foreach (explode(',', $uids_option) as $part) {
        $uid = (int) trim($part);
        if ($uid > 0) {
          $uids[] = $uid;
        }
      }
      $uids = array_values(array_unique($uids));
      if (empty($uids)) {
        $this->logger()->warning('No valid UIDs parsed from --uids option.');
        return;
      }
      $uids = array_slice($uids, $offset, $limit);
    }
    else {
      if (!$schema->tableExists('user__field_user_chargebee_id')) {
        $this->logger()->error('Missing required table user__field_user_chargebee_id.');
        return;
      }

      $query = $database->select('user__field_user_chargebee_id', 'cb');
      $query->fields('cb', ['entity_id']);
      $query->condition('cb.field_user_chargebee_id_value', '', '<>');

      if ($only_status !== NULL) {
        if (!$schema->tableExists('user__field_member_followup_status')) {
          $this->logger()->error('Cannot use --only-status: canonical followup field table is missing.');
          return;
        }
        $query->innerJoin('user__field_member_followup_status', 'mf', 'mf.entity_id = cb.entity_id');
        $query->condition('mf.field_member_followup_status_value', $only_status);
      }
      elseif ($override_status === NULL) {
        $or = $query->orConditionGroup();
        $has_status_source = FALSE;
        if ($schema->tableExists('user__field_member_followup_status')) {
          $query->leftJoin('user__field_member_followup_status', 'mf', 'mf.entity_id = cb.entity_id');
          $or->condition('mf.field_member_followup_status_value', '', '<>');
          $has_status_source = TRUE;
        }
        if ($schema->tableExists('user__field_chargebee_followup')) {
          $query->leftJoin('user__field_chargebee_followup', 'lf', 'lf.entity_id = cb.entity_id');
          $or->condition('lf.field_chargebee_followup_value', '', '<>');
          $has_status_source = TRUE;
        }
        if ($has_status_source) {
          $query->condition($or);
        }
      }

      $query->orderBy('cb.entity_id', 'ASC');
      $query->range($offset, $limit);
      $uids = array_map('intval', $query->execute()->fetchCol());
    }

    if (empty($uids)) {
      $this->logger()->notice('No users matched the selection criteria.');
      return;
    }

    $summary = [
      'users_targeted' => count($uids),
      'users_processed' => 0,
      'subscriptions_examined' => 0,
      'subscriptions_updated' => 0,
      'subscriptions_skipped_same' => 0,
    ];
    $skip_reasons = [];

    foreach (array_chunk($uids, 100) as $chunk) {
      $users = $storage->loadMultiple($chunk);
      foreach ($chunk as $uid) {
        if (empty($users[$uid])) {
          $skip_reasons['user_not_found'] = ($skip_reasons['user_not_found'] ?? 0) + 1;
          continue;
        }

        $user = $users[$uid];
        $status = $override_status;
        if ($status === NULL) {
          if ($user->hasField('field_member_followup_status')) {
            $status = trim((string) $user->get('field_member_followup_status')->value);
          }
          if (($status === NULL || $status === '') && $user->hasField('field_chargebee_followup')) {
            $status = trim((string) $user->get('field_chargebee_followup')->value);
          }
          $status = $status !== '' ? $status : NULL;
        }

        $result = $sync->pushUserStatus($user, $status, $force, $dry_run);
        $summary['users_processed']++;
        $summary['subscriptions_examined'] += (int) ($result['subscriptions_examined'] ?? 0);
        $summary['subscriptions_updated'] += (int) ($result['subscriptions_updated'] ?? 0);
        $summary['subscriptions_skipped_same'] += (int) ($result['subscriptions_skipped_same'] ?? 0);

        $reason = (string) ($result['skipped_reason'] ?? '');
        if ($reason !== '') {
          $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
        }
      }
    }

    $rows = [
      ['Users targeted', (string) $summary['users_targeted']],
      ['Users processed', (string) $summary['users_processed']],
      ['Subscriptions examined', (string) $summary['subscriptions_examined']],
      ['Subscriptions updated', (string) $summary['subscriptions_updated']],
      ['Subscriptions skipped (same)', (string) $summary['subscriptions_skipped_same']],
      ['Dry run', $dry_run ? 'yes' : 'no'],
      ['Forced', $force ? 'yes' : 'no'],
    ];
    $this->io()->table(['Metric', 'Value'], $rows);

    if (!empty($skip_reasons)) {
      ksort($skip_reasons);
      $reason_rows = [];
      foreach ($skip_reasons as $reason => $count) {
        $reason_rows[] = [$reason, (string) $count];
      }
      $this->io()->table(['Skipped reason', 'Count'], $reason_rows);
    }
  }

  /**
   * Back-attributes existing auto-system payment_updated rows to recent
   * human outreach.
   *
   * The daily snapshot writes a "system / payment_updated / NULL staff" row
   * each time a member quietly exits the recovery stage. Historically these
   * stole credit from the staff member whose phone call / email actually drove
   * the payment update. Going forward, recordAutomaticRecoverySuccess() now
   * inherits the most recent human outreach within 30 days. This command
   * applies the same look-back to existing system rows so the Intervention
   * Performance dashboard reflects real attribution.
   *
   * @option lookback-days
   *   How many days back from each system row to search for the human row.
   *   Default 30 — matches the runtime behavior.
   * @option dry-run
   *   Report what would change without writing.
   *
   * @command ms:backfill-system-attribution
   * @aliases ms-backfill-attr
   */
  public function backfillSystemAttribution(array $options = ['lookback-days' => 30, 'dry-run' => FALSE]): void {
    $database = \Drupal::database();
    $lookback = max(1, (int) $options['lookback-days']);
    $dry_run = (bool) $options['dry-run'];

    $system_rows = $database->select('ms_member_outreach_log', 'log')
      ->fields('log', ['id', 'uid', 'contact_date'])
      ->condition('contact_method', 'system')
      ->condition('outcome', MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED)
      ->isNull('staff_uid')
      ->orderBy('contact_date', 'ASC')
      ->execute()
      ->fetchAll();

    $total = count($system_rows);
    $attributed = 0;
    $left_alone = 0;

    foreach ($system_rows as $row) {
      $lookback_date = date('Y-m-d', strtotime($row->contact_date . ' -' . $lookback . ' days'));

      $human = $database->select('ms_member_outreach_log', 'log')
        ->fields('log', ['contact_method', 'staff_uid'])
        ->condition('uid', $row->uid)
        ->isNotNull('staff_uid')
        ->condition('contact_date', $lookback_date, '>=')
        ->condition('contact_date', $row->contact_date, '<=')
        ->orderBy('contact_date', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if (!$human || empty($human['staff_uid'])) {
        $left_alone++;
        continue;
      }

      if (!$dry_run) {
        $database->update('ms_member_outreach_log')
          ->fields([
            'contact_method' => (string) $human['contact_method'],
            'staff_uid' => (int) $human['staff_uid'],
            'notes' => 'Auto-confirmed (backfilled): member exited recovery after payment resolved. Credit to most recent human outreach within ' . $lookback . ' days.',
          ])
          ->condition('id', $row->id)
          ->execute();
      }
      $attributed++;
    }

    $this->io()->table(['Metric', 'Count'], [
      ['System rows scanned', (string) $total],
      ['Re-attributed to staff', (string) $attributed],
      ['No human outreach in window — left as system', (string) $left_alone],
    ]);

    if ($dry_run) {
      $this->logger()->info('Dry run complete. No data written.');
    }
    else {
      $this->logger()->success('Backfill complete.');
    }
  }

  /**
   * Backfills confirmed-cancel log rows for past recovery departures.
   *
   * Until 2026-08, a member whose subscription cancelled while in payment
   * recovery simply stopped being snapshotted: no confirmed_cancel row was
   * ever written, so the Intervention Performance page showed 0 confirmed
   * cancellations for everyone. The daily build now sweeps these departures
   * as they happen (recordDepartedRecoveryCancellations); this command
   * repairs the historical gap by finding members whose LAST daily snapshot
   * was in the recovery stage, who are no longer active members, and who
   * have no confirmed-cancel row near that date — then writing the same
   * back-attributed auto row the sweep would have written.
   *
   * @option since
   *   Only consider members whose last snapshot is on/after this date.
   *   Default 2026-04-20 — the last manually-logged cancellation.
   * @option dry-run
   *   Report what would be written without writing.
   *
   * @command ms:backfill-cancellations
   * @aliases ms-backfill-cancel
   */
  public function backfillCancellations(array $options = ['since' => '2026-04-20', 'dry-run' => FALSE]): void {
    $database = \Drupal::database();
    $since = (string) $options['since'];
    $dry_run = (bool) $options['dry-run'];

    // Members whose last-ever daily snapshot was recovery-stage.
    $candidates = $database->query("
      SELECT s.uid, s.snapshot_date
      FROM {ms_member_success_snapshot} s
      INNER JOIN (
        SELECT uid, MAX(snapshot_date) AS last_date
        FROM {ms_member_success_snapshot}
        WHERE snapshot_type = 'daily'
        GROUP BY uid
      ) latest ON latest.uid = s.uid AND latest.last_date = s.snapshot_date
      WHERE s.snapshot_type = 'daily'
        AND s.stage = :recovery
        AND s.snapshot_date >= :since
    ", [':recovery' => MemberSuccessLifecycle::STAGE_RECOVERY, ':since' => $since])->fetchAllKeyed();

    // Drop anyone who is still an active member (their case is still open).
    $active = [];
    if (!empty($candidates)) {
      $active = $database->select('users_field_data', 'u')
        ->fields('u', ['uid'])
        ->condition('u.uid', array_keys($candidates), 'IN')
        ->condition('u.status', 1)
        ->execute()
        ->fetchCol();
      $member_roles = $database->select('user__roles', 'r')
        ->fields('r', ['entity_id'])
        ->condition('r.entity_id', array_keys($candidates), 'IN')
        ->condition('r.roles_target_id', ['member', 'member_pending_approval'], 'IN')
        ->execute()
        ->fetchCol();
      $active = array_intersect($active, $member_roles);
    }

    $rows = [];
    $written = 0;
    $skipped_active = 0;
    foreach ($candidates as $uid => $last_date) {
      if (in_array($uid, $active)) {
        $skipped_active++;
        continue;
      }

      $name = $database->select('users_field_data', 'u')
        ->fields('u', ['name'])
        ->condition('u.uid', $uid)
        ->execute()
        ->fetchField();

      if ($dry_run) {
        // Report whether the runtime guard (existing cancel row within 90
        // days) would skip this member.
        $guard_date = date('Y-m-d', strtotime($last_date . ' -90 days'));
        $has_existing = (bool) $database->select('ms_member_outreach_log', 'log')
          ->fields('log', ['id'])
          ->condition('uid', $uid)
          ->condition('outcome', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL)
          ->condition('contact_date', $guard_date, '>=')
          ->range(0, 1)
          ->execute()
          ->fetchField();
        $rows[] = [
          (string) $uid,
          (string) $name,
          (string) $last_date,
          $has_existing ? 'skip (already logged)' : 'would write',
        ];
        continue;
      }

      $this->snapshotBuilder->recordAutomaticCancellation((int) $uid, (string) $last_date, time());
      $rows[] = [(string) $uid, (string) $name, (string) $last_date, 'processed'];
      $written++;
    }

    if (!empty($rows)) {
      $this->io()->table(['UID', 'Member', 'Last recovery snapshot', 'Action'], $rows);
    }
    $this->io()->table(['Metric', 'Count'], [
      ['Departed-in-recovery candidates since ' . $since, (string) count($candidates)],
      ['Skipped — still an active member', (string) $skipped_active],
      [$dry_run ? 'Would process' : 'Processed', (string) ($dry_run ? count($candidates) - $skipped_active : $written)],
    ]);

    if ($dry_run) {
      $this->logger()->info('Dry run complete. No data written.');
    }
    else {
      $this->logger()->success('Cancellation backfill complete.');
    }
  }

}
