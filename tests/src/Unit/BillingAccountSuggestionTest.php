<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the "possible account" matching rules for the billing queue.
 *
 * @group makerspace_member_success
 */
class BillingAccountSuggestionTest extends UnitTestCase {

  /**
   * The 2026-10 case: same name in Drupal, CiviCRM names empty.
   */
  public function testValeriaPerezMatchesByNameAndEmail(): void {
    $candidate = [
      'mail' => 'valeriaperezlovescats@gmail.com',
      'first_name' => 'Valeria',
      'last_name' => 'Perez',
      'civi_first_name' => NULL,
      'civi_last_name' => NULL,
    ];
    $this->assertSame('same name', ChargebeeAccountReconciler::matchReason('Valeria Perez', 'valeriaperez12344321@gmail.com', $candidate));

    // With no name anywhere, the email prefix still catches it.
    $candidate['first_name'] = $candidate['last_name'] = NULL;
    $this->assertSame('similar email', ChargebeeAccountReconciler::matchReason('Valeria Perez', 'valeriaperez12344321@gmail.com', $candidate));
  }

  /**
   * Names compare case- and space-insensitively, CiviCRM as a fallback.
   */
  public function testNameMatchIgnoresCaseAndSpacesAndUsesCivi(): void {
    $candidate = [
      'mail' => 'someone@example.com',
      'first_name' => '',
      'last_name' => '',
      'civi_first_name' => 'mary  ann',
      'civi_last_name' => 'SMITH',
    ];
    $this->assertSame('same name', ChargebeeAccountReconciler::matchReason('Mary Ann Smith', 'other@example.com', $candidate));
  }

  /**
   * Short shared prefixes and different names do not match.
   */
  public function testNoMatch(): void {
    $candidate = [
      'mail' => 'johnsmith@example.com',
      'first_name' => 'John',
      'last_name' => 'Smith',
    ];
    // "johnso" vs "johnsm": 5 shared characters, under the 8 needed.
    $this->assertNull(ChargebeeAccountReconciler::matchReason('John Smyth', 'johnson@example.com', $candidate));
    $this->assertNull(ChargebeeAccountReconciler::matchReason('', '', $candidate));
  }

  /**
   * Prefix length counts multibyte characters.
   */
  public function testCommonPrefixLength(): void {
    $this->assertSame(12, ChargebeeAccountReconciler::commonPrefixLength('valeriaperez1234', 'valeriaperezlovescats'));
    $this->assertSame(3, ChargebeeAccountReconciler::commonPrefixLength('cuéllar', 'cuéx'));
    $this->assertSame(0, ChargebeeAccountReconciler::commonPrefixLength('', 'abc'));
  }

}
