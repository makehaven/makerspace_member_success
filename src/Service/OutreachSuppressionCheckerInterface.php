<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\SuppressionResult;

/**
 * Evaluates whether a channel is allowed for a member.
 */
interface OutreachSuppressionCheckerInterface {

  /**
   * Returns whether outreach via channel is allowed.
   */
  public function check(int $uid, string $channel, array $context = []): SuppressionResult;

}

