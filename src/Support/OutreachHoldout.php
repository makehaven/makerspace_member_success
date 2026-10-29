<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Assigns members to the offer or the silent holdout of an automated offer.
 *
 * Without a holdout an automated offer can never show it worked: the August
 * GEMS pilot could not separate "the offer brought them back" from "they were
 * coming back anyway". A stable share of eligible members (holdout_percent,
 * default 25) is logged as held out instead of contacted, and
 * HoldoutReport compares the two groups.
 *
 * Assignment is a hash of stage and uid, not a coin flip per run: cron
 * re-evaluates candidates every run, and a member must land in the same arm
 * every time or both arms leak into each other.
 */
final class OutreachHoldout {

  public const ARM_OFFER = 'offer';
  public const ARM_HOLDOUT = 'holdout';

  /**
   * Queue status of a held-out row: never approved, never sent.
   */
  public const STATUS_HELD_OUT = 'held_out';

  /**
   * Stable 0-99 bucket for a member within one campaign.
   */
  public static function bucket(int $uid, string $stage): int {
    return (int) (crc32($stage . ':' . $uid) % 100);
  }

  /**
   * The arm a member falls in, or NULL when the stage runs no holdout.
   *
   * @param int $uid
   *   Member user id.
   * @param string $stage
   *   Queue stage (campaign) the member is being considered for.
   * @param int $percent
   *   Share of members held out, 0-100.
   * @param string[] $stages
   *   Stages (campaigns) that run a holdout.
   */
  public static function arm(int $uid, string $stage, int $percent, array $stages): ?string {
    // The first-month steps are one campaign: the same arm for all four.
    $campaign = FirstMonth::holdoutKey($stage);
    if ($percent <= 0 || !in_array($campaign, $stages, TRUE)) {
      return NULL;
    }
    return self::bucket($uid, $campaign) < min(100, $percent) ? self::ARM_HOLDOUT : self::ARM_OFFER;
  }

}
