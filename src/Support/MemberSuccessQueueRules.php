<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Queue visibility and suppression reset rules.
 */
final class MemberSuccessQueueRules {

  /**
   * Returns today's date in Y-m-d format.
   *
   * @param int|null $timestamp
   *   Optional timestamp override.
   *
   * @return string
   *   Date string.
   */
  public static function todayYmd(?int $timestamp = NULL): string {
    $ts = $timestamp ?? time();
    return date('Y-m-d', $ts);
  }

  /**
   * Returns TRUE when a snooze/followup date should remain visible.
   *
   * @param string|null $next_followup_date
   *   Next followup date in Y-m-d format.
   * @param string|null $today
   *   Optional today override in Y-m-d format.
   *
   * @return bool
   *   TRUE if queue item should be shown based on followup date.
   */
  public static function isFollowupDateVisible(?string $next_followup_date, ?string $today = NULL): bool {
    if (empty($next_followup_date)) {
      return TRUE;
    }

    $today = $today ?? self::todayYmd();
    return $next_followup_date <= $today;
  }

  /**
   * Returns TRUE when status should remain visible in queue.
   *
   * @param string|null $status
   *   Followup status value.
   *
   * @return bool
   *   TRUE if not resolved/suppressed.
   */
  public static function isStatusVisible(?string $status): bool {
    if ($status === NULL || $status === '') {
      return TRUE;
    }

    return !in_array($status, MemberSuccessLifecycle::resolvedFollowupStatuses(), TRUE);
  }

  /**
   * Returns TRUE if queue item should be visible.
   *
   * @param string|null $next_followup_date
   *   Next followup date.
   * @param string|null $outreach_status
   *   Snapshot outreach status.
   * @param string|null $member_followup_status
   *   Followup status stored on member.
   * @param string|null $today
   *   Optional today override.
   *
   * @return bool
   *   TRUE if row should appear in queue.
   */
  public static function isQueueVisible(
    ?string $next_followup_date,
    ?string $outreach_status,
    ?string $member_followup_status,
    ?string $today = NULL,
  ): bool {
    return self::isFollowupDateVisible($next_followup_date, $today)
      && self::isStatusVisible($outreach_status)
      && self::isStatusVisible($member_followup_status);
  }

  /**
   * Returns TRUE if suppression status should be reset after stage change.
   *
   * Always FALSE: explicit operator suppression (outreach_exhausted,
   * confirmed_cancellation, no_action_needed, needs_review) is a deliberate
   * "stop outreach" decision that should not silently flip back when the
   * member's lifecycle stage changes. Staff can re-open outreach manually via
   * the Log Contact form when a new event warrants it.
   *
   * @param string|null $previous_stage
   *   Previous stage.
   * @param string $new_stage
   *   New stage.
   * @param string|null $followup_status
   *   Existing followup suppression status.
   *
   * @return bool
   *   Always FALSE.
   */
  public static function shouldResetSuppressionOnStageChange(
    ?string $previous_stage,
    string $new_stage,
    ?string $followup_status,
  ): bool {
    return FALSE;
  }

}
