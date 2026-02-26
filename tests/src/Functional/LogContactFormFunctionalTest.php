<?php

namespace Drupal\Tests\makerspace_member_success\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Functional tests for Log Contact form workflow.
 *
 * @group makerspace_member_success
 */
class LogContactFormFunctionalTest extends BrowserTestBase {

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
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Test member user.
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

    // Create admin user with permission to log contacts
    $this->adminUser = $this->drupalCreateUser([
      'access makerspace member success queues',
      'log makerspace member success contacts',
      'access user profiles',
    ]);

    // Create test member
    $this->memberUser = $this->drupalCreateUser();
    $this->memberUser->set('field_member_followup_status', 'outreach_active');
    $this->memberUser->save();

    // Create a snapshot for the test member
    $this->createSnapshot($this->memberUser);
  }

  /**
   * Test logging a phone contact with "Will Return" outcome.
   */
  public function testLogPhoneContactWillReturn() {
    $this->drupalLogin($this->adminUser);

    // Navigate to log contact form
    $this->drupalGet('/admin/makerspace/member-success/log-contact/' . $this->memberUser->id());
    $this->assertSession()->statusCodeEquals(200);

    // Verify phone number display (if implemented)
    // $this->assertSession()->pageTextContains('Phone:');

    // Fill out form
    $edit = [
      'contact_method' => 'phone',
      'outcome' => 'will_return',
      'notes' => 'Member will rejoin in March',
      'mark_exhausted' => FALSE,
      'log_in_civicrm' => FALSE, // Disable for testing without CiviCRM
    ];
    $this->submitForm($edit, 'Log Contact');

    // Verify success message
    $this->assertSession()->pageTextContains('Contact logged for');

    // Verify snapshot was updated with outreach tracking
    $database = \Drupal::database();
    $snapshot = $database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['last_contact_date', 'next_followup_date', 'contact_count'])
      ->condition('uid', $this->memberUser->id())
      ->condition('is_latest', 1)
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(date('Y-m-d'), $snapshot['last_contact_date']);
    $this->assertEquals(1, $snapshot['contact_count']);
    // Will return = 14 days sleep
    $expected_followup = date('Y-m-d', strtotime('+14 days'));
    $this->assertEquals($expected_followup, $snapshot['next_followup_date']);

    // Verify followup status was auto-mapped to "return_intent"
    $updated_user = User::load($this->memberUser->id());
    $this->assertEquals('return_intent', $updated_user->get('field_member_followup_status')->value);

    // Verify outreach log entry was created
    $log_entry = $database->select('ms_member_outreach_log', 'log')
      ->fields('log', ['contact_method', 'outcome', 'notes'])
      ->condition('uid', $this->memberUser->id())
      ->orderBy('created_at', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $this->assertEquals('phone', $log_entry['contact_method']);
    $this->assertEquals('will_return', $log_entry['outcome']);
    $this->assertEquals('Member will rejoin in March', $log_entry['notes']);
  }

  /**
   * Test logging email contact with "Sent - Awaiting Reply" outcome.
   */
  public function testLogEmailContactSent() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/makerspace/member-success/log-contact/' . $this->memberUser->id());

    $edit = [
      'contact_method' => 'email',
      'outcome' => 'email_sent',
      'notes' => 'Sent payment reminder email',
      'mark_exhausted' => FALSE,
      'log_in_civicrm' => FALSE,
    ];
    $this->submitForm($edit, 'Log Contact');

    // Verify 7-day sleep period
    $database = \Drupal::database();
    $snapshot = $database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['next_followup_date'])
      ->condition('uid', $this->memberUser->id())
      ->condition('is_latest', 1)
      ->execute()
      ->fetchField();

    $expected_followup = date('Y-m-d', strtotime('+7 days'));
    $this->assertEquals($expected_followup, $snapshot);

    // Verify status mapped to "outreach_active"
    $updated_user = User::load($this->memberUser->id());
    $this->assertEquals('outreach_active', $updated_user->get('field_member_followup_status')->value);
  }

  /**
   * Test logging contact with "Payment Updated" resolves member.
   */
  public function testLogContactPaymentUpdatedResolved() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/makerspace/member-success/log-contact/' . $this->memberUser->id());

    $edit = [
      'contact_method' => 'phone',
      'outcome' => 'payment_updated',
      'notes' => 'Updated card on file',
      'mark_exhausted' => FALSE,
      'log_in_civicrm' => FALSE,
    ];
    $this->submitForm($edit, 'Log Contact');

    // Verify permanent resolution (far future date)
    $database = \Drupal::database();
    $snapshot = $database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['next_followup_date'])
      ->condition('uid', $this->memberUser->id())
      ->condition('is_latest', 1)
      ->execute()
      ->fetchField();

    $this->assertEquals('9999-12-31', $snapshot);

    // Verify followup status is NULL (removed from queue)
    $updated_user = User::load($this->memberUser->id());
    $this->assertNull($updated_user->get('field_member_followup_status')->value);
  }

  /**
   * Test manual "Mark as Outreach Exhausted" override.
   */
  public function testLogContactManualExhausted() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/makerspace/member-success/log-contact/' . $this->memberUser->id());

    $edit = [
      'contact_method' => 'phone',
      'outcome' => 'no_answer',
      'notes' => 'Third attempt, no callback',
      'mark_exhausted' => TRUE, // Manual override
      'log_in_civicrm' => FALSE,
    ];
    $this->submitForm($edit, 'Log Contact');

    // Verify status overridden to "outreach_exhausted"
    $updated_user = User::load($this->memberUser->id());
    $this->assertEquals('outreach_exhausted', $updated_user->get('field_member_followup_status')->value);
  }

  /**
   * Test conditional outcome options based on contact method.
   */
  public function testConditionalOutcomeOptions() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/makerspace/member-success/log-contact/' . $this->memberUser->id());

    // Verify phone options are present
    $this->assertSession()->optionExists('outcome', 'will_return');
    $this->assertSession()->optionExists('outcome', 'no_answer');
    $this->assertSession()->optionExists('outcome', 'left_message');

    // Note: Testing AJAX conditional display would require JavaScript testing
    // which is better suited for Playwright E2E tests
  }

  /**
   * Helper to create a snapshot for a user.
   */
  protected function createSnapshot(User $user) {
    $database = \Drupal::database();
    $database->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $user->id(),
        'snapshot_date' => date('Y-m-d'),
        'snapshot_type' => 'daily',
        'stage' => 'recovery',
        'risk_score' => 50,
        'is_latest' => 1,
        'payment_failed' => 1,
        'payment_pause' => 0,
        'outreach_status' => 'pending',
        'contact_count' => 0,
        'created_at' => time(),
      ])
      ->execute();
  }

}
