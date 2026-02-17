<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Form\LogContactForm;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for LogContactForm logic.
 *
 * Note: sleep-period and followup-status logic is tested via
 * MemberSuccessLifecycleTest. Snapshot/outreach write logic is tested via
 * OutreachServiceTest.
 *
 * @group makerspace_member_success
 */
class LogContactFormTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Test conditional outcome options based on contact method and stage.
   *
   * @dataProvider outcomeOptionsProvider
   */
  public function testGetOutcomeOptions(string $method, string $stage, array $expected_keys, array $absent_keys = []): void {
    $form = $this->getMockBuilder(LogContactForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $reflection = new \ReflectionMethod(LogContactForm::class, 'getOutcomeOptions');
    $reflection->setAccessible(TRUE);

    $result = $reflection->invoke($form, $method, $stage);

    foreach ($expected_keys as $key) {
      $this->assertArrayHasKey($key, $result, "Expected option '$key' for method '$method' / stage '$stage'");
    }
    foreach ($absent_keys as $key) {
      $this->assertArrayNotHasKey($key, $result, "Option '$key' should be absent for method '$method' / stage '$stage'");
    }
  }

  /**
   * Data provider for outcome options tests.
   */
  public static function outcomeOptionsProvider(): array {
    return [
      // Phone + recovery: payment_updated available; no_action_needed suppressed.
      'phone_recovery' => [
        'phone',
        MemberSuccessLifecycle::STAGE_RECOVERY,
        [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
          MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE,
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT,
        ],
        [MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED],
      ],
      // Phone + retention: no_action_needed available; payment_updated suppressed.
      'phone_retention' => [
        'phone',
        MemberSuccessLifecycle::STAGE_RETENTION,
        [
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
        ],
        [MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED],
      ],
      // Email + recovery: payment_updated available; no_action_needed suppressed.
      'email_recovery' => [
        'email',
        MemberSuccessLifecycle::STAGE_RECOVERY,
        [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_EMAIL_SENT,
          MemberSuccessLifecycle::OUTCOME_EMAIL_BOUNCED,
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT,
        ],
        [MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED],
      ],
      // In-person + recovery.
      'in_person_recovery' => [
        'in_person',
        MemberSuccessLifecycle::STAGE_RECOVERY,
        [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL,
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME,
        ],
        [MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED],
      ],
      // Other + recovery.
      'other_recovery' => [
        'other',
        MemberSuccessLifecycle::STAGE_RECOVERY,
        [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
          MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE,
        ],
        [MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED],
      ],
      // SMS + recovery: sms_sent available; no_action_needed suppressed.
      'sms_recovery' => [
        'sms',
        MemberSuccessLifecycle::STAGE_RECOVERY,
        [
          MemberSuccessLifecycle::OUTCOME_SMS_SENT,
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT,
        ],
        [MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED],
      ],
      // Onboarding suppresses both no_action_needed and payment_updated.
      'phone_onboarding' => [
        'phone',
        MemberSuccessLifecycle::STAGE_ONBOARDING,
        [
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN,
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
        ],
        [
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED,
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
        ],
      ],
    ];
  }

}
