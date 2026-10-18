<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler
 * @group makerspace_member_success
 */
class ChargebeeAccountReconcilerTest extends UnitTestCase {

  /**
   * @covers ::classify
   */
  public function testClassify(): void {
    // Linked member: nothing to do.
    $this->assertNull(ChargebeeAccountReconciler::classify(10, 10, TRUE));
    // Linked by customer id, email differs, still a member: nothing to do.
    $this->assertNull(ChargebeeAccountReconciler::classify(10, NULL, TRUE));
    // Geoffrey Reed / Josey Pestel: no account at all.
    $this->assertSame(ChargebeeAccountReconciler::KIND_NO_ACCOUNT, ChargebeeAccountReconciler::classify(NULL, NULL, FALSE));
    // Oleksandr Hlyniuk: rejoined under a new customer; the account by email
    // still carries the old id, so webhooks never reach it.
    $this->assertSame(ChargebeeAccountReconciler::KIND_RELINK, ChargebeeAccountReconciler::classify(NULL, 3574, FALSE));
    $this->assertSame(ChargebeeAccountReconciler::KIND_RELINK, ChargebeeAccountReconciler::classify(NULL, 3574, TRUE));
    // Linked, but the role never landed.
    $this->assertSame(ChargebeeAccountReconciler::KIND_NO_ROLE, ChargebeeAccountReconciler::classify(10, 10, FALSE));
  }

  /**
   * @covers ::addBusinessDays
   */
  public function testAddBusinessDaysSkipsWeekends(): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
      $at = static fn (string $when): int => (new \DateTimeImmutable($when, new \DateTimeZone('America/New_York')))->getTimestamp();
      // Paid Monday evening: due Wednesday evening.
      $this->assertSame($at('2026-10-14 19:00'), ChargebeeAccountReconciler::addBusinessDays($at('2026-10-12 19:00'), 2));
      // Paid Thursday: due Monday.
      $this->assertSame($at('2026-10-19 09:30'), ChargebeeAccountReconciler::addBusinessDays($at('2026-10-15 09:30'), 2));
      // Paid Saturday: due Tuesday.
      $this->assertSame($at('2026-10-20 11:00'), ChargebeeAccountReconciler::addBusinessDays($at('2026-10-17 11:00'), 2));
    }
    finally {
      date_default_timezone_set($previous);
    }
  }

  /**
   * @covers ::describe
   */
  public function testDescribeNamesHouseholdAddOns(): void {
    $household = ChargebeeAccountReconciler::describe(ChargebeeAccountReconciler::KIND_NO_ACCOUNT, 'additional-household-member-legacy');
    $this->assertStringContainsString('Household', $household);
    $plain = ChargebeeAccountReconciler::describe(ChargebeeAccountReconciler::KIND_NO_ACCOUNT, 'membership-2024-update');
    $this->assertStringNotContainsString('Household', $plain);
    $this->assertStringContainsString('Chargebee ID', ChargebeeAccountReconciler::describe(ChargebeeAccountReconciler::KIND_RELINK, NULL));
  }

}
