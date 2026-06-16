<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\user\UserInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessRiskScorer;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service to simulate scenarios for member success testing.
 */
class MemberSuccessScenarioSimulator {

  protected Connection $database;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected TimeInterface $time;
  protected OutreachQueueServiceInterface $queueService;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    OutreachQueueServiceInterface $queue_service,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->queueService = $queue_service;
    $this->configFactory = $config_factory;
  }

  /**
   * Loads persona definitions from JSON file.
   */
  public function getPersonas(): array {
    $module_path = \Drupal::service('extension.list.module')->getPath('makerspace_member_success');
    $file_path = DRUPAL_ROOT . '/' . $module_path . '/personas.json';
    if (!file_exists($file_path)) {
      return [];
    }
    return json_decode(file_get_contents($file_path), TRUE) ?: [];
  }

  /**
   * Creates a test user for a specific persona.
   */
  public function createTestUser(string $personaName): int {
    $personas = $this->getPersonas();
    if (!isset($personas[$personaName])) {
      throw new \InvalidArgumentException("Persona $personaName not found.");
    }
    $persona = $personas[$personaName];

    // Create user.
    $rand = bin2hex(random_bytes(4));
    $user = $this->entityTypeManager->getStorage('user')->create([
      'name' => 'test_' . $personaName . '_' . time() . '_' . $rand,
      'mail' => 'test_' . $personaName . '_' . time() . '_' . $rand . '@example.com',
      'status' => 1,
      'roles' => ['member'],
    ]);
    $user->save();
    $uid = (int) $user->id();

    $this->upsertSnapshotForPersona($uid, $persona['snapshot']);
    $this->syncQueue($uid);

    return $uid;
  }

  /**
   * Updates/Creates a snapshot row for a specific persona.
   */
  public function upsertSnapshotForPersona(int $uid, array $personaSnapshot, ?int $now_ts = NULL): void {
    $now_ts = $now_ts ?: $this->time->getRequestTime();
    $snapshot_date = date('Y-m-d', $now_ts);

    $row = [
      'uid' => $uid,
      'snapshot_date' => $snapshot_date,
      'snapshot_type' => 'daily',
      'is_latest' => 1,
      'created_at' => $now_ts,
    ];

    // Map offsets to dates/timestamps.
    $fields_to_map = [
      'join_date_offset' => ['field' => 'join_date', 'type' => 'date'],
      'activation_ts_offset' => ['field' => 'activation_ts', 'type' => 'ts'],
      'last_visit_ts_offset' => ['field' => 'last_visit_ts', 'type' => 'ts'],
      'last_badge_ts_offset' => ['field' => 'last_badge_ts', 'type' => 'ts'],
      'pause_start_date_offset' => ['field' => 'pause_start_date', 'type' => 'date'],
      'last_contact_date_offset' => ['field' => 'last_contact_date', 'type' => 'date'],
      'next_followup_date_offset' => ['field' => 'next_followup_date', 'type' => 'date'],
    ];

    foreach ($fields_to_map as $offset_key => $map) {
      if (isset($personaSnapshot[$offset_key])) {
        $offset = (int) $personaSnapshot[$offset_key];
        if ($map['type'] === 'date') {
          $row[$map['field']] = date('Y-m-d', $now_ts + ($offset * 86400));
        }
        else {
          $row[$map['field']] = $now_ts + ($offset * 86400);
        }
      }
    }

    // Direct mapping for other fields.
    $direct_fields = [
      'stage', 'risk_score', 'door_badge_status', 'serial_number_present', 'badge_count_total',
      'badge_count_window', 'visit_count_30d', 'payment_failed', 'payment_pause',
      'tenure_bucket', 'outreach_status', 'contact_count', 'member_followup_status',
      'civicrm_do_not_email', 'civicrm_do_not_phone', 'civicrm_do_not_sms', 'civicrm_do_not_mail',
    ];
    foreach ($direct_fields as $f) {
      if (isset($personaSnapshot[$f])) {
        $row[$f] = $personaSnapshot[$f];
      }
    }

    // Temporary fields for logic but not DB columns.
    if (isset($personaSnapshot['sms_consent'])) {
      $row['sms_consent'] = $personaSnapshot['sms_consent'];
    }

    // Default values if missing.
    $row['stage'] = $row['stage'] ?? MemberSuccessLifecycle::STAGE_ONBOARDING;
    $row['door_badge_status'] = $row['door_badge_status'] ?? 'pending';
    $row['risk_score'] = 0;
    $row['risk_reasons'] = [];

    // Re-calculate risk score.
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $badge_one_days = (int) ($config->get('badge_one_days') ?? 60);
    $badge_four_days = (int) ($config->get('badge_four_days') ?? 180);
    $badge_watch_days = (int) ($config->get('badge_watch_days') ?? 30);
    $recency_days = (array) ($config->get('retention_recency_days') ?? [30, 60, 90]);

    // Format data for calculate()
    $calc_data = $row;
    $calc_data['serial_present'] = $row['serial_number_present'] ?? 0;
    $calc_data['activation_ts'] = $row['activation_ts'] ?? NULL;

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($calc_data, $badge_one_days, $badge_four_days, $recency_days, $now_ts, $badge_watch_days);
    $row['risk_score'] = $score;
    $row['risk_reasons'] = serialize($reasons);

    // Remove temporary keys that are not in the database schema.
    unset($row['activation_ts'], $row['sms_consent']);

    // Reset is_latest for this user.
    $this->database->update('ms_member_success_snapshot')
      ->fields(['is_latest' => 0])
      ->condition('uid', $uid)
      ->execute();

    // Upsert.
    $this->database->merge('ms_member_success_snapshot')
      ->keys([
        'uid' => $uid,
        'snapshot_date' => $snapshot_date,
        'snapshot_type' => 'daily',
      ])
      ->fields($row)
      ->execute();
  }

  /**
   * Advances time for a user by shifting all dates/timestamps back.
   */
  public function advanceTime(int $uid, int $days): void {
    $snapshot = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms')
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute()
      ->fetchAssoc();

    if (!$snapshot) {
      throw new \Exception("No snapshot found for user $uid.");
    }

    $days_sec = $days * 86400;

    // Shift dates.
    $date_fields = ['snapshot_date', 'join_date', 'pause_start_date', 'last_contact_date', 'next_followup_date'];
    foreach ($date_fields as $f) {
      if (!empty($snapshot[$f]) && $snapshot[$f] !== '9999-12-31') {
        $snapshot[$f] = date('Y-m-d', strtotime($snapshot[$f]) - $days_sec);
      }
    }

    // Shift timestamps.
    $ts_fields = ['activation_ts', 'last_badge_ts', 'last_visit_ts', 'last_outreach_ts', 'created_at'];
    foreach ($ts_fields as $f) {
      if (!empty($snapshot[$f])) {
        $snapshot[$f] = (int) $snapshot[$f] - $days_sec;
      }
    }

    // Re-upsert (this will recalculate risk based on NOW)
    // Actually, we want to simulate moving time forward relative to NOW.
    // If we shift the snapshot dates BACK, it means from the perspective of TODAY,
    // more time has passed since those events.
    $this->upsertSnapshotFromData($uid, $snapshot);
    $this->syncQueue($uid);
  }

  /**
   * Helper to upsert a snapshot from a raw data array.
   */
  protected function upsertSnapshotFromData(int $uid, array $data): void {
    unset($data['id']);
    if (isset($data['risk_reasons'])) {
      $data['risk_reasons'] = @unserialize($data['risk_reasons']) ?: [];
    }

    // Convert serial_number_present to serial_present for scorer.
    $data['serial_present'] = $data['serial_number_present'] ?? 0;

    $config = $this->configFactory->get('makerspace_member_success.settings');
    $badge_one_days = (int) ($config->get('badge_one_days') ?? 60);
    $badge_four_days = (int) ($config->get('badge_four_days') ?? 180);
    $badge_watch_days = (int) ($config->get('badge_watch_days') ?? 30);
    $recency_days = (array) ($config->get('retention_recency_days') ?? [30, 60, 90]);
    $now_ts = $this->time->getRequestTime();

    [$score, $reasons] = MemberSuccessRiskScorer::calculate($data, $badge_one_days, $badge_four_days, $recency_days, $now_ts, $badge_watch_days);
    $data['risk_score'] = $score;
    $data['risk_reasons'] = serialize($reasons);
    $data['snapshot_date'] = date('Y-m-d', $now_ts);
    $data['is_latest'] = 1;

    // Remove temporary keys used for calculation.
    unset($data['serial_present'], $data['activation_ts'], $data['sms_consent']);

    // Reset is_latest for this user.
    $this->database->update('ms_member_success_snapshot')
      ->fields(['is_latest' => 0])
      ->condition('uid', $uid)
      ->execute();

    $this->database->merge('ms_member_success_snapshot')
      ->keys([
        'uid' => $uid,
        'snapshot_date' => $data['snapshot_date'],
        'snapshot_type' => 'daily',
      ])
      ->fields($data)
      ->execute();
  }

  /**
   * Syncs the outreach queue for a user.
   */
  public function syncQueue(int $uid): int {
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->fields('ms');
    $query->innerJoin('users_field_data', 'u', 'u.uid = ms.uid');
    $query->addField('u', 'mail', 'email');

    // Join CiviCRM data for consent fields that aren't in the snapshot table.
    $query->leftJoin('civicrm_uf_match', 'uf', 'uf.uf_id = ms.uid');
    $query->leftJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->addField('c', 'is_opt_out', 'is_opt_out');
    $query->leftJoin('civicrm_phone', 'p', 'p.contact_id = c.id AND p.is_primary = 1');
    $query->addField('p', 'phone', 'phone');

    [$sms_table, $sms_column] = $this->loadSmsConsentMetadata();
    if ($sms_table && $sms_column) {
      $query->leftJoin($sms_table, 'spv', 'spv.entity_id = c.id');
      $query->addField('spv', $sms_column, 'sms_consent');
    }

    $query->condition('ms.uid', $uid);

    $query->condition('ms.is_latest', 1);

    $snapshot = $query->execute()->fetchAssoc();

    if (!$snapshot) {
      return 0;
    }

    // The OutreachQueueService expects a specific array shape.
    $snapshot['risk_reasons'] = @unserialize($snapshot['risk_reasons']) ?: [];

    return $this->queueService->enqueueCandidate($uid, $snapshot['stage'], $snapshot);
  }

  /**
   * Resets simulation data for a user.
   */
  public function resetUser(int $uid): void {
    $this->database->delete('ms_member_success_snapshot')->condition('uid', $uid)->execute();
    $this->database->delete('ms_member_outreach_queue')->condition('uid', $uid)->execute();
    $this->database->delete('ms_member_outreach_log')->condition('uid', $uid)->execute();
  }

  /**
   * Sets the monthly payment value for a test user.
   */
  public function setMonthlyPayment(int $uid, float $value): void {
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'main',
    ]);
    $profile = reset($profiles);

    if (!$profile) {
      $profile = $this->entityTypeManager->getStorage('profile')->create([
        'uid' => $uid,
        'type' => 'main',
        'status' => 1,
        'is_default' => 1,
      ]);
    }

    if ($profile->hasField('field_member_payment_monthly')) {
      $profile->set('field_member_payment_monthly', $value);
      $profile->save();
    }
  }

  /**
   * Updates an existing test user's snapshot with new data.
   */
  public function updateSnapshot(int $uid, array $newData): void {
    $snapshot = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms')
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute()
      ->fetchAssoc();

    if (!$snapshot) {
      throw new \Exception("No snapshot found for user $uid.");
    }

    // Detect stage transition.
    $old_stage = $snapshot['stage'] ?? NULL;
    $new_stage = $newData['stage'] ?? $old_stage;
    $stage_changed = $old_stage && $new_stage && $old_stage !== $new_stage;

    // Merge new data.
    foreach ($newData as $k => $v) {
      if ($k === 'risk_reasons') {
        // Handled by calculator.
        continue;
      }
      $snapshot[$k] = $v;
    }

    if ($stage_changed) {
      // Production Rule: Clear outreach tracking on stage change.
      $snapshot['last_outreach_ts'] = NULL;
      $snapshot['outreach_status'] = NULL;
      $snapshot['last_contact_date'] = NULL;
      $snapshot['next_followup_date'] = NULL;
      $snapshot['contact_count'] = 0;
      $snapshot['member_followup_status'] = NULL;

      // Update the user entity to mirror production.
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user && $user->hasField('field_member_followup_status')) {
        $user->set('field_member_followup_status', NULL);
        $user->save();
      }
    }

    $this->upsertSnapshotFromData($uid, $snapshot);
    $this->syncQueue($uid);
  }

  /**
   * Simulates a staff contact recording.
   */
  public function recordContact(int $uid, string $method, string $outcome, string $notes = ''): void {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      throw new \Exception("User $uid not found.");
    }

    $snapshot = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['stage'])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute()
      ->fetchAssoc();

    $stage = $snapshot['stage'] ?? MemberSuccessLifecycle::STAGE_ONBOARDING;

    $outreach_service = \Drupal::service('makerspace_member_success.outreach_service');
    $outreach_service->recordContact(
      $user,
      $stage,
      $method,
      $outcome,
      $notes,
    // mark_exhausted.
      FALSE,
    // log_in_civicrm.
      FALSE
    );
  }

  /**
   * Helper to find the dynamic CiviCRM table for SMS consent.
   */
  protected function loadSmsConsentMetadata(): array {
    $query = $this->database->select('civicrm_custom_group', 'cg');
    $query->innerJoin('civicrm_custom_field', 'cf', 'cf.custom_group_id = cg.id');
    $query->addField('cg', 'table_name');
    $query->addField('cf', 'column_name');
    $query->condition('cg.name', 'SMS_Preferences');
    $query->condition('cf.name', 'SMS_Consent');
    $record = $query->execute()->fetchAssoc();

    if (!$record) {
      return [NULL, NULL];
    }
    return [$record['table_name'] ?? NULL, $record['column_name'] ?? NULL];
  }

  /**
   * Creates a historical interaction log entry.
   */
  public function createHistoricalInteraction(int $uid, string $date, string $method, string $outcome, ?int $staff_uid = NULL): void {
    $created_at = strtotime($date . ' 12:00:00');
    $this->database->insert('ms_member_outreach_log')
      ->fields([
        'uid' => $uid,
        'contact_date' => $date,
        'contact_method' => $method,
        'outcome' => $outcome,
        'staff_uid' => $staff_uid,
        'created_at' => $created_at,
        'notes' => 'Simulated historical data for trend testing.',
        'followup_status' => MemberSuccessLifecycle::followupStatusForOutcome($outcome),
        'sleep_days' => MemberSuccessLifecycle::sleepDaysForOutcome($outcome),
      ])
      ->execute();
  }

}
