<?php

namespace Drupal\Tests\makerspace_member_success\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Browser test for Queue Review bulk actions.
 *
 * This test is opt-in because CiviCRM BrowserTest bootstrap requires extra
 * environment wiring in some local/CI setups.
 *
 * @group makerspace_member_success
 */
class OutreachQueueReviewBulkActionsBrowserTest extends BrowserTestBase {

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
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Member user for queue rows.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $memberUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (getenv('MSS_ENABLE_CIVICRM_BROWSER_TESTS') !== '1') {
      $this->markTestSkipped('Set MSS_ENABLE_CIVICRM_BROWSER_TESTS=1 to run CiviCRM-dependent Browser tests.');
    }

    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer makerspace member success',
      'log makerspace member success contacts',
      'access user profiles',
    ]);
    $this->memberUser = $this->drupalCreateUser();
  }

  /**
   * Tests queue review approve bulk action through the UI.
   */
  public function testApproveBulkActionViaUi(): void {
    $this->drupalLogin($this->adminUser);

    $queue_id = $this->insertQueueRow('queued');
    $this->drupalGet('/admin/makerspace/member-success/queue-review?status=queued');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      "queue_items[$queue_id]" => $queue_id,
      'bulk[action]' => 'approve',
    ], 'Apply to selected');

    $this->assertSession()->pageTextContains('Updated 1 queue items.');
    $row = $this->loadQueueRow($queue_id);
    $this->assertSame('approved', (string) $row['status']);
    $this->assertSame((int) $this->adminUser->id(), (int) ($row['approved_by_uid'] ?? 0));
    $this->assertNotEmpty($row['approved_at']);
  }

  /**
   * Inserts a queue row for browser test.
   */
  protected function insertQueueRow(string $status = 'queued'): int {
    $now = time();
    return (int) \Drupal::database()->insert('ms_member_outreach_queue')
      ->fields([
        'uid' => (int) $this->memberUser->id(),
        'stage' => 'retention',
        'risk_score' => 42,
        'risk_reasons' => serialize(['inactive_30']),
        'recommended_channel' => 'email',
        'recommended_template_id' => 'tpl_retention_email',
        'recommended_reason_code' => 'pref_email',
        'destination_email' => 'member@example.com',
        'policy_version' => 'v1',
        'status' => $status,
        'scheduled_at' => $now + 3600,
        'created_at' => $now,
        'updated_at' => $now,
      ])
      ->execute();
  }

  /**
   * Loads a queue row by ID.
   */
  protected function loadQueueRow(int $id): array {
    return \Drupal::database()->select('ms_member_outreach_queue', 'q')
      ->fields('q')
      ->condition('q.id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: [];
  }

}

