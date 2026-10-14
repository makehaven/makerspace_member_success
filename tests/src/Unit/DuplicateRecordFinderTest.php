<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Service\DuplicateRecordFinder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\DuplicateRecordFinder
 * @group makerspace_member_success
 */
class DuplicateRecordFinderTest extends UnitTestCase {

  /**
   * A contact fact row.
   */
  protected function contact(int $id, ?int $uf_id, int $participants = 0, bool $member = FALSE, int $history = 0): array {
    return [
      'id' => $id,
      'name' => 'Pat Doe',
      'created' => '',
      'uf_id' => $uf_id,
      'member' => $member,
      'participants' => $participants,
      'contributions' => 0,
      'memberships' => 0,
      'history' => $history ?: $participants,
    ];
  }

  /**
   * An account fact row.
   */
  protected function account(int $uid, array $overrides = []): array {
    return $overrides + [
      'uid' => $uid,
      'name' => 'Pat Doe',
      'first' => 'Pat',
      'last' => 'Doe',
      'mail' => 'pat' . $uid . '@example.com',
      'status' => TRUE,
      'created' => 1700000000,
      'access' => 1700000000,
      'chargebee' => '',
      'member' => FALSE,
      'contact_id' => NULL,
    ];
  }

  /**
   * @covers ::contactPairReasons
   * @covers ::contactConfidence
   */
  public function testContactPairReasons(): void {
    // Same name and nothing else in common: two people.
    $this->assertSame([], DuplicateRecordFinder::contactPairReasons($this->contact(1, NULL), $this->contact(2, NULL), FALSE, FALSE, FALSE));

    // Account holder plus an event-only contact under the same name.
    $reasons = DuplicateRecordFinder::contactPairReasons($this->contact(1, 50), $this->contact(2, NULL, 1), FALSE, FALSE, FALSE);
    $this->assertCount(1, $reasons);
    $this->assertSame('medium', DuplicateRecordFinder::contactConfidence($reasons));

    // An accountless contact with no registrations is not "event-only".
    $this->assertSame([], DuplicateRecordFinder::contactPairReasons($this->contact(1, 50), $this->contact(2, NULL, 0), FALSE, FALSE, FALSE));

    // Two account holders are not an account/event-only split.
    $this->assertSame([], DuplicateRecordFinder::contactPairReasons($this->contact(1, 50), $this->contact(2, 51, 3), FALSE, FALSE, FALSE));

    $reasons = DuplicateRecordFinder::contactPairReasons($this->contact(1, NULL), $this->contact(2, NULL), TRUE, TRUE, FALSE);
    $this->assertSame(['same email', 'same phone'], $reasons);
    $this->assertSame('high', DuplicateRecordFinder::contactConfidence($reasons));
    $this->assertSame('medium', DuplicateRecordFinder::contactConfidence(['same postal code']));
  }

  /**
   * @covers ::compareContactKeepers
   */
  public function testContactKeeper(): void {
    $contacts = [
      $this->contact(30, NULL, 5),
      $this->contact(20, 70, 0),
      $this->contact(10, NULL, 1),
    ];
    usort($contacts, [DuplicateRecordFinder::class, 'compareContactKeepers']);
    // The contact behind the website account wins over more history.
    $this->assertSame(20, $contacts[0]['id']);
    $this->assertSame(30, $contacts[1]['id']);
  }

  /**
   * @covers ::accountPairReasons
   * @covers ::accountConfidence
   */
  public function testAccountPairReasons(): void {
    $a = $this->account(1, ['mail' => 'jslenker@newhaven.edu', 'first' => 'Jamie', 'last' => 'Slenker']);
    $b = $this->account(2, ['mail' => 'jslenker@vt.edu', 'first' => 'Jamie', 'last' => 'Slenker']);
    $reasons = DuplicateRecordFinder::accountPairReasons($a, $b, TRUE, TRUE);
    $this->assertCount(2, $reasons);
    $this->assertSame('high', DuplicateRecordFinder::accountConfidence($reasons));

    // Same first name and a generic local part: different people.
    $a = $this->account(1, ['mail' => 'mark@bigbangip.com', 'first' => 'Mark', 'last' => 'Moneypenny']);
    $b = $this->account(2, ['mail' => 'mark@intertopia.org', 'first' => 'Mark', 'last' => 'Zurolo']);
    $this->assertSame([], DuplicateRecordFinder::accountPairReasons($a, $b, FALSE, TRUE));
    $a = $this->account(1, ['mail' => 'info@one.example', 'last' => 'Doe']);
    $b = $this->account(2, ['mail' => 'info@two.example', 'last' => 'Doe']);
    $this->assertSame([], DuplicateRecordFinder::accountPairReasons($a, $b, FALSE, TRUE));

    // One account has no name; the local part carries the other's last name.
    $a = $this->account(1, ['mail' => 'douglas.hausladen@gmail.com', 'first' => '', 'last' => '']);
    $b = $this->account(2, ['mail' => 'douglas.hausladen@aya.yale.edu', 'first' => 'Douglas', 'last' => 'Hausladen']);
    $this->assertCount(1, DuplicateRecordFinder::accountPairReasons($a, $b, FALSE, TRUE));
  }

