<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests lifecycle constants and mappings.
 *
 * @group makerspace_member_success
 */
class MemberSuccessLifecycleTest extends UnitTestCase {

  /**
   * Tests that stages() includes the paused stage.
   */
  public function testStagesIncludesPaused(): void {
    $this->assertContains(MemberSuccessLifecycle::STAGE_PAUSED, MemberSuccessLifecycle::stages());
  }

  /**
   * Tests queue-resolving status list includes all permanent suppressions.
   */
  public function testResolvedFollowupStatuses(): void {
    $statuses = MemberSuccessLifecycle::resolvedFollowupStatuses();

    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION, $statuses);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, $statuses);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, $statuses);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_NEEDS_REVIEW, $statuses);
  }

  /**
   * Tests outcome to sleep mapping.
   */
  public function testSleepDaysForOutcome(): void {
    $this->assertSame(-1, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED));
    $this->assertSame(-1, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED));
    $this->assertSame(-1, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL));
    $this->assertSame(14, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_WILL_RETURN));
    $this->assertSame(7, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_SMS_SENT));
    $this->assertSame(0, MemberSuccessLifecycle::sleepDaysForOutcome(MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT));
    $this->assertSame(0, MemberSuccessLifecycle::sleepDaysForOutcome('unknown_outcome'));
  }

  /**
   * Tests outcome to followup mapping.
   */
  public function testFollowupStatusForOutcome(): void {
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
      MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL)
    );
    $this->assertNull(MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED));
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_WILL_RETURN, TRUE)
    );
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED,
      MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED)
    );
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE,
      MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_SMS_SENT)
    );
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE,
      MemberSuccessLifecycle::followupStatusForOutcome(MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT)
    );
  }

}
