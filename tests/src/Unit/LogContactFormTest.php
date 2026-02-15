<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\makerspace_member_success\Form\LogContactForm;

/**
 * Unit tests for LogContactForm logic.
 *
 * @group makerspace_member_success
 */
class LogContactFormTest extends UnitTestCase {

  /**
   * Test outcome to followup status mapping.
   *
   * @dataProvider outcomeToFollowupStatusProvider
   */
  public function testMapOutcomeToFollowupStatus($outcome, $mark_exhausted, $expected) {
    $form = $this->getMockBuilder(LogContactForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $method = new \ReflectionMethod(LogContactForm::class, 'mapOutcomeToFollowupStatus');
    $method->setAccessible(TRUE);

    $result = $method->invoke($form, $outcome, $mark_exhausted);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for outcome to followup status mapping tests.
   */
  public function outcomeToFollowupStatusProvider() {
    return [
      // Outcome, mark_exhausted, expected_status
      ['payment_updated', FALSE, NULL],
      ['confirmed_cancel', FALSE, 'confirmed_cancellation'],
      ['will_return', FALSE, 'return_intent'],
      ['needs_time', FALSE, 'outreach_active'],
      ['no_answer', FALSE, 'outreach_active'],
      ['left_message', FALSE, 'outreach_active'],
      ['email_sent', FALSE, 'outreach_active'],
      ['email_bounced', FALSE, 'outreach_active'],
      // Manual override
      ['will_return', TRUE, 'outreach_exhausted'],
      ['payment_updated', TRUE, 'outreach_exhausted'],
    ];
  }

  /**
   * Test sleep period calculation.
   *
   * @dataProvider sleepPeriodProvider
   */
  public function testGetSleepPeriod($outcome, $expected_days) {
    $form = $this->getMockBuilder(LogContactForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $method = new \ReflectionMethod(LogContactForm::class, 'getSleepPeriod');
    $method->setAccessible(TRUE);

    $result = $method->invoke($form, $outcome);
    $this->assertEquals($expected_days, $result);
  }

  /**
   * Data provider for sleep period tests.
   */
  public function sleepPeriodProvider() {
    return [
      // Outcome, expected_days
      ['left_message', 7],
      ['email_sent', 7],
      ['no_answer', 3],
      ['will_return', 14],
      ['needs_time', 14],
      ['payment_updated', -1],  // Resolved
      ['confirmed_cancel', -1], // Resolved
      ['email_bounced', 0],     // Immediate reappear
    ];
  }

  /**
   * Test conditional outcome options based on contact method.
   *
   * @dataProvider outcomeOptionsProvider
   */
  public function testGetOutcomeOptions($method, $expected_options) {
    $form = $this->getMockBuilder(LogContactForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $method_reflection = new \ReflectionMethod(LogContactForm::class, 'getOutcomeOptions');
    $method_reflection->setAccessible(TRUE);

    $result = $method_reflection->invoke($form, $method);

    // Check that all expected options are present
    foreach ($expected_options as $key) {
      $this->assertArrayHasKey($key, $result, "Expected option '$key' for method '$method'");
    }
  }

  /**
   * Data provider for outcome options tests.
   */
  public function outcomeOptionsProvider() {
    return [
      ['phone', ['payment_updated', 'will_return', 'no_answer', 'left_message']],
      ['email', ['payment_updated', 'will_return', 'email_sent', 'email_bounced']],
      ['in_person', ['payment_updated', 'will_return', 'confirmed_cancel', 'needs_time']],
      ['other', ['payment_updated', 'will_return', 'no_answer', 'left_message']],
    ];
  }

}
