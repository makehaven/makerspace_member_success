<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\HoldoutReport;
use Drupal\makerspace_member_success\Service\OutreachMessageBuilderInterface;
use Drupal\makerspace_member_success\Service\OutreachPolicyDeciderInterface;
use Drupal\makerspace_member_success\Service\OutreachQueueService;
use Drupal\makerspace_member_success\Service\OutreachSenderInterface;
use Drupal\makerspace_member_success\Service\OutreachService;
use Drupal\makerspace_member_success\Service\OutreachSuppressionCheckerInterface;
use Drupal\makerspace_member_success\Service\PauseReasonStore;
use Drupal\makerspace_member_success\Support\OutreachDecision;
use Drupal\makerspace_member_success\Support\OutreachHoldout;
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
   * Stale queued rows expire; fresh, approved and sent rows are untouched.
   */
  public function testExpireStaleQueued(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue', 'ms_member_success_snapshot', 'ms_member_outreach_log']);

    $service = $this->buildQueueService();
    $snapshot = static fn(int $uid): array => [
      'stage' => 'retention',
      'risk_score' => 45,
      'risk_reasons' => ['inactive_60'],
      'contact_count' => 0,
      'last_contact_date' => '',
      'email' => "member$uid@example.com",
      'phone' => '',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ];
    $stale = $service->enqueueCandidate(201, 'retention', $snapshot(201));
    $fresh = $service->enqueueCandidate(202, 'retention', $snapshot(202));
    $sent = $service->enqueueCandidate(203, 'retention', $snapshot(203));
    $service->markSent($sent, ['provider_message_id' => 'manual']);

    $old = \Drupal::time()->getCurrentTime() - 200 * 86400;
    $this->container->get('database')->update('ms_member_outreach_queue')
      ->fields(['updated_at' => $old])
      ->condition('id', [$stale, $sent], 'IN')
      ->execute();

    $this->assertSame(1, $service->expireStaleQueued(30));
    $this->assertSame('cancelled', (string) $this->databaseRow($stale)['status']);
    $this->assertSame('expired_stale', (string) $this->databaseRow($stale)['suppression_reason_code']);
    $this->assertSame('queued', (string) $this->databaseRow($fresh)['status']);
    $this->assertSame('sent', (string) $this->databaseRow($sent)['status']);
    $this->assertSame(0, $service->expireStaleQueued(0), '0 disables expiry.');

    // The member qualifying again reopens the same row.
    $reopened = $service->enqueueCandidate(201, 'retention', $snapshot(201));
    $this->assertSame($stale, $reopened);
    $this->assertSame('queued', (string) $this->databaseRow($stale)['status']);
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
   * Holdout members are logged held_out, cannot be approved, and are reported.
   */
  public function testHoldoutAssignmentAndReport(): void {
    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue', 'ms_member_success_snapshot', 'ms_member_outreach_log']);
    $this->config('makerspace_member_success.settings')
      ->set('holdout_percent', 25)
      ->set('holdout_stages', ['reengage'])
      ->set('holdout_window_days', 60)
      ->set('stage_reengage_min_risk_to_contact', 0)
      ->set('stage_reengage_max_attempts', 0)
      ->set('stage_reengage_cooldown_days', 0)
      ->save();

    $held_uid = $offer_uid = NULL;
    for ($uid = 500; $held_uid === NULL || $offer_uid === NULL; $uid++) {
      $arm = OutreachHoldout::arm($uid, 'reengage', 25, ['reengage']);
      if ($arm === OutreachHoldout::ARM_HOLDOUT) {
        $held_uid ??= $uid;
      }
      else {
        $offer_uid ??= $uid;
      }
    }

    $service = $this->buildQueueService();
    $snapshot = [
      'risk_score' => 0,
      'email' => 'member@example.com',
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'do_not_sms' => 0,
    ];
    $held_id = $service->enqueueCandidate($held_uid, 'reengage', $snapshot);
    $offer_id = $service->enqueueCandidate($offer_uid, 'reengage', $snapshot);
    // Recovery runs no holdout: no arm recorded.
    $recovery_id = $service->enqueueCandidate($held_uid, 'recovery', ['risk_score' => 90] + $snapshot);

    $held = $this->databaseRow($held_id);
    $this->assertSame('held_out', $held['status']);
    $this->assertSame('holdout', $held['suppression_reason_code']);
    $this->assertSame('holdout', $held['holdout_arm']);
    $offer = $this->databaseRow($offer_id);
    $this->assertSame('queued', $offer['status']);
    $this->assertSame('offer', $offer['holdout_arm']);
    $this->assertNull($this->databaseRow($recovery_id)['holdout_arm']);

    // A bulk approve must not release a held-out row.
    $service->approve($held_id, 1);
    $this->assertSame('held_out', $this->databaseRow($held_id)['status']);
    $service->approve($offer_id, 1);
    $this->assertSame('approved', $this->databaseRow($offer_id)['status']);

    // Rows only count toward outcomes once past the window.
    $this->container->get('database')->update('ms_member_outreach_queue')
      ->fields(['created_at' => time() - 90 * 86400])
      ->condition('id', [$held_id, $offer_id], 'IN')
      ->execute();
    $this->container->get('database')->update('ms_member_outreach_queue')
      ->fields(['status' => 'sent'])
      ->condition('id', $offer_id)
      ->execute();
    $report = (new HoldoutReport(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
    ))->build();
    $this->assertSame(['reengage'], array_keys($report));
    $this->assertSame(1, $report['reengage']['holdout']['assigned']);
    $this->assertSame(0, $report['reengage']['holdout']['contacted']);
    $this->assertSame(1, $report['reengage']['holdout']['matured']);
    $this->assertSame(1, $report['reengage']['offer']['contacted']);
    $this->assertSame(0, $report['reengage']['offer']['came_in']);
  }

  /**
   * The pause-reason question auto-sends on its own switch; answers store per pause.
   */
  public function testPauseReasonAutoApproveAndStore(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_outreach_queue', 'ms_pause_reason', 'ms_member_success_snapshot', 'ms_member_outreach_log']);
    $this->config('makerspace_member_success.settings')
      ->set('stage_pause_reason_min_risk_to_contact', 0)
      ->set('stage_pause_reason_max_attempts', 0)
      ->set('stage_pause_reason_cooldown_days', 0)
      ->set('pause_reason_enabled', FALSE)
      ->save();
    $service = $this->buildQueueService();
    $snapshot = ['risk_score' => 0, 'email' => 'p@example.com', 'is_opt_out' => 0, 'do_not_email' => 0, 'do_not_sms' => 0];

    $off_id = $service->enqueueCandidate(601, 'pause_reason', $snapshot);
    $this->assertSame('queued', $this->databaseRow($off_id)['status']);

    $this->config('makerspace_member_success.settings')->set('pause_reason_enabled', TRUE)->save();
    $on_id = $service->enqueueCandidate(602, 'pause_reason', $snapshot);
    $this->assertSame('approved', $this->databaseRow($on_id)['status']);

    $store = new PauseReasonStore($this->container->get('database'), $this->container->get('datetime.time'));
    $this->assertFalse($store->record(602, '2026-10-20', 'bogus'));
    $this->assertFalse($store->record(602, 'not-a-date', 'cost'));
    $this->assertNull($store->get(602, '2026-10-20'));
    $this->assertTrue($store->record(602, '2026-10-20', 'away'));
    // Changing the answer replaces it; a later pause gets its own row.
    $this->assertTrue($store->record(602, '2026-10-20', 'not_using', '  More classes on weekends  '));
    $this->assertTrue($store->record(602, '2027-02-01', 'school'));
    $this->assertSame('not_using', $store->get(602, '2026-10-20')['reason']);
    $this->assertSame('More classes on weekends', $store->get(602, '2026-10-20')['note']);
    $counts = $store->counts();
    ksort($counts);
    $this->assertSame(['not_using' => 1, 'school' => 1], $counts);
  }

  /**
   * Tests answers given before pausing join the pause that follows.
   */
  public function testPauseReasonBeforePauseAdoption(): void {
    $this->installSchema('makerspace_member_success', ['ms_pause_reason', 'ms_member_success_snapshot']);
    $db = $this->container->get('database');
    // hook_schema() lacks this column; an update hook adds it on real sites.
    if (!$db->schema()->fieldExists('ms_member_success_snapshot', 'pause_start_date')) {
      $db->schema()->addField('ms_member_success_snapshot', 'pause_start_date', ['type' => 'varchar', 'length' => 10, 'not null' => FALSE]);
    }
    $store = new PauseReasonStore($db, $this->container->get('datetime.time'));
    $today = date('Y-m-d');
    $in20 = date('Y-m-d', strtotime('+20 days'));
    $in60 = date('Y-m-d', strtotime('+60 days'));
    $snapshot = static function (int $uid, ?string $pauseStart) use ($db, $today): void {
      $db->insert('ms_member_success_snapshot')->fields([
        'uid' => $uid,
        'snapshot_date' => $today,
        'snapshot_type' => 'daily',
        'is_latest' => 1,
        'stage' => $pauseStart ? 'paused' : 'retention',
        'payment_pause' => $pauseStart ? 1 : 0,
        'pause_start_date' => $pauseStart,
      ])->execute();
    };

    $this->assertFalse($store->recordBeforePause(701, 'bogus'));
    // 701 pauses the same day; 702 at the end of the term, 20 days on;
    // 703 also answered the after-pause email (later answer wins);
    // 704 never pauses; 705's pause is too far out to be this answer's.
    foreach ([701 => 'away', 702 => 'cost', 703 => 'busy', 704 => 'school', 705 => 'other'] as $uid => $reason) {
      $this->assertTrue($store->recordBeforePause($uid, $reason, 'note ' . $uid));
    }
    $this->assertSame('cost', $store->getPending(702)['reason']);
    $store->record(703, $in20, 'not_using');
    $snapshot(701, $today);
    $snapshot(702, $in20);
    $snapshot(703, $in20);
    $snapshot(704, NULL);
    $snapshot(705, $in60);

    $this->assertSame(2, $store->adoptPending(45));
    $this->assertSame('away', $store->get(701, $today)['reason']);
    $this->assertNull($store->getPending(701));
    $this->assertSame('cost', $store->get(702, $in20)['reason']);
    $this->assertSame('note 702', $store->get(702, $in20)['note']);
    $this->assertNull($store->get(702, $today));
    $this->assertSame('not_using', $store->get(703, $in20)['reason']);
    $this->assertNull($store->get(703, $today));
    $this->assertSame('school', $store->getPending(704)['reason']);
    $this->assertSame('other', $store->getPending(705)['reason']);
    // Idempotent.
    $this->assertSame(0, $store->adoptPending(45));
    $source = $db->query('SELECT source FROM {ms_pause_reason} WHERE uid = 702')->fetchField();
    $this->assertSame('before_pause', $source);
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
