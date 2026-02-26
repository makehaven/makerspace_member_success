<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync;
use Drupal\makerspace_member_success\Service\CiviCrmActivityLogger;
use Drupal\makerspace_member_success\Service\CiviFollowupGroupSync;
use Drupal\makerspace_member_success\Service\OutreachService;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests OutreachService business logic.
 *
 * @group makerspace_member_success
 */
class OutreachServiceTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Builds a minimal OutreachService with mock dependencies.
   *
   * @param array $snapshot_fields
   *   Fields returned by the snapshot SELECT query (e.g. ['contact_count' => 2]).
   * @param array &$snapshot_update
   *   Captured fields from the snapshot UPDATE query.
   * @param int|null $civicrm_activity_id
   *   Value returned by activity_logger->logRetentionContact().
   * @param array $queue_update
   *   Captured fields from the queue UPDATE query.
   * @param array $queue_update_conditions
   *   Captured conditions from the queue UPDATE query.
   *
   * @return \Drupal\makerspace_member_success\Service\OutreachService
   *   The service under test.
   */
  protected function buildService(
    array $snapshot_fields,
    array &$snapshot_update,
    ?int $civicrm_activity_id = NULL,
    array &$queue_update = [],
    array &$queue_update_conditions = []
  ): OutreachService {
    // Statement stub for SELECT.
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn($snapshot_fields['contact_count'] ?? 0);

    // Select query stub.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    // Insert query stub.
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(NULL);

    // Snapshot update query stub — capture snapshot updates.
    $snapshot_update_query = $this->createMock(Update::class);
    $snapshot_update_query->method('fields')->willReturnCallback(function (array $fields) use (&$snapshot_update, $snapshot_update_query) {
      $snapshot_update = $fields;
      return $snapshot_update_query;
    });
    $snapshot_update_query->method('expression')->willReturnSelf();
    $snapshot_update_query->method('condition')->willReturnSelf();
    $snapshot_update_query->method('execute')->willReturn(NULL);

    // Queue update query stub — capture queue handled fields and conditions.
    $queue_update_query = $this->createMock(Update::class);
    $queue_update_query->method('fields')->willReturnCallback(function (array $fields) use (&$queue_update, $queue_update_query) {
      $queue_update = $fields;
      return $queue_update_query;
    });
    $queue_update_query->method('condition')->willReturnCallback(function ($field, $value = NULL, $operator = '=') use (&$queue_update_conditions, $queue_update_query) {
      $queue_update_conditions[] = [$field, $value, $operator];
      return $queue_update_query;
    });
    $queue_update_query->method('execute')->willReturn(NULL);

    // Database mock.
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('update')->willReturnCallback(function (string $table) use ($snapshot_update_query, $queue_update_query) {
      return $table === 'ms_member_outreach_queue' ? $queue_update_query : $snapshot_update_query;
    });
    
    $database->method('startTransaction')->willReturn(new class {
      public function rollBack() {}
    });

    // Entity type manager (not called unless confirmed_cancel with reason).
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    // Current user stub.
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(1);

    // Logger stub.
    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    // Activity logger stub.
    $activity_logger = $this->createMock(CiviCrmActivityLogger::class);
    $activity_logger->method('logRetentionContact')->willReturn($civicrm_activity_id);

    $followup_group_sync = $this->createMock(CiviFollowupGroupSync::class);
    $chargebee_followup_sync = $this->createMock(ChargebeeFollowupStatusSync::class);

    return new OutreachService(
      $database,
      $entity_type_manager,
      $current_user,
      $logger_factory,
      $activity_logger,
      $followup_group_sync,
      $chargebee_followup_sync
    );
  }

  /**
   * Builds a minimal User mock.
   *
   * @param int $uid
   *   User ID.
   *
   * @return \Drupal\user\Entity\User
   *   Mocked user.
   */
  protected function buildUser(int $uid = 42): User {
    $user = $this->createMock(User::class);
    $user->method('id')->willReturn($uid);
    $user->method('hasField')->willReturn(FALSE);
    $user->method('getDisplayName')->willReturn('Test Member');
    return $user;
  }

  /**
   * Tests that outreach_status is written to snapshot immediately.
   */
  public function testSnapshotOutreachStatusWrittenImmediately(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 0], $snapshot_update);

    $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RETENTION,
      'phone',
      MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
      '',
      FALSE,
      FALSE
    );

    $this->assertArrayHasKey('outreach_status', $snapshot_update);
    $this->assertArrayHasKey('member_followup_status', $snapshot_update);
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, $snapshot_update['outreach_status']);
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, $snapshot_update['member_followup_status']);
  }

  /**
   * Tests that next_followup_date is computed correctly per outcome.
   */
  public function testNextFollowupDateForSnoozedOutcome(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 0], $snapshot_update);

    $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'phone',
      MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
      '',
      FALSE,
      FALSE
    );

    $this->assertArrayHasKey('next_followup_date', $snapshot_update);
    $expected = date('Y-m-d', strtotime('+3 days'));
    $this->assertSame($expected, $snapshot_update['next_followup_date']);
  }

  /**
   * Tests that permanent outcomes set next_followup_date to 9999-12-31.
   */
  public function testPermanentOutcomeSetsDistantFollowupDate(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 0], $snapshot_update);

    $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RETENTION,
      'in_person',
      MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED,
      '',
      FALSE,
      FALSE
    );

    $this->assertSame('9999-12-31', $snapshot_update['next_followup_date']);
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED, $snapshot_update['outreach_status']);
  }

  /**
   * Tests that contact count is incremented from the previous snapshot value.
   */
  public function testContactCountIsIncremented(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 2], $snapshot_update);

    $result = $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'email',
      MemberSuccessLifecycle::OUTCOME_EMAIL_SENT,
      'Sent follow-up email.',
      FALSE,
      FALSE
    );

    $this->assertSame(3, $result['contact_count']);
    // contact_count is now updated via expression, so it's not in $snapshot_update.
    $this->assertArrayNotHasKey('contact_count', $snapshot_update);
  }

  /**
   * Tests payment_updated outcome: NULL outreach_status, far-future date.
   */
  public function testPaymentUpdatedOutcome(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 1], $snapshot_update);

    $result = $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'phone',
      MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED,
      '',
      FALSE,
      FALSE
    );

    $this->assertNull($snapshot_update['outreach_status']);
    $this->assertSame('9999-12-31', $snapshot_update['next_followup_date']);
    $this->assertSame(2, $result['contact_count']);
  }

  /**
   * Tests mark_exhausted overrides outcome followup status.
   */
  public function testMarkExhaustedOverridesStatus(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 3], $snapshot_update);

    $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'phone',
      MemberSuccessLifecycle::OUTCOME_NO_ANSWER,
      '',
      TRUE,  // mark_exhausted
      FALSE
    );

    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, $snapshot_update['outreach_status']);
  }

  /**
   * Tests zero-sleep outcome sets NULL next_followup_date.
   */
  public function testZeroSleepOutcomeSetsNullFollowupDate(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 0], $snapshot_update);

    $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RETENTION,
      'email',
      MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT,
      '',
      FALSE,
      FALSE
    );

    $this->assertNull($snapshot_update['next_followup_date']);
  }

  /**
   * Tests CiviCRM activity ID is returned in result.
   */
  public function testCiviCrmActivityIdReturnedInResult(): void {
    $snapshot_update = [];
    $service = $this->buildService(['contact_count' => 0], $snapshot_update, 999);

    $result = $service->recordContact(
      $this->buildUser(),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'phone',
      MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE,
      'Left voicemail.',
      FALSE,
      TRUE  // log_in_civicrm
    );

    $this->assertSame(999, $result['civicrm_activity_id']);
  }

  /**
   * Tests queue rows for same member/stage are marked sent after contact log.
   */
  public function testRecordContactMarksMatchingQueueItemsHandledAsSent(): void {
    $snapshot_update = [];
    $queue_update = [];
    $queue_update_conditions = [];
    $service = $this->buildService(
      ['contact_count' => 1],
      $snapshot_update,
      NULL,
      $queue_update,
      $queue_update_conditions
    );

    $service->recordContact(
      $this->buildUser(42),
      MemberSuccessLifecycle::STAGE_RECOVERY,
      'email',
      MemberSuccessLifecycle::OUTCOME_EMAIL_SENT,
      'manual follow-up',
      FALSE,
      FALSE
    );

    $this->assertSame('sent', $queue_update['status'] ?? NULL);
    $this->assertTrue(!array_key_exists('failure_code', $queue_update) || $queue_update['failure_code'] === NULL);
    $this->assertTrue(!array_key_exists('failure_message', $queue_update) || $queue_update['failure_message'] === NULL);
    $this->assertNotEmpty($queue_update['sent_at'] ?? NULL);
    $this->assertNotEmpty($queue_update['updated_at'] ?? NULL);
    $this->assertContains(['uid', 42, '='], $queue_update_conditions);
    $this->assertContains(['stage', MemberSuccessLifecycle::STAGE_RECOVERY, '='], $queue_update_conditions);
    $this->assertContains(['status', ['queued', 'approved'], 'IN'], $queue_update_conditions);
  }

}
