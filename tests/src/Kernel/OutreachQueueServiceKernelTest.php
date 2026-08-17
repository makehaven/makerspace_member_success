<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\OutreachMessageBuilderInterface;
use Drupal\makerspace_member_success\Service\OutreachPolicyDeciderInterface;
use Drupal\makerspace_member_success\Service\OutreachQueueService;
use Drupal\makerspace_member_success\Service\OutreachSenderInterface;
use Drupal\makerspace_member_success\Service\OutreachService;
use Drupal\makerspace_member_success\Service\OutreachSuppressionCheckerInterface;
use Drupal\makerspace_member_success\Support\OutreachDecision;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for outreach queue normalization and transitions.
 *
 * @group makerspace_member_success
 */
class OutreachQueueServiceKernelTest extends KernelTestBase {

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
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));
  }

  /**
   * Tests enqueue normalizes serialized risk reasons from snapshots.
   */
  public function testEnqueueNormalizesSerializedRiskReasons(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue']);

    $service = $this->buildQueueService();
    $id = $service->enqueueCandidate(101, 'retention', [
      'stage' => 'retention',
      'risk_score' => 45,
      'risk_reasons' => serialize(['payment_failed', 'inactive_30']),
      'contact_count' => 1,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member101@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ]);

    $row = $this->databaseRow($id);
    $this->assertSame('queued', (string) $row['status']);

    $reasons = $this->decodeReasons($row['risk_reasons'] ?? NULL);
    $this->assertSame(['payment_failed', 'inactive_30'], $reasons);
  }

  /**
   * Tests enqueue normalizes legacy double-serialized risk reasons.
   */
  public function testEnqueueNormalizesDoubleSerializedRiskReasons(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue']);

    $service = $this->buildQueueService();
    $id = $service->enqueueCandidate(102, 'retention', [
      'stage' => 'retention',
      'risk_score' => 45,
      'risk_reasons' => serialize(serialize(['inactive_60'])),
      'contact_count' => 1,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member102@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ]);

    $row = $this->databaseRow($id);
    $reasons = $this->decodeReasons($row['risk_reasons'] ?? NULL);
    $this->assertSame(['inactive_60'], $reasons);
  }

  /**
   * Tests manual sent transition updates queue status and metadata.
   */
  public function testMarkSent(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue']);

    $service = $this->buildQueueService();
    $id = $service->enqueueCandidate(103, 'retention', [
      'stage' => 'retention',
      'risk_score' => 45,
      'risk_reasons' => ['payment_failed'],
      'contact_count' => 1,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member103@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ]);

    $service->markSent($id, ['provider_message_id' => 'manual']);

    $row = $this->databaseRow($id);
    $this->assertSame('sent', (string) $row['status']);
    $this->assertSame('manual', (string) ($row['provider_message_id'] ?? ''));
    $this->assertNull($row['failure_message'] ?? NULL);
    $this->assertNotEmpty($row['sent_at']);
  }

  /**
   * Tests enqueue is idempotent for same user/stage open queue rows.
   */
  public function testEnqueueCandidateIdempotentForOpenRows(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue']);

    $service = $this->buildQueueService();
    $scheduled_at = time() + 3600;
    $first_id = $service->enqueueCandidate(104, 'retention', [
      'stage' => 'retention',
      'risk_score' => 35,
      'risk_reasons' => ['inactive_30'],
      'contact_count' => 1,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member104@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
      'scheduled_at' => $scheduled_at,
    ]);

    $second_id = $service->enqueueCandidate(104, 'retention', [
      'stage' => 'retention',
      'risk_score' => 60,
      'risk_reasons' => ['payment_failed'],
      'contact_count' => 1,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member104@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
      'scheduled_at' => $scheduled_at,
    ]);

    $this->assertSame($first_id, $second_id);

    $count = (int) $this->container->get('database')
      ->select('ms_member_outreach_queue', 'q')
      ->condition('q.uid', 104)
      ->condition('q.stage', 'retention')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count);

    $row = $this->databaseRow($first_id);
    $this->assertSame(60, (int) $row['risk_score']);
    $this->assertSame(['payment_failed'], $this->decodeReasons($row['risk_reasons'] ?? NULL));
  }

  /**
   * Tests gate suppressions reuse the member's open row instead of inserting.
   *
   * Regression test: the max-attempts/cooldown/below-threshold gates used to
   * insert a fresh suppressed row on every candidate run, so an exhausted
   * member accumulated a new row per cron run forever. They must dedup into
   * the one open row like every other enqueue outcome — and that row must be
   * revivable once the gates pass again.
   */
  public function testGateSuppressionReusesOpenRow(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue']);

    $service = $this->buildQueueService();
    $exhausted = [
      'stage' => 'retention',
      'risk_score' => 55,
      'risk_reasons' => ['inactive_30'],
      'contact_count' => 3,
      'last_contact_date' => date('Y-m-d', strtotime('-30 days')),
      'email' => 'member106@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ];

    $first_id = $service->enqueueCandidate(106, 'retention', $exhausted);
    $second_id = $service->enqueueCandidate(106, 'retention', $exhausted);

    $this->assertSame($first_id, $second_id);
    $count = (int) $this->container->get('database')
      ->select('ms_member_outreach_queue', 'q')
      ->condition('q.uid', 106)
      ->condition('q.stage', 'retention')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count);

    $row = $this->databaseRow($first_id);
    $this->assertSame('suppressed', (string) $row['status']);
    $this->assertSame('suppressed_max_attempts', (string) $row['suppression_reason_code']);

    // Once the gates pass again (e.g. attempts reset on a stage transition),
    // the same row revives instead of a duplicate appearing.
    $revived_id = $service->enqueueCandidate(106, 'retention', [
      'contact_count' => 1,
    ] + $exhausted);

    $this->assertSame($first_id, $revived_id);
    $row = $this->databaseRow($first_id);
    $this->assertSame('queued', (string) $row['status']);
  }

  /**
   * Tests cooldown uses outreach-log fallback when snapshot date is empty.
   */
  public function testEnqueueUsesOutreachLogFallbackForCooldownSuppression(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', [
      'ms_member_outreach_queue',
      'ms_member_success_snapshot',
      'ms_member_outreach_log',
    ]);

    $this->config('makerspace_member_success.settings')
      ->set('stage_retention_min_risk_to_contact', 20)
      ->set('stage_retention_max_attempts', 3)
      ->set('stage_retention_cooldown_days', 7)
      ->save();

    $now = time();
    $uid = 105;
    $this->container->get('database')->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => $uid,
        'snapshot_type' => 'daily',
        'snapshot_date' => date('Y-m-d'),
        'is_latest' => 1,
        'stage' => 'retention',
        'risk_score' => 55,
        'contact_count' => 0,
        'created_at' => $now,
      ])
      ->execute();

    $last_contact = date('Y-m-d', strtotime('-1 day'));
    $this->container->get('database')->insert('ms_member_outreach_log')
      ->fields([
        'uid' => $uid,
        'contact_date' => $last_contact,
        'contact_method' => 'email',
        'outcome' => 'email_sent',
        'notes' => 'recent outreach',
        'staff_uid' => 1,
        'created_at' => $now - 3600,
      ])
      ->execute();

    $service = $this->buildQueueService();
    $id = $service->enqueueCandidate($uid, 'retention', [
      'stage' => 'retention',
      'risk_score' => 55,
      'risk_reasons' => ['inactive_30'],
      'contact_count' => 0,
      'last_contact_date' => '',
      'email' => 'member105@example.com',
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ]);

    $row = $this->databaseRow($id);
    $this->assertSame('suppressed', (string) $row['status']);
    $this->assertSame('suppressed_cooldown', (string) $row['suppression_reason_code']);
  }

  /**
   * Builds a queue service with deterministic channel/template decisioning.
   */
  protected function buildQueueService(): OutreachQueueService {
    $policy_decider = new class implements OutreachPolicyDeciderInterface {
      public function decide(int $uid, array $snapshot): OutreachDecision {
        return new OutreachDecision('email', 'retention_email_template', 'pref_email', 70);
      }
    };

    return new OutreachQueueService(
      $this->container->get('database'),
      $this->container->get('datetime.time'),
      $policy_decider,
      $this->container->get('config.factory'),
      $this->createMock(OutreachMessageBuilderInterface::class),
      $this->createMock(OutreachSenderInterface::class),
      $this->container->get('entity_type.manager'),
      $this->createMock(OutreachService::class),
      $this->createMock(OutreachSuppressionCheckerInterface::class)
    );
  }

  /**
   * Loads a queue row by id.
   */
  protected function databaseRow(int $id): array {
    return $this->container->get('database')
      ->select('ms_member_outreach_queue', 'q')
      ->fields('q')
      ->condition('q.id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: [];
  }

  /**
   * Decodes serialized risk reasons with one-level legacy fallback.
   */
  protected function decodeReasons(mixed $raw): array {
    if (is_array($raw)) {
      return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
      return [];
    }
    $value = @unserialize($raw);
    if (is_array($value)) {
      return $value;
    }
    if (is_string($value) && trim($value) !== '') {
      $nested = @unserialize($value);
      if (is_array($nested)) {
        return $nested;
      }
    }
    return [];
  }

}
