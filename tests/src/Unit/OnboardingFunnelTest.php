<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the shared onboarding funnel step logic.
 *
 * @group makerspace_member_success
 * @coversDefaultClass \Drupal\makerspace_member_success\Support\OnboardingFunnel
 */
class OnboardingFunnelTest extends UnitTestCase {

  /**
   * A member who has only paid (account created) is stuck at profile.
   */
  public function testPaidOnlyStuckAtProfile(): void {
    $signals = ['has_account' => TRUE, 'account_ts' => 1000];
    $this->assertSame(OnboardingFunnel::STEP_PROFILE, OnboardingFunnel::nextStepId($signals));
    $this->assertFalse(OnboardingFunnel::isComplete($signals));
  }

  /**
   * Paid + profile saved → next action is watching the video.
   *
   * The quiz button lives at the bottom of the video page and shares the
   * quiz_passed signal, so the genuine next action after the profile is the
   * video; the quiz step trails it as "todo". A member stalled here is in the
   * safety-training stage regardless of which of the two they're on.
   */
  public function testProfileSavedStuckAtVideo(): void {
    $signals = [
      'has_account' => TRUE,
      'profile_present' => TRUE,
      'profile_ts' => 2000,
    ];
    $this->assertSame(OnboardingFunnel::STEP_VIDEO, OnboardingFunnel::nextStepId($signals));
  }

  /**
   * Quiz passed but orientation not booked → next step is scheduling.
   */
  public function testQuizPassedStuckAtSchedule(): void {
    $signals = [
      'has_account' => TRUE,
      'profile_present' => TRUE,
      'quiz_passed' => TRUE,
      'quiz_ts' => 3000,
    ];
    $this->assertSame(OnboardingFunnel::STEP_SCHEDULE, OnboardingFunnel::nextStepId($signals));
    // The video step counts as done once the quiz is passed.
    $steps = OnboardingFunnel::steps($signals);
    $video = array_values(array_filter($steps, fn($s) => $s['id'] === OnboardingFunnel::STEP_VIDEO))[0];
    $this->assertTrue($video['done']);
  }

  /**
   * Door badge active short-circuits everything to complete.
   */
  public function testDoorBadgeActiveIsComplete(): void {
    $signals = ['door_badge_active' => TRUE];
    $this->assertTrue(OnboardingFunnel::isComplete($signals));
    $this->assertNull(OnboardingFunnel::nextStep($signals));
    foreach (OnboardingFunnel::steps($signals) as $step) {
      $this->assertTrue($step['done'], $step['id'] . ' should be done');
      $this->assertSame('done', $step['state']);
    }
  }

  /**
   * Exactly one step is marked 'current' (the first incomplete one).
   */
  public function testSingleCurrentStep(): void {
    $signals = ['has_account' => TRUE, 'profile_present' => TRUE];
    $states = array_column(OnboardingFunnel::steps($signals), 'state', 'id');
    $current = array_keys(array_filter($states, fn($s) => $s === 'current'));
    $this->assertCount(1, $current);
    $this->assertSame(OnboardingFunnel::STEP_VIDEO, $current[0]);
    // Steps before current are done, after are todo.
    $this->assertSame('done', $states[OnboardingFunnel::STEP_ACCOUNT]);
    $this->assertSame('todo', $states[OnboardingFunnel::STEP_QUIZ]);
    $this->assertSame('todo', $states[OnboardingFunnel::STEP_SCHEDULE]);
  }

  /**
   * The resume URL for the profile step includes the uid.
   */
  public function testNextStepUrlIncludesUid(): void {
    $signals = ['has_account' => TRUE];
    $next = OnboardingFunnel::nextStep($signals, 42);
    $this->assertSame('/user/42/main?nextpage=video', $next['url']);
  }

  /**
   * Stall is measured from the most recent completed-step timestamp.
   */
  public function testHoursStalledFromLatestCompletedStep(): void {
    // Profile saved 3 days ago, quiz not passed: stalled ~72h at quiz.
    $now = 1_000_000;
    $signals = [
      'has_account' => TRUE,
      'account_ts' => $now - 100 * 3600,
      'profile_present' => TRUE,
      'profile_ts' => $now - 72 * 3600,
    ];
    $this->assertSame(72, OnboardingFunnel::hoursStalled($signals, $now));
    $this->assertSame($now - 72 * 3600, OnboardingFunnel::currentStepSince($signals));
  }

  /**
   * A completed funnel reports zero hours stalled.
   */
  public function testCompleteReportsNoStall(): void {
    $signals = ['door_badge_active' => TRUE];
    $this->assertSame(0, OnboardingFunnel::hoursStalled($signals, 1_000_000));
  }

