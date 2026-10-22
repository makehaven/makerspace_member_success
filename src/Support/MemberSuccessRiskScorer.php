<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Computes member risk score and reasons from snapshot data.
 */
final class MemberSuccessRiskScorer {

  /**
   * Reason recorded for a member past the settled-dormancy threshold.
   */
  public const REASON_SETTLED_DORMANT = 'settled_dormant';

  /**
   * Payment failed on a member who had already stopped coming in.
   *
   * Half of payment failures are churn wearing a payments costume: the lost
   * half averaged 11 visits in the 90 days before the card failed against 22
   * for those who recovered, and 54% of them had not been in at all (measured
   * 2026-09-10, 111 failures). A card-fix call does not reach that half, so
   * the queue labels them for a different conversation. Label only: it adds
   * no points, so the dunning-day ordering is unchanged.
   */
  public const REASON_PAYMENT_FAILED_COLD = 'payment_failed_cold';

  /**
   * Paused after not coming in for the cold window before the pause began.
   */
  public const REASON_PAUSE_COLD = 'pause_cold';

  /**
   * Cold before pausing AND short tenure.
   *
   * The profile least likely to resume (ended pausers averaged 13.9 months'
   * tenure and 5 prior visits, against 23.6 months and 10 for those who came
   * back) and the profile GEMS activates. Selects the GEMS-offer variant of
   * the pause-end email.
   */
  public const REASON_PAUSE_GEMS_FIT = 'pause_gems_fit';

  /**
   * Default "cold" window: no door visit in this many days.
   */
  public const DEFAULT_COLD_DAYS = 90;

