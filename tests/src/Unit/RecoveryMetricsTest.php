<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;
use Drupal\Tests\UnitTestCase;

/**
 * Tests RecoveryMetrics calculations against mock DB results.
 *
 * @group makerspace_member_success
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\RecoveryMetrics
 */
class RecoveryMetricsTest extends UnitTestCase {

  /**
   * Creates a mock database connection that returns the given rows for query().
   *
   * @param array $rows
   *   Rows to return from fetchAll() or fetchAssoc().
   * @param mixed $scalar
   *   Optional scalar value to return from fetchField().
   *
   * @return \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected function mockDatabase(array $rows = [], mixed $scalar = NULL): Connection {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchAll')->willReturn($rows);
    $stmt->method('fetchAssoc')->willReturn($rows[0] ?? []);
    $stmt->method('fetchField')->willReturn($scalar);
    $stmt->method('fetchAllKeyed')->willReturn([]);

    $db = $this->createMock(Connection::class);
    $db->method('query')->willReturn($stmt);

    return $db;
  }

  /**
   * @covers ::getResolutionRate
   */
  public function testGetResolutionRateWithData(): void {
    $db = $this->mockDatabase(rows: [
      ['total' => 10, 'resolved' => 4],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getResolutionRate();

    $this->assertSame(10, $result['total']);
    $this->assertSame(4, $result['resolved']);
    $this->assertSame(40.0, $result['rate']);
  }

  /**
   * @covers ::getResolutionRate
   */
  public function testGetResolutionRateWithNoContacts(): void {
    $db = $this->mockDatabase(rows: [
      ['total' => 0, 'resolved' => 0],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getResolutionRate();

    $this->assertSame(0, $result['total']);
    // Rate is 0 (int) when total is 0 — no division performed.
    $this->assertEquals(0, $result['rate']);
  }

  /**
   * @covers ::getResolutionRate
   */
  public function testGetResolutionRateWithDateRange(): void {
    // Verify that date params are passed through (no exception thrown).
    $db = $this->mockDatabase(rows: [
      ['total' => 5, 'resolved' => 2],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getResolutionRate('2026-01-01', '2026-01-31');

    $this->assertSame(5, $result['total']);
    $this->assertSame(2, $result['resolved']);
    $this->assertSame(40.0, $result['rate']);
  }

  /**
   * @covers ::getExhaustionRate
   */
  public function testGetExhaustionRateCalculation(): void {
    $db = $this->mockDatabase(rows: [
      ['total_high_attempts' => 8, 'exhausted' => 6],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getExhaustionRate();

    $this->assertSame(8, $result['total_high_attempts']);
    $this->assertSame(6, $result['exhausted']);
    $this->assertSame(75.0, $result['rate']);
  }

  /**
   * @covers ::getExhaustionRate
   */
  public function testGetExhaustionRateWithNoHighAttempts(): void {
    $db = $this->mockDatabase(rows: [
      ['total_high_attempts' => 0, 'exhausted' => 0],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getExhaustionRate();

    // Rate is 0 (int) when total is 0 — no division performed.
    $this->assertEquals(0, $result['rate']);
  }

  /**
   * @covers ::getChannelEffectiveness
   */
  public function testGetChannelEffectivenessMultipleChannels(): void {
    $db = $this->mockDatabase(rows: [
      ['contact_method' => 'email', 'total_contacted' => 10, 'resolved' => 3],
      ['contact_method' => 'phone', 'total_contacted' => 5,  'resolved' => 4],
      ['contact_method' => 'sms',   'total_contacted' => 2,  'resolved' => 0],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getChannelEffectiveness();

    $this->assertArrayHasKey('email', $result);
    $this->assertArrayHasKey('phone', $result);
    $this->assertArrayHasKey('sms', $result);

    $this->assertSame(30.0, $result['email']['rate']);
    $this->assertSame(80.0, $result['phone']['rate']);
    $this->assertSame(0.0,  $result['sms']['rate']);
  }

  /**
   * @covers ::getChannelEffectiveness
   */
  public function testGetChannelEffectivenessEmpty(): void {
    $db = $this->mockDatabase(rows: []);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getChannelEffectiveness();

    $this->assertSame([], $result);
  }

  /**
   * @covers ::getRetentionValue
   *
   * Verifies that the N+1 fix is in place: getRetentionValue() must issue
   * exactly ONE query (no per-member queries in a loop).
   */
  public function testGetRetentionValueIssuesSingleQuery(): void {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchAll')->willReturn([
      ['uid' => 1, 'was_resolved' => 1, 'monthly_payment' => '50.00'],
      ['uid' => 2, 'was_resolved' => 0, 'monthly_payment' => '75.00'],
    ]);

    $db = $this->createMock(Connection::class);
    // query() must be called exactly once — proof there is no per-member loop.
    $db->expects($this->once())
      ->method('query')
      ->willReturn($stmt);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getRetentionValue();

    $this->assertSame(2, $result['total_members_at_risk']);
    $this->assertSame(1, $result['members_resolved']);
    $this->assertSame(1, $result['members_lost']);
    // uid 1: $50 * 12 = $600, uid 2: $75 * 12 = $900 → total $1500.
    $this->assertSame(1500.0, $result['total_annual_value_at_risk']);
    $this->assertSame(600.0,  $result['annual_value_saved']);
    $this->assertSame(900.0,  $result['annual_value_lost']);
    $this->assertSame(40.0,   $result['value_saved_rate']);
  }

  /**
   * @covers ::getRetentionValue
   */
  public function testGetRetentionValueWithNoMembers(): void {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchAll')->willReturn([]);

    $db = $this->createMock(Connection::class);
    $db->method('query')->willReturn($stmt);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getRetentionValue();

    $this->assertSame(0, $result['total_members_at_risk']);
    // Rate is 0 (int) when no members — no division performed.
    $this->assertEquals(0, $result['value_saved_rate']);
  }

  /**
   * @covers ::getMonthlyTrends
   */
  public function testGetMonthlyTrendsCalculatesRates(): void {
    $db = $this->mockDatabase(rows: [
      ['month' => '2026-01', 'members_contacted' => 10, 'resolved' => 5, 'total_attempts' => 20],
      ['month' => '2026-02', 'members_contacted' => 8,  'resolved' => 2, 'total_attempts' => 16],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getMonthlyTrends();

    $this->assertArrayHasKey('2026-01', $result);
    $this->assertArrayHasKey('2026-02', $result);

    $this->assertSame(50.0, $result['2026-01']['resolution_rate']);
    $this->assertSame(25.0, $result['2026-02']['resolution_rate']);
    $this->assertSame(2.0,  $result['2026-01']['avg_attempts_per_member']);
    $this->assertSame(2.0,  $result['2026-02']['avg_attempts_per_member']);
  }

  /**
   * @covers ::getAttemptsDistribution
   */
  public function testGetAttemptsDistribution(): void {
    $db = $this->mockDatabase(rows: [
      ['attempt_bucket' => '1', 'member_count' => 20],
      ['attempt_bucket' => '2', 'member_count' => 10],
      ['attempt_bucket' => '5+', 'member_count' => 3],
    ]);

    $metrics = new RecoveryMetrics($db);
    $result = $metrics->getAttemptsDistribution();

    $this->assertSame(20, $result['1']);
    $this->assertSame(10, $result['2']);
    $this->assertSame(3,  $result['5+']);
  }

}
