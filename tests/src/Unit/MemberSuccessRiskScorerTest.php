<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessRiskScorer;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
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
    $payment_failed_reason = array_filter($reasons, static fn($r) => str_starts_with($r, 'payment_failed'));
    $this->assertNotEmpty($payment_failed_reason, 'A payment_failed_* reason should be present.');
  }

  /**
   * Returns base data for an onboarding member with active door badge,
   * missing serial, and an adjustable join date.
   */
  private function onboardingMissingSerialBase(int $now_ts, int $days_since_join): array {
    $join_ts = $now_ts - ($days_since_join * 86400);
    return [
      'stage' => MemberSuccessLifecycle::STAGE_ONBOARDING,
      'payment_failed' => FALSE,
      'payment_pause' => FALSE,
      'door_badge_status' => 'active',
      'serial_present' => FALSE,
      'activation_ts' => NULL,
      'badge_count_total' => 0,
      'badge_count_window' => 0,
      'last_visit_ts' => NULL,
      'tenure_bucket' => 'new_member',
      'join_date' => date('Y-m-d', $join_ts),
      'pause_start_date' => NULL,
    ];
  }

  public function testMissingSerialGracePeriodNoScore(): void {
    $now_ts = mktime(0, 0, 0, 6, 1, 2026);
    $data = $this->onboardingMissingSerialBase($now_ts, 7);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(0, $score);
    $this->assertNotContains('missing_serial', $reasons);
  }

  public function testMissingSerialAfterGracePeriodScores10(): void {
    $now_ts = mktime(0, 0, 0, 6, 1, 2026);
    $data = $this->onboardingMissingSerialBase($now_ts, 21);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(10, $score);
    $this->assertContains('missing_serial', $reasons);
  }

  public function testMissingSerialAging60DaysScores15(): void {
    $now_ts = mktime(0, 0, 0, 6, 1, 2026);
    $data = $this->onboardingMissingSerialBase($now_ts, 75);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(15, $score);
    $this->assertContains('missing_serial_aging', $reasons);
  }

  public function testMissingSerialStale180DaysCrossesActionable(): void {
    $now_ts = mktime(0, 0, 0, 6, 1, 2026);
    $data = $this->onboardingMissingSerialBase($now_ts, 200);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(25, $score);
    $this->assertGreaterThanOrEqual(20, $score, 'Stale missing serial should reach the Actionable bucket.');
    $this->assertContains('missing_serial_stale', $reasons);
  }

  /**
   * Base for an onboarding member stalled at a given funnel step.
   *
   * @param int $now_ts
   *   Current time.
   * @param string $step
   *   The onboarding step the member is stuck on (OnboardingFunnel::STEP_*).
   * @param int $hours_on_step
   *   Hours since they completed the previous step.
   * @param int $days_since_join
   *   Total membership age in days (drives the separate serial grace logic).
   */
  private function onboardingStalledBase(int $now_ts, string $step, int $hours_on_step, int $days_since_join): array {
    $join_ts = $now_ts - ($days_since_join * 86400);
    return [
      'stage' => MemberSuccessLifecycle::STAGE_ONBOARDING,
      'payment_failed' => FALSE,
      'payment_pause' => FALSE,
      'door_badge_status' => 'pending',
      'serial_present' => FALSE,
      'activation_ts' => NULL,
      'badge_count_total' => 0,
      'badge_count_window' => 0,
      'last_visit_ts' => NULL,
      'tenure_bucket' => 'new_member',
      'join_date' => date('Y-m-d', $join_ts),
      'pause_start_date' => NULL,
      'orientation_scheduled' => NULL,
      'onboarding_step' => $step,
      'onboarding_step_ts' => $now_ts - ($hours_on_step * 3600),
    ];
  }

  public function testFreshStepUnderStallThresholdNoScore(): void {
    // Saved profile 12 hours ago, now on the video step — within the 48h grace,
    // so not flagged yet.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_VIDEO, 12, 0);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(0, $score);
    $this->assertNotContains('stuck_at_video', $reasons);
  }

  public function testPaidButNoProfileFlaggedAfter48h(): void {
    // The exact gap ops called out: paid but never completed the profile.
    // Stuck at the profile step for 3 days → Actionable.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_PROFILE, 72, 3);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(20, $score);
    $this->assertContains('stuck_at_profile', $reasons);
    $this->assertNotContains('missing_serial', $reasons, 'Still inside serial grace.');
  }

  public function testStuckAtScheduleAfter48h(): void {
    // Passed the quiz but never booked orientation, 3 days on the step.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_SCHEDULE, 72, 3);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(20, $score);
    $this->assertContains('stuck_at_schedule', $reasons);
  }

  public function testStuckStepEscalatesWhenAging(): void {
    // 8 days on the same step → aging escalation (+25).
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_QUIZ, 8 * 24, 8);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(25, $score);
    $this->assertContains('stuck_at_quiz_aging', $reasons);
  }

  public function testStuckStepEscalatesWhenStale(): void {
    // 20 days on the same step → stale escalation (+30) plus serial signals.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_PROFILE, 20 * 24, 20);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    // +30 stuck stale, +10 missing serial (past 14-day grace, under 60 days).
    $this->assertSame(40, $score);
    $this->assertContains('stuck_at_profile_stale', $reasons);
    $this->assertContains('missing_serial', $reasons);
  }

  public function testOrientationScheduledIsOnTrack(): void {
    // Member on track — orientation booked, so the funnel reports them past
    // the actionable steps. No stuck-step score.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->onboardingStalledBase($now_ts, OnboardingFunnel::STEP_INVOLVE, 72, 3);
    $data['orientation_scheduled'] = '2026-06-05';
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(0, $score);
    $this->assertContains('orientation_scheduled_upcoming', $reasons);
    $this->assertNotContains('stuck_at_involve', $reasons);
  }

  /**
   * Base for an engagement member with no badges and low in-space activity.
   */
  private function engagementBase(int $now_ts, int $days_since_activation): array {
    return [
      'stage' => MemberSuccessLifecycle::STAGE_ENGAGEMENT,
      'payment_failed' => FALSE,
      'payment_pause' => FALSE,
      'door_badge_status' => 'active',
      'serial_present' => TRUE,
      'activation_ts' => $now_ts - ($days_since_activation * 86400),
      'badge_count_total' => 0,
      'badge_count_window' => 0,
      'visit_count_30d' => 0,
      'last_visit_ts' => NULL,
      'tenure_bucket' => 'new_member',
      'join_date' => date('Y-m-d', $now_ts - ($days_since_activation * 86400)),
      'pause_start_date' => NULL,
    ];
  }

  public function testEngagementUnder30DaysNoScore(): void {
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->engagementBase($now_ts, 20);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 60, 180, [30, 60, 90], $now_ts, 30);
    $this->assertSame(0, $score);
    $this->assertNotContains('engagement_drifting', $reasons);
  }

  public function testEngagementDriftingAt30DaysScores10(): void {
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->engagementBase($now_ts, 35);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 60, 180, [30, 60, 90], $now_ts, 30);
    $this->assertSame(10, $score);
    $this->assertContains('engagement_drifting', $reasons);
    $this->assertNotContains('no_badge_1', $reasons);
  }

  public function testEngagementActionableAt60DaysScores20(): void {
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->engagementBase($now_ts, 65);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 60, 180, [30, 60, 90], $now_ts, 30);
    $this->assertSame(20, $score);
    $this->assertContains('no_badge_1', $reasons);
    $this->assertNotContains('engagement_drifting', $reasons);
  }

  public function testEngagementDriftingSuppressedByFrequentVisits(): void {
    // Someone showing up 4+ days in the last 30 counts as engaged even with
    // no badge progress — don't flag drift or actionable.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->engagementBase($now_ts, 45);
    $data['visit_count_30d'] = 6;
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 60, 180, [30, 60, 90], $now_ts, 30);
    $this->assertSame(0, $score);
    $this->assertNotContains('engagement_drifting', $reasons);
    $this->assertNotContains('no_badge_1', $reasons);
  }

  public function testEngagementWatchParameterNullBackwardsCompatible(): void {
    // Omitting $badge_watch_days keeps legacy two-tier behavior.
    $now_ts = mktime(12, 0, 0, 6, 1, 2026);
    $data = $this->engagementBase($now_ts, 45);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 60, 180, [30, 60, 90], $now_ts);
    $this->assertSame(0, $score, '45 days without watch param and action at 60 = safe.');
  }

  public function testMissingSerialVeryStale900Days(): void {
    $now_ts = mktime(0, 0, 0, 6, 1, 2026);
    $data = $this->onboardingMissingSerialBase($now_ts, 913);
    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, 28, 180, [30, 60, 90], $now_ts);
    $this->assertSame(25, $score);
    $this->assertContains('missing_serial_stale', $reasons);
  }

}
