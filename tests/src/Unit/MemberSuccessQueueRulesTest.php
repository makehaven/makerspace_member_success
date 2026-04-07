<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MemberSuccessQueueRules.
 *
 * @group makerspace_member_success
 */
class MemberSuccessQueueRulesTest extends UnitTestCase {

  /**
   * Tests isFollowupDateVisible for various dates.
   *
   * @dataProvider followupDateProvider
   */
  public function testIsFollowupDateVisible(?string $date, string $today, bool $expected): void {
    $this->assertEquals($expected, MemberSuccessQueueRules::isFollowupDateVisible($date, $today));
  }

  /**
   * Data provider for testIsFollowupDateVisible.
   */
  public static function followupDateProvider(): array {
    return [
      'Empty date is visible' => [NULL, '2026-04-06', TRUE],
      'Past date is visible' => ['2026-04-05', '2026-04-06', TRUE],
      'Today is visible' => ['2026-04-06', '2026-04-06', TRUE],
      'Future date is hidden' => ['2026-04-07', '2026-04-06', FALSE],
    ];
  }

  /**
   * Tests isStatusVisible for various statuses.
   *
   * @dataProvider statusProvider
   */
  public function testIsStatusVisible(?string $status, bool $expected): void {
    $this->assertEquals($expected, MemberSuccessQueueRules::isStatusVisible($status));
  }

  /**
   * Data provider for testIsStatusVisible.
   */
  public static function statusProvider(): array {
    return [
      'Empty status is visible' => [NULL, TRUE],
      'Active status is visible' => [MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, TRUE],
      'Pending status is visible' => [MemberSuccessLifecycle::OUTREACH_STATUS_PENDING, TRUE],
      'Exhausted is hidden' => [MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, FALSE],
      'Cancellation is hidden' => [MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION, FALSE],
      'No action needed is hidden' => [MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, FALSE],
      'Needs review is hidden' => [MemberSuccessLifecycle::FOLLOWUP_NEEDS_REVIEW, FALSE],
    ];
  }

  /**
   * Tests the combined isQueueVisible logic.
   *
   * @dataProvider queueVisibleProvider
   */
  public function testIsQueueVisible(
    ?string $date,
    ?string $outreach_status,
    ?string $member_status,
    string $today,
    bool $expected
  ): void {
    $this->assertEquals(
      $expected,
      MemberSuccessQueueRules::isQueueVisible($date, $outreach_status, $member_status, $today)
    );
  }

  /**
   * Data provider for testIsQueueVisible.
   */
  public static function queueVisibleProvider(): array {
    return [
      'All empty is visible' => [NULL, NULL, NULL, '2026-04-06', TRUE],
      'Future date hides even if status is visible' => ['2026-04-07', NULL, NULL, '2026-04-06', FALSE],
      'Suppressed status hides even if date is past' => ['2026-04-05', MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, NULL, '2026-04-06', FALSE],
      'Member-level status suppression hides' => [NULL, NULL, MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION, '2026-04-06', FALSE],
      'Everything ready is visible' => ['2026-04-01', MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, NULL, '2026-04-06', TRUE],
    ];
  }

}
