<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;
use Drupal\Tests\UnitTestCase;

/**
 * Tests queue visibility and suppression reset rules.
 *
 * @group makerspace_member_success
 */
class MemberSuccessQueueRulesTest extends UnitTestCase {

  /**
   * Tests followup date visibility rules.
   */
  public function testFollowupDateVisibility(): void {
    $today = '2026-02-17';

    $this->assertTrue(MemberSuccessQueueRules::isFollowupDateVisible(NULL, $today));
    $this->assertTrue(MemberSuccessQueueRules::isFollowupDateVisible('2026-02-16', $today));
    $this->assertTrue(MemberSuccessQueueRules::isFollowupDateVisible('2026-02-17', $today));
    $this->assertFalse(MemberSuccessQueueRules::isFollowupDateVisible('2026-02-18', $today));
  }

  /**
   * Tests suppression status visibility.
   */
  public function testStatusVisibility(): void {
    $this->assertTrue(MemberSuccessQueueRules::isStatusVisible(NULL));
    $this->assertTrue(MemberSuccessQueueRules::isStatusVisible(''));
    $this->assertTrue(MemberSuccessQueueRules::isStatusVisible(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE));
    $this->assertFalse(MemberSuccessQueueRules::isStatusVisible(MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION));
    $this->assertFalse(MemberSuccessQueueRules::isStatusVisible(MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED));
  }

  /**
   * Tests full queue visibility evaluation.
   */
  public function testQueueVisibility(): void {
    $today = '2026-02-17';

    $this->assertTrue(MemberSuccessQueueRules::isQueueVisible(NULL, NULL, NULL, $today));
    $this->assertFalse(MemberSuccessQueueRules::isQueueVisible('2026-02-20', NULL, NULL, $today));
    $this->assertFalse(MemberSuccessQueueRules::isQueueVisible(NULL, MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, NULL, $today));
    $this->assertFalse(MemberSuccessQueueRules::isQueueVisible(NULL, NULL, MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION, $today));
    // no_action_needed suppresses via outreach_status.
    $this->assertFalse(MemberSuccessQueueRules::isQueueVisible(NULL, MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, NULL, $today));
    // no_action_needed suppresses via member_followup_status too.
    $this->assertFalse(MemberSuccessQueueRules::isQueueVisible(NULL, NULL, MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, $today));
  }

  /**
   * Tests suppression reset decision on stage change.
   */
  public function testSuppressionResetOnStageChange(): void {
    $this->assertTrue(MemberSuccessQueueRules::shouldResetSuppressionOnStageChange(
      MemberSuccessLifecycle::STAGE_RETENTION,
      MemberSuccessLifecycle::STAGE_RECOVERY,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED
    ));

    $this->assertFalse(MemberSuccessQueueRules::shouldResetSuppressionOnStageChange(
      MemberSuccessLifecycle::STAGE_RETENTION,
      MemberSuccessLifecycle::STAGE_RETENTION,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED
    ));

    $this->assertFalse(MemberSuccessQueueRules::shouldResetSuppressionOnStageChange(
      MemberSuccessLifecycle::STAGE_RETENTION,
      MemberSuccessLifecycle::STAGE_RECOVERY,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE
    ));

    // no_action_needed is a resolved status — should reset on stage change.
    $this->assertTrue(MemberSuccessQueueRules::shouldResetSuppressionOnStageChange(
      MemberSuccessLifecycle::STAGE_RETENTION,
      MemberSuccessLifecycle::STAGE_RECOVERY,
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED
    ));
  }

}