  /**
   * @covers ::accountPairWorthReporting
   */
  public function testAccountPairWorthReporting(): void {
    $now = 1800000000;
    $recent = ['access' => $now - 86400];
    // Two current members with a common name: two people.
    $this->assertFalse(DuplicateRecordFinder::accountPairWorthReporting(
      $this->account(1, ['member' => TRUE] + $recent), $this->account(2, ['member' => TRUE] + $recent), ['same first and last name'], $now));
    // ...unless their emails agree too.
    $this->assertTrue(DuplicateRecordFinder::accountPairWorthReporting(
      $this->account(1, ['member' => TRUE] + $recent), $this->account(2, ['member' => TRUE] + $recent), ['name', 'email'], $now));
    // Two non-members, untouched for over two years: history, not work.
    $old = ['created' => $now - 5 * 365 * 86400, 'access' => $now - 3 * 365 * 86400];
    $this->assertFalse(DuplicateRecordFinder::accountPairWorthReporting($this->account(1, $old), $this->account(2, $old), ['name'], $now));
    // Michael Harris 5713/1139: a new member next to an old idle account.
    $this->assertTrue(DuplicateRecordFinder::accountPairWorthReporting(
      $this->account(5713, ['member' => TRUE] + $recent), $this->account(1139, $old), ['name'], $now));
  }

  /**
   * @covers ::compareAccountKeepers
   * @covers ::accountRecommendation
   * @covers ::looksLikeSpamCluster
   */
  public function testAccountKeeperAndRecommendation(): void {
    $member = $this->account(5713, ['member' => TRUE, 'chargebee' => 'cbA', 'contact_id' => 900]);
    $idle = $this->account(1139, ['access' => 0, 'chargebee' => 'cbB', 'contact_id' => 400]);
    $accounts = [$idle, $member];
    usort($accounts, [DuplicateRecordFinder::class, 'compareAccountKeepers']);
    $this->assertSame(5713, $accounts[0]['uid']);

    $text = DuplicateRecordFinder::accountRecommendation($member, $idle, FALSE);
    $this->assertStringContainsString('Keep account 5713', $text);
    $this->assertStringContainsString('merge contact 400 into 900', $text);
    $this->assertStringContainsString('own Chargebee customer', $text);

    // Never tell staff to block a member's account.
    $text = DuplicateRecordFinder::accountRecommendation($member, $this->account(2, ['member' => TRUE]), FALSE);
    $this->assertStringNotContainsString('block', $text);

    $bot = ['first' => 'InsafDat', 'last' => 'InsafDat'];
    $this->assertTrue(DuplicateRecordFinder::looksLikeSpamCluster([$this->account(1, $bot), $this->account(2, $bot)]));
    $this->assertFalse(DuplicateRecordFinder::looksLikeSpamCluster([$this->account(1, $bot), $this->account(2, $bot + ['member' => TRUE])]));
    $this->assertFalse(DuplicateRecordFinder::looksLikeSpamCluster([$this->account(1), $this->account(2)]));
  }

  /**
   * @covers ::nameKey
   * @covers ::pairsFromGroups
   */
  public function testNameKeyAndPairs(): void {
    // Desirée Upchurch signed up twice, once without the accent.
    $this->assertSame(DuplicateRecordFinder::nameKey('Desiree', 'Upchurch'), DuplicateRecordFinder::nameKey(' Desirée ', 'UPCHURCH'));
    $this->assertSame(DuplicateRecordFinder::nameKey('Barbara', 'Ruth'), DuplicateRecordFinder::nameKey('Barbara  ', ' Ruth '));
    $this->assertNotSame(DuplicateRecordFinder::nameKey('Pat', 'Doe'), DuplicateRecordFinder::nameKey('Pa', 'tDoe'));

    $pairs = DuplicateRecordFinder::pairsFromGroups(['x' => [9, 3, 5], 'y' => [4]]);
    $this->assertSame([['a' => 3, 'b' => 5], ['a' => 3, 'b' => 9], ['a' => 5, 'b' => 9]], $pairs);
  }

  /**
   * @covers ::clusters
   * @covers ::pairKey
   */
  public function testClusters(): void {
    $this->assertSame('c-3-9', DuplicateRecordFinder::pairKey('c', 9, 3));
    $edges = [
      'c-1-2' => ['x'],
      'c-2-3' => ['x'],
      'c-7-8' => ['x'],
    ];
    $clusters = DuplicateRecordFinder::clusters($edges);
    usort($clusters, static fn($a, $b) => $a[0] <=> $b[0]);
    $this->assertSame([[1, 2, 3], [7, 8]], $clusters);
  }

}
