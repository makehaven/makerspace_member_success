<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Canonical lifecycle constants and mappings for member success workflows.
 */
final class MemberSuccessLifecycle {

  public const STAGE_ONBOARDING = 'onboarding';
  public const STAGE_ENGAGEMENT = 'engagement';
  public const STAGE_RETENTION = 'retention';
  public const STAGE_RECOVERY = 'recovery';
  public const STAGE_PAUSED = 'paused';

  public const FOLLOWUP_OUTREACH_EXHAUSTED = 'outreach_exhausted';
  public const FOLLOWUP_CONFIRMED_CANCELLATION = 'confirmed_cancellation';
  public const FOLLOWUP_RETURN_INTENT = 'return_intent';
  public const FOLLOWUP_OUTREACH_ACTIVE = 'outreach_active';
  public const FOLLOWUP_NO_ACTION_NEEDED = 'no_action_needed';
  public const FOLLOWUP_NEEDS_REVIEW = 'needs_review';

  /**
   * Initial outreach_status assigned to recovery-stage members with no history.
   */
  public const OUTREACH_STATUS_PENDING = 'pending';

  public const OUTCOME_PAYMENT_UPDATED = 'payment_updated';
  public const OUTCOME_CONFIRMED_CANCEL = 'confirmed_cancel';
  public const OUTCOME_WILL_RETURN = 'will_return';
  public const OUTCOME_NEEDS_TIME = 'needs_time';
  public const OUTCOME_NO_ANSWER = 'no_answer';
  public const OUTCOME_LEFT_MESSAGE = 'left_message';
  public const OUTCOME_EMAIL_SENT = 'email_sent';
  public const OUTCOME_EMAIL_BOUNCED = 'email_bounced';
  public const OUTCOME_NO_ACTION_NEEDED = 'no_action_needed';
  public const OUTCOME_SMS_SENT = 'sms_sent';
  public const OUTCOME_INVALID_CONTACT = 'invalid_contact';

  /**
   * Returns all valid lifecycle stages.
   *
   * @return string[]
   *   Stage machine names.
   */
  public static function stages(): array {
    return [
      self::STAGE_ONBOARDING,
      self::STAGE_ENGAGEMENT,
      self::STAGE_RETENTION,
      self::STAGE_RECOVERY,
      self::STAGE_PAUSED,
    ];
  }

  /**
   * Returns followup statuses that suppress queue visibility.
   *
   * @return string[]
   *   Suppressing statuses.
   */
  public static function resolvedFollowupStatuses(): array {
    return [
      self::FOLLOWUP_CONFIRMED_CANCELLATION,
      self::FOLLOWUP_OUTREACH_EXHAUSTED,
      self::FOLLOWUP_NO_ACTION_NEEDED,
      self::FOLLOWUP_NEEDS_REVIEW,
    ];
  }

  /**
   * Followup statuses a NEW payment-failure episode wipes.
   *
   * Policy (Kate, 2026-08-17): when a member who had recovered falls into a
   * fresh payment failure, their outreach file re-opens — including members
   * previously closed as exhausted or "confirmed cancellation". The guard is
   * structural: snapshots only cover current members, so someone whose
   * membership actually ended never re-enters a queue this way. Retries and
   * extra cards within one unresolved failure are the SAME episode and never
   * trigger this. needs_review is deliberately kept — it is an escalation to
   * staff, not a stop-contact decision.
   */
  public static function followupStatusesResetOnNewPaymentEpisode(): array {
    return [
      self::FOLLOWUP_CONFIRMED_CANCELLATION,
      self::FOLLOWUP_OUTREACH_EXHAUSTED,
      self::FOLLOWUP_NO_ACTION_NEEDED,
      self::FOLLOWUP_RETURN_INTENT,
    ];
  }

  /**
   * Whether this build observes the START of a new payment-failure episode.
   *
   * True only on the flag's 0→1 transition against the previous snapshot:
   * the member currently has a failed payment and the prior snapshot did
   * not. A missing previous snapshot is NOT an episode start — we have no
   * history to judge, and wiping operator decisions on first sight would be
   * destructive.
   *
   * @param array $previous
   *   The previous snapshot row (may be empty for first-ever builds).
   * @param bool $payment_failed
   *   Whether the member is payment-failed in the current build.
   */
  public static function isNewPaymentEpisode(array $previous, bool $payment_failed): bool {
    return $payment_failed
      && $previous !== []
      && empty($previous['payment_failed']);
  }

