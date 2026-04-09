<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Data object for a fully prepared outreach message.
 */
final class PreparedMessage {

  /**
   * Constructs a PreparedMessage.
   */
  public function __construct(
    public int $queueId,
    public int $uid,
    public string $channel,
    public string $destination,
    public ?string $templateId,
    public string $body,
    public array $tokens = [],
    public string $subject = '',
  ) {}

}
