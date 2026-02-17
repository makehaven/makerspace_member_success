<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Applies shared member-success queue query rules to Views SQL queries.
 */
class MemberSuccessQueueQueryApplier {

  /**
   * Current request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a MemberSuccessQueueQueryApplier object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * Applies default risk ordering unless user explicitly requested sorting.
   *
   * @param \Drupal\views\Plugin\views\query\Sql $query
   *   The views SQL query.
   * @param string $alias
   *   Table alias for ms_member_success_snapshot.
   */
  public function applyDefaultOrdering(Sql $query, string $alias): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    if (!$request->query->has('order') && !$request->query->has('sort')) {
      $query->orderby = [];
      $query->addOrderBy($alias, 'risk_score', 'DESC');
    }
  }

  /**
   * Applies queue visibility filters for snooze dates and resolved statuses.
   *
   * @param \Drupal\views\Plugin\views\query\Sql $query
   *   The views SQL query.
   * @param string $alias
   *   Table alias for ms_member_success_snapshot.
   * @param string $today
   *   Current date in Y-m-d format.
   */
  public function applyVisibilityFilters(Sql $query, string $alias, string $today): void {
    $group = $query->setWhereGroup('AND');

    $followup_group = $query->setWhereGroup('OR', $group);
    $query->addWhere($followup_group, "{$alias}.next_followup_date", NULL, 'IS NULL');
    $query->addWhere($followup_group, "{$alias}.next_followup_date", $today, '<=');

    $resolved_statuses = MemberSuccessLifecycle::resolvedFollowupStatuses();

    $outreach_status_group = $query->setWhereGroup('OR', $group);
    $query->addWhere($outreach_status_group, "{$alias}.outreach_status", NULL, 'IS NULL');
    $query->addWhere($outreach_status_group, "{$alias}.outreach_status", $resolved_statuses, 'NOT IN');

    $followup_status_group = $query->setWhereGroup('OR', $group);
    $query->addWhere($followup_status_group, "{$alias}.member_followup_status", NULL, 'IS NULL');
    $query->addWhere($followup_status_group, "{$alias}.member_followup_status", $resolved_statuses, 'NOT IN');
  }

}
