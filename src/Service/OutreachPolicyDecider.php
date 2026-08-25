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
    protected OutreachSuppressionCheckerInterface $suppressionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(int $uid, array $snapshot): OutreachDecision {
    $stage = (string) ($snapshot['stage'] ?? 'retention');
    $preferred = strtolower(trim((string) ($snapshot['preferred_outreach_method'] ?? '')));
    $risk_score = (int) ($snapshot['risk_score'] ?? 0);
    $risk_reasons = $this->decodeRiskReasons($snapshot['risk_reasons'] ?? NULL);

    $config = $this->configFactory->get('makerspace_member_success.settings');
    $context = [
      // The stage being decided FOR, which is not always the member's
      // lifecycle stage in the snapshot. OutreachSuppressionChecker reads
      // $context['stage'] in preference to the snapshot's own, but nothing
      // used to supply it — so a campaign-scoped stage silently fell back to
      // the member's lifecycle stage and was judged against that stage's risk
      // threshold. Found 2026-08-25 building the badge nudge: healthy members
      // (risk 0) were suppressed as "below threshold" against the retention
      // threshold of 20, a stage they were not being contacted under.
      'stage' => $stage,
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

    // Pass the config key name (e.g. "template_onboarding") as the default,
    // not the full template text. The column is varchar(64) and the full text
    // would be truncated. OutreachMessageBuilder resolves the content at send time.
    $email_key = (string) ($config->get("template_$stage") ?? '') !== '' ? "template_$stage" : '';
    $sms_key = (string) ($config->get("sms_template_$stage") ?? '') !== '' ? "sms_template_$stage" : '';

    $email_template = $this->resolveTemplateForChannel(
      $stage,
      $risk_reasons,
      $email_key,
      (array) ($config->get('email_template_overrides') ?? [])
    );
    $sms_template = $this->resolveTemplateForChannel(
      $stage,
      $risk_reasons,
      $sms_key,
      (array) ($config->get('sms_template_overrides') ?? [])
    );
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

    // Email is the default channel when no preference is stated.
    // SMS is only used as a fallback after email fails, and still requires
    // explicit consent (enforced by the suppression checker).
    $email_check = $this->suppressionChecker->check($uid, 'email', $context);
    if ($email_check->allowed) {
      if ($email_template !== '') {
        return new OutreachDecision('email', $email_template, 'email_fallback_no_pref', $priority);
      }
      $template_reason = $template_reason ?? 'suppressed_missing_template_email';
    }

    $sms_check = $this->suppressionChecker->check($uid, 'sms', $context);
    if ($sms_check->allowed) {
      if ($sms_template !== '') {
        return new OutreachDecision('sms', $sms_template, 'sms_fallback_no_email', $priority);
      }
      $template_reason = $template_reason ?? 'suppressed_missing_template_sms';
    }

    $reason = $template_reason ?? $email_check->reasonCode ?? $sms_check->reasonCode ?? 'suppressed_no_allowed_channel';
    return new OutreachDecision('manual_only', NULL, $reason, $priority);
  }

  /**
   * Selects a template ID with optional stage/reason overrides.
   *
   * Precedence: matching override rule > stage default template.
   */
  protected function resolveTemplateForChannel(
    string $stage,
    array $riskReasons,
    string $defaultTemplate,
    array $overrideRules,
  ): string {
    $stage = strtolower(trim($stage));
    $best_score = -1;
    $best_template = '';

    foreach ($overrideRules as $raw_rule) {
      if (!is_string($raw_rule) || !str_contains($raw_rule, '=')) {
        continue;
      }

      [$left, $template_id] = array_map('trim', explode('=', $raw_rule, 2));
      if ($left === '' || $template_id === '' || !str_contains($left, '.')) {
        continue;
      }
      if (!preg_match('/^\d+$/', $template_id)) {
        continue;
      }

      [$stage_pattern, $reason_pattern] = array_map('trim', explode('.', strtolower($left), 2));
      if ($stage_pattern === '' || $reason_pattern === '') {
        continue;
      }

      foreach ($riskReasons as $reason) {
        $score = $this->matchScore($stage, strtolower($reason), $stage_pattern, $reason_pattern);
        if ($score > $best_score) {
          $best_score = $score;
          $best_template = $template_id;
        }
      }
    }

    if ($best_template !== '') {
      return $best_template;
    }

    return $defaultTemplate;
  }

  /**
   * Scores a stage/reason rule match. Higher score means stronger match.
   */
  protected function matchScore(
    string $stage,
    string $reason,
    string $stagePattern,
    string $reasonPattern,
  ): int {
    if (!$this->matchPattern($stagePattern, $stage) || !$this->matchPattern($reasonPattern, $reason)) {
      return -1;
    }

    $stage_score = $stagePattern === '*' ? 10 : 100;
    $reason_score = str_contains($reasonPattern, '*') ? 20 + strlen(str_replace('*', '', $reasonPattern)) : 50;
    return $stage_score + $reason_score;
  }

  /**
   * Returns TRUE when value matches '*' wildcard pattern.
   */
  protected function matchPattern(string $pattern, string $value): bool {
    if ($pattern === '*') {
      return TRUE;
    }
    if (!str_contains($pattern, '*')) {
      return $pattern === $value;
    }

    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $value);
  }

  /**
   * Decodes risk reasons from array/serialized values.
   */
  protected function decodeRiskReasons(mixed $raw): array {
    if (is_array($raw)) {
      return array_values(array_filter(array_map('strval', $raw), 'strlen'));
    }
    if (!is_string($raw) || trim($raw) === '') {
      return [];
    }

    $decoded = @unserialize($raw);
    if (is_array($decoded)) {
      return array_values(array_filter(array_map('strval', $decoded), 'strlen'));
    }
    if (is_string($decoded) && trim($decoded) !== '') {
      $nested = @unserialize($decoded);
      if (is_array($nested)) {
        return array_values(array_filter(array_map('strval', $nested), 'strlen'));
      }
    }

    return [];
  }

}
