<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Form\OutreachQueueReviewForm;
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
      $this->createMock(OutreachQueueServiceInterface::class)
    ) extends OutreachQueueReviewForm {
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

}

