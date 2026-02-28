<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Form\OutreachQueueReviewForm;
use Drupal\makerspace_member_success\Service\FollowupStatusManager;
use Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for queue-review form row filtering behavior.
 *
 * @group makerspace_member_success
 */
class OutreachQueueReviewFormKernelTest extends KernelTestBase {

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
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));
  }

  /**
   * Tests queued rows are hidden when stale/recent outreach exists.
   */
  public function testLoadRowsHidesStaleAndRecentQueuedRows(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', [
      'ms_member_outreach_queue',
      'ms_member_success_snapshot',
      'ms_member_outreach_log',
    ]);

    $now = time();
    $today = date('Y-m-d');

    // UID 201: queued row should be hidden because outreach log created after
    // queue row (stale work).
    $this->insertSnapshotRow(201, 'recovery', $today, $now);
    $this->insertQueueRow(201, 'recovery', 'queued', $now - 7200, $now + 3600);
    $this->insertOutreachLogRow(201, $today, $now - 1800);

    // UID 202: queued row remains visible; no recent or stale outreach record.
    $this->insertSnapshotRow(202, 'recovery', $today, $now);
    $this->insertQueueRow(202, 'recovery', 'queued', $now - 7200, $now + 3600);
    $this->insertOutreachLogRow(202, date('Y-m-d', strtotime('-20 days')), $now - 86400 * 20);

    $form = new class(
      $this->container->get('database'),
      $this->createMock(OutreachQueueServiceInterface::class),
      $this->createMock(FollowupStatusManager::class)
    ) extends OutreachQueueReviewForm {
      public function loadRowsForTest(string $status, string $stage = ''): array {
        return $this->loadRows($status, $stage);
      }
    };

    $rows = $form->loadRowsForTest('queued', 'recovery');
    $uids = array_map(static fn($row) => (int) $row->uid, $rows);

    $this->assertNotContains(201, $uids);
    $this->assertContains(202, $uids);
  }

  /**
   * Inserts an is_latest snapshot row used by queue filtering.
   */
  protected function insertSnapshotRow(int $uid, string $stage, string $today, int $createdAt): void {
    $this->container->get('database')->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_type' => 'daily',
        'snapshot_date' => $today,
        'is_latest' => 1,
        'stage' => $stage,
        'risk_score' => 50,
        'next_followup_date' => NULL,
        'outreach_status' => NULL,
        'member_followup_status' => NULL,
        'created_at' => $createdAt,
      ])
      ->execute();
  }

  /**
   * Inserts a queue row used by loadRows().
   */
  protected function insertQueueRow(int $uid, string $stage, string $status, int $createdAt, int $scheduledAt): void {
    $this->container->get('database')->insert('ms_member_outreach_queue')
      ->fields([
        'uid' => $uid,
        'stage' => $stage,
        'risk_score' => 50,
        'risk_reasons' => serialize(['payment_failed']),
        'recommended_channel' => 'email',
        'recommended_template_id' => '167',
        'recommended_reason_code' => 'pref_email',
        'status' => $status,
        'scheduled_at' => $scheduledAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
      ])
      ->execute();
  }

  /**
   * Inserts an outreach-log row for stale/cooldown filtering checks.
   */
  protected function insertOutreachLogRow(int $uid, string $contactDate, int $createdAt): void {
    $this->container->get('database')->insert('ms_member_outreach_log')
      ->fields([
        'uid' => $uid,
        'contact_date' => $contactDate,
        'contact_method' => 'email',
        'outcome' => 'email_sent',
        'notes' => 'test',
        'staff_uid' => 1,
        'created_at' => $createdAt,
      ])
      ->execute();
  }

}
