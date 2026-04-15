<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\makerspace_member_success\Support\MemberSuccessBuckets;
use Drupal\Tests\UnitTestCase;

/**
 * Tests risk-bucket thresholds, normalization, and SQL predicate mapping.
 *
 * @group makerspace_member_success
 */
class MemberSuccessBucketsTest extends UnitTestCase {

  public function testDefaultIsActionable(): void {
    $this->assertSame(MemberSuccessBuckets::ACTIONABLE, MemberSuccessBuckets::DEFAULT_BUCKET);
    $this->assertSame(MemberSuccessBuckets::ACTIONABLE, MemberSuccessBuckets::normalize(NULL));
    $this->assertSame(MemberSuccessBuckets::ACTIONABLE, MemberSuccessBuckets::normalize(''));
    $this->assertSame(MemberSuccessBuckets::ACTIONABLE, MemberSuccessBuckets::normalize('garbage'));
  }

  public function testNormalizePassesThroughValidBuckets(): void {
    foreach (MemberSuccessBuckets::order() as $bucket) {
      $this->assertSame($bucket, MemberSuccessBuckets::normalize($bucket));
      $this->assertTrue(MemberSuccessBuckets::isValid($bucket));
    }
  }

  public function testIsValidRejectsUnknown(): void {
    $this->assertFalse(MemberSuccessBuckets::isValid(NULL));
    $this->assertFalse(MemberSuccessBuckets::isValid('garbage'));
    $this->assertFalse(MemberSuccessBuckets::isValid('ACTIONABLE'));
  }

  public function testRiskConditionsActionable(): void {
    $this->assertSame([['>=', 20]], MemberSuccessBuckets::riskConditions(MemberSuccessBuckets::ACTIONABLE));
  }

  public function testRiskConditionsWatch(): void {
    $this->assertSame(
      [['>=', 1], ['<=', 19]],
      MemberSuccessBuckets::riskConditions(MemberSuccessBuckets::WATCH)
    );
  }

  public function testRiskConditionsSafe(): void {
    $this->assertSame([['=', 0]], MemberSuccessBuckets::riskConditions(MemberSuccessBuckets::SAFE));
  }

  public function testRiskConditionsAllIsEmpty(): void {
    $this->assertSame([], MemberSuccessBuckets::riskConditions(MemberSuccessBuckets::ALL));
  }

  public function testRiskConditionsUnknownFallsBackToActionable(): void {
    $this->assertSame([['>=', 20]], MemberSuccessBuckets::riskConditions('garbage'));
  }

  /**
   * @dataProvider matchesCases
   */
  public function testMatches(string $bucket, int $score, bool $expected): void {
    $this->assertSame($expected, MemberSuccessBuckets::matches($bucket, $score));
  }

  public static function matchesCases(): array {
    return [
      'actionable 20' => [MemberSuccessBuckets::ACTIONABLE, 20, TRUE],
      'actionable 19' => [MemberSuccessBuckets::ACTIONABLE, 19, FALSE],
      'actionable 50' => [MemberSuccessBuckets::ACTIONABLE, 50, TRUE],
      'actionable 0' => [MemberSuccessBuckets::ACTIONABLE, 0, FALSE],

      'watch 1' => [MemberSuccessBuckets::WATCH, 1, TRUE],
      'watch 19' => [MemberSuccessBuckets::WATCH, 19, TRUE],
      'watch 20' => [MemberSuccessBuckets::WATCH, 20, FALSE],
      'watch 0' => [MemberSuccessBuckets::WATCH, 0, FALSE],

      'safe 0' => [MemberSuccessBuckets::SAFE, 0, TRUE],
      'safe 1' => [MemberSuccessBuckets::SAFE, 1, FALSE],

      'all 0' => [MemberSuccessBuckets::ALL, 0, TRUE],
      'all 100' => [MemberSuccessBuckets::ALL, 100, TRUE],
    ];
  }

  public function testLabelAndDescriptionNonEmpty(): void {
    foreach (MemberSuccessBuckets::order() as $bucket) {
      $this->assertNotSame('', MemberSuccessBuckets::label($bucket));
      $this->assertNotSame('', MemberSuccessBuckets::description($bucket));
    }
  }

  public function testOrderStartsWithActionable(): void {
    $this->assertSame(MemberSuccessBuckets::ACTIONABLE, MemberSuccessBuckets::order()[0]);
  }

  public function testOrderForStageDropsWatchOnRecovery(): void {
    $order = MemberSuccessBuckets::orderForStage('recovery');
    $this->assertNotContains(
      MemberSuccessBuckets::WATCH,
      $order,
      'Recovery scoring is binary (+50 payment_failed); Watch tab should be hidden.'
    );
    $this->assertContains(MemberSuccessBuckets::ACTIONABLE, $order);
    $this->assertContains(MemberSuccessBuckets::SAFE, $order);
    $this->assertContains(MemberSuccessBuckets::ALL, $order);
  }

  public function testOrderForStageKeepsWatchOnGradientStages(): void {
    foreach (['onboarding', 'engagement', 'retention'] as $display_id) {
      $this->assertSame(
        MemberSuccessBuckets::order(),
        MemberSuccessBuckets::orderForStage($display_id),
        $display_id . ' has a watch tier and should render all four buckets.'
      );
    }
  }

  public function testOrderForStageUnknownFallsBackToDefault(): void {
    $this->assertSame(MemberSuccessBuckets::order(), MemberSuccessBuckets::orderForStage('lifecycle'));
  }

  public function testIsVisibleForStage(): void {
    $this->assertTrue(MemberSuccessBuckets::isVisibleForStage('onboarding', MemberSuccessBuckets::WATCH));
    $this->assertTrue(MemberSuccessBuckets::isVisibleForStage('engagement', MemberSuccessBuckets::WATCH));
    $this->assertTrue(MemberSuccessBuckets::isVisibleForStage('retention', MemberSuccessBuckets::WATCH));
    $this->assertFalse(MemberSuccessBuckets::isVisibleForStage('recovery', MemberSuccessBuckets::WATCH));
    // Actionable is always visible.
    foreach (['onboarding', 'engagement', 'retention', 'recovery'] as $s) {
      $this->assertTrue(MemberSuccessBuckets::isVisibleForStage($s, MemberSuccessBuckets::ACTIONABLE));
    }
  }

}
