<?php

namespace Drupal\Tests\makerspace_member_success\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Functional tests for member success queue visibility rules.
 *
 * @group makerspace_member_success
 */
class QueueVisibilityFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'makerspace_member_success',
    'user',
    'system',
    'field',
    'views',
    'civicrm',
    'civicrm_entity',
  ];

  /**
   * Staff user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $staffUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->staffUser = $this->drupalCreateUser([
      'access makerspace member success queues',
      'access user profiles',
    ]);
  }

  /**
   * Tests snoozed and resolved members are hidden in the recovery queue.
   */
  public function testRecoveryQueueVisibilityFilters(): void {
    $today = date('Y-m-d');
    $future = date('Y-m-d', strtotime('+7 days'));

    $visible_now = User::create([
      'name' => 'Queue Visible Now',
      'mail' => 'visible.now@example.com',
      'status' => 1,
    ]);
    $visible_now->save();

    $visible_due = User::create([
      'name' => 'Queue Visible Due',
      'mail' => 'visible.due@example.com',
      'status' => 1,
    ]);
    $visible_due->save();

    $hidden_snoozed = User::create([
      'name' => 'Queue Hidden Snoozed',
      'mail' => 'hidden.snoozed@example.com',
      'status' => 1,
    ]);
    $hidden_snoozed->save();

    $hidden_exhausted = User::create([
      'name' => 'Queue Hidden Exhausted',
      'mail' => 'hidden.exhausted@example.com',
      'status' => 1,
    ]);
    $hidden_exhausted->save();

    $hidden_cancel = User::create([
      'name' => 'Queue Hidden Cancel',
      'mail' => 'hidden.cancel@example.com',
      'status' => 1,
    ]);
    $hidden_cancel->save();

    $this->insertSnapshotRow($visible_now->id(), NULL, NULL, NULL);
    $this->insertSnapshotRow($visible_due->id(), $today, NULL, NULL);
    $this->insertSnapshotRow($hidden_snoozed->id(), $future, NULL, NULL);
    $this->insertSnapshotRow($hidden_exhausted->id(), NULL, 'outreach_exhausted', NULL);
    $this->insertSnapshotRow($hidden_cancel->id(), NULL, NULL, 'confirmed_cancellation');

    $this->drupalLogin($this->staffUser);
    $this->drupalGet('/admin/makerspace/member-success/recovery');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->pageTextContains('Queue Visible Now');
    $this->assertSession()->pageTextContains('Queue Visible Due');

    $this->assertSession()->pageTextNotContains('Queue Hidden Snoozed');
    $this->assertSession()->pageTextNotContains('Queue Hidden Exhausted');
    $this->assertSession()->pageTextNotContains('Queue Hidden Cancel');
  }

  /**
   * Inserts a recovery queue snapshot row.
   *
   * @param int $uid
   *   User ID.
   * @param string|null $next_followup_date
   *   Next followup date in Y-m-d format.
   * @param string|null $outreach_status
   *   Snapshot outreach status.
   * @param string|null $member_followup_status
   *   Followup status from member profile sync.
   */
  protected function insertSnapshotRow(
    int $uid,
    ?string $next_followup_date,
    ?string $outreach_status,
    ?string $member_followup_status
  ): void {
    \Drupal::database()->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_date' => date('Y-m-d'),
        'snapshot_type' => 'daily',
        'stage' => 'recovery',
        'risk_score' => 50,
        'risk_reasons' => serialize(['payment_failed']),
        'serial_number_present' => 1,
        'badge_count_total' => 0,
        'badge_count_window' => 0,
        'visit_count_30d' => 0,
        'payment_failed' => 1,
        'payment_pause' => 0,
        'outreach_status' => $outreach_status,
        'next_followup_date' => $next_followup_date,
        'member_followup_status' => $member_followup_status,
        'contact_count' => 0,
        'is_latest' => 1,
        'created_at' => time(),
      ])
      ->execute();
  }

}
