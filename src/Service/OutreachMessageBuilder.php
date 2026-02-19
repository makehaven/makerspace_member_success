<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\PreparedMessage;

/**
 * Builds outbound payloads from queue entries.
 */
class OutreachMessageBuilder implements OutreachMessageBuilderInterface {

  /**
   * Constructs an outreach message builder.
   */
  public function __construct(protected Connection $database) {}

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

    $tokens = [
      'uid' => (int) $row['uid'],
      'stage' => (string) ($row['stage'] ?? ''),
      'risk_score' => (int) ($row['risk_score'] ?? 0),
    ];

    $body = sprintf('Queue #%d for %s using template %s', $queueId, $channel, $template_id ?? 'none');

    return new PreparedMessage(
      (int) $row['id'],
      (int) $row['uid'],
      $channel,
      $destination,
      $template_id,
      $body,
      $tokens
    );
  }

}

