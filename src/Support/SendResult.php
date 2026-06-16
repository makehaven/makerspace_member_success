<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Data object describing send status.
 */
final class SendResult {

  /**
   * Constructs a SendResult.
   */
  public function __construct(
    public bool $success,
    public ?string $providerMessageId = NULL,
    public ?string $failureCode = NULL,
    public ?string $failureMessage = NULL,
  ) {}

}
