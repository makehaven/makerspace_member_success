<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\makerspace_member_success\Support\OutreachDecision;

/**
 * Chooses channel/template based on preference, consent, and fallbacks.
 */
class OutreachPolicyDecider implements OutreachPolicyDeciderInterface {

  /**
   * Constructs a policy decider.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected OutreachSuppressionCheckerInterface $suppressionChecker
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(int $uid, array $snapshot): OutreachDecision {
    $stage = (string) ($snapshot['stage'] ?? 'retention');
    $preferred = strtolower(trim((string) ($snapshot['preferred_outreach_method'] ?? '')));
    $risk_score = (int) ($snapshot['risk_score'] ?? 0);

    $config = $this->configFactory->get('makerspace_member_success.settings');
    $context = [
      'email' => $snapshot['email'] ?? '',
      'phone' => $snapshot['phone'] ?? '',
      'sms_consent' => $snapshot['sms_consent'] ?? NULL,
      'is_opt_out' => $snapshot['is_opt_out'] ?? 0,
      'do_not_email' => $snapshot['do_not_email'] ?? NULL,
      'do_not_sms' => $snapshot['do_not_sms'] ?? NULL,
      'pause_all' => (bool) $config->get('pause_all'),
      'pause_email' => (bool) $config->get('pause_email'),
      'pause_sms' => (bool) $config->get('pause_sms'),
    ];

    $priority = $risk_score >= 50 ? 100 : ($risk_score >= 20 ? 70 : 30);
    $email_template = (string) ($config->get("template_$stage") ?? '');
    $sms_template = (string) ($config->get("sms_template_$stage") ?? '');
    $template_reason = NULL;

    if (str_contains($preferred, 'sms')) {
      $sms_check = $this->suppressionChecker->check($uid, 'sms', $context);
      if ($sms_check->allowed) {
        if ($sms_template !== '') {
          return new OutreachDecision('sms', $sms_template, 'pref_sms', $priority);
        }
        $template_reason = 'suppressed_missing_template_sms';
      }
    }

    if (str_contains($preferred, 'email')) {
      $email_check = $this->suppressionChecker->check($uid, 'email', $context);
      if ($email_check->allowed) {
        if ($email_template !== '') {
          return new OutreachDecision('email', $email_template, 'pref_email', $priority);
        }
        $template_reason = $template_reason ?? 'suppressed_missing_template_email';
      }
    }

    $sms_check = $this->suppressionChecker->check($uid, 'sms', $context);
    if ($sms_check->allowed) {
      if ($sms_template !== '') {
        return new OutreachDecision('sms', $sms_template, 'sms_fallback_no_pref', $priority);
      }
      $template_reason = $template_reason ?? 'suppressed_missing_template_sms';
    }

    $email_check = $this->suppressionChecker->check($uid, 'email', $context);
    if ($email_check->allowed) {
      if ($email_template !== '') {
        return new OutreachDecision('email', $email_template, 'email_fallback_no_sms', $priority);
      }
      $template_reason = $template_reason ?? 'suppressed_missing_template_email';
    }

    $reason = $template_reason ?? $sms_check->reasonCode ?? $email_check->reasonCode ?? 'suppressed_no_allowed_channel';
    return new OutreachDecision('manual_only', NULL, $reason, $priority);
  }

}
