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
    // Keep this as explicit expressions to avoid mutating root where-group
    // operators (which can unintentionally turn all queue filters into OR).
    $query->addWhereExpression(
      0,
      "({$alias}.next_followup_date IS NULL OR {$alias}.next_followup_date <= :ms_today)",
      [':ms_today' => $today]
    );

    $resolved_statuses = MemberSuccessLifecycle::resolvedFollowupStatuses();
    if (empty($resolved_statuses)) {
      return;
    }

    $outreach_args = [];
    $outreach_placeholders = [];
    foreach ($resolved_statuses as $index => $status) {
      $placeholder = ":ms_outreach_resolved_{$index}";
      $outreach_placeholders[] = $placeholder;
      $outreach_args[$placeholder] = $status;
    }
    $query->addWhereExpression(
      0,
      "({$alias}.outreach_status IS NULL OR {$alias}.outreach_status NOT IN (" . implode(', ', $outreach_placeholders) . '))',
      $outreach_args
    );

    $followup_args = [];
    $followup_placeholders = [];
    foreach ($resolved_statuses as $index => $status) {
      $placeholder = ":ms_followup_resolved_{$index}";
      $followup_placeholders[] = $placeholder;
      $followup_args[$placeholder] = $status;
    }
    $query->addWhereExpression(
      0,
      "({$alias}.member_followup_status IS NULL OR {$alias}.member_followup_status NOT IN (" . implode(', ', $followup_placeholders) . '))',
      $followup_args
    );
  }

}
