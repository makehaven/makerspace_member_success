<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
    $tokens = $this->buildTokens($uid, (string) ($row['stage'] ?? ''), (int) ($row['risk_score'] ?? 0));

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
   */
  protected function buildTokens(int $uid, string $stage, int $riskScore): array {
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
        // Try profile field first.
        if ($user->hasField('field_first_name') && !$user->get('field_first_name')->isEmpty()) {
          $first_name = (string) $user->get('field_first_name')->value;
        }
        // Fall back to display name.
        if ($first_name === '') {
          $name_parts = explode(' ', $user->getDisplayName(), 2);
          $first_name = $name_parts[0];
        }
      }
    }
    catch (\Exception $e) {
      // Leave first_name empty if user load fails.
    }
    $tokens['contact.first_name'] = $first_name;

    // Resolve base URL.
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : 'https://www.makehaven.org';
    $tokens['domain.base_url'] = $base_url;

    return $tokens;
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
