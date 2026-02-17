<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\MemberSuccessQueueQueryApplier;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests query helper for member success queue views.
 *
 * @group makerspace_member_success
 */
class MemberSuccessQueueQueryApplierTest extends UnitTestCase {

  /**
   * Tests default ordering applies when no explicit user sort is present.
   */
  public function testApplyDefaultOrderingWithoutUserSort(): void {
    $request_stack = new RequestStack();
    $request_stack->push(new Request());

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['addOrderBy'])
      ->getMock();

    $query->expects($this->once())
      ->method('addOrderBy')
      ->with('ms', 'risk_score', 'DESC');

    $applier = new MemberSuccessQueueQueryApplier($request_stack);
    $applier->applyDefaultOrdering($query, 'ms');
  }

  /**
   * Tests default ordering is skipped when user provided table sort args.
   */
  public function testApplyDefaultOrderingWithUserSort(): void {
    $request_stack = new RequestStack();
    $request = new Request(['order' => 'risk_score', 'sort' => 'asc']);
    $request_stack->push($request);

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['addOrderBy'])
      ->getMock();

    $query->expects($this->never())
      ->method('addOrderBy');

    $applier = new MemberSuccessQueueQueryApplier($request_stack);
    $applier->applyDefaultOrdering($query, 'ms');
  }

  /**
   * Tests visibility filters add grouped conditions for snooze and statuses.
   */
  public function testApplyVisibilityFilters(): void {
    $request_stack = new RequestStack();
    $request_stack->push(new Request());

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['setWhereGroup', 'addWhere'])
      ->getMock();

    $query->expects($this->exactly(4))
      ->method('setWhereGroup')
      ->willReturnOnConsecutiveCalls(1, 2, 3, 4);

    $query->expects($this->exactly(6))
      ->method('addWhere');

    $applier = new MemberSuccessQueueQueryApplier($request_stack);
    $applier->applyVisibilityFilters($query, 'ms', '2026-02-17');
  }

}
