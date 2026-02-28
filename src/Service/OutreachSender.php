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
    // Enable simulated success for test accounts during development/scenario testing.
    if (str_ends_with($message->destination, '@example.com') || str_contains($message->destination, '555-01')) {
      return new SendResult(TRUE, 'simulated_' . time());
    }

    return new SendResult(
      FALSE,
      NULL,
      'not_implemented',
      'Automated sending is not enabled. Keep manual approval/send workflow.'
    );
  }

}

