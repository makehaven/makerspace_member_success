<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\ReportRange;
use Drupal\Tests\UnitTestCase;

/**
 * Tests consistent page and export date boundaries.
 *
 * @group makerspace_member_success
 */
class ReportRangeTest extends UnitTestCase {

  /**
   * Missing bounds resolve to a visible, bounded range.
   */
  public function testDefaults(): void {
    $this->assertSame(['2026-06-11', '2026-09-09'], ReportRange::resolve(NULL, NULL, '2026-09-09'));
    $this->assertSame(['2026-08-01', '2026-09-09'], ReportRange::resolve('2026-08-01', NULL, '2026-09-09'));
    $this->assertSame(['2025-12-01', '2026-03-01'], ReportRange::resolve(NULL, '2026-03-01', '2026-09-09'));
  }

  /**
   * Invalid calendar dates are rejected rather than silently normalized.
   */
  public function testInvalidDate(): void {
    $this->expectException(\InvalidArgumentException::class);
    ReportRange::resolve('2026-02-30', NULL, '2026-09-09');
  }

  /**
   * Reversed dates cannot silently return misleading empty metrics.
   */
  public function testReversedRange(): void {
    $this->expectException(\InvalidArgumentException::class);
    ReportRange::resolve('2026-09-10', NULL, '2026-09-09');
  }

}
