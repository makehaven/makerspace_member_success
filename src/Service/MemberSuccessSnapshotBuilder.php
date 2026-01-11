<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Drupal\civicrm\Civicrm;

/**
 * Builds member success snapshot data.
 */
class MemberSuccessSnapshotBuilder {

  protected Connection $database;

  protected ConfigFactoryInterface $configFactory;

  protected TimeInterface $time;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected LoggerInterface $logger;

  protected Civicrm $civicrm;

  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, TimeInterface $time, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, Civicrm $civicrm) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('makerspace_member_success');
    $this->civicrm = $civicrm;
  }

  /**
   * Builds daily snapshots for active members.
   */
  public function buildDailySnapshots(?\DateTimeInterface $date = NULL, string $snapshot_type = 'daily'): int {
    $date = $date ?: new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $snapshot_date = $date->format('Y-m-d');
    $now_ts = (int) $this->time->getRequestTime();
    $uids = $this->loadActiveMemberIds();
    $count = 0;

    foreach ($uids as $uid) {
      $row = $this->buildSnapshotForUser($uid, $snapshot_date, $snapshot_type, $now_ts);
      $this->upsertSnapshot($row);
      $count++;
    }

    $this->logger->info('Generated @count member success snapshots for @date.', [
      '@count' => $count,
      '@date' => $snapshot_date,
    ]);

    return $count;
  }

  /**
   * Computes a snapshot row for a user.
   */
  public function buildSnapshotForUser(int $uid, string $snapshot_date, string $snapshot_type, int $now_ts): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $door_badge_tid = (int) ($config->get('door_badge_tid') ?? 1519);
    $badge_one_days = (int) ($config->get('badge_one_days') ?? 28);
    $badge_four_days = (int) ($config->get('badge_four_days') ?? 180);
    $new_member_days = (int) ($config->get('new_member_days') ?? 180);
    $recency_days = (array) ($config->get('retention_recency_days') ?? [30, 60, 90]);

    $profile = $this->loadProfileData($uid);
    $user_flags = $this->loadUserFlags($uid);
    $door_badge = $this->loadDoorBadgeStatus($uid, $door_badge_tid);
    $badge_stats = $this->loadBadgeStats($uid, $door_badge_tid, $badge_four_days, $now_ts);
    $visit_stats = $this->loadVisitStats($uid, $now_ts);
    $civi_data = $this->loadCiviCrmData($uid);

    $serial_present = $profile['serial_present'] || $user_flags['serial_present'];
    $payment_failed = $user_flags['payment_failed'];
    $payment_pause = $user_flags['payment_pause'];

    $activation_ts = $door_badge['created'] ?? NULL;
    if ($activation_ts === NULL && $profile['join_date']) {
      $activation_ts = strtotime($profile['join_date'] . ' 00:00:00');
    }

    $stage = 'onboarding';
    if ($payment_failed || $payment_pause) {
      $stage = 'recovery';
    }
    elseif ($door_badge['status'] === 'active' && $serial_present) {
      $engagement_window = $badge_four_days * 86400;
      if ($activation_ts !== NULL && $now_ts - $activation_ts <= $engagement_window) {
        $stage = 'engagement';
      }
      else {
        $stage = 'retention';
      }
    }

    $tenure_bucket = NULL;
    if ($stage === 'onboarding') {
      $tenure_bucket = 'onboarding';
    }
    elseif (!empty($profile['join_date'])) {
      $join_ts = strtotime($profile['join_date'] . ' 00:00:00');
      if ($join_ts) {
        $tenure_days = (int) floor(($now_ts - $join_ts) / 86400);
        $tenure_bucket = $tenure_days <= $new_member_days ? 'new_member' : 'sustaining';
      }
    }

    [$risk_score, $risk_reasons] = $this->buildRiskIndicators([
      'stage' => $stage,
      'payment_failed' => $payment_failed,
      'payment_pause' => $payment_pause,
      'door_badge_status' => $door_badge['status'],
      'serial_present' => $serial_present,
      'activation_ts' => $activation_ts,
      'badge_count_total' => $badge_stats['count_total'],
      'badge_count_window' => $badge_stats['count_window'],
      'last_visit_ts' => $visit_stats['last_visit_ts'],
      'tenure_bucket' => $tenure_bucket,
    ], $badge_one_days, $badge_four_days, $recency_days, $now_ts);

    return [
      'uid' => $uid,
      'snapshot_date' => $snapshot_date,
      'snapshot_type' => $snapshot_type,
      'stage' => $stage,
      'risk_score' => $risk_score,
      'risk_reasons' => $risk_reasons,
      'join_date' => $profile['join_date'],
      'orientation_date' => $door_badge['created'] ? date('Y-m-d', $door_badge['created']) : NULL,
      'door_badge_status' => $door_badge['status'],
      'serial_number_present' => $serial_present ? 1 : 0,
      'badge_count_total' => $badge_stats['count_total'],
      'badge_count_window' => $badge_stats['count_window'],
      'tenure_bucket' => $tenure_bucket,
      'membership_type' => $profile['membership_type'],
      'last_badge_ts' => $badge_stats['last_badge_ts'],
      'last_visit_ts' => $visit_stats['last_visit_ts'],
      'visit_count_30d' => $visit_stats['visit_count_30d'],
      'payment_failed' => $payment_failed ? 1 : 0,
      'payment_pause' => $payment_pause ? 1 : 0,
      'payment_status' => $profile['payment_status'],
      'cancellation_followup' => NULL,
      'civicrm_do_not_phone' => $civi_data['do_not_phone'],
      'civicrm_do_not_email' => $civi_data['do_not_email'],
      'civicrm_do_not_sms' => $civi_data['do_not_sms'],
      'civicrm_do_not_mail' => $civi_data['do_not_mail'],
      'preferred_outreach_method' => $civi_data['preferred_outreach_method'],
      'last_outreach_ts' => NULL,
      'outreach_status' => NULL,
      'created_at' => $now_ts,
    ];
  }

  /**
   * Returns the configured door badge term ID.
   */
  public function getDoorBadgeTermId(): int {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    return (int) ($config->get('door_badge_tid') ?? 1519);
  }

  /**
   * Loads CiviCRM data for a user.
   */
  protected function loadCiviCrmData(int $uid): array {
    $default = [
      'do_not_phone' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
      'do_not_mail' => 0,
      'preferred_outreach_method' => NULL,
    ];

    if (!$this->civicrm) {
      return $default;
    }

    try {
      $this->civicrm->initialize();
      $uf_match = civicrm_api3('UFMatch', 'get', [
        'uf_id' => $uid,
        'sequential' => 1,
      ]);

      if (empty($uf_match['values'][0]['contact_id'])) {
        return $default;
      }
      $contact_id = $uf_match['values'][0]['contact_id'];

      $config = $this->configFactory->get('makerspace_member_success.settings');
      $pref_field = $config->get('civicrm_preferred_method_field') ?? 'preferred_communication_method';

      $params = [
        'id' => $contact_id,
        'return' => ['do_not_phone', 'do_not_email', 'do_not_sms', 'do_not_mail', $pref_field],
      ];

      $contact = civicrm_api3('Contact', 'get', $params);
      if (!empty($contact['values'][$contact_id])) {
        $data = $contact['values'][$contact_id];
        $pref = $data[$pref_field] ?? NULL;
        if (is_array($pref)) {
          $pref = reset($pref);
        }

        return [
          'do_not_phone' => (int) ($data['do_not_phone'] ?? 0),
          'do_not_email' => (int) ($data['do_not_email'] ?? 0),
          'do_not_sms' => (int) ($data['do_not_sms'] ?? 0),
          'do_not_mail' => (int) ($data['do_not_mail'] ?? 0),
          'preferred_outreach_method' => (string) $pref,
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching CiviCRM data for uid @uid: @message', ['@uid' => $uid, '@message' => $e->getMessage()]);
    }

    return $default;
  }

  /**
   * Loads active member ids (including pending approvals).
   *
   * @return int[]
   *   User ids.
   */
  protected function loadActiveMemberIds(): array {
    $query = $this->database->select('users_field_data', 'u');
    $query->addField('u', 'uid');
    $query->innerJoin('user__roles', 'r', 'r.entity_id = u.uid');
    $query->condition('u.status', 1);
    $query->condition('r.roles_target_id', ['member', 'member_pending_approval'], 'IN');
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Loads profile data for a member.
   */
  protected function loadProfileData(int $uid): array {
    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['profile_id']);
    $query->condition('p.uid', $uid);
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.status', 1);
    $query->range(0, 1);

    $query->leftJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->addField('join_date', 'field_member_join_date_value', 'join_date');
    $query->leftJoin('profile__field_card_serial_number', 'serial', 'serial.entity_id = p.profile_id AND serial.deleted = 0');
    $query->addField('serial', 'field_card_serial_number_value', 'profile_serial');
    $query->leftJoin('profile__field_member_payment_status', 'payment_status', 'payment_status.entity_id = p.profile_id AND payment_status.deleted = 0');
    $query->addField('payment_status', 'field_member_payment_status_target_id', 'payment_status');
    // Fetch Membership Type (Entity Reference)
    $query->leftJoin('profile__field_membership_type', 'type_ref', 'type_ref.entity_id = p.profile_id AND type_ref.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = type_ref.field_membership_type_target_id');
    $query->addField('term', 'name', 'membership_type');

    $record = $query->execute()->fetchAssoc() ?: [];
    return [
      'join_date' => $record['join_date'] ?? NULL,
      'serial_present' => !empty($record['profile_serial']),
      'payment_status' => $record['payment_status'] ?? NULL,
      'membership_type' => $record['membership_type'] ?? NULL,
    ];
  }

  /**
   * Loads user-level flags and fields.
   */
  protected function loadUserFlags(int $uid): array {
    $serial = $this->database->select('user__field_card_serial_number', 'serial')
      ->fields('serial', ['field_card_serial_number_value'])
      ->condition('serial.entity_id', $uid)
      ->condition('serial.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $payment_failed = $this->database->select('user__field_payment_failed', 'failed')
      ->fields('failed', ['field_payment_failed_value'])
      ->condition('failed.entity_id', $uid)
      ->condition('failed.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $payment_pause = $this->database->select('user__field_chargebee_payment_pause', 'pause')
      ->fields('pause', ['field_chargebee_payment_pause_value'])
      ->condition('pause.entity_id', $uid)
      ->condition('pause.deleted', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return [
      'serial_present' => !empty($serial),
      'payment_failed' => !empty($payment_failed),
      'payment_pause' => !empty($payment_pause),
    ];
  }

  /**
   * Loads door badge status for a member.
   */
  protected function loadDoorBadgeStatus(int $uid, int $door_badge_tid): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('n', 'created');
    $query->leftJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->addField('status', 'field_badge_status_value', 'status');
    $query->innerJoin('node__field_member_to_badge', 'member', 'member.entity_id = n.nid AND member.deleted = 0');
    $query->innerJoin('node__field_badge_requested', 'badge', 'badge.entity_id = n.nid AND badge.deleted = 0');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('member.field_member_to_badge_target_id', $uid);
    $query->condition('badge.field_badge_requested_target_id', $door_badge_tid);
    $query->orderBy('n.created', 'DESC');
    $query->range(0, 1);

    $record = $query->execute()->fetchAssoc() ?: [];
    return [
      'status' => $record['status'] ?? NULL,
      'created' => isset($record['created']) ? (int) $record['created'] : NULL,
    ];
  }

  /**
   * Loads badge counts and last badge data for a member.
   */
  protected function loadBadgeStats(int $uid, int $door_badge_tid, int $window_days, int $now_ts): array {
    $window_start = $now_ts - ($window_days * 86400);

    $base = $this->database->select('node_field_data', 'n');
    $base->condition('n.type', 'badge_request');
    $base->condition('n.status', 1);
    $base->innerJoin('node__field_member_to_badge', 'member', 'member.entity_id = n.nid AND member.deleted = 0');
    $base->innerJoin('node__field_badge_requested', 'badge', 'badge.entity_id = n.nid AND badge.deleted = 0');
    $base->leftJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $base->condition('member.field_member_to_badge_target_id', $uid);
    $base->condition('badge.field_badge_requested_target_id', $door_badge_tid, '!=');
    $base->condition('status.field_badge_status_value', 'active');

    $total_query = clone $base;
    $total_query->addExpression('COUNT(n.nid)', 'count_total');
    $count_total = (int) $total_query->execute()->fetchField();

    $window_query = clone $base;
    $window_query->condition('n.created', $window_start, '>=');
    $window_query->addExpression('COUNT(n.nid)', 'count_window');
    $count_window = (int) $window_query->execute()->fetchField();

    $last_query = clone $base;
    $last_query->addExpression('MAX(n.created)', 'last_badge_ts');
    $last_badge_ts = $last_query->execute()->fetchField();

    return [
      'count_total' => $count_total,
      'count_window' => $count_window,
      'last_badge_ts' => $last_badge_ts ? (int) $last_badge_ts : NULL,
    ];
  }

  /**
   * Loads visit recency and frequency stats.
   */
  protected function loadVisitStats(int $uid, int $now_ts): array {
    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression('MAX(acl.created)', 'last_visit_ts');
    $query->condition('acl.type', 'access_control_request');
    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $query->condition('user_ref.field_access_request_user_target_id', $uid);
    $last_visit_ts = $query->execute()->fetchField();

    $window_start = $now_ts - (30 * 86400);
    $count_query = $this->database->select('access_control_log_field_data', 'acl');
    $count_query->addExpression('COUNT(DISTINCT DATE(FROM_UNIXTIME(acl.created)))', 'visit_days');
    $count_query->condition('acl.type', 'access_control_request');
    $count_query->condition('acl.created', $window_start, '>=');
    $count_query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $count_query->condition('user_ref.field_access_request_user_target_id', $uid);
    $visit_days = $count_query->execute()->fetchField();

    return [
      'last_visit_ts' => $last_visit_ts ? (int) $last_visit_ts : NULL,
      'visit_count_30d' => $visit_days ? (int) $visit_days : 0,
    ];
  }

  /**
   * Builds risk score and reasons based on snapshot data.
   *
   * @return array{0:int,1:array}
   *   Risk score and reasons list.
   */
  protected function buildRiskIndicators(array $data, int $badge_one_days, int $badge_four_days, array $recency_days, int $now_ts): array {
    $score = 0;
    $reasons = [];

    if (!empty($data['payment_failed']) || !empty($data['payment_pause'])) {
      $score += 50;
      $reasons[] = 'payment_issue';
    }

    if ($data['stage'] === 'onboarding') {
      if (($data['door_badge_status'] ?? NULL) !== 'active') {
        $score += 20;
        $reasons[] = 'door_badge_pending';
      }
      if (empty($data['serial_present'])) {
        $score += 10;
        $reasons[] = 'missing_serial';
      }
    }

    if ($data['stage'] === 'engagement' && !empty($data['activation_ts'])) {
      $since_activation = $now_ts - $data['activation_ts'];
      if ($since_activation >= ($badge_one_days * 86400) && $data['badge_count_window'] < 1) {
        $score += 20;
        $reasons[] = 'no_badge_1';
      }
      if ($since_activation >= ($badge_four_days * 86400) && $data['badge_count_total'] < 4) {
        $score += 20;
        $reasons[] = 'no_badge_4';
      }
    }

    if ($data['stage'] === 'retention' && !empty($data['last_visit_ts'])) {
      $max_days = 0;
      foreach ($recency_days as $days) {
        $max_days = max($max_days, (int) $days);
      }
      if ($max_days > 0 && $now_ts - $data['last_visit_ts'] >= ($max_days * 86400)) {
        $score += 20;
        $reasons[] = 'inactive';
      }
    }

    return [$score, $reasons];
  }

  /**
   * Inserts or updates a snapshot row.
   */
  public function upsertSnapshot(array $row): void {
    $keys = [
      'uid' => $row['uid'],
      'snapshot_date' => $row['snapshot_date'],
      'snapshot_type' => $row['snapshot_type'],
    ];

    $fields = $row;
    unset($fields['uid'], $fields['snapshot_date'], $fields['snapshot_type']);
    if (isset($fields['risk_reasons']) && is_array($fields['risk_reasons'])) {
      $fields['risk_reasons'] = serialize($fields['risk_reasons']);
    }

    $this->database->merge('ms_member_success_snapshot')
      ->keys($keys)
      ->fields($fields)
      ->execute();
  }

}