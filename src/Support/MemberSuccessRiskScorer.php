<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Computes member risk score and reasons from snapshot data.
 */
final class MemberSuccessRiskScorer {

  /**
   * Calculates risk score and reasons for a member.
   *
   * @param array $data
   *   Snapshot data array with keys: stage, payment_failed, payment_pause,
   *   door_badge_status, serial_present, activation_ts, badge_count_total,
   *   badge_count_window, visit_count_30d, last_visit_ts, tenure_bucket,
   *   join_date.
   * @param int $badge_one_days
   *   Days threshold for first badge check.
   * @param int $badge_four_days
   *   Days threshold for four-badge engagement check.
   * @param array $recency_days
   *   Inactivity day thresholds for retention tiers.
   * @param int $now_ts
   *   Current Unix timestamp.
   *
   * @return array{0:int,1:array}
   *   Risk score and array of reason strings.
   */
  public static function calculate(array $data, int $badge_one_days, int $badge_four_days, array $recency_days, int $now_ts, ?int $badge_watch_days = NULL): array {
    $score = 0;
    $reasons = [];

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
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_PAUSED) {
      $pause_duration_days = 0;
      if (!empty($data['pause_start_date'])) {
        $pause_start_ts = strtotime($data['pause_start_date'] . ' 00:00:00');
        if ($pause_start_ts) {
          $pause_duration_days = (int) floor(($now_ts - $pause_start_ts) / 86400);
        }
      }
      // High-risk window: approaching the 3-month Chargebee pause limit (days 61–90).
      if ($pause_duration_days >= 61) {
        $penalty = 40;
        // Sustaining members are statistically more likely to return.
        if (($data['tenure_bucket'] ?? NULL) === 'sustaining') {
          $penalty = 30;
        }
        $score += $penalty;
        $reasons[] = 'pause_ending';
      }
      // Low-risk window (days 1–60): planned break, no penalty.
    }

    if ($data['stage'] === MemberSuccessLifecycle::STAGE_ONBOARDING) {
      $days_since_join = 0;
      if (!empty($data['join_date'])) {
        $join_ts = strtotime($data['join_date'] . ' 00:00:00');
        if ($join_ts) {
          $days_since_join = (int) floor(($now_ts - $join_ts) / 86400);
        }
      }

      $door_badge_pending = ($data['door_badge_status'] ?? NULL) !== 'active';
      $has_orientation_scheduled = !empty($data['orientation_scheduled']);

      // Pipeline-health signal: the signup flow is supposed to prompt new
      // members to book their orientation immediately. If a day has passed
      // since signup and they still haven't scheduled one (and don't already
      // have an active door badge), something in the pipeline broke and
      // staff should investigate manually. Flag as actionable from day 1.
      if ($door_badge_pending) {
        if ($has_orientation_scheduled) {
          // On track — no score, just surface the signal for transparency.
          $reasons[] = 'orientation_scheduled_upcoming';
        }
        elseif ($days_since_join >= 1) {
          $score += 20;
          $reasons[] = 'orientation_not_scheduled';
        }
        // Under 1 day old with no orientation yet: still within the normal
        // book-right-after-signup window.
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
          $score += min(10 * ($highest_tier + 1), 40);
          $reasons[] = 'inactive_' . $thresholds[$highest_tier];
        }
      }
    }

    return [$score, $reasons];
  }

}
