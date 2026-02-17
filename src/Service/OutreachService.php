<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates outreach contact recording for the member success module.
 */
class OutreachService {

  protected Connection $database;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountInterface $currentUser;

  protected LoggerInterface $logger;

  protected CiviCrmActivityLogger $activityLogger;

  /**
   * Constructs an OutreachService object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    CiviCrmActivityLogger $activity_logger
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('makerspace_member_success');
    $this->activityLogger = $activity_logger;
  }

  /**
   * Records a contact attempt and updates all relevant state immediately.
   *
   * @param \Drupal\user\Entity\User $user
   *   The member being contacted.
   * @param string $stage
   *   The member's current lifecycle stage.
   * @param string $contact_method
   *   Contact method (phone, email, sms, in_person, other).
   * @param string $outcome
   *   Contact outcome machine name.
   * @param string $notes
   *   Free-text notes about the contact.
   * @param bool $mark_exhausted
   *   If TRUE, sets outreach_exhausted status regardless of outcome.
   * @param bool $log_in_civicrm
   *   If TRUE, creates a CiviCRM activity for the contact.
   * @param string $cancellation_reason
   *   Optional cancellation reason when outcome is confirmed_cancel.
   *
   * @return array{civicrm_activity_id: int|null, contact_count: int}
   *   Activity ID (if created) and new contact count for messenger feedback.
   */
  public function recordContact(
    User $user,
    string $stage,
    string $contact_method,
    string $outcome,
    string $notes,
    bool $mark_exhausted,
    bool $log_in_civicrm,
    string $cancellation_reason = ''
  ): array {
    $uid = (int) $user->id();
    $today = date('Y-m-d');

    // 1. Compute sleep days and next followup date.
    $sleep_days = MemberSuccessLifecycle::sleepDaysForOutcome($outcome);
    if ($sleep_days > 0) {
      $next_followup_date = date('Y-m-d', strtotime($today . ' +' . $sleep_days . ' days'));
    }
    elseif ($sleep_days === -1) {
      $next_followup_date = '9999-12-31';
    }
    else {
      $next_followup_date = NULL;
    }

    // 2. Compute followup status.
    $followup_status = MemberSuccessLifecycle::followupStatusForOutcome($outcome, $mark_exhausted);

    // 3. Write field_member_followup_status to user entity if status changed.
    if ($followup_status !== NULL && $user->hasField('field_member_followup_status')) {
      $current_status = $user->get('field_member_followup_status')->value;
      if ($followup_status !== $current_status) {
        $user->set('field_member_followup_status', $followup_status);
        $user->save();
        $this->logger->notice(
          'Updated followup status for user @uid from @old to @new (outcome: @outcome)',
          ['@uid' => $uid, '@old' => $current_status ?? 'empty', '@new' => $followup_status, '@outcome' => $outcome]
        );
      }
    }

    // 4. Write field_member_end_reason to profile if confirmed_cancel.
    if ($outcome === MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL && !empty($cancellation_reason)) {
      $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
        'uid' => $uid,
        'type' => 'main',
        'is_default' => TRUE,
        'status' => TRUE,
      ]);
      if (!empty($profiles)) {
        $profile = reset($profiles);
        if ($profile->hasField('field_member_end_reason')) {
          $profile->set('field_member_end_reason', $cancellation_reason);
          $profile->save();
          $this->logger->notice(
            'Updated cancellation reason for user @uid to @reason',
            ['@uid' => $uid, '@reason' => $cancellation_reason]
          );
        }
      }
    }

    // 5. Create CiviCRM activity if requested.
    $civicrm_activity_id = NULL;
    if ($log_in_civicrm) {
      $civicrm_activity_id = $this->activityLogger->logRetentionContact(
        $user,
        $contact_method,
        $outcome,
        $notes
      );
    }

    // 6. Insert row into ms_member_outreach_log.
    try {
      $this->database->insert('ms_member_outreach_log')
        ->fields([
          'uid' => $uid,
          'contact_date' => $today,
          'contact_method' => $contact_method,
          'outcome' => $outcome,
          'notes' => $notes,
          'followup_status' => $followup_status,
          'staff_uid' => $this->currentUser->id(),
          'civicrm_activity_id' => $civicrm_activity_id,
          'sleep_days' => $sleep_days,
          'created_at' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to insert outreach log for uid @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
    }

    // 7. Read current contact count and compute new count.
    $current_count = (int) $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['contact_count'])
      ->condition('ms.uid', $uid)
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->execute()
      ->fetchField();
    $new_count = $current_count + 1;

    // 8. Update snapshot immediately — fixes the outreach_status staleness bug.
    $this->database->update('ms_member_success_snapshot')
      ->fields([
        'last_contact_date' => $today,
        'next_followup_date' => $next_followup_date,
        'contact_count' => $new_count,
        'last_outreach_ts' => time(),
        'outreach_status' => $followup_status,
      ])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute();

    $this->logger->notice(
      'Outreach recorded for uid @uid: @method → @outcome (attempt #@count)',
      ['@uid' => $uid, '@method' => $contact_method, '@outcome' => $outcome, '@count' => $new_count]
    );

    return [
      'civicrm_activity_id' => $civicrm_activity_id,
      'contact_count' => $new_count,
    ];
  }

}
