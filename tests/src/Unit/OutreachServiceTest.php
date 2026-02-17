<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\makerspace_member_success\Service\CiviCrmActivityLogger;
use Drupal\makerspace_member_success\Service\OutreachService;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Tests OutreachService business logic.
 *
 * @group makerspace_member_success
 */
class OutreachServiceTest extends UnitTestCase {

  /**
   * Builds a minimal OutreachService with mock dependencies.
   *
   * @param array $snapshot_fields
   *   Fields returned by the snapshot SELECT query (e.g. ['contact_count' => 2]).
   * @param array &$snapshot_update
   *   Captured fields from the snapshot UPDATE query.
   * @param int|null $civicrm_activity_id
   *   Value returned by activity_logger->logRetentionContact().
   *
   * @return \Drupal\makerspace_member_success\Service\OutreachService
   *   The service under test.
   */
  protected function buildService(array $snapshot_fields, array &$snapshot_update, ?int $civicrm_activity_id = NULL): OutreachService {
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

    // Update query stub — capture the fields passed to it.
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnCallback(function (array $fields) use (&$snapshot_update, $update) {
      $snapshot_update = $fields;
      return $update;
    });
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(NULL);

    // Database mock.
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('insert')->willReturn($insert);
    $database->method('update')->willReturn($update);

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

    return new OutreachService($database, $entity_type_manager, $current_user, $logger_factory, $activity_logger);
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
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, $snapshot_update['outreach_status']);
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
    $this->assertSame(3, $snapshot_update['contact_count']);
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

}
