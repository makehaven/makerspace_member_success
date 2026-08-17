<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the new-payment-episode detection and reset scoping.
 *
 * @group makerspace_member_success
 */
class PaymentEpisodeResetTest extends UnitTestCase {

  /**
   * A fresh failure after a clean previous snapshot starts an episode.
   */
  public function testCleanToFailedStartsEpisode(): void {
    $previous = ['payment_failed' => 0, 'stage' => MemberSuccessLifecycle::STAGE_RETENTION];
    $this->assertTrue(MemberSuccessLifecycle::isNewPaymentEpisode($previous, TRUE));
  }

  /**
   * An ongoing failure (retries, second cards) is the same episode.
   */
  public function testOngoingFailureIsNotANewEpisode(): void {
    $previous = ['payment_failed' => 1, 'stage' => MemberSuccessLifecycle::STAGE_RECOVERY];
    $this->assertFalse(MemberSuccessLifecycle::isNewPaymentEpisode($previous, TRUE));
  }

  /**
   * Recovering (failed -> clean) is not an episode start.
   */
  public function testRecoveryIsNotAnEpisode(): void {
    $previous = ['payment_failed' => 1];
    $this->assertFalse(MemberSuccessLifecycle::isNewPaymentEpisode($previous, FALSE));
  }

  /**
   * A member with no history never triggers a reset on first sight.
   */
  public function testMissingPreviousSnapshotIsNotAnEpisode(): void {
    $this->assertFalse(MemberSuccessLifecycle::isNewPaymentEpisode([], TRUE));
    $this->assertFalse(MemberSuccessLifecycle::isNewPaymentEpisode([], FALSE));
  }

  /**
   * Episode reset wipes closure statuses but never needs_review.
   */
  public function testResetScopeCoversClosuresButKeepsNeedsReview(): void {
    $reset = MemberSuccessLifecycle::followupStatusesResetOnNewPaymentEpisode();

    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION, $reset);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, $reset);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, $reset);
    $this->assertContains(MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT, $reset);
    $this->assertNotContains(MemberSuccessLifecycle::FOLLOWUP_NEEDS_REVIEW, $reset);
    $this->assertNotContains(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, $reset);
  }

}
