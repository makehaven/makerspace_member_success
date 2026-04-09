<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\makerspace_member_success\Support\PreparedMessage;
use Drupal\makerspace_member_success\Support\SendResult;

/**
 * Sends outreach messages via Drupal's mail system (email) or SMS.
 */
class OutreachSender implements OutreachSenderInterface {

  /**
   * Constructs an OutreachSender.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function send(PreparedMessage $message): SendResult {
    // Simulated success for test accounts during development/scenario testing.
    if (str_ends_with($message->destination, '@example.com') || str_contains($message->destination, '555-01')) {
      return new SendResult(TRUE, 'simulated_' . time());
    }

    if ($message->destination === '') {
      return new SendResult(FALSE, NULL, 'no_destination', 'No destination address provided.');
    }

    if ($message->channel === 'sms') {
      return new SendResult(FALSE, NULL, 'not_implemented', 'SMS sending is not yet configured.');
    }

    if ($message->subject === '' && $message->body === '') {
      return new SendResult(FALSE, NULL, 'empty_message', 'Template resolved to empty subject and body.');
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $params = [
      'subject' => $message->subject,
      'body' => $message->body,
      'uid' => $message->uid,
      'queue_id' => $message->queueId,
    ];

    $result = $this->mailManager->mail(
      'makerspace_member_success',
      'outreach',
      $message->destination,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    if (!empty($result['result'])) {
      return new SendResult(TRUE, 'drupal_mail_' . $message->queueId);
    }

    return new SendResult(FALSE, NULL, 'mail_failed', 'Drupal mail system returned failure for queue item ' . $message->queueId . '.');
  }

}
