<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;

/**
 * Service for calculating member recovery outreach metrics.
 *
 * "Resolved" here means the outreach reached a positive case-closing outcome
 * (payment updated, member will return, or no action needed). Confirmed
 * cancellations are case-closing but lost, so they are surfaced in a separate
 * column rather than counted as resolved. See
 * MemberSuccessLifecycle::resolvedOutcomes() for the canonical list.
 */
class RecoveryMetrics {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a RecoveryMetrics object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Builds an "IN (:p0,:p1,...)" clause from resolvedOutcomes() with params.
   *
   * @return array{0: string, 1: array<string, string>}
   *   The placeholder list ("p0,p1,...") and the matching parameters.
   */
  private function resolvedPlaceholders(string $prefix = 'resolved'): array {
    $outcomes = MemberSuccessLifecycle::resolvedOutcomes();
    $placeholders = [];
    $params = [];
    foreach (array_values($outcomes) as $i => $outcome) {
      $key = ':' . $prefix . '_' . $i;
      $placeholders[] = $key;
      $params[$key] = $outcome;
    }
    return [implode(',', $placeholders), $params];
  }

  /**
   * Get resolution rate (% of contacted members who resolved their issue).
   *
   * @return array
   *   ['total' => int, 'resolved' => int, 'rate' => float]
   */
  public function getResolutionRate($start_date = NULL, $end_date = NULL) {
    $params = [];
    $date_where = $this->buildDateFilter($start_date, $end_date, $params);
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $params += $resolved_params;
    $params[':cancel_outcome'] = MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL;

    $query = "
      SELECT
        COUNT(DISTINCT uid) as total,
        COUNT(DISTINCT CASE
          WHEN outcome IN ({$resolved_in})
          THEN uid
        END) as resolved,
        COUNT(DISTINCT CASE
          WHEN outcome = :cancel_outcome
          THEN uid
        END) as confirmed_cancel
      FROM {ms_member_outreach_log}
      WHERE 1=1" . $date_where . "
    ";

    $result = $this->database->query($query, $params)->fetchAssoc();
    $total = (int) ($result['total'] ?? 0);
    $resolved = (int) ($result['resolved'] ?? 0);
    $confirmed_cancel = (int) ($result['confirmed_cancel'] ?? 0);
    $rate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

    return [
      'total' => $total,
      'resolved' => $resolved,
      'confirmed_cancel' => $confirmed_cancel,
      'rate' => $rate,
    ];
  }

  /**
   * Get average attempts to resolution for successful cases.
   *
   * @return float
   *   Average number of attempts before resolution
   */
  public function getAverageAttemptsToSuccess() {
    [$resolved_in, $params] = $this->resolvedPlaceholders();
    $query = "
      SELECT AVG(attempt_count) as avg_attempts
      FROM (
        SELECT uid, COUNT(*) as attempt_count
        FROM {ms_member_outreach_log}
        WHERE uid IN (
          SELECT DISTINCT uid
          FROM {ms_member_outreach_log}
          WHERE outcome IN ({$resolved_in})
        )
        GROUP BY uid
      ) resolved_members
    ";

    $result = $this->database->query($query, $params)->fetchField();
    return round((float) $result, 1);
  }

  /**
   * Get exhaustion rate (% with 3+ attempts and no resolution).
   *
   * @return array
   *   ['total' => int, 'exhausted' => int, 'rate' => float]
   */
  public function getExhaustionRate() {
    // Members with 3+ attempts.
    $high_attempt_query = "
      SELECT uid, COUNT(*) as attempts
      FROM {ms_member_outreach_log}
      GROUP BY uid
      HAVING COUNT(*) >= 3
    ";

    // Of those, how many are unresolved?
    [$resolved_in, $params] = $this->resolvedPlaceholders();
    $query = "
      SELECT
        COUNT(*) as total_high_attempts,
        SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) as exhausted
      FROM (
        SELECT
          l.uid,
          MAX(CASE WHEN l.outcome IN ({$resolved_in}) THEN 1 ELSE 0 END) as resolved
        FROM ({$high_attempt_query}) high
        JOIN {ms_member_outreach_log} l ON high.uid = l.uid
        GROUP BY l.uid
      ) subq
    ";

    $result = $this->database->query($query, $params)->fetchAssoc();
    $total = (int) $result['total_high_attempts'];
    $exhausted = (int) $result['exhausted'];
    $rate = $total > 0 ? round(($exhausted / $total) * 100, 1) : 0;

