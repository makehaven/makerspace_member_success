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
   * Pulls followup status from Chargebee into Drupal for one user.
   */
  public function pullUserStatus(
    User $user,
    bool $dryRun = FALSE,
    bool $overwrite = FALSE
  ): array {
    $result = [
      'uid' => (int) $user->id(),
      'customer_id' => '',
      'chargebee_value' => NULL,
      'mapped_status' => NULL,
      'current_status' => NULL,
      'subscriptions_examined' => 0,
      'updated' => 0,
      'changed' => FALSE,
      'dry_run' => $dryRun,
      'skipped_reason' => NULL,
    ];

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

    $followupField = $this->resolveDrupalFollowupField($user);
    if ($followupField === NULL) {
      $result['skipped_reason'] = 'missing_followup_field';
      return $result;
    }

    $settings = $this->configFactory->get('makerspace_member_success.settings');
    $fieldParam = trim((string) ($settings->get('chargebee_followup_push_field_param') ?? 'cf_Cancelation_Followup'));
    if ($fieldParam === '') {
      $fieldParam = 'cf_Cancelation_Followup';
    }

    try {
      $subscriptions = $this->fetchCustomerSubscriptions($customerId);
      $result['subscriptions_examined'] = count($subscriptions);
      if (empty($subscriptions)) {
        $result['skipped_reason'] = 'no_subscriptions';
        return $result;
      }

      $chargebeeValue = $this->selectChargebeeFollowupValue($subscriptions, $fieldParam);
      $result['chargebee_value'] = $chargebeeValue;
      if ($chargebeeValue === NULL) {
        $result['skipped_reason'] = 'no_chargebee_followup';
        return $result;
      }

      $mappedStatus = $this->mapFromChargebeeValue($chargebeeValue);
      $result['mapped_status'] = $mappedStatus;
      if ($mappedStatus === NULL) {
        $result['skipped_reason'] = 'unmapped_chargebee_value';
        return $result;
      }

      $currentStatus = trim((string) $user->get($followupField)->value);
      $result['current_status'] = $currentStatus !== '' ? $currentStatus : NULL;
      if ($currentStatus !== '' && $currentStatus !== $mappedStatus && !$overwrite) {
        $result['skipped_reason'] = 'existing_value_present';
        return $result;
      }

      if ($currentStatus === $mappedStatus) {
        $result['skipped_reason'] = 'already_synced';
        return $result;
      }

      $result['changed'] = TRUE;
      if ($dryRun) {
        return $result;
      }

      $user->set($followupField, $mappedStatus);
      $user->save();
      $result['updated'] = 1;
    }
    catch (\RuntimeException $e) {
      $reason = $e->getMessage();
      if ($reason === 'missing_chargebee_config' || $reason === 'invalid_chargebee_url') {
        $result['skipped_reason'] = $reason;
        return $result;
      }
      $result['skipped_reason'] = 'request_failed';
      $result['error'] = $e->getMessage();
    }
    catch (\Exception $e) {
      $this->logger->warning('Chargebee followup pull failed for uid @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
      $result['skipped_reason'] = 'request_failed';
      $result['error'] = $e->getMessage();
    }

    return $result;
  }

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

    try {
      [$baseUrl, $auth] = $this->buildChargebeeAuthContext();
      $rows = $this->fetchCustomerSubscriptions($customerId, $baseUrl, $auth);
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
    catch (\RuntimeException $e) {
      $reason = $e->getMessage();
      if ($reason === 'missing_chargebee_config' || $reason === 'invalid_chargebee_url') {
        $result['skipped_reason'] = $reason;
        return $result;
      }
      $result['skipped_reason'] = 'request_failed';
      $result['error'] = $e->getMessage();
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
   * Maps Chargebee followup labels to Drupal machine names.
   */
  protected function mapFromChargebeeValue(?string $chargebeeValue): ?string {
    $value = trim((string) $chargebeeValue);
    if ($value === '') {
      return NULL;
    }

    return match (strtolower($value)) {
      'outreach active' => MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE,
      'outreach exhausted' => MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      'return intent' => MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT,
      'confirmed cancellation' => MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
      'no action needed' => MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED,
      default => NULL,
    };
  }

  /**
   * Returns configured followup field available on user account.
   */
  protected function resolveDrupalFollowupField(User $user): ?string {
    if ($user->hasField('field_member_followup_status')) {
      return 'field_member_followup_status';
    }
    if ($user->hasField('field_chargebee_followup')) {
      return 'field_chargebee_followup';
    }
    return NULL;
  }

  /**
   * Chooses the best Chargebee followup value from subscriptions.
   */
  protected function selectChargebeeFollowupValue(array $rows, string $fieldParam): ?string {
    $priority = [
      'active' => 0,
      'in_trial' => 1,
      'future' => 2,
      'non_renewing' => 3,
      'paused' => 4,
      'cancelled' => 5,
    ];

    $best = NULL;
    foreach ($rows as $row) {
      $subscription = is_array($row['subscription'] ?? NULL) ? $row['subscription'] : [];
      $candidateValue = trim((string) ($subscription[$fieldParam] ?? ''));
      if ($candidateValue === '') {
        continue;
      }

      $status = strtolower((string) ($subscription['status'] ?? ''));
      $rank = $priority[$status] ?? 99;
      $updatedAt = (int) ($subscription['updated_at'] ?? 0);

      if (
        $best === NULL
        || $rank < $best['rank']
        || ($rank === $best['rank'] && $updatedAt > $best['updated_at'])
      ) {
        $best = [
          'value' => $candidateValue,
          'rank' => $rank,
          'updated_at' => $updatedAt,
        ];
      }
    }

    return $best['value'] ?? NULL;
  }

  /**
   * Builds API base URL and authorization header value.
   *
   * @return array{0:string,1:string}
   *   Base URL and authorization header value.
   */
  protected function buildChargebeeAuthContext(): array {
    $chargebee = $this->configFactory->get('chargebee_portal.settings');
    $apiKey = trim((string) $chargebee->get('live_api_key'));
    $portalUrl = trim((string) $chargebee->get('live_portal_url'));
    if ($apiKey === '' || $portalUrl === '') {
      throw new \RuntimeException('missing_chargebee_config');
    }

    $baseUrl = $this->extractBaseUrl($portalUrl);
    if ($baseUrl === '') {
      throw new \RuntimeException('invalid_chargebee_url');
    }

    return [$baseUrl, 'Basic ' . base64_encode($apiKey . ':')];
  }

  /**
   * Fetches subscriptions for a Chargebee customer.
   *
   * @return array<int, array<string,mixed>>
   *   Chargebee list rows.
   */
  protected function fetchCustomerSubscriptions(string $customerId, ?string $baseUrl = NULL, ?string $auth = NULL): array {
    if ($baseUrl === NULL || $auth === NULL) {
      [$baseUrl, $auth] = $this->buildChargebeeAuthContext();
    }

    $response = $this->httpClient->request('GET', $baseUrl . '/api/v2/subscriptions', [
      'headers' => ['Authorization' => $auth],
      'query' => [
        'customer_id[is]' => $customerId,
        'limit' => 100,
      ],
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE);
    return is_array($payload['list'] ?? NULL) ? $payload['list'] : [];
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
