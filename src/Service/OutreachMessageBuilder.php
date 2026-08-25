<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Drupal\makerspace_member_success\Support\PreparedMessage;

/**
 * Builds outbound payloads from queue entries.
 */
class OutreachMessageBuilder implements OutreachMessageBuilderInterface {

  /**
   * Constructs an outreach message builder.
   */
  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected ?PendingBadgeFinder $pendingBadgeFinder = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(int $queueId): PreparedMessage {
    $row = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q')
      ->condition('q.id', $queueId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      throw new \InvalidArgumentException(sprintf('Queue item %d was not found.', $queueId));
    }

    $channel = (string) ($row['actual_channel'] ?? $row['recommended_channel']);
    $template_id = (string) ($row['actual_template_id'] ?? $row['recommended_template_id']) ?: NULL;
    $destination = $channel === 'sms'
      ? (string) ($row['destination_phone'] ?? '')
      : (string) ($row['destination_email'] ?? '');

    $uid = (int) $row['uid'];

    // Resolve template content from config key or CiviCRM template ID.
    $template_content = $this->resolveTemplateContent($template_id, $channel);

    // Build token substitution map.
    $tokens = $this->buildTokens(
      $uid,
      (string) ($row['stage'] ?? ''),
      (int) ($row['risk_score'] ?? 0),
      $row['risk_reasons'] ?? NULL,
    );

    // Apply token substitution.
    $template_content = $this->replaceTokens($template_content, $tokens);

    // Parse subject line and body from the template.
    [$subject, $body] = $this->parseTemplate($template_content);

    return new PreparedMessage(
      (int) $row['id'],
      $uid,
      $channel,
      $destination,
      $template_id,
      $body,
      $tokens,
      $subject
    );
  }

  /**
   * Loads template content from a config key or CiviCRM template ID.
   *
   * Config-based keys look like "template_onboarding" or "sms_template_engagement".
   * CiviCRM override IDs are numeric strings like "42".
   */
  protected function resolveTemplateContent(?string $templateId, string $channel): string {
    if ($templateId === NULL || $templateId === '') {
      return '';
    }

    // Config-based template key (e.g. "template_onboarding", "sms_template_retention").
    if (preg_match('/^(sms_)?template_/', $templateId)) {
      $config = $this->configFactory->get('makerspace_member_success.settings');
      return (string) ($config->get($templateId) ?? '');
    }

    // CiviCRM numeric template ID — not yet implemented; return empty.
    return '';
  }

