<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\makerspace_member_success\Form\OutreachQueueReviewForm;
use Drupal\makerspace_member_success\Service\FollowupStatusManager;
use Drupal\makerspace_member_success\Service\OutreachCandidateGenerator;
use Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for queue review risk reason decoding.
 *
 * @group makerspace_member_success
 */
class OutreachQueueReviewFormTest extends UnitTestCase {

  /**
   * Tests decoding for normal and legacy serialized risk reasons.
   */
  public function testDecodeRiskReasons(): void {
    $form = new class(
      $this->createMock(Connection::class),
      $this->createMock(OutreachQueueServiceInterface::class),
      $this->createMock(FollowupStatusManager::class),
      $this->createMock(OutreachCandidateGenerator::class)
    ) extends OutreachQueueReviewForm {
      protected function t($string, array $args = [], array $options = []) {
        return strtr((string) $string, $args);
      }
      public function decodeForTest(mixed $raw): array {
        return $this->decodeRiskReasons($raw);
      }
    };

    $expected = ['inactive_30', 'payment_failed'];
    $serialized = serialize($expected);
    $double_serialized = serialize($serialized);

    $this->assertSame($expected, $form->decodeForTest($expected));
    $this->assertSame($expected, $form->decodeForTest($serialized));
    $this->assertSame($expected, $form->decodeForTest($double_serialized));
    $this->assertSame([], $form->decodeForTest('not-serialized'));
    $this->assertSame([], $form->decodeForTest(NULL));
  }

  /**
   * Tests queue reason formatting for new suppression labels.
   */
  public function testFormatQueueReasonSuppressionLabels(): void {
    $form = new class(
      $this->createMock(Connection::class),
      $this->createMock(OutreachQueueServiceInterface::class),
      $this->createMock(FollowupStatusManager::class),
      $this->createMock(OutreachCandidateGenerator::class)
    ) extends OutreachQueueReviewForm {
      protected function t($string, array $args = [], array $options = []) {
        return strtr((string) $string, $args);
      }
      public function formatReasonForTest(object $row): string {
        return $this->formatQueueReason($row);
      }
    };

    $cooldown = (object) ['suppression_reason_code' => 'suppressed_cooldown'];
    $missing_template = (object) ['suppression_reason_code' => 'suppressed_missing_template_email'];

    $this->assertSame('In cooldown window', $form->formatReasonForTest($cooldown));
    $this->assertSame('Missing email template', $form->formatReasonForTest($missing_template));
  }

  /**
   * Tests template title lookup trims and keys by template ID.
   */
  public function testLoadTemplateTitles(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllAssoc')->with('id')->willReturn([
      167 => (object) ['msg_title' => ' Success Recovery '],
      200 => (object) ['msg_title' => 'Retention Nudge'],
    ]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->with('civicrm_msg_template', 'mt')->willReturn($select);

    $form = new class(
      $database,
      $this->createMock(OutreachQueueServiceInterface::class),
      $this->createMock(FollowupStatusManager::class),
      $this->createMock(OutreachCandidateGenerator::class)
    ) extends OutreachQueueReviewForm {
      protected function t($string, array $args = [], array $options = []) {
        return strtr((string) $string, $args);
      }
      public function loadTemplateTitlesForTest(array $templateIds): array {
        return $this->loadTemplateTitles($templateIds);
      }
    };

    $titles = $form->loadTemplateTitlesForTest([167, 200, 167]);
    $this->assertSame('Success Recovery', $titles[167] ?? NULL);
    $this->assertSame('Retention Nudge', $titles[200] ?? NULL);
  }

}
