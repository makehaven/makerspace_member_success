<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessRiskScorer;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MemberSuccessRiskScorer (extracted from MemberSuccessSnapshotBuilder).
 *
 * @group makerspace_member_success
 */
class MemberSuccessSnapshotBuilderTest extends UnitTestCase {

  /**
   * Tests risk score calculation for payment issues.
   */
  public function testRiskScorePaymentIssue() {
    $data = [
      'payment_failed' => 1,
      'stage' => 'recovery',
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], time());

    $this->assertGreaterThanOrEqual(50, $score);
    $this->assertNotEmpty(
      array_filter($reasons, static fn($r) => str_starts_with($r, 'payment_failed')),
      'A payment_failed_* reason should be present.'
    );
  }

  /**
   * Tests risk score for inactive members in retention.
   */
  public function testRiskScoreInactiveRetention() {
    $now = time();
    $data = [
      'stage' => 'retention',
      'last_visit_ts' => $now - (40 * 86400),
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(10, $score);
    $this->assertContains('inactive_30', $reasons);
  }

  /**
   * Tests tiered retention inactivity scoring.
   */
  public function testRiskScoreInactiveRetentionTiered() {
    $now = time();
    $data = [
      'stage' => 'retention',
      'last_visit_ts' => $now - (100 * 86400),
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now);

    $this->assertEquals(30, $score);
    $this->assertContains('inactive_90', $reasons);
  }

  /**
   * Tests zero risk for active engaged members.
   */
  public function testNoRiskForEngagedMember() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (10 * 86400),
      'badge_count_window' => 1,
      'badge_count_total' => 1,
      'door_badge_status' => 'active',
      'serial_present' => 1,
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(0, $score);
    $this->assertEmpty($reasons);
  }

  /**
   * Tests risk score for onboarding members missing serial numbers (after grace period).
   */
  public function testRiskScoreOnboardingMissingSerial() {
    $now = time();
    $data = [
      'stage' => 'onboarding',
      'door_badge_status' => 'active',
      'serial_present' => 0,
      'join_date' => date('Y-m-d', $now - (20 * 86400)),
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(10, $score);
    $this->assertContains('missing_serial', $reasons);
  }

  /**
   * Tests risk score for onboarding members stalled at booking orientation.
   */
  public function testRiskScoreOnboardingStuckAtSchedule() {
    // Passed the quiz but stalled 3 days without booking orientation → the
    // sub-step stall replaces the old single `orientation_not_scheduled` flag.
    $now = time();
    $data = [
      'stage' => 'onboarding',
      'door_badge_status' => 'requested',
      'serial_present' => 1,
      'join_date' => date('Y-m-d', $now - (3 * 86400)),
      'orientation_scheduled' => NULL,
      'onboarding_step' => OnboardingFunnel::STEP_SCHEDULE,
      'onboarding_step_ts' => $now - (72 * 3600),
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(20, $score);
    $this->assertContains('stuck_at_schedule', $reasons);
  }

  /**
   * Tests engagement risk: no badges in first 28 days.
   */
  public function testRiskScoreEngagementNoBadge1() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (30 * 86400),
      'badge_count_window' => 0,
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(20, $score);
    $this->assertContains('no_badge_1', $reasons);
  }

  /**
   * Tests engagement risk: fewer than 4 badges in 180 days.
   */
  public function testRiskScoreEngagementNoBadge4() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (190 * 86400),
      'badge_count_window' => 1,
      'badge_count_total' => 3,
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(20, $score);
    $this->assertContains('no_badge_4', $reasons);
  }

  /**
   * Tests onboarding members within grace period have zero risk.
   */
  public function testNoRiskDuringOnboardingGracePeriod() {
    // Grace period now means: orientation is on the calendar (member is on
    // track) AND we're still inside the 14-day serial pickup window.
    // Without orientation_scheduled, members are flagged from day 1 as a
    // pipeline-break signal — that's the new policy.
    $now = time();
    $data = [
      'stage' => 'onboarding',
      'door_badge_status' => 'requested',
      'serial_present' => 0,
      'join_date' => date('Y-m-d', $now - (10 * 86400)),
      'orientation_scheduled' => date('Y-m-d', $now + (5 * 86400)),
    ];

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30], $now);

    $this->assertEquals(0, $score);
    $this->assertContains('orientation_scheduled_upcoming', $reasons);
    $this->assertNotContains('orientation_not_scheduled', $reasons);
    $this->assertNotContains('missing_serial', $reasons);
  }

}
