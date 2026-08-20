<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for automatic confirmed-cancel logging on recovery departure.
 *
 * Guards the 2026-08-20 fix: a member whose subscription cancelled while in
 * payment recovery used to vanish from the snapshots with no outreach-log row,
 * so the Intervention Performance page showed 0 confirmed cancellations for
 * everyone (Kate/Christina). The daily build must now write a back-attributed
 * confirmed_cancel row for such departures — the mirror image of the
 * automatic payment_updated row.
 *
 * @group makerspace_member_success
 */
class AutomaticCancellationKernelTest extends KernelTestBase {

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
  }

  /**
   * A departed recovery member with recent human outreach: credited cancel.
   */
  public function testDepartedRecoveryMemberGetsBackAttributedCancelRow(): void {
    $member = $this->createUser('departed-worked');
    $staff = $this->createUser('staff-christina');
    $this->insertLatestSnapshot($member, MemberSuccessLifecycle::STAGE_RECOVERY);
    $this->insertOutreachRow($member, $staff, 'sms', 'no_answer', '-5 days');

    // The member is NOT in the active list — they departed.
    $swept = $this->sweep([]);

    $this->assertSame(1, $swept);
    $row = $this->fetchCancelRow($member);
    $this->assertNotNull($row, 'A confirmed_cancel row must be written for a departed recovery member.');
    $this->assertSame($staff, (int) $row['staff_uid'], 'The cancel must be credited to the staff member who last reached out.');
    $this->assertSame('sms', $row['contact_method'], 'The human outreach channel must be copied onto the auto row.');
  }

  /**
   * A departed recovery member nobody contacted: unattributed system cancel.
   */
  public function testDepartedRecoveryMemberWithoutOutreachGetsSystemRow(): void {
    $member = $this->createUser('departed-silent');
    $this->insertLatestSnapshot($member, MemberSuccessLifecycle::STAGE_RECOVERY);

    $this->sweep([]);

    $row = $this->fetchCancelRow($member);
    $this->assertNotNull($row);
    $this->assertNull($row['staff_uid'], 'With no human outreach in 30 days the row must stay unattributed.');
    $this->assertSame('system', $row['contact_method']);
  }

  /**
   * A cancellation already logged within 90 days must not be duplicated.
   */
  public function testExistingRecentCancelRowSuppressesTheAutoRow(): void {
    $member = $this->createUser('departed-logged');
    $staff = $this->createUser('staff-logged');
    $this->insertLatestSnapshot($member, MemberSuccessLifecycle::STAGE_RECOVERY);
    $this->insertOutreachRow($member, $staff, 'phone', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL, '-10 days');

    $this->sweep([]);

    $count = (int) \Drupal::database()->select('ms_member_outreach_log', 'log')
      ->condition('uid', $member)
      ->condition('outcome', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL)
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, $count, 'The manually logged cancellation must not be duplicated by the sweep.');
  }

  /**
   * Members still active, or departed from other stages, are never swept.
   */
  public function testActiveAndNonRecoveryMembersAreNotSwept(): void {
    $active = $this->createUser('still-active');
    $this->insertLatestSnapshot($active, MemberSuccessLifecycle::STAGE_RECOVERY);

    $departed_retention = $this->createUser('departed-retention');
    $this->insertLatestSnapshot($departed_retention, MemberSuccessLifecycle::STAGE_RETENTION);

    // Only $active is in the active list; $departed_retention departed but
    // was never in recovery.
    $swept = $this->sweep([$active]);

    $this->assertSame(0, $swept);
    $this->assertNull($this->fetchCancelRow($active), 'An active recovery member must not receive a cancel row.');
    $this->assertNull($this->fetchCancelRow($departed_retention), 'A member who departed outside recovery must not receive a cancel row.');
  }

  /**
   * Creates a bare user and returns its uid.
   */
  protected function createUser(string $name): int {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    return (int) $user->id();
  }

  /**
   * Inserts an is_latest daily snapshot at the given stage.
   */
  protected function insertLatestSnapshot(int $uid, string $stage): void {
    \Drupal::database()->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_date' => date('Y-m-d', strtotime('-1 day')),
        'snapshot_type' => 'daily',
        'stage' => $stage,
        'risk_score' => 40,
        'serial_number_present' => 1,
        'badge_count_total' => 1,
        'badge_count_window' => 1,
        'visit_count_30d' => 0,
        'payment_failed' => $stage === MemberSuccessLifecycle::STAGE_RECOVERY ? 1 : 0,
        'payment_pause' => 0,
        'is_latest' => 1,
        'created_at' => strtotime('-1 day'),
      ])
      ->execute();
  }

  /**
   * Inserts an outreach log row.
   */
  protected function insertOutreachRow(int $uid, ?int $staff_uid, string $method, string $outcome, string $when): void {
    \Drupal::database()->insert('ms_member_outreach_log')
      ->fields([
        'uid' => $uid,
        'contact_date' => date('Y-m-d', strtotime($when)),
        'contact_method' => $method,
        'outcome' => $outcome,
        'staff_uid' => $staff_uid,
        'created_at' => strtotime($when),
      ])
      ->execute();
  }

  /**
   * Runs the departure sweep with the given active-uid list.
   */
  protected function sweep(array $active_uids): int {
    $builder = new class(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('civicrm'),
    ) extends MemberSuccessSnapshotBuilder {

      /**
       * Exposes the protected sweep for testing.
       */
      public function sweepForTest(array $active_uids, string $contact_date, int $created_at): int {
        return $this->recordDepartedRecoveryCancellations($active_uids, 'daily', $contact_date, $created_at);
      }

    };

    return $builder->sweepForTest($active_uids, date('Y-m-d'), time());
  }

  /**
   * Fetches the auto/any confirmed_cancel row for a member, if present.
   */
  protected function fetchCancelRow(int $uid): ?array {
    $row = \Drupal::database()->select('ms_member_outreach_log', 'log')
      ->fields('log')
      ->condition('uid', $uid)
      ->condition('outcome', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL)
      ->condition('contact_date', date('Y-m-d'))
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

}
