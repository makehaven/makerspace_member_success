<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\civicrm\Civicrm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Syncs member followup status to configured CiviCRM groups.
 */
class CiviFollowupGroupSync {

  /**
   * Constructs a CiviFollowupGroupSync service.
   */
  public function __construct(
    protected Civicrm $civicrm,
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
   * Applies followup-status group sync for one user.
   */
  public function syncForUser(User $user, ?string $followupStatus): void {
    $groupMap = $this->getConfiguredGroupMap();
    if (empty($groupMap)) {
      return;
    }

    $contactId = $this->getContactId((int) $user->id());
    if (!$contactId) {
      return;
    }

    $targetGroupId = $followupStatus !== NULL ? ($groupMap[$followupStatus] ?? NULL) : NULL;
    $allGroupIds = array_values(array_unique(array_filter(array_values($groupMap))));

    try {
      $this->civicrm->initialize();
      foreach ($allGroupIds as $groupId) {
        if ($targetGroupId && (int) $groupId === (int) $targetGroupId) {
          // Ensure membership in mapped group for current followup status.
          $this->civicrmApi('GroupContact', 'create', [
            'contact_id' => $contactId,
            'group_id' => (int) $groupId,
            'status' => 'Added',
          ]);
        }
        else {
          // Remove from other mapped groups to keep a single current state.
          try {
            $this->civicrmApi('GroupContact', 'delete', [
              'contact_id' => $contactId,
              'group_id' => (int) $groupId,
            ]);
          }
          catch (\Exception) {
            // Ignore if membership did not exist.
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed followup group sync for uid @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Returns configured map of followup status => CiviCRM group ID.
   *
   * @return array<string, int>
   *   Group mapping keyed by followup status machine name.
   */
  protected function getConfiguredGroupMap(): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $map = [
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE => (int) ($config->get('civicrm_group_outreach_active') ?? 0),
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED => (int) ($config->get('civicrm_group_outreach_exhausted') ?? 0),
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED => (int) ($config->get('civicrm_group_no_action_needed') ?? 0),
      MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT => (int) ($config->get('civicrm_group_return_intent') ?? 0),
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION => (int) ($config->get('civicrm_group_confirmed_cancellation') ?? 0),
    ];

    return array_filter($map, static fn(int $groupId): bool => $groupId > 0);
  }

  /**
   * Gets CiviCRM contact ID for a Drupal user ID.
   */
  protected function getContactId(int $uid): ?int {
    try {
      $this->civicrm->initialize();
      $result = $this->civicrmApi('UFMatch', 'get', [
        'uf_id' => $uid,
        'sequential' => 1,
      ]);
      if (!empty($result['values'][0]['contact_id'])) {
        return (int) $result['values'][0]['contact_id'];
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed loading Civi contact for uid @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  /**
   * Wrapper around civicrm_api3 for testability.
   */
  protected function civicrmApi(string $entity, string $action, array $params): array {
    return civicrm_api3($entity, $action, $params);
  }

}
