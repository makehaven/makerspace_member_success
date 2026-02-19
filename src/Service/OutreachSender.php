<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\PreparedMessage;
use Drupal\makerspace_member_success\Support\SendResult;

/**
 * Placeholder sender for future provider integrations.
 */
class OutreachSender implements OutreachSenderInterface {

  /**
   * {@inheritdoc}
   */
  public function send(PreparedMessage $message): SendResult {
    return new SendResult(
      FALSE,
      NULL,
      'not_implemented',
      'Automated sending is not enabled. Keep manual approval/send workflow.'
    );
  }

}

