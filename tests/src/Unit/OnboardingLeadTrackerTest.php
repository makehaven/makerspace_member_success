<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\OnboardingLeadTracker;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the pure lead-aggregation logic.
 *
 * @group makerspace_member_success
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\OnboardingLeadTracker
 */
class OnboardingLeadTrackerTest extends UnitTestCase {

  /**
   * Repeat submissions from one email collapse to a single lead, latest wins.
   */
  public function testRepeatSubmissionsDedupeToLatest(): void {
    $submissions = [
      11 => ['sid' => 11, 'created' => 1000, 'email' => 'a@example.com', 'first_name' => 'Al'],
      12 => ['sid' => 12, 'created' => 3000, 'email' => 'A@Example.com', 'first_name' => 'Alice', 'last_name' => 'B'],
      13 => ['sid' => 13, 'created' => 2000, 'email' => 'a@example.com'],
    ];
    $result = OnboardingLeadTracker::aggregateByEmail($submissions);

    $this->assertCount(1, $result, 'Case-insensitive email dedupes to one lead.');
    $lead = $result[0];
    $this->assertSame('a@example.com', $lead['email']);
    $this->assertSame(3, $lead['count']);
    $this->assertSame(12, $lead['sid'], 'Latest submission sid wins.');
    $this->assertSame(3000, $lead['created']);
    $this->assertSame('Alice B', $lead['name'], 'Name from the latest non-empty submission.');
  }

  /**
   * Distinct emails produce distinct leads; blank emails are dropped.
   */
  public function testDistinctEmailsAndBlankDropped(): void {
    $submissions = [
      1 => ['sid' => 1, 'created' => 100, 'email' => 'one@example.com'],
      2 => ['sid' => 2, 'created' => 200, 'email' => 'two@example.com'],
      3 => ['sid' => 3, 'created' => 300, 'email' => ''],
      4 => ['sid' => 4, 'created' => 400],
    ];
    $result = OnboardingLeadTracker::aggregateByEmail($submissions);

    $emails = array_column($result, 'email');
    sort($emails);
    $this->assertSame(['one@example.com', 'two@example.com'], $emails);
  }

  /**
   * An earlier name is not overwritten by a later nameless submission.
   */
  public function testNameNotClobberedByLaterBlank(): void {
    $submissions = [
      1 => ['sid' => 1, 'created' => 100, 'email' => 'x@example.com', 'first_name' => 'Ada'],
      2 => ['sid' => 2, 'created' => 500, 'email' => 'x@example.com'],
    ];
    $result = OnboardingLeadTracker::aggregateByEmail($submissions);
    $this->assertSame('Ada', $result[0]['name']);
    $this->assertSame(2, $result[0]['sid'], 'Latest sid still tracked even when it has no name.');
  }

  /**
   * Delayed follow-up contains the personal checkout link and cautious copy.
   */
  public function testFollowupBodyWithCheckoutLink(): void {
    $body = OnboardingLeadTracker::buildFollowupBody(
      'Ada',
      'https://makehaven.chargebee.com/hosted_pages/plans/member',
      'Standard — $60/month'
    );

    $this->assertStringContainsString('Hi Ada,', $body);
    $this->assertStringContainsString('personal checkout link', $body);
    $this->assertStringContainsString('Standard — $60/month', $body);
    $this->assertStringContainsString('If you already completed payment', $body);
  }

  /**
   * A missing checkout link does not send someone through the form again.
   */
  public function testFollowupBodyWithoutCheckoutLink(): void {
    $body = OnboardingLeadTracker::buildFollowupBody('there', NULL);

    $this->assertStringContainsString('could not generate your personal checkout link', $body);
    $this->assertStringContainsString('do not need to submit the form again', $body);
  }

  /**
   * The offer of help and the tour invitation reach the applicant.
   *
   * Staff were sending these two things by hand alongside the automated email
   * (Kate, 2026-09-11); the automated copy now carries them.
   */
  public function testFollowupBodyOffersHelpAndTour(): void {
    $body = OnboardingLeadTracker::buildFollowupBody(
      'Ada',
      'https://makehaven.chargebee.com/hosted_pages/plans/member',
      '',
      'https://www.makehaven.org/open-tours'
    );

    $this->assertStringContainsString('Is there anything I can do to help you along in the process?', $body);
    $this->assertStringContainsString('sign up for a tour', $body);
    $this->assertStringContainsString('https://www.makehaven.org/open-tours', $body);
    // The invitation comes before the sign-off, not after it.
    $this->assertLessThan(
      strpos($body, 'The MakeHaven Team'),
      strpos($body, 'sign up for a tour'),
    );
  }

  /**
   * Without a configured tour link the invitation still reads as a sentence.
   */
  public function testFollowupBodyInvitesToTourWithoutLink(): void {
    $body = OnboardingLeadTracker::buildFollowupBody('Ada', NULL, '', NULL);

    $this->assertStringContainsString('I invite you to sign up for a tour.', $body);
    $this->assertStringNotContainsString('/open-tours', $body);
  }

  /**
   * Bcc parsing keeps valid addresses, drops junk, and de-duplicates.
   */
  public function testNormalizeBcc(): void {
    $this->assertSame(
      'kate.cebik@makehaven.org, crm@makehaven.org',
      OnboardingLeadTracker::normalizeBcc(" kate.cebik@makehaven.org ,crm@makehaven.org\n"),
    );
    $this->assertSame(
      'crm@makehaven.org',
      OnboardingLeadTracker::normalizeBcc('not-an-address; crm@makehaven.org; CRM@makehaven.org'),
    );
    $this->assertSame('', OnboardingLeadTracker::normalizeBcc('   '));
  }

}
