<?php

namespace Drupal\makerspace_member_success\Commands;

use Drush\Commands\DrushCommands;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;
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
   * Constructs a MemberSuccessCommands object.
   *
   * @param \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder $snapshot_builder
   *   The snapshot builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\makerspace_member_success\Service\RecoveryMetrics $recovery_metrics
   *   The recovery metrics service (optional for backwards compatibility).
   */
  public function __construct(MemberSuccessSnapshotBuilder $snapshot_builder, EntityTypeManagerInterface $entity_type_manager, RecoveryMetrics $recovery_metrics = NULL) {
    parent::__construct();
    $this->snapshotBuilder = $snapshot_builder;
    $this->entityTypeManager = $entity_type_manager;
    $this->recoveryMetrics = $recovery_metrics;
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

    // Get recovery metrics service
    if (!$this->recoveryMetrics) {
      $this->recoveryMetrics = \Drupal::service('makerspace_member_success.recovery_metrics');
    }

    // Get metrics
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

    // ROI Summary
    $this->output()->writeln('<comment>ROI SUMMARY:</comment>');
    $this->output()->writeln('  Members at Risk: ' . $roi['total_members_at_risk']);
    $this->output()->writeln('  Annual Value Saved: $' . number_format($roi['annual_value_saved'], 0));
    $this->output()->writeln('  Members Resolved: ' . $roi['members_resolved']);
    $this->output()->writeln('  Resolution Rate: ' . $all_metrics['resolution_rate']['rate'] . '%');
    $this->output()->writeln('  Avg Days to Resolution: ' . round($all_metrics['avg_days_to_resolution'], 1));
    $this->output()->writeln('');

    // Staff Performance
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

    // Channel Effectiveness
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

}
