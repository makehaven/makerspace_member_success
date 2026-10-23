<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\PauseReason;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Support\PauseReason
 * @group makerspace_member_success
 */
class PauseReasonTest extends TestCase {

  /**
   * A token is valid only for the member and pause it was issued for.
   */
  public function testTokenBindsMemberAndPause(): void {
    $token = PauseReason::token(42, '2026-10-20', 'salt');
    $this->assertTrue(PauseReason::validToken(42, '2026-10-20', $token, 'salt'));
    $this->assertFalse(PauseReason::validToken(43, '2026-10-20', $token, 'salt'));
    $this->assertFalse(PauseReason::validToken(42, '2026-10-21', $token, 'salt'));
    $this->assertFalse(PauseReason::validToken(42, '2026-10-20', $token, 'other-salt'));
    $this->assertFalse(PauseReason::validToken(42, '2026-10-20', '', 'salt'));
  }

  /**
   * The email lists every answer, each with its own preselecting link.
   */
  public function testLinkListHasEveryAnswer(): void {
    $list = PauseReason::linkList('https://www.makehaven.org', 42, '2026-10-20', 'salt');
    $base = 'https://www.makehaven.org/membership/pause-reason/42/2026-10-20/' . PauseReason::token(42, '2026-10-20', 'salt');
    foreach (PauseReason::REASONS as $code => $label) {
      $this->assertStringContainsString($label . "\n" . $base . '?r=' . $code, $list);
    }
    $this->assertCount(count(PauseReason::REASONS), explode("\n\n", $list));
  }

  /**
   * Unknown answer codes have no label, so they are never stored.
   */
  public function testUnknownReasonHasNoLabel(): void {
    $this->assertSame('', PauseReason::label('bogus'));
    $this->assertSame('Cost', PauseReason::label('cost'));
  }

}
