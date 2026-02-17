<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;

/**
 * Kernel tests for stage transition suppression reset behavior.
 *
 * @group makerspace_member_success
 */
class StageTransitionResetKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'profile',
    'node',
    'taxonomy',
    'civicrm',
    'civicrm_entity',
    'makerspace_member_success',
  ];

  /**
   * Tests suppression resets when stage changes.
   */
  public function testSuppressionResetsOnStageChange(): void {
    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot']);

    $user = User::create([
      'name' => 'stage-reset-user',
      'mail' => 'stage-reset-user@example.com',
      'status' => 1,
    ]);
    $user->save();

    \Drupal::database()->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => (int) $user->id(),
        'snapshot_date' => date('Y-m-d', strtotime('-1 day')),
        'snapshot_type' => 'daily',
        'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
        'risk_score' => 20,
        'serial_number_present' => 1,
        'badge_count_total' => 1,
        'badge_count_window' => 1,
        'visit_count_30d' => 0,
        'payment_failed' => 0,
        'payment_pause' => 0,
        'outreach_status' => 'outreach_active',
        'last_contact_date' => date('Y-m-d', strtotime('-10 days')),
        'next_followup_date' => date('Y-m-d', strtotime('+5 days')),
        'contact_count' => 3,
        'last_outreach_ts' => strtotime('-10 days'),
        'is_latest' => 1,
        'created_at' => strtotime('-1 day'),
      ])
      ->execute();

    $database = $this->container->get('database');
    $config_factory = $this->container->get('config.factory');
    $time = $this->container->get('datetime.time');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $logger_factory = $this->container->get('logger.factory');
    $civicrm = $this->container->get('civicrm');

    $builder = new class($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm) extends MemberSuccessSnapshotBuilder {
      protected function loadProfileData(int $uid): array {
        return [
          'join_date' => date('Y-m-d', strtotime('-2 years')),
          'serial_present' => 1,
          'membership_type' => 'Standard',
          'payment_status' => 'active',
        ];
      }
      protected function loadUserFlags(int $uid): array {
        return [
          'serial_present' => 1,
          'payment_failed' => 1,
          'payment_pause' => 0,
        ];
      }
      protected function loadDoorBadgeStatus(int $uid, int $door_badge_tid): array {
        return ['status' => 'active', 'created' => strtotime('-2 years')];
      }
      protected function loadBadgeStats(int $uid, int $door_badge_tid, int $badge_four_days, int $now_ts): array {
        return [
          'count_total' => 2,
          'count_window' => 0,
          'last_badge_ts' => $now_ts - 10000,
        ];
      }
      protected function loadVisitStats(int $uid, int $now_ts): array {
        return [
          'visit_count_30d' => 0,
          'last_visit_ts' => $now_ts - (120 * 86400),
        ];
      }
      protected function loadCiviCrmData(int $uid): array {
        return [
          'do_not_phone' => 0,
          'do_not_email' => 0,
          'do_not_sms' => 0,
          'do_not_mail' => 0,
          'preferred_outreach_method' => NULL,
          'member_followup_status' => MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
        ];
      }
    };

    $row = $builder->buildSnapshotForUser(
      (int) $user->id(),
      date('Y-m-d'),
      'daily',
      (int) $time->getRequestTime()
    );

    $this->assertSame(MemberSuccessLifecycle::STAGE_RECOVERY, $row['stage']);
    $this->assertNull($row['member_followup_status']);
    $this->assertNull($row['last_contact_date']);
    $this->assertNull($row['next_followup_date']);
    $this->assertSame(0, (int) $row['contact_count']);
    $this->assertNull($row['last_outreach_ts']);
  }

}
