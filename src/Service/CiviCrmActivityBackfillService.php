<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\civicrm\Civicrm;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Psr\Log\LoggerInterface;

/**
 * Service to backfill outreach logs from CiviCRM activities.
 */
class CiviCrmActivityBackfillService {

  protected Connection $database;
  protected Civicrm $civicrm;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  public function __construct(
    Connection $database,
    Civicrm $civicrm,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->civicrm = $civicrm;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('makerspace_member_success');
  }

  /**
   * Backfills outreach logs from CiviCRM activities.
   *
   * @param int $days
   *   How many days back to look.
   * @param array $activityTypes
   *   Activity type IDs to include (e.g. [2, 3] for Phone Call, Email).
   * @param bool $dryRun
   *   If TRUE, no database writes will occur.
   *
   * @return array
   *   Summary of processing results.
   */
  public function backfill(int $days = 90, array $activityTypes = [2, 3], bool $dryRun = FALSE): array {
    $summary = [
      'examined' => 0,
      'imported' => 0,
      'skipped_exists' => 0,
      'skipped_no_user' => 0,
      'errors' => 0,
    ];

    try {
      $this->civicrm->initialize();
      $since = date('Y-m-d H:i:s', strtotime("-$days days"));

      // 1. Fetch activities.
      // Note: APIv3 doesn't easily join ActivityContact and UFMatch in one go.
      // We'll fetch activities and then resolve contacts.
      $result = civicrm_api3('Activity', 'get', [
        'activity_type_id' => ['IN' => $activityTypes],
        'activity_date_time' => ['>=' => $since],
        'is_test' => 0,
        'is_deleted' => 0,
        'options' => ['limit' => 0],
      ]);

      if (empty($result['values'])) {
        return $summary;
      }

      foreach ($result['values'] as $id => $act) {
        $summary['examined']++;
        $activity_id = (int) $id;

        // 2. Check if already imported.
        $exists = $this->database->select('ms_member_outreach_log', 'l')
          ->fields('l', ['id'])
          ->condition('civicrm_activity_id', $activity_id)
          ->execute()
          ->fetchField();

        if ($exists) {
          $summary['skipped_exists']++;
          continue;
        }

        // 3. Resolve target member (Contact ID).
        // In CiviCRM, target contacts are in civicrm_activity_contact with record_type_id = 3 (Activity Targets).
        $contacts = civicrm_api3('ActivityContact', 'get', [
          'activity_id' => $activity_id,
          'record_type_id' => 'Activity Targets',
          'sequential' => 1,
        ]);

        if (empty($contacts['values'])) {
          // Fallback to all activity contacts if no targets found (sometimes used for simple logs)
          $contacts = civicrm_api3('ActivityContact', 'get', [
            'activity_id' => $activity_id,
            'sequential' => 1,
          ]);
        }

        if (empty($contacts['values'])) {
          continue;
        }

        foreach ($contacts['values'] as $ac) {
          $contact_id = (int) $ac['contact_id'];

          // 4. Resolve Drupal UID.
          $uid = $this->database->select('civicrm_uf_match', 'uf')
            ->fields('uf', ['uf_id'])
            ->condition('contact_id', $contact_id)
            ->execute()
            ->fetchField();

          if (!$uid) {
            $summary['skipped_no_user']++;
            continue;
          }

          // 5. Map fields.
          $method_map = [
            2 => 'phone',
            3 => 'email',
          // Meeting/In-person.
            1 => 'meeting',
          ];
          $method = $method_map[$act['activity_type_id']] ?? 'other';
          $date = substr($act['activity_date_time'], 0, 10);

          // Heuristic for outcome:
          // Since CiviCRM doesn't have our specific outcomes, we assume 'no_answer'
          // unless keywords suggest otherwise.
          $outcome = MemberSuccessLifecycle::OUTCOME_NO_ANSWER;
          $subject = strtolower($act['subject'] ?? '');
          if (str_contains($subject, 'spoke') || str_contains($subject, 'connected') || str_contains($subject, 'resolved')) {
            // Conservative success.
            $outcome = MemberSuccessLifecycle::OUTCOME_WILL_RETURN;
          }
          if (str_contains($subject, 'cancel')) {
            $outcome = MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL;
          }

          if (!$dryRun) {
            $this->database->insert('ms_member_outreach_log')
              ->fields([
                'uid' => (int) $uid,
                'contact_date' => $date,
                'contact_method' => $method,
                'outcome' => $outcome,
                'notes' => '[BACKFILL] ' . ($act['subject'] ?? '') . "

" . strip_tags($act['details'] ?? ''),
                'followup_status' => MemberSuccessLifecycle::followupStatusForOutcome($outcome),
            // Default to admin for backfills.
                'staff_uid' => 1,
                'civicrm_activity_id' => $activity_id,
                'created_at' => strtotime($act['activity_date_time']),
                'sleep_days' => MemberSuccessLifecycle::sleepDaysForOutcome($outcome),
              ])
              ->execute();
            $summary['imported']++;
          }
          else {
            $summary['imported']++;
          }

          // Process one contact per activity for the log (standard case)
          break;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Backfill error: @message', ['@message' => $e->getMessage()]);
      $summary['errors']++;
    }

    return $summary;
  }

}
