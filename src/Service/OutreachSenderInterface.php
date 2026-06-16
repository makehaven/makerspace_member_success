<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\PreparedMessage;
use Drupal\makerspace_member_success\Support\SendResult;

/**
 * Sends prepared messages via configured providers.
 */
interface OutreachSenderInterface {

  /**
   * Sends a prepared message.
   */
  public function send(PreparedMessage $message): SendResult;

}
