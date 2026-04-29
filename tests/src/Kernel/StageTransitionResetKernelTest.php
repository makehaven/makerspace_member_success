<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for stage transition suppression preservation behavior.
 *
 * These tests guard the bug fix that prevents explicit operator suppression
 * (outreach_exhausted, confirmed_cancellation) from silently flipping back to
 * "active" when a member's lifecycle stage changes. Christina (2026-04-28)
 * reported members marked exhausted/cancelled re-appearing in queues; the
 * root cause was an auto-reset on stage transition that has been removed.
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
    'makerspace_member_success',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    $container->register('civicrm', 'Drupal\civicrm\Civicrm')
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
  }

  /**
   * Tests that outreach_exhausted persists across retention -> recovery.
   *
   * Time-based outreach tracking (last_contact_date, next_followup_date,
   * contact_count, snapshot outreach_status) is still cleared on transition,
   * but the operator-set member_followup_status MUST persist.
   */
  public function testOutreachExhaustedPersistsOnStageChange(): void {
    $this->bootSchemaAndCivicrm();
    $uid = $this->createPreviousSnapshot('exhausted-stage-user', MemberSuccessLifecycle::STAGE_RETENTION);

    $row = $this->buildSnapshotWithFlip(
      $uid,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      payment_failed: 1
    );

    $this->assertSame(MemberSuccessLifecycle::STAGE_RECOVERY, $row['stage']);
    // Time-based snooze/tracking fields ARE cleared on stage transition.
    $this->assertSame(MemberSuccessLifecycle::OUTREACH_STATUS_PENDING, $row['outreach_status']);
    $this->assertNull($row['last_contact_date']);
    $this->assertNull($row['next_followup_date']);
    $this->assertSame(0, (int) $row['contact_count']);
    $this->assertNull($row['last_outreach_ts']);
    // Explicit operator suppression is PRESERVED — this is the bug fix.
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      $row['member_followup_status']
    );
  }

  /**
   * Tests that confirmed_cancellation persists across retention -> recovery.
   *
   * This is the exact scenario Christina reported — a member confirms they
   * are cancelling, then a payment failure flips the stage to recovery, and
   * the cancellation suppression must remain in place.
   */
  public function testConfirmedCancellationPersistsOnStageChange(): void {
    $this->bootSchemaAndCivicrm();
    $uid = $this->createPreviousSnapshot('cancelled-stage-user', MemberSuccessLifecycle::STAGE_RETENTION);

    $row = $this->buildSnapshotWithFlip(
      $uid,
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
      payment_failed: 1
    );

    $this->assertSame(MemberSuccessLifecycle::STAGE_RECOVERY, $row['stage']);
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
      $row['member_followup_status'],
      'Confirmed cancellation must remain on the snapshot after stage transition.'
    );
  }

  /**
   * Tests that suppression persists when payment recovers (recovery -> retention).
   *
   * If the member fixes their payment without staff intervention, they exit
   * recovery into retention. Their existing exhausted/cancelled status should
   * still apply — staff have already decided no further outreach is wanted.
   */
  public function testSuppressionPersistsAcrossRecoveryToRetentionTransition(): void {
    $this->bootSchemaAndCivicrm();
    $uid = $this->createPreviousSnapshot('recovered-stage-user', MemberSuccessLifecycle::STAGE_RECOVERY);

    $row = $this->buildSnapshotWithFlip(
      $uid,
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      payment_failed: 0
    );

    $this->assertSame(MemberSuccessLifecycle::STAGE_RETENTION, $row['stage']);
    $this->assertSame(
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
      $row['member_followup_status'],
      'Exhausted status must remain when payment recovers without staff action.'
    );
  }

  /**
   * Boots schema and the synthetic CiviCRM service for kernel test setup.
   */
  protected function bootSchemaAndCivicrm(): void {
    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot', 'ms_member_outreach_log']);
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));
    $schema = $this->container->get('database')->schema();
    if (!$schema->fieldExists('ms_member_success_snapshot', 'pause_start_date')) {
      $schema->addField('ms_member_success_snapshot', 'pause_start_date', [
        'type' => 'varchar',
        'length' => 10,
        'not null' => FALSE,
      ]);
    }
  }

  /**
   * Creates a user and an "is_latest" snapshot row with the given previous stage.
   */
  protected function createPreviousSnapshot(string $name, string $previous_stage): int {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    $uid = (int) $user->id();

    \Drupal::database()->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_date' => date('Y-m-d', strtotime('-1 day')),
        'snapshot_type' => 'daily',
        'stage' => $previous_stage,
        'risk_score' => 20,
        'serial_number_present' => 1,
        'badge_count_total' => 1,
        'badge_count_window' => 1,
        'visit_count_30d' => 0,
        'payment_failed' => $previous_stage === MemberSuccessLifecycle::STAGE_RECOVERY ? 1 : 0,
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

    return $uid;
  }

  /**
   * Runs the snapshot builder for a user with mocked external data sources.
   *
   * @param int $uid
   *   User id with a pre-existing snapshot.
   * @param string $followup_status
   *   The CiviCRM-sourced followup status to return from the mock.
   * @param int $payment_failed
   *   Whether the user's payment is currently failed (drives stage flip).
   *
   * @return array
   *   The freshly computed snapshot row (not yet persisted).
   */
  protected function buildSnapshotWithFlip(int $uid, string $followup_status, int $payment_failed): array {
    $database = $this->container->get('database');
    $config_factory = $this->container->get('config.factory');
    $time = $this->container->get('datetime.time');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $logger_factory = $this->container->get('logger.factory');
    $civicrm = $this->container->get('civicrm');

    $builder = new class($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm, $followup_status, $payment_failed) extends MemberSuccessSnapshotBuilder {
      private string $mockFollowupStatus;
      private int $mockPaymentFailed;
      public function __construct($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm, string $followup_status, int $payment_failed) {
        parent::__construct($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm);
        $this->mockFollowupStatus = $followup_status;
        $this->mockPaymentFailed = $payment_failed;
      }
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
          'payment_failed' => $this->mockPaymentFailed,
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
          'orientation_scheduled' => NULL,
          'member_followup_status' => $this->mockFollowupStatus,
        ];
      }
    };

    return $builder->buildSnapshotForUser(
      $uid,
      date('Y-m-d'),
      'daily',
      (int) $time->getRequestTime()
    );
  }

}
