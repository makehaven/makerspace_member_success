<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\FirstMonth;
use Drupal\makerspace_member_success\Support\OutreachHoldout;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Support\FirstMonth
 * @group makerspace_member_success
 */
class FirstMonthTest extends TestCase {

  /**
   * Each step goes out on its day or up to WINDOW_DAYS late, never between.
   */
  public function testStepForAge(): void {
    $this->assertSame(0, FirstMonth::stepForAge(0));
    $this->assertSame(0, FirstMonth::stepForAge(3));
    $this->assertNull(FirstMonth::stepForAge(4));
    $this->assertSame(7, FirstMonth::stepForAge(7));
    $this->assertSame(14, FirstMonth::stepForAge(17));
    $this->assertNull(FirstMonth::stepForAge(29));
    $this->assertSame(30, FirstMonth::stepForAge(33));
    $this->assertNull(FirstMonth::stepForAge(34));
    $this->assertNull(FirstMonth::stepForAge(-1));
  }

  /**
   * Stage names round-trip; other stages are left alone.
   */
  public function testStages(): void {
    $this->assertSame('first_month_14', FirstMonth::stage(14));
    $this->assertSame(14, FirstMonth::stepOf('first_month_14'));
    $this->assertNull(FirstMonth::stepOf('first_month_5'));
    $this->assertNull(FirstMonth::stepOf('badge'));
    $this->assertSame('first_month', FirstMonth::settingsStage('first_month_7'));
    $this->assertSame('badge', FirstMonth::settingsStage('badge'));
  }

  /**
   * A member lands in the same holdout arm for every step of the series.
   */
  public function testHoldoutArmSharedAcrossSteps(): void {
    for ($uid = 1; $uid <= 200; $uid++) {
      $arms = array_map(
        static fn(int $step) => OutreachHoldout::arm($uid, FirstMonth::stage($step), 25, ['first_month']),
        FirstMonth::STEPS,
      );
      $this->assertCount(1, array_unique($arms), "uid $uid");
      $this->assertNotNull($arms[0]);
    }
    $this->assertNull(OutreachHoldout::arm(5, 'first_month_0', 25, ['reengage']));
  }

}
