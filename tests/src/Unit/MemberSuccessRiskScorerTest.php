<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessRiskScorer;
use Drupal\Tests\UnitTestCase;

/**
 * Tests risk score calculation for the paused lifecycle stage.
 *
 * @group makerspace_member_success
 */
class MemberSuccessRiskScorerTest extends UnitTestCase {

  /**
   * Returns base data for a paused member with no risk factors.
   */
  private function pausedBase(string $pause_start_date, int $now_ts, string $tenure_bucket = 'sustaining'): array {
    return [
      'stage' => MemberSuccessLifecycle::STAGE_PAUSED,
      'payment_failed' => FALSE,
      'payment_pause' => TRUE,
      'door_badge_status' => 'active',
      'serial_present' => TRUE,
      'activation_ts' => NULL,
      'badge_count_total' => 5,
      'badge_count_window' => 2,
      'last_visit_ts' => NULL,
      'tenure_bucket' => $tenure_bucket,
      'join_date' => NULL,
      'pause_start_date' => $pause_start_date,
    ];
  }

  /**
   * Tests that a pause at day 30 produces score 0 with no pause_ending reason.
   */
  public function testPauseDay30NoRisk(): void {
    $pause_start = mktime(0, 0, 0, 1, 1, 2025);
    $now_ts = $pause_start + (30 * 86400);
    $data = $this->pausedBase(date('Y-m-d', $pause_start), $now_ts);

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);

    $this->assertSame(0, $score);
    $this->assertNotContains('pause_ending', $reasons);
  }

  /**
   * Tests that a pause at day 65 scores 40 with pause_ending reason (new member).
   */
  public function testPauseDay65HighRisk(): void {
    $pause_start = mktime(0, 0, 0, 1, 1, 2025);
    $now_ts = $pause_start + (65 * 86400);
    $data = $this->pausedBase(date('Y-m-d', $pause_start), $now_ts, 'new_member');

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);

    $this->assertSame(40, $score);
    $this->assertContains('pause_ending', $reasons);
  }

  /**
   * Tests that a sustaining member at day 65 gets reduced penalty of 30.
   */
  public function testPauseDay65SustainingReducedPenalty(): void {
    $pause_start = mktime(0, 0, 0, 1, 1, 2025);
    $now_ts = $pause_start + (65 * 86400);
    $data = $this->pausedBase(date('Y-m-d', $pause_start), $now_ts, 'sustaining');

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);

    $this->assertSame(30, $score);
    $this->assertContains('pause_ending', $reasons);
  }

  /**
   * Tests that payment_failed overrides pause and adds its own penalty.
   */
  public function testPaymentFailedOverridesPause(): void {
    $pause_start = mktime(0, 0, 0, 1, 1, 2025);
    $now_ts = $pause_start + (30 * 86400);
    $data = $this->pausedBase(date('Y-m-d', $pause_start), $now_ts);
    // Simulate a member flagged both paused and payment_failed (recovery wins
    // in the snapshot builder, but the scorer still responds to the flag).
    $data['payment_failed'] = TRUE;

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);

    $this->assertGreaterThanOrEqual(50, $score);
    $this->assertContains('payment_failed', $reasons);
  }

}