  /**
   * Outcomes that count as a positive intervention resolution.
   *
   * Used by performance analytics ("Resolution Rate") to credit outreach when
   * the member was retained or there was nothing more to do. Confirmed
   * cancellations are intentionally excluded — they are case-closing but the
   * member was lost, so they are tracked in a separate column.
   *
   * @return string[]
   */
  public static function resolvedOutcomes(): array {
    return [
      self::OUTCOME_PAYMENT_UPDATED,
      self::OUTCOME_WILL_RETURN,
      self::OUTCOME_NO_ACTION_NEEDED,
    ];
  }

  /**
   * TRUE when the outcome counts toward the Resolution Rate metric.
   */
  public static function isResolvedOutcome(string $outcome): bool {
    return in_array($outcome, self::resolvedOutcomes(), TRUE);
  }

  /**
   * Maps outreach outcome to queue sleep days.
   *
   * @param string $outcome
   *   Outcome machine name.
   *
   * @return int
   *   Sleep period days, or -1 for permanent suppression.
   */
  public static function sleepDaysForOutcome(string $outcome): int {
    $sleep_periods = [
      self::OUTCOME_LEFT_MESSAGE => 7,
      self::OUTCOME_EMAIL_SENT => 7,
      self::OUTCOME_SMS_SENT => 7,
      self::OUTCOME_NO_ANSWER => 3,
      self::OUTCOME_WILL_RETURN => 14,
      self::OUTCOME_NEEDS_TIME => 14,
      self::OUTCOME_PAYMENT_UPDATED => -1,
      self::OUTCOME_CONFIRMED_CANCEL => -1,
      self::OUTCOME_NO_ACTION_NEEDED => -1,
      self::OUTCOME_EMAIL_BOUNCED => 0,
      self::OUTCOME_INVALID_CONTACT => 0,
    ];

    return $sleep_periods[$outcome] ?? 0;
  }

  /**
   * Maps outreach outcome to followup status.
   *
   * @param string $outcome
   *   Outcome machine name.
   * @param bool $mark_exhausted
   *   Whether outreach was manually marked exhausted.
   *
   * @return string|null
   *   Followup status, or NULL if no status should be set.
   */
  public static function followupStatusForOutcome(string $outcome, bool $mark_exhausted = FALSE): ?string {
    if ($mark_exhausted) {
      return self::FOLLOWUP_OUTREACH_EXHAUSTED;
    }

    $mapping = [
      self::OUTCOME_PAYMENT_UPDATED => NULL,
      self::OUTCOME_CONFIRMED_CANCEL => self::FOLLOWUP_CONFIRMED_CANCELLATION,
      self::OUTCOME_WILL_RETURN => self::FOLLOWUP_RETURN_INTENT,
      self::OUTCOME_NEEDS_TIME => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_NO_ANSWER => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_LEFT_MESSAGE => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_EMAIL_SENT => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_EMAIL_BOUNCED => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_SMS_SENT => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_INVALID_CONTACT => self::FOLLOWUP_OUTREACH_ACTIVE,
      self::OUTCOME_NO_ACTION_NEEDED => self::FOLLOWUP_NO_ACTION_NEEDED,
    ];

    return $mapping[$outcome] ?? NULL;
  }

  /**
   * Returns outcomes that are suppressed/hidden for a given stage.
   *
   * @param string $stage
   *   Lifecycle stage machine name.
   *
   * @return string[]
   *   Suppressed outcome machine names.
   */
  public static function getSuppressedOutcomesForStage(string $stage): array {
    return match ($stage) {
      self::STAGE_ONBOARDING,
      self::STAGE_ENGAGEMENT => [
        self::OUTCOME_NO_ACTION_NEEDED,
        self::OUTCOME_PAYMENT_UPDATED,
      ],
      self::STAGE_RETENTION => [
        self::OUTCOME_PAYMENT_UPDATED,
      ],
      self::STAGE_RECOVERY => [
        self::OUTCOME_NO_ACTION_NEEDED,
      ],
      default => [],
    };
  }

}