  /**
   * Unknown timestamps yield zero stall rather than a bogus huge number.
   */
  public function testNoTimestampYieldsZeroStall(): void {
    $signals = ['has_account' => TRUE];
    $this->assertSame(0, OnboardingFunnel::hoursStalled($signals, 1_000_000));
  }

  /**
   * A "stuck_at_<step>" reason resolves to the canonical step (label + URL).
   */
  public function testStepFromStuckReason(): void {
    $video = OnboardingFunnel::stepFromStuckReason('stuck_at_video', 7);
    $this->assertSame('video', $video['id']);
    $this->assertSame('Watch the safety video', $video['label']);
    $this->assertSame('/video', $video['url']);

    // Profile step builds a uid-scoped URL.
    $profile = OnboardingFunnel::stepFromStuckReason('stuck_at_profile', 42);
    $this->assertSame('/user/42/main?nextpage=video', $profile['url']);
  }

  /**
   * The _aging / _stale severity suffixes are stripped before matching.
   */
  public function testStepFromStuckReasonStripsSeveritySuffix(): void {
    $this->assertSame('schedule', OnboardingFunnel::stepFromStuckReason('stuck_at_schedule_aging')['id']);
    $this->assertSame('video', OnboardingFunnel::stepFromStuckReason('stuck_at_video_stale')['id']);
  }

  /**
   * Non-step reasons return NULL so callers can fall back gracefully.
   */
  public function testStepFromStuckReasonRejectsUnrelated(): void {
    $this->assertNull(OnboardingFunnel::stepFromStuckReason('missing_serial'));
    $this->assertNull(OnboardingFunnel::stepFromStuckReason('payment_failed'));
  }

  /**
   * The auto-nudge step scoping allows only members stuck at listed steps.
   */
  public function testStuckStepAllowedScopesToListedSteps(): void {
    // Empty allow-list = no restriction (original stage-only behaviour).
    $this->assertTrue(OnboardingFunnel::stuckStepAllowed(['stuck_at_schedule'], []));

    // Profile-only scoping: profile-stalled allowed, others not.
    $this->assertTrue(OnboardingFunnel::stuckStepAllowed(['stuck_at_profile_aging'], ['profile']));
    $this->assertFalse(OnboardingFunnel::stuckStepAllowed(['stuck_at_schedule'], ['profile']));
    $this->assertFalse(OnboardingFunnel::stuckStepAllowed(['stuck_at_video_stale'], ['profile']));

    // No recognisable stuck-at step = not allowed under any non-empty scope.
    $this->assertFalse(OnboardingFunnel::stuckStepAllowed(['missing_serial'], ['profile']));
    $this->assertFalse(OnboardingFunnel::stuckStepAllowed([], ['profile']));

    // Multi-step scope matches any listed step.
    $this->assertTrue(OnboardingFunnel::stuckStepAllowed(['stuck_at_video'], ['profile', 'video']));
  }

  /**
   * Funnel-page detection matches aliases and the uid-scoped profile form.
   */
  public function testIsMemberFacingPath(): void {
    $this->assertTrue(OnboardingFunnel::isMemberFacingPath('/user/42/main'));
    $this->assertTrue(OnboardingFunnel::isMemberFacingPath('/quiz/1'));
    $this->assertTrue(OnboardingFunnel::isMemberFacingPath('/quiz/1/take/3'));
    $this->assertTrue(OnboardingFunnel::isMemberFacingPath('/orientation-video'));
    $this->assertTrue(OnboardingFunnel::isMemberFacingPath('/key-pickup-and-site-safety-intro'));
    $this->assertFalse(OnboardingFunnel::isMemberFacingPath('/user/42'));
    $this->assertFalse(OnboardingFunnel::isMemberFacingPath('/user/42/instructor'));
    $this->assertFalse(OnboardingFunnel::isMemberFacingPath('/quiz/12'));
    $this->assertFalse(OnboardingFunnel::isMemberFacingPath('/'));
    $this->assertFalse(OnboardingFunnel::isMemberFacingPath('/videos'));
  }

  /**
   * The first recognised stuck-at-step in a reason list wins.
   */
  public function testStepFromRiskReasonsPicksFirstMatch(): void {
    $reasons = ['missing_serial', 'stuck_at_quiz_aging', 'stuck_at_video'];
    $this->assertSame('quiz', OnboardingFunnel::stepFromRiskReasons($reasons)['id']);
    $this->assertNull(OnboardingFunnel::stepFromRiskReasons(['missing_serial', 'foo']));
  }

}
