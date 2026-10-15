<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\DuplicateEvidence;
use Drupal\makerspace_member_success\Service\DuplicateRecordFinder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\DuplicateEvidence
 * @group makerspace_member_success
 */
class DuplicateEvidenceTest extends UnitTestCase {

  /**
   * Shared values ignore case and extra spaces, and keep the first list's form.
   */
  public function testShared(): void {
    $this->assertSame(['Pat@Example.com'], DuplicateEvidence::shared(['Pat@Example.com', 'a@b.org'], [' pat@example.com ']));
    $this->assertSame(['12 Main  St'], DuplicateEvidence::shared(['12 Main  St'], ['12 main st']));
    $this->assertSame([], DuplicateEvidence::shared(['06510'], ['06511']));
    $this->assertSame([], DuplicateEvidence::shared([], ['x']));
  }

  /**
   * Ten-digit numbers are formatted; anything else is shown as entered.
   */
  public function testFormatPhone(): void {
    $this->assertSame('(203) 555-1234', DuplicateEvidence::formatPhone('2035551234', '203.555.1234'));
    $this->assertSame('(203) 555-1234', DuplicateEvidence::formatPhone('12035551234', '+1 203 555 1234'));
    $this->assertSame('555-1234', DuplicateEvidence::formatPhone('5551234', ' 555-1234 '));
  }

  /**
   * Four-digit ZIPs get their leading zero back; others are left alone.
   */
  public function testNormalizeZip(): void {
    $this->assertSame('06511', DuplicateEvidence::normalizeZip('6511'));
    $this->assertSame('06511', DuplicateEvidence::normalizeZip('06511'));
    $this->assertSame('K1A', DuplicateEvidence::normalizeZip('K1A'));
  }

  /**
   * Rows name their two contacts lower ID first, as CiviCRM stores exceptions.
   */
  public function testContactPair(): void {
    $contact = ['type' => 'contact', 'keep_id' => 13149, 'other_id' => 2];
    $this->assertSame([2, 13149], DuplicateRecordFinder::contactPair($contact));
    $account = ['type' => 'account', 'keep_id' => 4818, 'other_id' => 4816];
    $this->assertSame([8359, 8361], DuplicateRecordFinder::contactPair($account + ['keep_cid' => 8361, 'other_cid' => 8359]));
    // Accounts without two distinct contacts have nothing to record in CiviCRM.
    $this->assertNull(DuplicateRecordFinder::contactPair($account + ['keep_cid' => NULL, 'other_cid' => 5]));
    $this->assertNull(DuplicateRecordFinder::contactPair($account + ['keep_cid' => 5, 'other_cid' => 5]));
  }

}
