<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Pushes member followup status updates to Chargebee subscription custom fields.
 */
class ChargebeeFollowupStatusSync {

  /**
   * Constructs a ChargebeeFollowupStatusSync service.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Pushes followup status to Chargebee for all subscriptions of a customer.
   */
  public function pushUserStatus(
    User $user,
    ?string $followupStatus,
    bool $force = FALSE,
    bool $dryRun = FALSE
  ): array {
    $result = [
      'uid' => (int) $user->id(),
      'customer_id' => '',
      'mapped_value' => NULL,
      'subscriptions_examined' => 0,
      'subscriptions_updated' => 0,
      'subscriptions_skipped_same' => 0,
      'dry_run' => $dryRun,
      'skipped_reason' => NULL,
    ];

    $settings = $this->configFactory->get('makerspace_member_success.settings');
    if (!$force && !(bool) $settings->get('chargebee_followup_push_enabled')) {
      $result['skipped_reason'] = 'push_disabled';
      return $result;
    }

    if (!$user->hasField('field_user_chargebee_id') || $user->get('field_user_chargebee_id')->isEmpty()) {
      $result['skipped_reason'] = 'missing_chargebee_id';
      return $result;
    }
    $customerId = trim((string) $user->get('field_user_chargebee_id')->value);
    if ($customerId === '') {
      $result['skipped_reason'] = 'missing_chargebee_id';
      return $result;
    }
    $result['customer_id'] = $customerId;

    $chargebee = $this->configFactory->get('chargebee_portal.settings');
    $apiKey = trim((string) $chargebee->get('live_api_key'));
    $portalUrl = trim((string) $chargebee->get('live_portal_url'));
    if ($apiKey === '' || $portalUrl === '') {
      $this->logger->warning('Chargebee push skipped for uid @uid: missing API key or portal URL.', ['@uid' => $user->id()]);
      $result['skipped_reason'] = 'missing_chargebee_config';
      return $result;
    }

    $fieldParam = trim((string) ($settings->get('chargebee_followup_push_field_param') ?? 'cf_Cancelation_Followup'));
    if ($fieldParam === '') {
      $fieldParam = 'cf_Cancelation_Followup';
    }

    $cbValue = $this->mapToChargebeeValue($followupStatus);
    $result['mapped_value'] = $cbValue;
    if ($cbValue === NULL) {
      $result['skipped_reason'] = 'unmapped_followup_status';
      return $result;
    }

    $baseUrl = $this->extractBaseUrl($portalUrl);
    if ($baseUrl === '') {
      $this->logger->warning('Chargebee push skipped for uid @uid: invalid portal URL.', ['@uid' => $user->id()]);
      $result['skipped_reason'] = 'invalid_chargebee_url';
      return $result;
    }

    $auth = 'Basic ' . base64_encode($apiKey . ':');

    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/subscriptions', [
        'headers' => ['Authorization' => $auth],
        'query' => [
          'customer_id[is]' => $customerId,
          'limit' => 100,
        ],
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE);
      $rows = is_array($payload['list'] ?? NULL) ? $payload['list'] : [];
      $result['subscriptions_examined'] = count($rows);
      foreach ($rows as $row) {
        $subscription = is_array($row['subscription'] ?? NULL) ? $row['subscription'] : [];
        $subscriptionId = trim((string) ($subscription['id'] ?? ''));
        if ($subscriptionId === '') {
          continue;
        }

        $current = $subscription[$fieldParam] ?? NULL;
        if ((string) $current === $cbValue) {
          $result['subscriptions_skipped_same']++;
          continue;
        }

        if ($dryRun) {
          $result['subscriptions_updated']++;
          continue;
        }

        $this->updateSubscriptionFollowupField($baseUrl, $auth, $subscriptionId, $fieldParam, $cbValue);
        $result['subscriptions_updated']++;
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Chargebee followup push failed for uid @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
      $result['skipped_reason'] = 'request_failed';
      $result['error'] = $e->getMessage();
    }
    return $result;
  }

  /**
   * Maps Drupal followup machine names to Chargebee labels.
   */
  protected function mapToChargebeeValue(?string $followupStatus): ?string {
    if ($followupStatus === NULL || $followupStatus === '') {
      // Do not push empty/no-followup state by default.
      return NULL;
    }

    return match ($followupStatus) {
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE => 'Outreach Active',
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED => 'Outreach Exhausted',
      MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT => 'Return Intent',
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION => 'Confirmed Cancellation',
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED => 'No Action Needed',
      default => NULL,
    };
  }

  /**
   * Extracts scheme + host from Chargebee portal URL.
   */
  protected function extractBaseUrl(string $portalUrl): string {
    $parsed = parse_url($portalUrl);
    if (!isset($parsed['scheme'], $parsed['host'])) {
      return '';
    }
    return $parsed['scheme'] . '://' . $parsed['host'];
  }

  /**
   * Updates followup custom field with catalog-version compatible endpoint.
   */
  protected function updateSubscriptionFollowupField(
    string $baseUrl,
    string $auth,
    string $subscriptionId,
    string $fieldParam,
    string $value
  ): void {
    $encodedId = rawurlencode($subscriptionId);
    $form = [$fieldParam => $value];

    // Product Catalog 2.0 endpoint.
    $pc2Url = $baseUrl . '/api/v2/subscriptions/' . $encodedId . '/update_for_items';
    try {
      $this->httpClient->request('POST', $pc2Url, [
        'headers' => ['Authorization' => $auth],
        'form_params' => $form,
      ]);
      return;
    }
    catch (\Exception $e) {
      $message = $e->getMessage();
      // Fallback for Product Catalog 1.0 incompatibility.
      if (
        stripos($message, 'incompatible with the product catalog version') === FALSE
        && stripos($message, 'product catalog 2.0') === FALSE
      ) {
        throw $e;
      }
    }

    // Product Catalog 1.0 endpoint.
    $pc1Url = $baseUrl . '/api/v2/subscriptions/' . $encodedId;
    $this->httpClient->request('POST', $pc1Url, [
      'headers' => ['Authorization' => $auth],
      'form_params' => $form,
    ]);
  }

}
