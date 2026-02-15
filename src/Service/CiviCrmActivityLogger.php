<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\civicrm\Civicrm;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Service for creating CiviCRM activities related to member retention.
 */
class CiviCrmActivityLogger {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  /**
   * Constructs a CiviCrmActivityLogger object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    Civicrm $civicrm
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('makerspace_member_success');
    $this->currentUser = $current_user;
    $this->civicrm = $civicrm;
  }

  /**
   * Logs a retention contact in CiviCRM.
   *
   * @param \Drupal\user\Entity\User $target_user
   *   The member being contacted.
   * @param string $method
   *   Contact method (phone, email, in_person, other).
   * @param string $outcome
   *   Outcome of contact (will_return, confirmed_cancel, etc.).
   * @param string $notes
   *   Optional notes about the contact.
   *
   * @return int|null
   *   The created activity ID, or NULL if failed.
   */
  public function logRetentionContact(User $target_user, string $method, string $outcome, string $notes = ''): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrm) {
        $this->logger->error('CiviCRM service not available.');
        return NULL;
      }

      $this->civicrm->initialize();

      // Get target contact ID
      $target_contact_id = $this->getContactId($target_user->id());
      if (!$target_contact_id) {
        $this->logger->error('Could not find CiviCRM contact for user @uid', ['@uid' => $target_user->id()]);
        return NULL;
      }

      // Get source contact ID (staff member logging contact)
      $source_contact_id = $this->getContactId($this->currentUser->id());
      if (!$source_contact_id) {
        // Fallback: use target as source if staff not found
        $source_contact_id = $target_contact_id;
      }

      // Get activity type ID based on method
      $activity_type_id = $this->getActivityTypeId($method);

      // Build activity subject
      $outcome_labels = $this->getOutcomeLabels();
      $outcome_label = $outcome_labels[$outcome] ?? $outcome;
      $subject = "Retention follow-up: {$outcome_label}";

      // Build activity details
      $details = "Contact Method: " . ucfirst($method) . "\n";
      $details .= "Outcome: {$outcome_label}\n";
      if ($notes) {
        $details .= "\nNotes:\n{$notes}";
      }

      // Create activity via APIv3
      $params = [
        'activity_type_id' => $activity_type_id,
        'source_contact_id' => $source_contact_id,
        'target_contact_id' => $target_contact_id,
        'subject' => $subject,
        'details' => $details,
        'activity_date_time' => date('Y-m-d H:i:s'),
        'status_id' => 2, // Completed
        'priority_id' => 2, // Normal
      ];

      $result = civicrm_api3('Activity', 'create', $params);

      if (!empty($result['id'])) {
        $this->logger->info('Created CiviCRM activity @id for user @uid', [
          '@id' => $result['id'],
          '@uid' => $target_user->id(),
        ]);
        return (int) $result['id'];
      }

      return NULL;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM activity: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets CiviCRM contact ID for a Drupal user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return int|null
   *   The CiviCRM contact ID, or NULL if not found.
   */
  public function getContactId(int $uid): ?int {
    try {
      $this->civicrm->initialize();

      $result = civicrm_api3('UFMatch', 'get', [
        'uf_id' => $uid,
        'sequential' => 1,
      ]);

      if (!empty($result['values'][0]['contact_id'])) {
        return (int) $result['values'][0]['contact_id'];
      }

      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get CiviCRM contact ID for uid @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets activity type ID based on contact method.
   *
   * @param string $method
   *   Contact method (phone, email, in_person, other).
   *
   * @return int
   *   The activity type ID.
   */
  protected function getActivityTypeId(string $method): int {
    $config = $this->configFactory->get('makerspace_member_success.settings');

    // Try to get configured activity type
    $config_key = "civicrm_activity_type_{$method}";
    $activity_type_id = $config->get($config_key);

    if ($activity_type_id) {
      return (int) $activity_type_id;
    }

    // Fallback to default activity types
    // These are common CiviCRM defaults, but may vary by installation
    $defaults = [
      'phone' => 3,  // Phone Call
      'email' => 3,  // Email (usually 3, but can be different)
      'in_person' => 1, // Meeting
      'other' => 1,  // Meeting
    ];

    return $defaults[$method] ?? 1;
  }

  /**
   * Gets human-readable outcome labels.
   *
   * @return array
   *   Outcome machine names to labels.
   */
  protected function getOutcomeLabels(): array {
    return [
      'will_return' => 'Member Will Return',
      'confirmed_cancel' => 'Confirmed Cancellation',
      'payment_updated' => 'Payment Method Updated',
      'needs_time' => 'Needs Time to Decide',
      'no_answer' => 'No Answer',
      'left_message' => 'Left Voicemail/Message',
      'email_sent' => 'Email Sent - Awaiting Reply',
      'email_bounced' => 'Email Bounced/Undeliverable',
    ];
  }

  /**
   * Gets available activity types from CiviCRM.
   *
   * @return array
   *   Activity type IDs to labels.
   */
  public function getActivityTypes(): array {
    try {
      $this->civicrm->initialize();

      $result = civicrm_api3('OptionValue', 'get', [
        'option_group_id' => 'activity_type',
        'is_active' => 1,
        'options' => ['limit' => 100],
      ]);

      $types = [];
      if (!empty($result['values'])) {
        foreach ($result['values'] as $type) {
          $types[$type['value']] = $type['label'];
        }
      }

      return $types;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch activity types: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