  /**
   * Builds a token map for a member.
   *
   * @param int $uid
   *   Member user id.
   * @param string $stage
   *   Lifecycle stage.
   * @param int $riskScore
   *   Risk score.
   * @param mixed $riskReasonsRaw
   *   Serialized/array risk reasons from the queue row, used to derive the
   *   stage-aware {next_step} / {next_step_url} tokens for onboarding nudges.
   */
  protected function buildTokens(int $uid, string $stage, int $riskScore, mixed $riskReasonsRaw = NULL): array {
    $tokens = [
      'uid' => $uid,
      'stage' => $stage,
      'risk_score' => $riskScore,
    ];

    // Resolve member's first name.
    $first_name = '';
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user) {
        // 1. Try field_first_name directly on the user entity.
        if ($user->hasField('field_first_name') && !$user->get('field_first_name')->isEmpty()) {
          $first_name = (string) $user->get('field_first_name')->value;
        }
        // 2. Try the main profile entity (Profile module).
        if ($first_name === '') {
          $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
            'uid' => $uid,
            'type' => 'main',
            'status' => 1,
          ]);
          $profile = reset($profiles);
          if ($profile && $profile->hasField('field_first_name') && !$profile->get('field_first_name')->isEmpty()) {
            $first_name = (string) $profile->get('field_first_name')->value;
          }
        }
        // 3. Fall back to the first word of the display name, but only if it
        //    looks like a real name (no underscores, no digits, reasonable length).
        if ($first_name === '') {
          $display = $user->getDisplayName();
          $candidate = explode(' ', $display, 2)[0];
          $looks_like_name = !str_contains($candidate, '_')
            && !preg_match('/\d/', $candidate)
            && strlen($candidate) >= 2
            && strlen($candidate) <= 30;
          if ($looks_like_name) {
            $first_name = $candidate;
          }
        }
      }
    }
    catch (\Exception $e) {
      // Leave first_name empty if user or profile load fails.
    }
    // Final fallback: generic greeting so the email still reads naturally.
    $tokens['contact.first_name'] = $first_name !== '' ? $first_name : 'there';

    // Resolve base URL.
    // In Drush/CLI context the request stack returns a synthetic host
    // ('default'). Fall back to the known production URL in that case.
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : '';
    if ($base_url === '' || $base_url === 'http://default' || $base_url === 'https://default') {
      $base_url = 'https://www.makehaven.org';
    }
    $tokens['domain.base_url'] = $base_url;

    // Badge-nudge tokens. Only resolved for the badge stage: listFor() is a
    // multi-join query and there is no reason to run it for onboarding or
    // retention mail. The list comes from PendingBadgeFinder — the same
    // selection the daily snapshot counts — so the email can never name a
    // badge the reports say the member does not have.
    if ($stage === 'badge' && $this->pendingBadgeFinder !== NULL) {
      $config = $this->configFactory->get('makerspace_member_success.settings');
      $max = (int) ($config->get('badge_nudge_max_badges_listed') ?? 6);
      $badges = $this->pendingBadgeFinder->listFor($uid);
      $names = array_column($badges, 'name');
      $shown = $max > 0 ? array_slice($names, 0, $max) : $names;
      $remainder = count($names) - count($shown);

      $lines = array_map(static fn (string $n): string => '  - ' . $n, $shown);
      if ($remainder > 0) {
        $lines[] = '  - and ' . $remainder . ' more';
      }

      $tokens['badge.count'] = (string) count($names);
      $tokens['badge.list'] = implode("\n", $lines);
      $tokens['badge.plural'] = count($names) === 1 ? 'badge' : 'badges';
      $tokens['badge.is_are'] = count($names) === 1 ? 'is' : 'are';
      $tokens['badge.first'] = $names[0] ?? '';
      $tokens['badge.complete_url'] = $base_url . '/badges/complete';
      $tokens['badge.schedules_url'] = $base_url . '/facilitator/schedules';
    }

    // Stage-aware "next step" tokens for onboarding nudges. Derived from the
    // member's stuck-at-step risk reason and the canonical OnboardingFunnel
    // step (single source of truth for label + URL, so copy never drifts).
    // Falls back to a generic resume action when no step-specific reason is
    // present (e.g. missing-serial), so the template still reads naturally.
    $next_label = 'finish setting up your membership';
    $next_url = $base_url . '/user';
    $reasons = $this->decodeRiskReasons($riskReasonsRaw);
    $step = OnboardingFunnel::stepFromRiskReasons($reasons, $uid);
    if ($step !== NULL) {
      $next_label = lcfirst((string) $step['label']);
      $next_url = $base_url . $step['url'];
    }
    $tokens['next_step'] = $next_label;
    $tokens['next_step_url'] = $next_url;

    return $tokens;
  }

  /**
   * Decodes risk reasons (possibly single- or double-serialized) to an array.
   */
  protected function decodeRiskReasons(mixed $raw): array {
    if (is_array($raw)) {
      return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
      return [];
    }
    $parsed = @unserialize($raw, ['allowed_classes' => FALSE]);
    if (is_array($parsed)) {
      return $parsed;
    }
    if (is_string($parsed) && $parsed !== '') {
      $nested = @unserialize($parsed, ['allowed_classes' => FALSE]);
      if (is_array($nested)) {
        return $nested;
      }
    }
    return [];
  }

  /**
   * Replaces {token} placeholders in the template string.
   */
  protected function replaceTokens(string $text, array $tokens): string {
    $search = [];
    $replace = [];
    foreach ($tokens as $key => $value) {
      if (is_scalar($value)) {
        $search[] = '{' . $key . '}';
        $replace[] = (string) $value;
      }
    }
    return str_replace($search, $replace, $text);
  }

  /**
   * Parses "Subject: ..." from the first line; returns [subject, body].
   *
   * Template format:
   *   Subject: My subject line.
   *
   *   Body text here...
   */
  protected function parseTemplate(string $template): array {
    $template = trim($template);
    if ($template === '') {
      return ['', ''];
    }

    $lines = explode("\n", $template);
    $first = ltrim($lines[0]);

    if (str_starts_with($first, 'Subject:')) {
      $subject = trim(substr($first, 8));
      // Skip the blank separator line after the subject.
      $body_start = 1;
      if (isset($lines[1]) && trim($lines[1]) === '') {
        $body_start = 2;
      }
      $body = trim(implode("\n", array_slice($lines, $body_start)));
      return [$subject, $body];
    }

    return ['', $template];
  }

}
