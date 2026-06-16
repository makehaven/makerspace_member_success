<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Cache\Cache;
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

  protected CiviFollowupGroupSync $followupGroupSync;

  protected ChargebeeFollowupStatusSync $chargebeeFollowupSync;

  /**
   * Constructs an OutreachService object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    CiviCrmActivityLogger $activity_logger,
    CiviFollowupGroupSync $followup_group_sync,
    ChargebeeFollowupStatusSync $chargebee_followup_sync,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('makerspace_member_success');
    $this->activityLogger = $activity_logger;
    $this->followupGroupSync = $followup_group_sync;
    $this->chargebeeFollowupSync = $chargebee_followup_sync;
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
   * @param int|null $existing_civicrm_activity_id
   *   Existing CiviCRM activity ID to associate with this outreach log row.
   *
   * @return array{civicrm_activity_id: int|null, contact_count: int, chargebee_sync: array|null}
   *   Activity ID (if created), new contact count, and Chargebee sync status.
   */
  public function recordContact(
    User $user,
    string $stage,
    string $contact_method,
    string $outcome,
    string $notes,
    bool $mark_exhausted,
    bool $log_in_civicrm,
    string $cancellation_reason = '',
    ?int $existing_civicrm_activity_id = NULL,
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

    $transaction = $this->database->startTransaction();
    $chargebee_result = NULL;

    try {
      // 3. Write field_member_followup_status to user entity if status changed.
      if ($followup_status !== NULL && $user->hasField('field_member_followup_status')) {
        $current_status = $user->get('field_member_followup_status')->value;
        if ($followup_status !== $current_status) {
          $user->set('field_member_followup_status', $followup_status);
          $user->save();
          $this->followupGroupSync->syncForUser($user, $followup_status);
          $chargebee_result = $this->chargebeeFollowupSync->pushUserStatus($user, $followup_status);
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
      $civicrm_activity_id = $existing_civicrm_activity_id;
      if ($log_in_civicrm && !$civicrm_activity_id) {
        $civicrm_activity_id = $this->activityLogger->logRetentionContact(
          $user,
          $contact_method,
          $outcome,
          $notes
        );
      }

      // 6. Insert row into ms_member_outreach_log.
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

      // 7. Read current contact count (for return value) before incrementing.
      $current_count = (int) $this->database->select('ms_member_success_snapshot', 'ms')
        ->fields('ms', ['contact_count'])
        ->condition('ms.uid', $uid)
        ->condition('ms.is_latest', 1)
        ->condition('ms.snapshot_type', 'daily')
        ->execute()
        ->fetchField();
      $new_count = $current_count + 1;

      // 8. Update snapshot immediately — atomic increment for contact_count.
      //
      // Both outreach_status and member_followup_status are written here.
      // outreach_status is the queue-internal state set by this service.
      // member_followup_status mirrors the Drupal user field and is what
      // the Views queue filters on. They are kept in sync so that queue
      // visibility updates immediately without waiting for the nightly rebuild.
      // buildSnapshotForUser() merges them with an effective-status fallback
      // for the rare case where one column was populated but not the other.
      $this->database->update('ms_member_success_snapshot')
        ->fields([
          'last_contact_date' => $today,
          'next_followup_date' => $next_followup_date,
          'last_outreach_ts' => time(),
          'outreach_status' => $followup_status,
          'member_followup_status' => $followup_status,
        ])
        ->expression('contact_count', 'contact_count + 1')
        ->condition('uid', $uid)
        ->condition('is_latest', 1)
        ->execute();

      // Prevent duplicate outreach: once a manual or auto interaction is logged,
      // pending queue rows for the same member/stage are considered handled.
      $this->markQueueItemsHandled($uid, $stage);

      // Queue views use tag-based caching; direct table writes must explicitly
      // invalidate the view config tag so updated queue visibility is immediate.
      Cache::invalidateTags(['config:views.view.member_success_queue']);

      $this->logger->notice(
        'Outreach recorded for uid @uid: @method → @outcome (attempt #@count)',
        ['@uid' => $uid, '@method' => $contact_method, '@outcome' => $outcome, '@count' => $new_count]
      );

      return [
        'civicrm_activity_id' => $civicrm_activity_id,
        'contact_count' => $new_count,
        'chargebee_sync' => $chargebee_result,
      ];
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error(
        'Outreach recording failed for uid @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
      throw $e;
    }
  }

  /**
   * Marks queued/approved queue rows as sent for this member and stage.
   */
  protected function markQueueItemsHandled(int $uid, string $stage): void {
    $now = time();
    try {
      $this->database->update('ms_member_outreach_queue')
        ->fields([
          'status' => 'sent',
          'sent_at' => $now,
          'updated_at' => $now,
          'failure_code' => NULL,
          'failure_message' => NULL,
        ])
        ->condition('uid', $uid)
        ->condition('stage', $stage)
        ->condition('status', ['queued', 'approved'], 'IN')
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'Unable to sync queue status for uid @uid stage @stage: @message',
        ['@uid' => $uid, '@stage' => $stage, '@message' => $e->getMessage()]
      );
    }
  }

}
