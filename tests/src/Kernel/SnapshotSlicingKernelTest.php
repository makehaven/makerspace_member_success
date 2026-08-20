<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\civicrm\Civicrm;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests resumable slicing of the daily snapshot build.
 *
 * Cron runs as a web request and is killed at the host's request ceiling, so
 * a snapshot pass that does not fit gets truncated at the same place every
 * run and takes the rest of hook_cron() down with it (2026-08-19: the
 * join-form lead scan and onboarding nudge both stopped silently). Slicing
 * lets a pass resume across cron runs instead.
 *
 * @group makerspace_member_success
 */
class SnapshotSlicingKernelTest extends KernelTestBase {

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
    $this->container->set('civicrm', $this->createMock(Civicrm::class));

    Role::create(['id' => 'member', 'label' => 'Member'])->save();
  }

  /**
   * A pass with no budget still covers everyone (drush behaviour unchanged).
   */
  public function testUnbudgetedPassCoversWholeRoster(): void {
    $this->createMembers(3);
    $builder = $this->builder();

    $this->assertSame(3, $builder->buildDailySnapshots());
    $this->assertSame(3, $this->snapshotCountForToday());
  }

  /**
   * A sliced pass skips members that already have a row for the date.
   *
   * This is what makes resuming safe: work is derived from the snapshot table
   * itself, not a stored cursor, so a pass that dies mid-loop simply picks up
   * where it left off rather than redoing or skipping members.
   */
  public function testSlicedPassSkipsMembersAlreadyBuilt(): void {
    $uids = $this->createMembers(3);
    $builder = $this->builder();

    // Simulate a previous pass that only reached the first member.
    $this->seedSnapshot($uids[0]);
    $this->assertSame(1, $this->snapshotCountForToday());

    // The next slice must build exactly the two that are still missing.
    $this->assertSame(2, $builder->buildDailySnapshots(NULL, 'daily', 60));
    $this->assertSame(3, $this->snapshotCountForToday());
  }

  /**
   * Completing a pass stamps state, and the next slice becomes a no-op.
   *
   * Without the no-op guard the end-of-pass bookkeeping (departed-recovery
   * cancellation logging) would re-run on every cron and double-log losses.
   */
  public function testCompletedPassStampsStateAndThenNoOps(): void {
    $this->createMembers(2);
    $builder = $this->builder();
    $state = $this->container->get('state');

    $this->assertSame(2, $builder->buildDailySnapshots(NULL, 'daily', 60));
    $this->assertSame(
      date('Y-m-d'),
      $state->get(MemberSuccessSnapshotBuilder::STATE_LAST_COMPLETE_DATE)
    );
    $this->assertGreaterThan(0, (int) $state->get(MemberSuccessSnapshotBuilder::STATE_LAST_COMPLETE));

    // Roster already covered for today: nothing more to do.
    $this->assertSame(0, $builder->buildDailySnapshots(NULL, 'daily', 60));
    $this->assertSame(2, $this->snapshotCountForToday());
  }

  /**
   * A new member appearing mid-day is picked up by the next slice.
   */
  public function testMemberAddedAfterCompletionIsPickedUp(): void {
    $this->createMembers(2);
    $builder = $this->builder();
    $builder->buildDailySnapshots(NULL, 'daily', 60);

    $this->createMembers(1, 'latecomer');
    $this->assertSame(1, $builder->buildDailySnapshots(NULL, 'daily', 60));
    $this->assertSame(3, $this->snapshotCountForToday());
  }

  /**
   * One member that throws must not end the pass.
   *
   * This is the regression guard for 2026-08-19: a single member threw a
   * TypeError, it escaped core's \Exception-only cron handler as a fatal, and
   * it killed both the rest of the roster and every later hook_cron() stage.
   * Every other member must still be snapshotted.
   */
  public function testOneFailingMemberDoesNotEndThePass(): void {
    $uids = $this->createMembers(4);
    $builder = new PoisonMemberSnapshotBuilder(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('civicrm'),
      NULL,
      $this->container->get('state'),
    );
    $builder->poisonUid = $uids[1];

    $written = $builder->buildDailySnapshots(NULL, 'daily', 60);

    $this->assertSame(3, $written, 'The three healthy members were still built.');
    $this->assertSame(3, $this->snapshotCountForToday());
    $this->assertSame(
      0,
      (int) $this->container->get('database')->select('ms_member_success_snapshot', 'ms')
        ->condition('uid', $uids[1])
        ->countQuery()
        ->execute()
        ->fetchField(),
      'The failing member has no row, but did not stop the others.'
    );
  }

  /**
   * Returns a builder wired from the container with a stubbed row build.
   */
  protected function builder(): MemberSuccessSnapshotBuilder {
    return new TestSlicingSnapshotBuilder(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('civicrm'),
      NULL,
      $this->container->get('state'),
    );
  }

  /**
   * Creates active users holding the member role.
   *
   * @return int[]
   *   The created user ids.
   */
  protected function createMembers(int $count, string $prefix = 'member'): array {
    $uids = [];
    for ($i = 0; $i < $count; $i++) {
      $name = $prefix . '-' . $i . '-' . uniqid();
      $user = User::create([
        'name' => $name,
        'mail' => $name . '@example.com',
        'status' => 1,
      ]);
      $user->addRole('member');
      $user->save();
      $uids[] = (int) $user->id();
    }
    return $uids;
  }

  /**
   * Inserts a minimal snapshot row for today, as a prior slice would have.
   */
  protected function seedSnapshot(int $uid): void {
    $this->container->get('database')->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_date' => date('Y-m-d'),
        'snapshot_type' => 'daily',
        'stage' => 'onboarding',
        'risk_score' => 0,
        'is_latest' => 1,
        'created_at' => $this->container->get('datetime.time')->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Counts snapshot rows written for today.
   */
  protected function snapshotCountForToday(): int {
    return (int) $this->container->get('database')->select('ms_member_success_snapshot', 'ms')
      ->condition('snapshot_date', date('Y-m-d'))
      ->condition('snapshot_type', 'daily')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}

/**
 * Builder stub that skips the per-member signal loading.
 *
 * The buildSnapshotForUser() method reaches into profile, badge, visit and
 * CiviCRM field tables
 * that a kernel test would have to stand up in full. None of that is what
 * slicing changes, so it is replaced with a minimal row here and the test
 * stays pointed at the resume/completion logic.
 */
class TestSlicingSnapshotBuilder extends MemberSuccessSnapshotBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildSnapshotForUser(int $uid, string $snapshot_date, string $snapshot_type, int $now_ts): array {
    return [
      'uid' => $uid,
      'snapshot_date' => $snapshot_date,
      'snapshot_type' => $snapshot_type,
      'stage' => 'onboarding',
      'risk_score' => 0,
      'created_at' => $now_ts,
    ];
  }

}

/**
 * Builder stub whose row build fatals for one specific member.
 *
 * Reproduces the 2026-08-19 outage shape: a single member throws an \Error
 * (not an \Exception), which core's cron handler does not catch.
 */
class PoisonMemberSnapshotBuilder extends TestSlicingSnapshotBuilder {

  /**
   * Uid that will throw.
   */
  public int $poisonUid = 0;

  /**
   * {@inheritdoc}
   */
  public function buildSnapshotForUser(int $uid, string $snapshot_date, string $snapshot_type, int $now_ts): array {
    if ($uid === $this->poisonUid) {
      throw new \TypeError('isNewPaymentEpisode(): Argument #1 ($previous) must be of type array, false given');
    }
    return parent::buildSnapshotForUser($uid, $snapshot_date, $snapshot_type, $now_ts);
  }

}
