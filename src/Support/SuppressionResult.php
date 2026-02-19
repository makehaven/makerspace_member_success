<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Data object for suppression checks.
 */
final class SuppressionResult {

  /**
   * Constructs a SuppressionResult.
   */
  public function __construct(
    public bool $allowed,
    public ?string $reasonCode = NULL
  ) {}

}

