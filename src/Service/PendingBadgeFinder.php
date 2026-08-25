<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Finds badges a member has earned on paper but cannot yet use.
 *
 * A badge whose term requires an in-person checkout is created `pending` when
 * the quiz is passed, and only becomes `active` when a facilitator approves it.
 * Nothing measured whether that second half ever happened until this existed:
 * when the flow was first walked (2026-08-24) there were 1,971 member+badge
 * pairs waiting across 544 current members — 63% of the membership holding at
 * least one tool they qualified for and could not use.
 *
 * This is deliberately the ONLY place those rules live. The daily snapshot
 * counts them and the nudge lists them, and if the two ever disagreed a member
 * would be emailed about a badge the reports say they do not have. Both call
 * here.
 *
 * The guards, and why each is needed:
 *  - **Grouped by badge.** 12 member+badge pairs carry more than one pending
 *    row (one carries five), left over from before
 *    `QuizResultHook::loadExistingBadgeRequest()` started blocking a retake
 *    from creating a second request. New duplicates can no longer be made, so
 *    grouping is sufficient and no cleanup is required first.
 *  - **Skip badges already held active.** A stale pending row must never make
 *    a member look like they are waiting for something they can already use.
 *  - **Checkout-required badges only.** Badges marked `class`, `no`, or unset
 *    are docs-gated or misconfigured; telling their holders to "book a
 *    checkout" would be wrong.
 *  - **Badges with at least one issuer.** A badge nobody is able to grant is
 *    not something to chase a member about.
 *  - **The door badge is excluded.** That is orientation, a different flow with
 *    its own funnel and its own nudge.
 *
 * Membership is read from `field_member_to_badge`, not `node.uid`: 39 pending
 * rows are authored by someone other than the member they belong to, and one
 * is owned by anonymous.
 */
class PendingBadgeFinder {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Badges this member is waiting on a facilitator for.
   *
   * @param int $uid
   *   The member's user ID.
   *
   * @return array<int, array{tid:int, name:string, requested_ts:int}>
   *   One entry per badge, oldest request first.
   */
  public function listFor(int $uid): array {
    $door_badge_tid = (int) ($this->configFactory
      ->get('makerspace_member_success.settings')
      ->get('door_badge_tid') ?? 1519);

    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->innerJoin('node__field_member_to_badge', 'member', 'member.entity_id = n.nid AND member.deleted = 0');
    $query->innerJoin('node__field_badge_requested', 'badge', 'badge.entity_id = n.nid AND badge.deleted = 0');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->innerJoin(
      'taxonomy_term__field_badge_checkout_requirement',
      'req',
      "req.entity_id = badge.field_badge_requested_target_id AND req.deleted = 0 AND req.field_badge_checkout_requirement_value = 'yes'"
    );
    $query->innerJoin(
      'taxonomy_term__field_badge_issuer',
      'iss',
      'iss.entity_id = badge.field_badge_requested_target_id AND iss.deleted = 0'
    );
    $query->innerJoin('taxonomy_term_field_data', 'term', 'term.tid = badge.field_badge_requested_target_id');
    $query->condition('member.field_member_to_badge_target_id', $uid);
    $query->condition('badge.field_badge_requested_target_id', $door_badge_tid, '!=');
    $query->condition('status.field_badge_status_value', 'pending');
    $query->condition('badge.field_badge_requested_target_id', $this->badgesAlreadyHeld($uid), 'NOT IN');

    $query->addField('badge', 'field_badge_requested_target_id', 'tid');
    $query->addField('term', 'name', 'name');
    $query->addExpression('MIN(n.created)', 'requested_ts');
    $query->groupBy('badge.field_badge_requested_target_id');
    $query->groupBy('term.name');
    $query->orderBy('requested_ts', 'ASC');

    $out = [];
    foreach ($query->execute() as $row) {
      $out[] = [
        'tid' => (int) $row->tid,
        'name' => (string) $row->name,
        'requested_ts' => (int) $row->requested_ts,
      ];
    }
    return $out;
  }

  /**
   * Count and oldest-request timestamp, for the daily snapshot.
   *
   * @return array{count:int, oldest_ts:int|null}
   *   Distinct badges waiting, and when the oldest was requested.
   */
  public function statsFor(int $uid): array {
    $badges = $this->listFor($uid);
    return [
      'count' => count($badges),
      'oldest_ts' => $badges ? $badges[0]['requested_ts'] : NULL,
    ];
  }

  /**
   * Sub-query of badge terms this member already holds active.
   */
  protected function badgesAlreadyHeld(int $uid) {
    $held = $this->database->select('node_field_data', 'n2');
    $held->addField('badge2', 'field_badge_requested_target_id');
    $held->condition('n2.type', 'badge_request');
    $held->condition('n2.status', 1);
    $held->innerJoin('node__field_member_to_badge', 'member2', 'member2.entity_id = n2.nid AND member2.deleted = 0');
    $held->innerJoin('node__field_badge_requested', 'badge2', 'badge2.entity_id = n2.nid AND badge2.deleted = 0');
    $held->innerJoin('node__field_badge_status', 'status2', 'status2.entity_id = n2.nid AND status2.deleted = 0');
    $held->condition('member2.field_member_to_badge_target_id', $uid);
    $held->condition('status2.field_badge_status_value', 'active');
    return $held;
  }

}
