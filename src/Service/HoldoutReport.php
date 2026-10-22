<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\OutreachHoldout;

/**
 * Compares members offered an automated offer with the silent holdout.
 *
 * Intention to treat: the offer arm is every member assigned to it, whether
 * staff approved the send or not, because the holdout arm is assigned before
 * any staff choice. Comparing held-out members with only the members who were
 * actually emailed would credit the offer with staff's judgement about who
 * was worth emailing.
 *
 * Only rows at least holdout_window_days old count, so a member offered
 * yesterday is not scored as "did not come back".
 */
class HoldoutReport {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Per-campaign, per-arm outcome counts.
   *
   * @return array<string, array<string, array<string, int>>>
   *   stage => arm => [assigned, contacted, matured, came_in, member_now].
   *   came_in = a door visit after the assignment; member_now = holds the
   *   member role today. Both are counted over matured rows only.
   */
  public function build(): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $window = max(0, (int) ($config->get('holdout_window_days') ?? 60));
    $matured_before = $this->time->getCurrentTime() - $window * 86400;

    $query = $this->database->select('ms_member_outreach_queue', 'q');
    $query->fields('q', ['uid', 'stage', 'holdout_arm', 'status', 'created_at']);
    $query->isNotNull('q.holdout_arm');
    $query->leftJoin('ms_member_success_snapshot', 'ms', "ms.uid = q.uid AND ms.is_latest = 1 AND ms.snapshot_type = 'daily'");
    $query->addField('ms', 'last_visit_ts', 'last_visit_ts');
    $query->leftJoin('user__roles', 'r', "r.entity_id = q.uid AND r.roles_target_id = 'member'");
    // One row at most per join: one latest daily snapshot per member, one
    // 'member' role row.
    $query->addField('r', 'entity_id', 'member_role_uid');

    $report = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $stage = (string) $row->stage;
      $arm = (string) $row->holdout_arm;
      $report[$stage][$arm] ??= ['assigned' => 0, 'contacted' => 0, 'matured' => 0, 'came_in' => 0, 'member_now' => 0];
      $cell = &$report[$stage][$arm];
      $cell['assigned']++;
      if ($row->status === 'sent') {
        $cell['contacted']++;
      }
      if ((int) $row->created_at <= $matured_before) {
        $cell['matured']++;
        if ((int) ($row->last_visit_ts ?? 0) > (int) $row->created_at) {
          $cell['came_in']++;
        }
        if (!empty($row->member_role_uid)) {
          $cell['member_now']++;
        }
      }
      unset($cell);
    }
    foreach ($report as $stage => $arms) {
      foreach ([OutreachHoldout::ARM_OFFER, OutreachHoldout::ARM_HOLDOUT] as $arm) {
        $report[$stage][$arm] ??= ['assigned' => 0, 'contacted' => 0, 'matured' => 0, 'came_in' => 0, 'member_now' => 0];
      }
    }
    ksort($report);
    return $report;
  }

}
