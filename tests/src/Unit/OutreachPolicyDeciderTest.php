<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\makerspace_member_success\Service\OutreachPolicyDecider;
use Drupal\makerspace_member_success\Service\OutreachSuppressionCheckerInterface;
use Drupal\makerspace_member_success\Support\SuppressionResult;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for outreach policy channel/template selection.
 *
 * @group makerspace_member_success
 */
class OutreachPolicyDeciderTest extends UnitTestCase {

  /**
   * Tests channel/template choice matrix for key policy scenarios.
   *
   * @dataProvider decisionMatrixProvider
   */
  public function testDecisionMatrix(
    array $config_values,
    array $snapshot,
    array $check_results,
    string $expected_channel,
    ?string $expected_template,
    string $expected_reason
  ): void {
    $suppression_checker = $this->createMock(OutreachSuppressionCheckerInterface::class);
    $suppression_checker->method('check')
      ->willReturnCallback(function (int $uid, string $channel) use (&$check_results): SuppressionResult {
        return $check_results[$channel] ?? new SuppressionResult(FALSE, 'suppressed_no_allowed_channel');
      });

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($config_values) {
        return $config_values[$key] ?? NULL;
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('makerspace_member_success.settings')
      ->willReturn($config);

    $decider = new OutreachPolicyDecider($config_factory, $suppression_checker);
    $decision = $decider->decide(42, $snapshot);

    $this->assertSame($expected_channel, $decision->channel);
    $this->assertSame($expected_template, $decision->templateId);
    $this->assertSame($expected_reason, $decision->reasonCode);
  }

  /**
   * Provides policy matrix cases.
   */
  public static function decisionMatrixProvider(): array {
    $base_config = [
      'pause_all' => FALSE,
      'pause_email' => FALSE,
      'pause_sms' => FALSE,
      'template_retention' => 'tpl_email_ret',
      'sms_template_retention' => 'tpl_sms_ret',
      'email_template_overrides' => [],
      'sms_template_overrides' => [],
    ];

    $base_snapshot = [
      'stage' => 'retention',
      'risk_score' => 35,
      'email' => 'member@example.com',
      'phone' => '+12035550123',
      'sms_consent' => 1,
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ];

    return [
      'preferred_sms_uses_sms_template' => [
        $base_config,
        $base_snapshot + ['preferred_outreach_method' => 'sms'],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'sms',
        'tpl_sms_ret',
        'pref_sms',
      ],
      'sms_blocked_falls_back_to_email' => [
        $base_config,
        $base_snapshot + ['preferred_outreach_method' => 'sms'],
        [
          'sms' => new SuppressionResult(FALSE, 'suppressed_no_sms_consent'),
          'email' => new SuppressionResult(TRUE),
        ],
        'email',
        'tpl_email_ret',
        'email_fallback_no_pref',
      ],
      'no_preference_defaults_to_email_even_with_sms_consent' => [
        $base_config,
        $base_snapshot + ['preferred_outreach_method' => '', 'sms_consent' => 1],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'email',
        'tpl_email_ret',
        'email_fallback_no_pref',
      ],
      'preferred_email_missing_template_falls_to_sms' => [
        array_replace($base_config, ['template_retention' => '']),
        $base_snapshot + ['preferred_outreach_method' => 'email'],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'sms',
        'tpl_sms_ret',
        'sms_fallback_no_email',
      ],
      'no_templates_returns_manual_only' => [
        array_replace($base_config, ['template_retention' => '', 'sms_template_retention' => '']),
        $base_snapshot + ['preferred_outreach_method' => 'email'],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'manual_only',
        NULL,
        'suppressed_missing_template_email',
      ],
      'paused_returns_manual_only' => [
        $base_config + ['pause_all' => TRUE],
        $base_snapshot + ['preferred_outreach_method' => 'sms'],
        [
          'sms' => new SuppressionResult(FALSE, 'suppressed_paused'),
          'email' => new SuppressionResult(FALSE, 'suppressed_paused'),
        ],
        'manual_only',
        NULL,
        'suppressed_paused',
      ],
      'exact_stage_reason_override_beats_default' => [
        array_replace($base_config, [
          'email_template_overrides' => ['retention.payment_failed=777'],
        ]),
        $base_snapshot + [
          'preferred_outreach_method' => 'email',
          'risk_reasons' => ['payment_failed', 'inactive_30'],
        ],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'email',
        '777',
        'pref_email',
      ],
      'wildcard_reason_override_applies' => [
        array_replace($base_config, [
          'sms_template_retention' => 'tpl_sms_ret',
          'sms_template_overrides' => ['retention.inactive_*=888'],
        ]),
        $base_snapshot + [
          'preferred_outreach_method' => 'sms',
          'risk_reasons' => ['inactive_60'],
        ],
        [
          'sms' => new SuppressionResult(TRUE),
          'email' => new SuppressionResult(TRUE),
        ],
        'sms',
        '888',
        'pref_sms',
      ],
    ];
  }

}
