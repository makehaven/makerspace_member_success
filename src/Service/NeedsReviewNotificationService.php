<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends notifications when a member is routed to needs review.
 */
class NeedsReviewNotificationService {

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the notification service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected AccountProxyInterface $currentUser,
    protected LanguageManagerInterface $languageManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * Sends notification emails to configured reviewers.
   */
  public function notify(UserInterface $user, string $stage): void {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    if (!(bool) $config->get('needs_review_notification_enabled')) {
      return;
    }

    $recipients = array_values(array_filter(array_map('trim', (array) $config->get('needs_review_notification_emails'))));
    if (empty($recipients)) {
      return;
    }

    $params = [
      'member_name' => $user->getDisplayName(),
      'member_email' => $user->getEmail(),
      'member_uid' => (int) $user->id(),
      'stage' => $stage,
      'actor_uid' => (int) $this->currentUser->id(),
      'review_queue_url' => Url::fromRoute('makerspace_member_success.needs_review_queue', [], ['absolute' => TRUE])->toString(),
      'member_url' => $user->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $site_mail = (string) ($this->configFactory->get('system.site')->get('mail') ?? '');

    foreach ($recipients as $to) {
      $result = $this->mailManager->mail(
        'makerspace_member_success',
        'needs_review_notification',
        $to,
        $langcode,
        $params,
        $site_mail !== '' ? $site_mail : NULL,
        TRUE
      );
      if (empty($result['result'])) {
        $this->logger->warning('Failed sending needs-review notification for uid @uid to @to.', [
          '@uid' => $user->id(),
          '@to' => $to,
        ]);
      }
    }
  }

}
