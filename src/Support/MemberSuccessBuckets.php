<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Risk-score bucket definitions for member success queues.
 *
 * Single source of truth for the "Actionable / Watch / Safe / All" split
 * used on lifecycle stage pages and the dashboard card links. Keeping the
 * thresholds here guarantees the card count and the list default match.
 */
final class MemberSuccessBuckets {

  public const ACTIONABLE = 'actionable';
  public const WATCH = 'watch';
  public const SAFE = 'safe';
  public const ALL = 'all';

  public const DEFAULT_BUCKET = self::ACTIONABLE;

  /**
   * Ordered bucket keys as they should appear in the tab strip (default set).
   *
   * @return string[]
   */
  public static function order(): array {
    return [self::ACTIONABLE, self::WATCH, self::SAFE, self::ALL];
  }

  /**
   * Ordered bucket keys for a specific lifecycle stage page.
   *
   * Recovery has a binary scoring rubric — payment either failed (+50) or
   * it didn't — so no member can ever land in the Watch range (1–19).
   * Rendering a tab that's permanently 0 is misleading, so we drop it.
   *
   * Onboarding, engagement, and retention all have gradient scoring with
   * real watch-tier signals.
   *
   * @param string $display_id
   *   Views display id ('onboarding', 'engagement', 'retention', 'recovery').
   *
   * @return string[]
   */
  public static function orderForStage(string $display_id): array {
    return match ($display_id) {
      'recovery' => [self::ACTIONABLE, self::SAFE, self::ALL],
      default => self::order(),
    };
  }

  /**
   * Whether a bucket is meaningful for the given stage display.
   */
  public static function isVisibleForStage(string $display_id, string $bucket): bool {
    return in_array($bucket, self::orderForStage($display_id), TRUE);
  }

  /**
   * Human label for each bucket.
   */
  public static function label(string $bucket): string {
    return match ($bucket) {
      self::ACTIONABLE => 'Actionable',
      self::WATCH => 'Watch',
      self::SAFE => 'Safe',
      self::ALL => 'All',
      default => 'Actionable',
    };
  }

  /**
   * Short descriptor shown under the tab (risk range).
   */
  public static function description(string $bucket): string {
    return match ($bucket) {
      self::ACTIONABLE => 'risk ≥ 20',
      self::WATCH => 'risk 1–19',
      self::SAFE => 'risk 0',
      self::ALL => 'everyone visible',
      default => '',
    };
  }

  /**
   * Whether a bucket value is recognized.
   */
  public static function isValid(?string $bucket): bool {
    return $bucket !== NULL && in_array($bucket, self::order(), TRUE);
  }

  /**
   * Returns the bucket if valid, otherwise the default.
   */
  public static function normalize(?string $bucket): string {
    return self::isValid($bucket) ? $bucket : self::DEFAULT_BUCKET;
  }

  /**
   * SQL risk_score predicates for a bucket.
   *
   * Each entry is `[operator, value]` and all entries must be AND-ed together.
   * `ALL` returns an empty array (no constraint on risk_score).
   *
   * @return array<int,array{0:string,1:int}>
   */
  public static function riskConditions(string $bucket): array {
    return match (self::normalize($bucket)) {
      self::ACTIONABLE => [['>=', 20]],
      self::WATCH => [['>=', 1], ['<=', 19]],
      self::SAFE => [['=', 0]],
      self::ALL => [],
    };
  }

  /**
   * PHP-level risk match (mirrors riskConditions for unit tests / previews).
   */
  public static function matches(string $bucket, int $risk_score): bool {
    return match (self::normalize($bucket)) {
      self::ACTIONABLE => $risk_score >= 20,
      self::WATCH => $risk_score >= 1 && $risk_score <= 19,
      self::SAFE => $risk_score === 0,
      self::ALL => TRUE,
    };
  }

}
