<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * The "your first month" series: days 0, 7, 14 and 30 after day 0.
 *
 * Day 0 is the first daily snapshot in which the member has a door card
 * serial recorded. The serial is typed in by staff at orientation, so it
 * proves the member was in the building; the door badge going active does
 * not (that can happen online). JR, 2026-10-29.
 *
 * Each step is its own queue stage (first_month_0 ... first_month_30) so the
 * queue's one-open-row-per-stage dedup, templates and gates work unchanged,
 * while the holdout treats the four as one campaign: a member is held out of
 * the whole series or none of it.
 */
final class FirstMonth {

  public const STAGE_PREFIX = 'first_month_';

  /**
   * Holdout campaign key shared by every step.
   */
  public const CAMPAIGN = 'first_month';

  /**
   * Day offsets of the steps, in order.
   */
  public const STEPS = [0, 7, 14, 30];

  /**
   * Days after a step's day in which it may still go out.
   *
   * Covers a cron outage or a cooldown that lifts a day or two late. After
   * that the email is stale ("your first week" read in week three) and the
   * step is dropped.
   */
  public const WINDOW_DAYS = 3;

  /**
   * Queue stage for a step.
   */
  public static function stage(int $step): string {
    return self::STAGE_PREFIX . $step;
  }

  /**
   * Whether a queue stage belongs to the series.
   */
  public static function isStage(string $stage): bool {
    return str_starts_with($stage, self::STAGE_PREFIX);
  }

  /**
   * Step day of a series stage, or NULL.
   */
  public static function stepOf(string $stage): ?int {
    if (!self::isStage($stage)) {
      return NULL;
    }
    $step = (int) substr($stage, strlen(self::STAGE_PREFIX));
    return in_array($step, self::STEPS, TRUE) ? $step : NULL;
  }

  /**
   * The step due for a member who is $age days past day 0, or NULL.
   */
  public static function stepForAge(int $age): ?int {
    foreach (self::STEPS as $step) {
      if ($age >= $step && $age <= $step + self::WINDOW_DAYS) {
        return $step;
      }
    }
    return NULL;
  }

  /**
   * Stage name the stage_<name>_* gate settings are read under.
   *
   * The four steps share one set (stage_first_month_*); each step keeps its
   * own template (template_first_month_<step>).
   */
  public static function settingsStage(string $stage): string {
    return self::isStage($stage) ? self::CAMPAIGN : $stage;
  }

  /**
   * Holdout campaign key for a stage: the series shares one.
   */
  public static function holdoutKey(string $stage): string {
    return self::isStage($stage) ? self::CAMPAIGN : $stage;
  }

}