  /**
   * Calculates risk score and reasons for a member.
   *
   * @param array $data
   *   Snapshot data array with keys: stage, payment_failed, payment_pause,
   *   door_badge_status, serial_present, activation_ts, badge_count_total,
   *   badge_count_window, visit_count_30d, last_visit_ts, tenure_bucket,
   *   join_date. Optional: pause_resume_date, cold_days,
   *   pause_end_lead_days, pause_gems_tenure_days.
   * @param int $badge_one_days
   *   Days threshold for first badge check.
   * @param int $badge_four_days
   *   Days threshold for four-badge engagement check.
   * @param array $recency_days
   *   Inactivity day thresholds for retention tiers.
   * @param int $now_ts
   *   Current Unix timestamp.
   * @param int|null $badge_watch_days
   *   Days threshold for the engagement watch tier, or NULL to skip it.
   * @param array|null $dormancy
   *   Dormancy (no door visit) settings — see dormancy(). NULL disables the
   *   factor, which keeps older callers and tests unchanged.
   *
   * @return array{0:int,1:array}
   *   Risk score and array of reason strings.
   */
  public static function calculate(array $data, int $badge_one_days, int $badge_four_days, array $recency_days, int $now_ts, ?int $badge_watch_days = NULL, ?array $dormancy = NULL): array {
    $score = 0;
    $reasons = [];

    // Dormancy: no door visit for a long stretch, in any stage where the
    // member is expected to be coming in. Computed once here; the retention
    // block below lets it REPLACE the smaller inactivity tier rather than
    // stack on top of it, so a long-absent retention member is not counted
    // twice for the same absence.
    [$dormancy_points, $dormancy_reason] = $dormancy !== NULL
      ? self::dormancy($data, $now_ts, $dormancy)
      : [0, NULL];
    $dormancy_applied = FALSE;

    // Settled dormancy: past the settled threshold the member is left alone.
    // Staff found (JR 2026-10-14) that calling someone who has quietly stopped
    // coming for months mostly prompts a cancellation, so these members score
    // 0 and drop out of every call queue. They are reached instead by the
    // capped, staff-approved re-engagement offer (stage "reengage"), which
    // finds them by this reason. Paused and recovery members never get here:
    // dormancy() excludes them.
    if ($dormancy_reason === self::REASON_SETTLED_DORMANT) {
      return [0, [self::REASON_SETTLED_DORMANT]];
    }

    // Payment failed = CRITICAL. Chargebee retries for ~8 days before
    // cancelling; the escalating boost matches that window so staff see the
    // hottest cases (days 3–8 of dunning) at the top of the recovery queue.
    // Day 9+ drops back down because Chargebee has almost certainly cancelled
    // by then and the member will transition to a cancellation state via
    // webhook sync — at which point prevention is moot.
    if (!empty($data['payment_failed'])) {
      $dunning_days = 0;
      if (!empty($data['payment_failed_since'])) {
        $failed_since_ts = strtotime($data['payment_failed_since'] . ' 00:00:00');
        if ($failed_since_ts) {
          $dunning_days = (int) floor(($now_ts - $failed_since_ts) / 86400);
        }
      }

      if ($dunning_days <= 2) {
        $score += 55;
        $reasons[] = 'payment_failed_fresh';
      }
      elseif ($dunning_days <= 5) {
        $score += 70;
        $reasons[] = 'payment_failed_prime';
      }
      elseif ($dunning_days <= 8) {
        $score += 85;
        $reasons[] = 'payment_failed_last_chance';
      }
      else {
        $score += 40;
        $reasons[] = 'payment_failed_stale';
      }

      if (self::isCold($data['last_visit_ts'] ?? NULL, $now_ts, (int) ($data['cold_days'] ?? self::DEFAULT_COLD_DAYS))) {
        $reasons[] = self::REASON_PAYMENT_FAILED_COLD;
      }
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_PAUSED) {
      $pause_start_ts = NULL;
      $pause_duration_days = 0;
      if (!empty($data['pause_start_date'])) {
        $pause_start_ts = strtotime($data['pause_start_date'] . ' 00:00:00') ?: NULL;
        if ($pause_start_ts) {
          $pause_duration_days = (int) floor(($now_ts - $pause_start_ts) / 86400);
        }
      }

      // When does the pause end? Chargebee's own resume date when we have it
      // (synced daily by PauseScheduleSync): pauses run one, two or three
      // months, so the old "day 61 of an assumed 90" rule fired a month late
      // for a one-month pause and a month early for nothing. Without a
      // resume date, fall back to that rule.
      $resume_ts = !empty($data['pause_resume_date'])
        ? (strtotime($data['pause_resume_date'] . ' 00:00:00') ?: NULL)
        : NULL;
      $ending = FALSE;
      if ($resume_ts !== NULL) {
        $days_left = (int) ceil(($resume_ts - $now_ts) / 86400);
        $ending = $days_left >= 0 && $days_left <= (int) ($data['pause_end_lead_days'] ?? 14);
      }
      else {
        // Approaching the 3-month Chargebee pause limit (days 61–90).
        $ending = $pause_duration_days >= 61;
      }
      if ($ending) {
        $penalty = 40;
        // Sustaining members are statistically more likely to return.
        if (($data['tenure_bucket'] ?? NULL) === 'sustaining') {
          $penalty = 30;
        }
        $score += $penalty;
        $reasons[] = 'pause_ending';
      }

      // Cold before the pause: no visit in the cold window ending on the day
      // the pause began. Measured against the pause start, not today — nobody
      // comes in while paused.
      $cold_days = (int) ($data['cold_days'] ?? self::DEFAULT_COLD_DAYS);
      if ($pause_start_ts !== NULL && self::isCold($data['last_visit_ts'] ?? NULL, $pause_start_ts, $cold_days)) {
        $reasons[] = self::REASON_PAUSE_COLD;
        $gems_tenure_days = (int) ($data['pause_gems_tenure_days'] ?? 365);
        $activation_ts = !empty($data['activation_ts']) ? (int) $data['activation_ts'] : NULL;
        if ($activation_ts !== NULL && $gems_tenure_days > 0
          && ($pause_start_ts - $activation_ts) < $gems_tenure_days * 86400) {
          $reasons[] = self::REASON_PAUSE_GEMS_FIT;
        }
      }
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_ONBOARDING) {
      $days_since_join = 0;
      if (!empty($data['join_date'])) {
        $join_ts = strtotime($data['join_date'] . ' 00:00:00');
        if ($join_ts) {
          $days_since_join = (int) floor(($now_ts - $join_ts) / 86400);
        }
      }

      // Sub-step stall detection. Instead of a single "waiting N days" signal,
      // flag the specific funnel step the member is stuck on (profile, safety
      // training, or booking orientation) once they've been stalled past the
      // configured grace window. Ops set this to 48 hours — most stalled
      // members just fail to move forward on one step, and a two-week wait was
      // far too late to intervene.
      $stall_hours = (int) ($data['onboarding_stall_hours'] ?? 48);
      $step = $data['onboarding_step'] ?? NULL;
      $step_ts = (int) ($data['onboarding_step_ts'] ?? 0);

      // Steps worth surfacing as a stall. 'account' is always done and
      // 'involve' is a soft post-orientation nudge, so neither is flagged.
      $actionable_steps = [
        OnboardingFunnel::STEP_PROFILE,
        OnboardingFunnel::STEP_VIDEO,
        OnboardingFunnel::STEP_QUIZ,
        OnboardingFunnel::STEP_SCHEDULE,
      ];

      if ($step && in_array($step, $actionable_steps, TRUE)) {
        if ($step_ts) {
          $hours = (int) floor(($now_ts - $step_ts) / 3600);
          if ($hours >= $stall_hours) {
            // Escalate the score the longer they sit on the same step.
            if ($hours >= 24 * 14) {
              $score += 30;
              $suffix = '_stale';
            }
            elseif ($hours >= 24 * 7) {
              $score += 25;
              $suffix = '_aging';
            }
            else {
              $score += 20;
              $suffix = '';
            }
            $reasons[] = 'stuck_at_' . $step . $suffix;
          }
        }
        elseif ($days_since_join >= 1) {
          // No step timestamp (e.g. legacy/backfilled row) but clearly past the
          // immediate signup window — still surface as actionable.
          $score += 20;
          $reasons[] = 'stuck_at_' . $step;
        }
      }
      elseif (($data['door_badge_status'] ?? NULL) !== 'active' && !empty($data['orientation_scheduled'])) {
        // On track — orientation booked, just waiting for the day to arrive.
        $reasons[] = 'orientation_scheduled_upcoming';
      }

      // Missing serial keeps a 14-day scheduling grace — someone might be
      // mid-pickup. After that, escalate by age so long-stalled members
      // don't sit in "Watch" forever.
      $serial_grace_days = 14;
      if ($days_since_join >= $serial_grace_days && empty($data['serial_present'])) {
        if ($days_since_join >= 180) {
          $score += 25;
          $reasons[] = 'missing_serial_stale';
        }
        elseif ($days_since_join >= 60) {
          $score += 15;
          $reasons[] = 'missing_serial_aging';
        }
        else {
          $score += 10;
          $reasons[] = 'missing_serial';
        }
      }
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_ENGAGEMENT && !empty($data['activation_ts'])) {
      $since_activation = $now_ts - $data['activation_ts'];
      // visit_count_30d is the count of distinct days visited in the last 30d.
      $recent_visit_day_count = (int) ($data['visit_count_30d'] ?? 0);
      // Frequent entry activity indicates ongoing in-space engagement even
      // when new badge requests are not being filed.
      $frequent_visit_days_threshold = 4;

      $no_badge_condition = $data['badge_count_window'] < 1
        && $recent_visit_day_count < $frequent_visit_days_threshold;

      if ($no_badge_condition && $since_activation >= ($badge_one_days * 86400)) {
        // Actionable — member has gone $badge_one_days (default 60) without
        // earning a badge or showing up frequently.
        $score += 20;
        $reasons[] = 'no_badge_1';
      }
      elseif (
        $badge_watch_days !== NULL
        && $no_badge_condition
        && $since_activation >= ($badge_watch_days * 86400)
      ) {
        // Watch — drifting, not yet stalled. Lets staff see members who've
        // started slowing down before they hit the actionable threshold.
        $score += 10;
        $reasons[] = 'engagement_drifting';
      }

      if ($since_activation >= ($badge_four_days * 86400) && $data['badge_count_total'] < 4) {
        $score += 20;
        $reasons[] = 'no_badge_4';
      }
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_RETENTION) {
      // Retention risk escalates by inactivity tier.
      $thresholds = array_values(array_unique(array_filter(array_map('intval', $recency_days), static fn($d) => $d > 0)));
      sort($thresholds);

      if (!empty($thresholds)) {
        $days_since_last_visit = !empty($data['last_visit_ts'])
          ? (int) floor(($now_ts - (int) $data['last_visit_ts']) / 86400)
          : PHP_INT_MAX;

        $highest_tier = -1;
        foreach ($thresholds as $index => $threshold_days) {
          if ($days_since_last_visit >= $threshold_days) {
            $highest_tier = $index;
          }
        }

        if ($highest_tier >= 0) {
          // Cap retention inactivity contribution at 40.
          $tier_points = min(10 * ($highest_tier + 1), 40);
          if ($dormancy_points >= $tier_points) {
            // Dormancy supersedes the inactivity tier (replace, not add).
            $score += $dormancy_points;
            $reasons[] = $dormancy_reason;
            $dormancy_applied = TRUE;
          }
          else {
            $score += $tier_points;
            $reasons[] = 'inactive_' . $thresholds[$highest_tier];
          }
        }
      }
    }

    if ($dormancy_points > 0 && !$dormancy_applied) {
      $score += $dormancy_points;
      $reasons[] = $dormancy_reason;
    }

    return [$score, $reasons];
  }

