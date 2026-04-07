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
    if (getenv('MSS_ENABLE_CIVICRM_BROWSER_TESTS') !== '1') {
      $this->markTestSkipped('Set MSS_ENABLE_CIVICRM_BROWSER_TESTS=1 to run CiviCRM-dependent Browser tests.');
    }

    parent::setUp();

    $this->staffUser = $this->drupalCreateUser([
      'access makerspace member success queues',
      'access user profiles',
      'log makerspace member success contacts',
    ]);
  }

  /**
   * Tests hidden recovery members move to the secondary hidden-members table.
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

    $main_table = $this->assertSession()->elementExists('css', 'table.views-table');
    $main_text = $main_table->getText();
    $this->assertStringContainsString('Queue Visible Now', $main_text);
    $this->assertStringContainsString('Queue Visible Due', $main_text);
    $this->assertStringNotContainsString('Queue Hidden Snoozed', $main_text);
    $this->assertStringNotContainsString('Queue Hidden Exhausted', $main_text);
    $this->assertStringNotContainsString('Queue Hidden Cancel', $main_text);

    $this->assertSession()->pageTextContains('Snoozed Recovery Members (1)');
    $this->assertSession()->pageTextContains('Suppressed Recovery Members (2)');

    $snoozed_table = $this->assertSession()->elementExists('css', '.ms-snoozed-recovery-table');
    $snoozed_text = $snoozed_table->getText();
    $this->assertStringContainsString('Queue Hidden Snoozed', $snoozed_text);
    $this->assertStringContainsString('Snoozed until ' . $future, $snoozed_text);

    $suppressed_details = $this->assertSession()->elementExists('xpath', '//details[contains(., "Suppressed Recovery Members")]');
    $this->assertFalse($suppressed_details->hasAttribute('open'), 'Suppressed recovery members table should be collapsed by default.');
    $suppressed_table = $this->assertSession()->elementExists('css', '.ms-suppressed-recovery-table');
    $suppressed_text = $suppressed_table->getText();
    $this->assertStringContainsString('Queue Hidden Exhausted', $suppressed_text);
    $this->assertStringContainsString('Queue Hidden Cancel', $suppressed_text);
    $this->assertStringContainsString('Suppressed after outreach exhausted', $suppressed_text);
    $this->assertStringContainsString('Suppressed after confirmed cancellation', $suppressed_text);
    $this->assertStringContainsString('Log Interaction', $suppressed_text);
  }

  /**
   * Tests the recovery queue empty-state when all members are hidden.
   */
  public function testRecoveryQueueShowsEmptyStateWhenOnlyHiddenMembersRemain(): void {
    $future = date('Y-m-d', strtotime('+7 days'));

    $hidden_snoozed = User::create([
      'name' => 'Recovery Snoozed Only',
      'mail' => 'recovery.snoozed.only@example.com',
      'status' => 1,
    ]);
    $hidden_snoozed->save();

    $hidden_exhausted = User::create([
      'name' => 'Recovery Exhausted Only',
      'mail' => 'recovery.exhausted.only@example.com',
      'status' => 1,
    ]);
    $hidden_exhausted->save();

    $this->insertSnapshotRow($hidden_snoozed->id(), $future, NULL, NULL);
    $this->insertSnapshotRow($hidden_exhausted->id(), NULL, 'outreach_exhausted', NULL);

    $this->drupalLogin($this->staffUser);
    $this->drupalGet('/admin/makerspace/member-success/recovery');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->pageTextContains('No members need immediate recovery outreach today');
    $this->assertSession()->pageTextContains('Current hidden counts:');
    $this->assertSession()->pageTextContains('2 hidden total, 1 snoozed for later follow-up, 1 suppressed by status.');
    $this->assertSession()->pageTextContains('Snoozed Recovery Members (1)');
    $this->assertSession()->pageTextContains('Suppressed Recovery Members (1)');

    $snoozed_table = $this->assertSession()->elementExists('css', '.ms-snoozed-recovery-table');
    $this->assertStringContainsString('Recovery Snoozed Only', $snoozed_table->getText());

    $suppressed_details = $this->assertSession()->elementExists('xpath', '//details[contains(., "Suppressed Recovery Members")]');
    $this->assertFalse($suppressed_details->hasAttribute('open'), 'Suppressed recovery members table should be collapsed by default.');
    $suppressed_table = $this->assertSession()->elementExists('css', '.ms-suppressed-recovery-table');
    $this->assertStringContainsString('Recovery Exhausted Only', $suppressed_table->getText());
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
