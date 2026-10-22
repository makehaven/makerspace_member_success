<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\makerspace_member_success\Service\PauseScheduleSync;
use Drupal\Tests\UnitTestCase;

/**
 * Tests mapping Chargebee paused subscriptions to resume dates.
 *
 * @group makerspace_member_success
 */
class PauseScheduleSyncTest extends UnitTestCase {

  /**
   * Maps rows, counts the ones it cannot use, keeps the later of two dates.
   */
  public function testDatesFromRows(): void {
    $resume_a = mktime(12, 0, 0, 11, 3, 2026);
    $resume_b = mktime(12, 0, 0, 12, 1, 2026);
    $rows = [
      ['subscription' => ['status' => 'paused', 'customer_id' => 'cb_1', 'resume_date' => $resume_a]],
      ['subscription' => ['status' => 'paused', 'customer_id' => 'cb_1', 'resume_date' => $resume_b]],
      ['subscription' => ['status' => 'paused', 'customer_id' => 'cb_2']],
      ['subscription' => ['status' => 'paused', 'customer_id' => 'cb_unknown', 'resume_date' => $resume_a]],
      ['subscription' => ['status' => 'active', 'customer_id' => 'cb_3', 'resume_date' => $resume_a]],
    ];
    $uids = ['cb_1' => 10, 'cb_2' => 20];
    $stats = ['paused' => 0, 'matched' => 0, 'no_resume_date' => 0, 'unmatched' => 0];

    $dates = PauseScheduleSync::datesFromRows($rows, fn(string $id) => $uids[$id] ?? NULL, $stats);

    $this->assertSame([10 => date('Y-m-d', $resume_b)], $dates);
    $this->assertSame(['paused' => 4, 'matched' => 2, 'no_resume_date' => 1, 'unmatched' => 1], $stats);
  }

  /**
   * Reads a stored date and rejects anything that is not Y-m-d.
   */
  public function testResumeDateFor(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with(PauseScheduleSync::STATE_KEY)->willReturn([
      'dates' => [10 => '2026-11-03', 11 => 'garbage'],
    ]);
    $this->assertSame('2026-11-03', PauseScheduleSync::resumeDateFor($state, 10));
    $this->assertNull(PauseScheduleSync::resumeDateFor($state, 11));
    $this->assertNull(PauseScheduleSync::resumeDateFor($state, 12));
    $this->assertNull(PauseScheduleSync::resumeDateFor(NULL, 10));
  }

}
