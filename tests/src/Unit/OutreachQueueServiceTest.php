<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Service\OutreachPolicyDeciderInterface;
use Drupal\makerspace_member_success\Service\OutreachQueueService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for outreach queue data normalization.
 *
 * @group makerspace_member_success
 */
class OutreachQueueServiceTest extends UnitTestCase {

  /**
   * Tests risk reason normalization for queue writes.
   */
  public function testNormalizeRiskReasons(): void {
    $service = new class(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(OutreachPolicyDeciderInterface::class),
      $this->createMock(ConfigFactoryInterface::class)
    ) extends OutreachQueueService {
      public function normalizeForTest(mixed $raw): ?string {
        return $this->normalizeRiskReasons($raw);
      }
    };

    $expected = ['inactive_30', 'payment_failed'];
    $serialized = serialize($expected);
    $double_serialized = serialize($serialized);

    $this->assertSame($serialized, $service->normalizeForTest($expected));
    $this->assertSame($serialized, $service->normalizeForTest($serialized));
    $this->assertSame($serialized, $service->normalizeForTest($double_serialized));
    $this->assertNull($service->normalizeForTest('not-serialized'));
    $this->assertNull($service->normalizeForTest(NULL));
  }

}
