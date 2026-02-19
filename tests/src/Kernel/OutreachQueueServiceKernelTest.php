<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\OutreachPolicyDeciderInterface;
use Drupal\makerspace_member_success\Service\OutreachQueueService;
use Drupal\makerspace_member_success\Support\OutreachDecision;

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
    'civicrm',
    'civicrm_entity',
    'makerspace_member_success',
  ];

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
    $this->assertStringStartsWith('provider_message_id:manual', (string) ($row['failure_message'] ?? ''));
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
      $this->container->get('config.factory')
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
