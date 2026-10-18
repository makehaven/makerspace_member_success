<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;

/**
 * Pending members are held for two business days; dismiss keeps a note.
 *
 * @group makerspace_member_success
 */
class BillingReconcilePendingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * Thursday 2026-10-15 10:00 New York.
   */
  protected int $paidAt;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    date_default_timezone_set('America/New_York');
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    require_once __DIR__ . '/../../../makerspace_member_success.install';
    $schema = $this->container->get('database')->schema();
    $schema->createTable('ms_billing_mismatch', _makerspace_member_success_billing_mismatch_schema());
    $schema->createTable('user__field_user_chargebee_id', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_user_chargebee_id_value' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
      ],
    ]);
    $this->paidAt = (new \DateTimeImmutable('2026-10-15 10:00', new \DateTimeZone('America/New_York')))->getTimestamp();
  }

  /**
   * Adds a linked account with the given roles.
   */
  protected function addAccount(int $uid, string $customer_id, array $roles): void {
    $db = $this->container->get('database');
    $db->insert('users')->fields(['uid' => $uid, 'uuid' => "uuid-$uid", 'langcode' => 'en'])->execute();
    $db->insert('users_field_data')->fields([
      'uid' => $uid,
      'langcode' => 'en',
      'preferred_langcode' => 'en',
      'name' => "user$uid",
      'mail' => "user$uid@example.com",
      'status' => 1,
      'created' => $this->paidAt,
      'changed' => $this->paidAt,
      'access' => 0,
      'login' => 0,
      'default_langcode' => 1,
    ])->execute();
    $db->insert('user__field_user_chargebee_id')->fields(['entity_id' => $uid, 'field_user_chargebee_id_value' => $customer_id])->execute();
    foreach (array_values($roles) as $delta => $role) {
      $db->insert('user__roles')->fields([
        'bundle' => 'user',
        'deleted' => 0,
        'entity_id' => $uid,
        'revision_id' => $uid,
        'langcode' => 'en',
        'delta' => $delta,
        'roles_target_id' => $role,
      ])->execute();
    }
  }

  /**
   * A reconciler that reads fixed subscriptions at a fixed time.
   */
  protected function reconcilerAt(string $when): ChargebeeAccountReconciler {
    $now = (new \DateTimeImmutable($when, new \DateTimeZone('America/New_York')))->getTimestamp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $subscriptions = [];
    foreach (['cust_pending' => 'sub_pending', 'cust_norole' => 'sub_norole'] as $customer => $sub) {
      $subscriptions[] = [
        'subscription' => ['id' => $sub, 'customer_id' => $customer, 'status' => 'active', 'created_at' => $this->paidAt, 'plan_id' => 'membership-2024-update'],
        'customer' => ['email' => "$customer@example.com", 'first_name' => $customer],
      ];
    }
    return new class(
      $this->container->get('database'),
      $this->container->get('http_client'),
      $this->container->get('config.factory'),
      $this->container->get('plugin.manager.mail'),
      $this->container->get('state'),
      $time,
      $this->container->get('logger.factory'),
      NULL,
      $subscriptions,
    ) extends ChargebeeAccountReconciler {

      public function __construct($database, $http, $config, $mail, $state, $time, $logger, $etm, protected array $fixtures) {
        parent::__construct($database, $http, $config, $mail, $state, $time, $logger, $etm);
      }

      protected function fetchPayingSubscriptions(): array {
        return $this->fixtures;
      }

    };
  }

  /**
   * Open rows keyed by subscription id.
   */
  protected function openSubscriptions(ChargebeeAccountReconciler $reconciler): array {
    return array_column($reconciler->getOpen(), 'subscription_id');
  }

  /**
   * Tests that a pending member is listed only once two business days pass.
   */
  public function testPendingMemberHeldForTwoBusinessDays(): void {
    $this->addAccount(10, 'cust_pending', [ChargebeeAccountReconciler::PENDING_ROLE]);
    $this->addAccount(11, 'cust_norole', []);

    // Thursday evening: the non-pending account is a miss; the pending one is
    // waiting for staff.
    $this->reconcilerAt('2026-10-15 20:00')->reconcile();
    $this->assertSame(['sub_norole'], $this->openSubscriptions($this->reconcilerAt('2026-10-15 20:00')));

    // Monday 09:59, just inside two business days: still held.
    $stats = $this->reconcilerAt('2026-10-19 09:59')->reconcile();
    $this->assertSame(0, $stats['new']);
    $this->assertNotContains('sub_pending', $this->openSubscriptions($this->reconcilerAt('2026-10-19 09:59')));

    // Monday 10:01: late, so it is listed and counted as new (emailed).
    $stats = $this->reconcilerAt('2026-10-19 10:01')->reconcile();
    $this->assertSame(1, $stats['new']);
    $this->assertContains('sub_pending', $this->openSubscriptions($this->reconcilerAt('2026-10-19 10:01')));
  }

  /**
   * Tests that dismissing keeps the note, and a changed problem clears it.
   */
  public function testDismissKeepsNote(): void {
    $this->addAccount(11, 'cust_norole', []);
    $reconciler = $this->reconcilerAt('2026-10-16 12:00');
    $reconciler->reconcile();
    $reconciler->dismiss('sub_norole', 1, 'Comped by Kate, set up by hand.');

    $row = $this->container->get('database')->select('ms_billing_mismatch', 'm')
      ->fields('m', ['dismissed_by', 'dismiss_note'])
      ->condition('subscription_id', 'sub_norole')
      ->execute()->fetchAssoc();
    $this->assertSame('Comped by Kate, set up by hand.', $row['dismiss_note']);
    $this->assertNotContains('sub_norole', $this->openSubscriptions($reconciler));

    // The next day's run leaves the dismissal and its note alone.
    $this->reconcilerAt('2026-10-17 12:00')->reconcile();
    $note = $this->container->get('database')->select('ms_billing_mismatch', 'm')
      ->fields('m', ['dismiss_note'])->condition('subscription_id', 'sub_norole')
      ->execute()->fetchField();
    $this->assertSame('Comped by Kate, set up by hand.', $note);
  }

}
