<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\PreparedMessage;

/**
 * Builds channel-ready outbound messages from queue records.
 */
interface OutreachMessageBuilderInterface {

  /**
   * Builds a prepared message for a queue item.
   */
  public function build(int $queueId): PreparedMessage;

}