    return [
      'total_high_attempts' => $total,
      'exhausted' => $exhausted,
      'rate' => $rate,
    ];
  }

  /**
   * Get average days to resolution.
   *
   * @return float
   *   Average days from first contact to resolution
   */
  public function getAverageDaysToResolution($start_date = NULL, $end_date = NULL) {
    $params = [];
    $date_where = $this->buildDateFilter($start_date, $end_date, $params);
    [$resolved_in_outer, $resolved_params_outer] = $this->resolvedPlaceholders('resolved_outer');
    [$resolved_in_inner, $resolved_params_inner] = $this->resolvedPlaceholders('resolved_inner');
    $params += $resolved_params_outer + $resolved_params_inner;

    $query = "
      SELECT AVG(days_to_resolution) as avg_days
      FROM (
        SELECT
          uid,
          DATEDIFF(
            MAX(CASE WHEN outcome IN ({$resolved_in_outer}) THEN contact_date END),
            MIN(contact_date)
          ) as days_to_resolution
        FROM {ms_member_outreach_log}
        WHERE uid IN (
          SELECT DISTINCT uid
          FROM {ms_member_outreach_log}
          WHERE outcome IN ({$resolved_in_inner})
        )" . $date_where . "
        GROUP BY uid
      ) resolved
    ";

    $result = $this->database->query($query, $params)->fetchField();
    return round((float) $result, 1);
  }

  /**
   * Get channel effectiveness (resolution rate by contact method).
   *
   * @return array
   *   Array keyed by contact_method with resolution stats
   */
  public function getChannelEffectiveness($start_date = NULL, $end_date = NULL) {
    $params = [];
    $date_where = $this->buildDateFilter($start_date, $end_date, $params);
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $params += $resolved_params;
    $params[':cancel_outcome'] = MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL;

    $query = "
      SELECT
        contact_method,
        COUNT(DISTINCT uid) as total_contacted,
        COUNT(DISTINCT CASE
          WHEN outcome IN ({$resolved_in})
          THEN uid
        END) as resolved,
        COUNT(DISTINCT CASE
          WHEN outcome = :cancel_outcome
          THEN uid
        END) as confirmed_cancel
      FROM {ms_member_outreach_log}
      WHERE 1=1" . $date_where . "
      GROUP BY contact_method
      ORDER BY contact_method
    ";

    $results = $this->database->query($query, $params)->fetchAll(FetchAs::Associative);
    $effectiveness = [];

    foreach ($results as $row) {
      $total = (int) ($row['total_contacted'] ?? 0);
      $resolved = (int) ($row['resolved'] ?? 0);
      $confirmed_cancel = (int) ($row['confirmed_cancel'] ?? 0);
      $rate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

      $effectiveness[$row['contact_method']] = [
        'total' => $total,
        'resolved' => $resolved,
        'confirmed_cancel' => $confirmed_cancel,
        'rate' => $rate,
      ];
    }

    return $effectiveness;
  }

  /**
   * Get attempts distribution (how many members at each attempt count).
   *
   * @return array
   *   Array keyed by attempt_count with member counts
   */
  public function getAttemptsDistribution() {
    $query = "
      SELECT
        CASE
          WHEN attempts >= 5 THEN '5+'
          ELSE CAST(attempts AS CHAR)
        END as attempt_bucket,
        COUNT(*) as member_count
      FROM (
        SELECT uid, COUNT(*) as attempts
        FROM {ms_member_outreach_log}
        GROUP BY uid
      ) counts
      GROUP BY attempt_bucket
      ORDER BY attempt_bucket
    ";

    $results = $this->database->query($query)->fetchAll(FetchAs::Associative);
    $distribution = [];

    foreach ($results as $row) {
      $distribution[$row['attempt_bucket']] = (int) $row['member_count'];
    }

    return $distribution;
  }

  /**
   * Get all metrics in one call.
   *
   * @return array
   *   All metrics data
   */
  public function getAllMetrics($start_date = NULL, $end_date = NULL) {
    return [
      'resolution_rate' => $this->getResolutionRate($start_date, $end_date),
      'avg_attempts_to_success' => $this->getAverageAttemptsToSuccess(),
      'exhaustion_rate' => $this->getExhaustionRate(),
      'avg_days_to_resolution' => $this->getAverageDaysToResolution($start_date, $end_date),
      'channel_effectiveness' => $this->getChannelEffectiveness($start_date, $end_date),
      'attempts_distribution' => $this->getAttemptsDistribution(),
    ];
  }

  /**
   * Helper to build date filter clause and parameters.
   */
  private function buildDateFilter($start_date, $end_date, &$params = []) {
    if ($start_date && $end_date) {
      $params[':start_date'] = $start_date;
      $params[':end_date'] = $end_date;
      return " AND contact_date BETWEEN :start_date AND :end_date";
    }
    return '';
  }

  /**
   * Get performance metrics by staff member.
   *
   * @return array
   *   Array of staff performance data, keyed by staff_uid
   */
  public function getStaffPerformance($start_date = NULL, $end_date = NULL) {
    // Build date filter clause.
    $date_where = '';
    if ($start_date && $end_date) {
      $date_where = " AND log.contact_date BETWEEN :start_date AND :end_date";
    }

    // First get basic stats per staff member.
    $params = [];
    if ($start_date && $end_date) {
      $params[':start_date'] = $start_date;
      $params[':end_date'] = $end_date;
    }
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $params += $resolved_params;
    $params[':cancel_outcome'] = MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL;

    $query = "
      SELECT
        log.staff_uid,
        u.name as staff_name,
        COUNT(DISTINCT log.uid) as members_contacted,
        COUNT(DISTINCT CASE
          WHEN log.outcome IN ({$resolved_in})
          THEN log.uid
        END) as resolved,
        COUNT(DISTINCT CASE
          WHEN log.outcome = :cancel_outcome
          THEN log.uid
        END) as confirmed_cancel,
        COUNT(*) as total_attempts
      FROM {ms_member_outreach_log} log
      LEFT JOIN {users_field_data} u ON u.uid = log.staff_uid
      WHERE log.staff_uid IS NOT NULL" . $date_where . "
      GROUP BY log.staff_uid, u.name
      ORDER BY resolved DESC, members_contacted DESC
    ";

    $results = $this->database->query($query, $params)->fetchAll(FetchAs::Associative);

    // Get average days to resolution per staff member using subquery.
    // Note: inner subquery uses 'log' alias so $date_where (log.contact_date) works.
    $days_params = [];
    if ($start_date && $end_date) {
      $days_params[':start_date'] = $start_date;
      $days_params[':end_date'] = $end_date;
    }
    [$days_resolved_outer, $days_resolved_outer_params] = $this->resolvedPlaceholders('days_outer');
    [$days_resolved_inner, $days_resolved_inner_params] = $this->resolvedPlaceholders('days_inner');
    $days_params += $days_resolved_outer_params + $days_resolved_inner_params;

    $days_query = "
      SELECT
        staff_uid,
        AVG(days_to_resolution) as avg_days
      FROM (
        SELECT
          log.staff_uid,
          log.uid,
          DATEDIFF(
            MAX(CASE WHEN log.outcome IN ({$days_resolved_outer}) THEN log.contact_date END),
            MIN(log.contact_date)
          ) as days_to_resolution
        FROM {ms_member_outreach_log} log
        WHERE log.staff_uid IS NOT NULL
          AND log.outcome IN ({$days_resolved_inner})" . $date_where . "
        GROUP BY log.staff_uid, log.uid
      ) as member_resolution_times
      WHERE days_to_resolution IS NOT NULL
      GROUP BY staff_uid
    ";

    $days_results = $this->database->query($days_query, $days_params)->fetchAllKeyed();
    $performance = [];

    foreach ($results as $row) {
      $contacted = (int) ($row['members_contacted'] ?? 0);
      $resolved = (int) ($row['resolved'] ?? 0);
      $confirmed_cancel = (int) ($row['confirmed_cancel'] ?? 0);
      $rate = $contacted > 0 ? round(($resolved / $contacted) * 100, 1) : 0;
      $staff_uid = (int) $row['staff_uid'];

      $performance[$staff_uid] = [
        'staff_uid' => $staff_uid,
        'staff_name' => $row['staff_name'] ?? 'Unknown',
        'members_contacted' => $contacted,
        'resolved' => $resolved,
        'confirmed_cancel' => $confirmed_cancel,
        'resolution_rate' => $rate,
        'total_attempts' => (int) $row['total_attempts'],
        'avg_days_to_resolution' => round((float) ($days_results[$staff_uid] ?? 0), 1),
      ];
    }

    return $performance;
  }

  /**
   * Get productivity metrics for a specific staff member and date range.
   *
   * @param int $staff_uid
   *   The staff member's user ID.
   * @param string $start_date
   *   Start date (YYYY-MM-DD).
   * @param string $end_date
   *   End date (YYYY-MM-DD).
   *
   * @return array
   *   Productivity metrics for the period
   */
  public function getStaffProductivity(int $staff_uid, string $start_date, string $end_date) {
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $query = "
      SELECT
        COUNT(DISTINCT uid) as members_contacted,
        COUNT(*) as total_attempts,
        COUNT(DISTINCT CASE
          WHEN outcome IN ({$resolved_in})
          THEN uid
        END) as resolved,
        COUNT(DISTINCT DATE(contact_date)) as active_days,
        MIN(contact_date) as first_contact,
        MAX(contact_date) as last_contact
      FROM {ms_member_outreach_log}
      WHERE staff_uid = :staff_uid
        AND contact_date BETWEEN :start_date AND :end_date
    ";

    $result = $this->database->query($query, [
      ':staff_uid' => $staff_uid,
      ':start_date' => $start_date,
      ':end_date' => $end_date,
    ] + $resolved_params)->fetchAssoc();

    $contacted = (int) $result['members_contacted'];
    $resolved = (int) $result['resolved'];
    $active_days = (int) $result['active_days'];

    return [
      'members_contacted' => $contacted,
      'total_attempts' => (int) $result['total_attempts'],
      'resolved' => $resolved,
      'resolution_rate' => $contacted > 0 ? round(($resolved / $contacted) * 100, 1) : 0,
      'active_days' => $active_days,
      'avg_contacts_per_day' => $active_days > 0 ? round($contacted / $active_days, 1) : 0,
      'first_contact' => $result['first_contact'],
      'last_contact' => $result['last_contact'],
    ];
  }

  /**
   * Calculate the monetary value of retention efforts.
   *
   * @return array
   *   Value metrics including total at risk and value saved
   */
  public function getRetentionValue($start_date = NULL, $end_date = NULL) {
    $params = [];
    $date_where = $this->buildDateFilter($start_date, $end_date, $params);
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $params += $resolved_params;

    // Single query: join monthly payment directly to avoid N+1 per member.
    $query = "
      SELECT
        log.uid,
        MAX(CASE WHEN log.outcome IN ({$resolved_in}) THEN 1 ELSE 0 END) as was_resolved,
        COALESCE(payment.field_member_payment_monthly_value, 0) as monthly_payment
      FROM {ms_member_outreach_log} log
      LEFT JOIN {profile} p
        ON p.uid = log.uid AND p.type = 'main' AND p.is_default = 1 AND p.status = 1
      LEFT JOIN {profile__field_member_payment_monthly} payment
        ON payment.entity_id = p.profile_id AND payment.deleted = 0
      WHERE 1=1" . $date_where . "
      GROUP BY log.uid, payment.field_member_payment_monthly_value
    ";

    $members = $this->database->query($query, $params)->fetchAll(FetchAs::Associative);

    $total_at_risk = 0;
    $value_saved = 0;
    $resolved_count = 0;

    foreach ($members as $member) {
      $annual_value = (float) $member['monthly_payment'] * 12;
      $total_at_risk += $annual_value;

      if ((int) $member['was_resolved'] === 1) {
        $value_saved += $annual_value;
        $resolved_count++;
      }
    }

    return [
      'total_members_at_risk' => count($members),
      'members_resolved' => $resolved_count,
      'members_lost' => count($members) - $resolved_count,
      'total_annual_value_at_risk' => round($total_at_risk, 2),
      'annual_value_saved' => round($value_saved, 2),
      'annual_value_lost' => round($total_at_risk - $value_saved, 2),
      'value_saved_rate' => $total_at_risk > 0 ? round(($value_saved / $total_at_risk) * 100, 1) : 0,
    ];
  }

  /**
   * Get monthly trend data for tracking improvements over time.
   *
   * @param int $months
   *   Number of months to look back (default: 12).
   *
   * @return array
   *   Monthly metrics for trending
   */
  public function getMonthlyTrends(int $months = 12) {
    [$resolved_in, $resolved_params] = $this->resolvedPlaceholders();
    $params = [':months' => $months] + $resolved_params;
    $query = "
      SELECT
        DATE_FORMAT(contact_date, '%Y-%m') as month,
        COUNT(DISTINCT uid) as members_contacted,
        COUNT(DISTINCT CASE
          WHEN outcome IN ({$resolved_in})
          THEN uid
        END) as resolved,
        COUNT(*) as total_attempts
      FROM {ms_member_outreach_log}
      WHERE contact_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
      GROUP BY DATE_FORMAT(contact_date, '%Y-%m')
      ORDER BY month ASC
    ";

    $results = $this->database->query($query, $params)->fetchAll(FetchAs::Associative);
    $trends = [];

    foreach ($results as $row) {
      $contacted = (int) $row['members_contacted'];
      $resolved = (int) $row['resolved'];

      $trends[$row['month']] = [
        'month' => $row['month'],
        'members_contacted' => $contacted,
        'resolved' => $resolved,
        'resolution_rate' => $contacted > 0 ? round(($resolved / $contacted) * 100, 1) : 0,
        'total_attempts' => (int) $row['total_attempts'],
        'avg_attempts_per_member' => $contacted > 0 ? round((int) $row['total_attempts'] / $contacted, 1) : 0,
      ];
    }

    return $trends;
  }

}
