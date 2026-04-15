<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\MemberSuccessBuckets;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;

/**
 * Counts members per risk bucket within a lifecycle stage.
 *
 * Uses the same snooze / resolved-status visibility rules as the dashboard
 * card query and the Views visibility applier, so the tab counts on a stage
 * page always match the "Actionable" number on the dashboard card.
 */
class MemberSuccessBucketCounter {

  public function __construct(protected Connection $database) {}

  /**
   * Returns an int count keyed by bucket name for a given stage.
   *
   * Only members with a daily, latest snapshot for the stage, and only rows
   * that pass the visibility filter (not snoozed, not in a resolved outreach
   * status) are included in any bucket.
   *
   * @return array<string,int>
   *   Keys: actionable, watch, safe, all.
   */
  public function countsForStage(string $stage): array {
    $today = MemberSuccessQueueRules::todayYmd();
    $resolved = MemberSuccessLifecycle::resolvedFollowupStatuses();

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('s.snapshot_type', 'daily');
    $query->condition('s.is_latest', 1);
    $query->condition('s.stage', $stage);

    $followup_date = $query->orConditionGroup()
      ->isNull('s.next_followup_date')
      ->condition('s.next_followup_date', $today, '<=');
    $query->condition($followup_date);

    if (!empty($resolved)) {
      $outreach = $query->orConditionGroup()
        ->isNull('s.outreach_status')
        ->condition('s.outreach_status', $resolved, 'NOT IN');
      $query->condition($outreach);

      $member_followup = $query->orConditionGroup()
        ->isNull('s.member_followup_status')
        ->condition('s.member_followup_status', $resolved, 'NOT IN');
      $query->condition($member_followup);
    }

    $query->addExpression('COUNT(*)', 'total_all');
    $query->addExpression('SUM(CASE WHEN s.risk_score >= 20 THEN 1 ELSE 0 END)', 'total_actionable');
    $query->addExpression('SUM(CASE WHEN s.risk_score BETWEEN 1 AND 19 THEN 1 ELSE 0 END)', 'total_watch');
    $query->addExpression('SUM(CASE WHEN s.risk_score = 0 THEN 1 ELSE 0 END)', 'total_safe');

    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      MemberSuccessBuckets::ACTIONABLE => (int) ($row['total_actionable'] ?? 0),
      MemberSuccessBuckets::WATCH => (int) ($row['total_watch'] ?? 0),
      MemberSuccessBuckets::SAFE => (int) ($row['total_safe'] ?? 0),
      MemberSuccessBuckets::ALL => (int) ($row['total_all'] ?? 0),
    ];
  }

}