  /**
   * Scores dormancy: how long a member has gone without a door visit.
   *
   * The clock is the shorter of "days since last visit" and "days since
   * joining", so a member who joined ten days ago and has not come in yet is
   * not dormant, while an onboarding member with no first visit 45 days
   * after joining is. Paused members (an agreed break) and recovery members
   * (already scored on the dunning window, whose ordering this would flatten)
   * are excluded.
   *
   * @param array $data
   *   Snapshot data: stage, last_visit_ts, join_date, activation_ts.
   * @param int $now_ts
   *   Current Unix timestamp.
   * @param array $settings
   *   Keys: enabled, watch_days, watch_points, high_days, high_points,
   *   settled_days (0 = never settle).
   *
   * @return array{0:int,1:?string}
   *   Points and reason key (no_visit_<days>), [0, settled_dormant] past the
   *   settled threshold, or [0, NULL].
   */
  public static function dormancy(array $data, int $now_ts, array $settings): array {
    if (empty($settings['enabled'])) {
      return [0, NULL];
    }
    $stage = $data['stage'] ?? NULL;
    if ($stage === MemberSuccessLifecycle::STAGE_PAUSED
      || $stage === MemberSuccessLifecycle::STAGE_RECOVERY
      || !empty($data['payment_pause'])) {
      return [0, NULL];
    }

    // Tenure anchor: join date, else the lifecycle activation timestamp. With
    // neither we cannot tell a brand-new member from a long-absent one.
    $anchor_ts = NULL;
    if (!empty($data['join_date'])) {
      $anchor_ts = strtotime($data['join_date'] . ' 00:00:00') ?: NULL;
    }
    if ($anchor_ts === NULL && !empty($data['activation_ts'])) {
      $anchor_ts = (int) $data['activation_ts'];
    }
    if ($anchor_ts === NULL) {
      return [0, NULL];
    }

    $days_since_join = (int) floor(($now_ts - $anchor_ts) / 86400);
    $days_without_visit = $days_since_join;
    if (!empty($data['last_visit_ts'])) {
      $days_without_visit = min($days_without_visit, (int) floor(($now_ts - (int) $data['last_visit_ts']) / 86400));
    }

    $settled_days = (int) ($settings['settled_days'] ?? 0);
    if ($settled_days > 0 && $days_without_visit >= $settled_days) {
      return [0, self::REASON_SETTLED_DORMANT];
    }

    $tiers = [
      [(int) ($settings['high_days'] ?? 0), (int) ($settings['high_points'] ?? 0)],
      [(int) ($settings['watch_days'] ?? 0), (int) ($settings['watch_points'] ?? 0)],
    ];
    // Highest day threshold first; tiers replace each other, never add.
    usort($tiers, static fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($tiers as [$days, $points]) {
      if ($days > 0 && $points > 0 && $days_without_visit >= $days) {
        return [$points, 'no_visit_' . $days];
      }
    }
    return [0, NULL];
  }

  /**
   * Whether the member had no door visit in the cold window ending at $as_of.
   *
   * A member with no recorded visit at all counts as cold. The door log
   * starts September 2024 and misses tailgaters, so this is a floor.
   */
  public static function isCold(mixed $last_visit_ts, int $as_of, int $cold_days): bool {
    if ($cold_days <= 0) {
      return FALSE;
    }
    if (empty($last_visit_ts)) {
      return TRUE;
    }
    return ($as_of - (int) $last_visit_ts) >= $cold_days * 86400;
  }

  /**
   * Builds the dormancy settings array from module config.
   *
   * @param object $config
   *   An ImmutableConfig/Config object (anything with get()).
   */
  public static function dormancySettingsFromConfig(object $config): array {
    return [
      'enabled' => (bool) ($config->get('dormancy_enabled') ?? FALSE),
      'watch_days' => (int) ($config->get('dormancy_watch_days') ?? 45),
      'watch_points' => (int) ($config->get('dormancy_watch_points') ?? 20),
      'high_days' => (int) ($config->get('dormancy_high_days') ?? 90),
      'high_points' => (int) ($config->get('dormancy_high_points') ?? 35),
      'settled_days' => (int) ($config->get('dormancy_settled_days') ?? 0),
    ];
  }

}
