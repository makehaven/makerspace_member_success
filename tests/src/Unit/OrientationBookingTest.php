<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Whether the onboarding queue sees a booked key pickup.
 *
 * It never did before 2026-11-16: it asked CiviCRM for a type and status the
 * booking system does not write, so booked members were listed as stuck.
 *
 * @group makerspace_member_success
 */
final class OrientationBookingTest extends UnitTestCase {

  private const TYPES = ['Orientation', 'Attended Orientation'];
  private const TODAY = '2026-11-16';

  private function pick(array $activities): ?string {
    return MemberSuccessSnapshotBuilder::earliestOrientationBooking($activities, self::TYPES, self::TODAY);
  }

  public function testACalendlyBookingCountsThoughItIsMarkedCompleted(): void {
    $this->assertSame('2026-11-23', $this->pick([
      ['activity_date_time' => '2026-11-23 12:00:00', 'type' => 'Orientation', 'status' => 'Completed'],
    ]));
  }

  public function testAScheduledBookingCounts(): void {
    $this->assertSame('2026-12-04', $this->pick([
      ['activity_date_time' => '2026-12-04 09:30:00', 'type' => 'Attended Orientation', 'status' => 'Scheduled'],
    ]));
  }

  public function testACancelledBookingDoesNot(): void {
    $this->assertNull($this->pick([
      ['activity_date_time' => '2026-11-18 12:00:00', 'type' => 'Orientation', 'status' => 'Cancelled'],
    ]));
  }

  public function testCancelledThenRebookedUsesTheNewDate(): void {
    $this->assertSame('2026-11-23', $this->pick([
      ['activity_date_time' => '2026-11-16 12:00:00', 'type' => 'Orientation', 'status' => 'Cancelled'],
      ['activity_date_time' => '2026-11-23 12:00:00', 'type' => 'Orientation', 'status' => 'Completed'],
    ]));
  }

  public function testABookingTodayCountsAndYesterdaysDoesNot(): void {
    $this->assertSame('2026-11-16', $this->pick([
      ['activity_date_time' => '2026-11-16 19:00:00', 'type' => 'Orientation', 'status' => 'Completed'],
    ]));
    $this->assertNull($this->pick([
      ['activity_date_time' => '2026-11-15 19:00:00', 'type' => 'Orientation', 'status' => 'Completed'],
    ]));
  }

  public function testOtherActivitiesAreIgnored(): void {
    $this->assertNull($this->pick([
      ['activity_date_time' => '2026-12-01 18:00:00', 'type' => 'Event Registration', 'status' => 'Completed'],
      ['activity_date_time' => '2026-11-20 10:00:00', 'type' => 'Tour', 'status' => 'Scheduled'],
    ]));
  }

  public function testTheEarliestOfSeveralBookingsWins(): void {
    $this->assertSame('2026-11-20', $this->pick([
      ['activity_date_time' => '2026-12-04 09:30:00', 'type' => 'Orientation', 'status' => 'Completed'],
      ['activity_date_time' => '2026-11-20 12:00:00', 'type' => 'orientation', 'status' => 'Completed'],
    ]));
  }

}
