<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\OutreachHoldout;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Support\OutreachHoldout
 * @group makerspace_member_success
 */
class OutreachHoldoutTest extends TestCase {

  /**
   * A member lands in the same arm on every run.
   */
  public function testAssignmentIsStable(): void {
    for ($uid = 1; $uid <= 200; $uid++) {
      $this->assertSame(
        OutreachHoldout::arm($uid, 'pause_end', 25, ['pause_end']),
        OutreachHoldout::arm($uid, 'pause_end', 25, ['pause_end']),
      );
    }
  }

  /**
   * About holdout_percent of members are held out.
   */
  public function testShareIsNearPercent(): void {
    $held = 0;
    $n = 4000;
    for ($uid = 1; $uid <= $n; $uid++) {
      if (OutreachHoldout::arm($uid, 'reengage', 25, ['reengage']) === OutreachHoldout::ARM_HOLDOUT) {
        $held++;
      }
    }
    $this->assertGreaterThan(0.22 * $n, $held);
    $this->assertLessThan(0.28 * $n, $held);
  }

  /**
   * Stages without a holdout, and a 0% holdout, assign nobody.
   */
  public function testNoHoldoutOutsideConfiguredStages(): void {
    $this->assertNull(OutreachHoldout::arm(7, 'recovery', 25, ['pause_end', 'reengage']));
    $this->assertNull(OutreachHoldout::arm(7, 'pause_end', 0, ['pause_end']));
  }

  /**
   * 100% holds everyone out; the bucket stays within 0-99.
   */
  public function testBounds(): void {
    for ($uid = 1; $uid <= 300; $uid++) {
      $bucket = OutreachHoldout::bucket($uid, 'pause_end');
      $this->assertGreaterThanOrEqual(0, $bucket);
      $this->assertLessThan(100, $bucket);
      $this->assertSame(OutreachHoldout::ARM_HOLDOUT, OutreachHoldout::arm($uid, 'pause_end', 100, ['pause_end']));
    }
  }

}
