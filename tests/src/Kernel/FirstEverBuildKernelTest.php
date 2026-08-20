<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel test for a member's first-ever snapshot build.
 *
 * Regression guard (caught in local rehearsal 2026-08-20): the episode-reset
 * change passed the previous-snapshot fetchAssoc() result — FALSE when the
 * member has no prior snapshot — straight into
 * MemberSuccessLifecycle::isNewPaymentEpisode(array $previous, ...), so every
 * brand-new member's first daily build threw a TypeError and aborted the
 * entire cron run. The fetch must coerce FALSE to [].
 *
 * @group makerspace_member_success
 */
class FirstEverBuildKernelTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot', 'ms_member_outreach_log']);
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));

    // Columns added by update hooks that may lag the install schema.
    $schema = $this->container->get('database')->schema();
    foreach (['pause_start_date', 'payment_failed_since'] as $column) {
      if (!$schema->fieldExists('ms_member_success_snapshot', $column)) {
        $schema->addField('ms_member_success_snapshot', $column, [
          'type' => 'varchar',
          'length' => 10,
          'not null' => FALSE,
        ]);
      }
    }
  }

  /**
   * A first build with no previous snapshot must not fatal, failed or not.
   */
  public function testFirstBuildWithoutPreviousSnapshotDoesNotFatal(): void {
    $user = User::create([
      'name' => 'brand-new-member',
      'mail' => 'brand-new-member@example.com',
      'status' => 1,
    ]);
    $user->save();

    // Payment-failed first build: this is the exact input that fataled.
    $row = $this->buildSnapshot((int) $user->id(), payment_failed: 1);
    $this->assertSame(MemberSuccessLifecycle::STAGE_RECOVERY, $row['stage']);
    $this->assertSame(0, (int) $row['contact_count']);

    // Healthy first build stays sane too.
    $row = $this->buildSnapshot((int) $user->id(), payment_failed: 0);
    $this->assertNotSame(MemberSuccessLifecycle::STAGE_RECOVERY, $row['stage']);
  }

  /**
   * Runs the snapshot builder with all external data sources mocked.
   */
  protected function buildSnapshot(int $uid, int $payment_failed): array {
    $builder = new class(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('civicrm'),
      $payment_failed,
    ) extends MemberSuccessSnapshotBuilder {

      /**
       * Mocked payment-failed flag.
       */
      private int $mockPaymentFailed;

      public function __construct($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm, int $payment_failed) {
        parent::__construct($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm);
        $this->mockPaymentFailed = $payment_failed;
      }

      protected function loadProfileData(int $uid): array {
        return [
          'profile_present' => TRUE,
          'profile_created_ts' => strtotime('-3 days'),
          'account_ts' => strtotime('-4 days'),
          'join_date' => date('Y-m-d', strtotime('-3 days')),
          'serial_present' => 0,
          'membership_type' => 'Standard',
          'payment_status' => 'active',
        ];
      }

      protected function loadUserFlags(int $uid): array {
        return [
          'serial_present' => 0,
          'payment_failed' => $this->mockPaymentFailed,
          'payment_pause' => 0,
        ];
      }

      protected function loadDoorBadgeStatus(int $uid, int $door_badge_tid): array {
        return ['status' => 'none', 'created' => NULL];
      }

      protected function loadBadgeStats(int $uid, int $door_badge_tid, int $badge_four_days, int $now_ts): array {
        return [
          'count_total' => 0,
          'count_window' => 0,
          'last_badge_ts' => NULL,
        ];
      }

      protected function loadVisitStats(int $uid, int $now_ts): array {
        return [
          'visit_count_30d' => 0,
          'last_visit_ts' => NULL,
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
          // Non-empty so the builder skips its Drupal-field fallback query —
          // the user field table does not exist in the kernel environment.
          'member_followup_status' => MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE,
        ];
      }

      protected function loadQuizPassed(int $uid, int $quiz_qid): array {
        return ['passed' => FALSE, 'ts' => NULL];
      }

      protected function loadFirstCardScan(int $uid): ?string {
        return NULL;
      }

    };

    return $builder->buildSnapshotForUser(
      $uid,
      date('Y-m-d'),
      'daily',
      (int) $this->container->get('datetime.time')->getRequestTime()
    );
  }

}
