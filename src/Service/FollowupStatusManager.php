<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Psr\Log\LoggerInterface;

/**
 * Applies followup status updates without requiring a contact interaction.
 */
class FollowupStatusManager {

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CiviFollowupGroupSync $followupGroupSync,
    protected ChargebeeFollowupStatusSync $chargebeeFollowupSync,
    protected NeedsReviewNotificationService $needsReviewNotification,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected TimeInterface $time,
    protected LoggerInterface $logger
  ) {}

  /**
   * Applies a no-contact followup status by queue row id.
   */
  public function applyNoContactStatusByQueueId(int $queue_id, string $followup_status, string $reason_code): bool {
    $row = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q', ['id', 'uid', 'stage'])
      ->condition('q.id', $queue_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return FALSE;
    }

    return $this->applyNoContactStatusByMemberStage(
      (int) $row['uid'],
      (string) $row['stage'],
      $followup_status,
      $reason_code
    );
  }

  /**
   * Applies a no-contact followup status for one user + stage.
   */
  public function applyNoContactStatusByMemberStage(int $uid, string $stage, string $followup_status, string $reason_code): bool {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $user = $user_storage->load($uid);
    if (!$user) {
      return FALSE;
    }

    if ($user->hasField('field_member_followup_status')) {
      $user->set('field_member_followup_status', $followup_status);
      $user->save();
      $this->followupGroupSync->syncForUser($user, $followup_status);
      $this->chargebeeFollowupSync->pushUserStatus($user, $followup_status);
    }
    elseif ($user->hasField('field_chargebee_followup')) {
      // Backward-compatible fallback for older environments.
      $user->set('field_chargebee_followup', $followup_status);
      $user->save();
      $this->followupGroupSync->syncForUser($user, $followup_status);
      $this->chargebeeFollowupSync->pushUserStatus($user, $followup_status);
    }

    // Keep latest snapshot state aligned with user-level followup status.
    $this->database->update('ms_member_success_snapshot')
      ->fields([
        'member_followup_status' => $followup_status,
        'outreach_status' => $followup_status,
      ])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->condition('snapshot_type', 'daily')
      ->execute();

    // Suppress open queue rows for the same member + stage.
    $this->database->update('ms_member_outreach_queue')
      ->fields([
        'status' => 'suppressed',
        'suppression_reason_code' => $reason_code,
        'updated_at' => $this->time->getCurrentTime(),
      ])
      ->condition('uid', $uid)
      ->condition('stage', $stage)
      ->condition('status', ['queued', 'approved', 'failed', 'cancelled'], 'IN')
      ->execute();

    $this->logger->notice(
      'Set followup status to @status without interaction for uid @uid (stage: @stage).',
      ['@status' => $followup_status, '@uid' => $uid, '@stage' => $stage]
    );

    if ($followup_status === MemberSuccessLifecycle::FOLLOWUP_NEEDS_REVIEW) {
      $this->needsReviewNotification->notify($user, $stage);
    }

    $this->cacheTagsInvalidator->invalidateTags(['config:views.view.member_success_queue']);

    return TRUE;
  }

}
