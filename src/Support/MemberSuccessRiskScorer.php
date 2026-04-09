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
  public static function calculate(array $data, int $badge_one_days, int $badge_four_days, array $recency_days, int $now_ts): array {
    $score = 0;
    $reasons = [];

    // Payment failed = CRITICAL (need immediate action)
    if (!empty($data['payment_failed'])) {
      $score += 50;
      $reasons[] = 'payment_failed';
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
      // Calculate days since join to determine if in grace period
      $days_since_join = 0;
      if (!empty($data['join_date'])) {
        $join_ts = strtotime($data['join_date'] . ' 00:00:00');
        if ($join_ts) {
          $days_since_join = (int) floor(($now_ts - $join_ts) / 86400);
        }
      }

      // 2-week grace period - only flag members who have been waiting 14+ days
      $grace_period_days = 14;
      $in_grace_period = $days_since_join < $grace_period_days;

      if (!$in_grace_period) {
        if (($data['door_badge_status'] ?? NULL) !== 'active') {
          // Suppress the door-badge risk if a future orientation is already on
          // the calendar — they're on track, no nudge needed yet.
          if (!empty($data['orientation_scheduled'])) {
            $reasons[] = 'orientation_scheduled_upcoming';
          }
          else {
            $score += 20;
            $reasons[] = 'door_badge_pending';
          }
        }
        if (empty($data['serial_present'])) {
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

      if (
        $since_activation >= ($badge_one_days * 86400)
        && $data['badge_count_window'] < 1
        && $recent_visit_day_count < $frequent_visit_days_threshold
      ) {
        $score += 20;
        $reasons[] = 'no_badge_1';
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
