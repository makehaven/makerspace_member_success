<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests pinning the Intervention Performance drill-down semantics.
 *
 * The "N of M resolved" card counts DISTINCT members, and the drill-down
 * ("Who is behind these numbers?", 2026-08-20) must list exactly those
 * members — one entry per member no matter how many case-closing rows they
 * have, cancellations listed separately, both respecting the date filter.
 *
 * @group makerspace_member_success
 */
class RecoveryMetricsResolutionDetailsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
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
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_log']);
  }

  /**
   * The drill-down lists must match the resolution-rate counts exactly.
   */
  public function testDetailsMatchResolutionRateCounts(): void {
    $staff = $this->createUser('staff-a');
    $resolved_twice = $this->createUser('member-resolved-twice');
    $resolved_once = $this->createUser('member-resolved-once');
    $cancelled = $this->createUser('member-cancelled');
    $contacted_only = $this->createUser('member-contacted-only');
    $out_of_range = $this->createUser('member-out-of-range');

    // Two resolved rows for the same member — must count/list ONCE.
    $this->insertRow($resolved_twice, $staff, '2026-06-01', 'payment_updated');
    $this->insertRow($resolved_twice, $staff, '2026-06-10', 'payment_updated');
    $this->insertRow($resolved_once, NULL, '2026-06-05', 'payment_updated');
    $this->insertRow($cancelled, $staff, '2026-06-07', MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL);
    $this->insertRow($contacted_only, $staff, '2026-06-08', 'no_answer');
    // Resolved, but outside the queried range — must not appear.
    $this->insertRow($out_of_range, $staff, '2026-01-15', 'payment_updated');

    $metrics = new RecoveryMetrics(\Drupal::database());
    $rate = $metrics->getResolutionRate('2026-05-01', '2026-06-30');
    $details = $metrics->getResolutionDetails('2026-05-01', '2026-06-30');

    // 4 distinct members contacted in range; 2 resolved; 1 cancelled.
    $this->assertSame(4, $rate['total']);
    $this->assertSame(2, $rate['resolved']);
    $this->assertSame(1, $rate['confirmed_cancel']);

    // The drill-down must list exactly the counted members, once each.
    $this->assertCount($rate['resolved'], $details['resolved']);
    $this->assertCount($rate['confirmed_cancel'], $details['cancelled']);
    $resolved_uids = array_column($details['resolved'], 'uid');
    $this->assertEqualsCanonicalizing([$resolved_twice, $resolved_once], array_map('intval', $resolved_uids));
    $this->assertSame($cancelled, (int) $details['cancelled'][0]['uid']);

    // Attribution data used by the page must be present.
    $this->assertSame('staff-a', $details['cancelled'][0]['staff_name']);
    $by_uid = array_column($details['resolved'], NULL, 'uid');
    $this->assertNull($by_uid[$resolved_once]['staff_uid'], 'A system self-recovery row keeps its NULL staff attribution.');
  }

  /**
   * The full contact-log export returns every row, newest first, with names.
   */
  public function testContactLogRowsAreRowLevel(): void {
    $staff = $this->createUser('staff-b');
    $member = $this->createUser('member-logged');
    $this->insertRow($member, $staff, '2026-06-01', 'email_sent');
    $this->insertRow($member, $staff, '2026-06-09', 'payment_updated');
    $this->insertRow($member, NULL, '2026-06-04', 'no_answer');

    $metrics = new RecoveryMetrics(\Drupal::database());
    $rows = $metrics->getContactLogRows('2026-05-01', '2026-06-30');

    $this->assertCount(3, $rows, 'Every log row must be exported, not a per-member summary.');
    $this->assertSame(['2026-06-09', '2026-06-04', '2026-06-01'], array_column($rows, 'contact_date'));
    $this->assertSame('member-logged', $rows[0]['member_name']);
    $this->assertSame('staff-b', $rows[0]['staff_name']);
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
   * Inserts an outreach log row.
   */
  protected function insertRow(int $uid, ?int $staff_uid, string $date, string $outcome): void {
    \Drupal::database()->insert('ms_member_outreach_log')
      ->fields([
        'uid' => $uid,
        'contact_date' => $date,
        'contact_method' => 'email',
        'outcome' => $outcome,
        'staff_uid' => $staff_uid,
        'created_at' => strtotime($date),
      ])
      ->execute();
  }

}
