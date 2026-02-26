<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\makerspace_member_success\Form\OutreachQueueReviewForm;
use Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync;
use Drupal\makerspace_member_success\Service\CiviFollowupGroupSync;
use Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for Queue Review bulk submit behavior.
 *
 * @group makerspace_member_success
 */
class OutreachQueueReviewBulkActionsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests approve action delegates to queue service approve().
   */
  public function testApproveAction(): void {
    $queue_service = $this->createMock(OutreachQueueServiceInterface::class);
    $queue_service->expects($this->once())
      ->method('approve')
      ->with(123, 99);

    $form = $this->buildForm($queue_service, 99);
    $form_state = new FormState();
    $form_state->setValue('queue_items', [123 => 123]);
    $form_state->setValue('bulk', ['action' => 'approve', 'reason' => 'manual_suppressed']);
    $render = [];
    $form->submitForm($render, $form_state);
  }

  /**
   * Tests approve action when form values are flattened (non-tree input).
   */
  public function testApproveActionWithFlattenedValues(): void {
    $queue_service = $this->createMock(OutreachQueueServiceInterface::class);
    $queue_service->expects($this->once())
      ->method('approve')
      ->with(321, 99);

    $form = $this->buildForm($queue_service, 99);
    $form_state = new FormState();
    $form_state->setValue('queue_items', [321 => 321]);
    $form_state->setValue('action', 'approve');
    $form_state->setValue('reason', 'manual_suppressed');
    $render = [];
    $form->submitForm($render, $form_state);
  }

  /**
   * Tests suppress action delegates to queue service suppress().
   */
  public function testSuppressAction(): void {
    $queue_service = $this->createMock(OutreachQueueServiceInterface::class);
    $queue_service->expects($this->once())
      ->method('suppress')
      ->with(124, 'handled_offline');

    $form = $this->buildForm($queue_service, 99);
    $form_state = new FormState();
    $form_state->setValue('queue_items', [124 => 124]);
    $form_state->setValue('bulk', ['action' => 'suppress', 'reason' => 'handled_offline']);
    $render = [];
    $form->submitForm($render, $form_state);
  }

  /**
   * Tests mark sent action delegates to queue service markSent().
   */
  public function testMarkSentManualAction(): void {
    $queue_service = $this->createMock(OutreachQueueServiceInterface::class);
    $queue_service->expects($this->once())
      ->method('markSent')
      ->with(125, ['provider_message_id' => 'manual']);

    $form = $this->buildForm($queue_service, 99);
    $form_state = new FormState();
    $form_state->setValue('queue_items', [125 => 125]);
    $form_state->setValue('bulk', ['action' => 'mark_sent_manual', 'reason' => 'manual_suppressed']);
    $render = [];
    $form->submitForm($render, $form_state);
  }

  /**
   * Builds a form test double with controlled account/request/messenger.
   */
  protected function buildForm(OutreachQueueServiceInterface $queueService, int $uid): OutreachQueueReviewForm {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addStatus')->willReturnSelf();
    $messenger->method('addWarning')->willReturnSelf();

    return new class(
      $this->createMock(Connection::class),
      $queueService,
      $this->createMock(CiviFollowupGroupSync::class),
      $this->createMock(ChargebeeFollowupStatusSync::class),
      $account,
      $messenger
    ) extends OutreachQueueReviewForm {
      public function __construct(
        Connection $database,
        OutreachQueueServiceInterface $queueService,
        CiviFollowupGroupSync $followupGroupSync,
        ChargebeeFollowupStatusSync $chargebeeFollowupSync,
        protected AccountInterface $account,
        protected MessengerInterface $testMessenger
      ) {
        parent::__construct($database, $queueService, $followupGroupSync, $chargebeeFollowupSync);
      }

      protected function currentUser() {
        return $this->account;
      }

      public function messenger() {
        return $this->testMessenger;
      }

      protected function getRequest() {
        return Request::create('/admin/makerspace/member-success/queue-review?status=queued');
      }
    };
  }

}
