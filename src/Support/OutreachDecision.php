<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Data object describing a recommended outreach action.
 */
final class OutreachDecision {

  /**
   * Constructs an OutreachDecision.
   */
  public function __construct(
    public string $channel,
    public ?string $templateId,
    public string $reasonCode,
    public int $priority
  ) {}

}

