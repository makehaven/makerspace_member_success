<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;

/**
 * Service for calculating member recovery outreach metrics.
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
   * Get resolution rate (% of contacted members who resolved their issue).
   *
   * @return array
   *   ['total' => int, 'resolved' => int, 'rate' => float]
   */
  public function getResolutionRate() {
    $query = "
      SELECT
        COUNT(DISTINCT uid) as total,
        COUNT(DISTINCT CASE
          WHEN outcome IN ('payment_updated', 'confirmed_cancel')
          THEN uid
        END) as resolved
      FROM {ms_member_outreach_log}
    ";

    $result = $this->database->query($query)->fetchAssoc();
    $total = (int) $result['total'];
    $resolved = (int) $result['resolved'];
    $rate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

    return [
      'total' => $total,
      'resolved' => $resolved,
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
    $query = "
      SELECT AVG(attempt_count) as avg_attempts
      FROM (
        SELECT uid, COUNT(*) as attempt_count
        FROM {ms_member_outreach_log}
        WHERE uid IN (
          SELECT DISTINCT uid
          FROM {ms_member_outreach_log}
          WHERE outcome IN ('payment_updated', 'confirmed_cancel')
        )
        GROUP BY uid
      ) resolved_members
    ";

    $result = $this->database->query($query)->fetchField();
    return round((float) $result, 1);
  }

  /**
   * Get exhaustion rate (% with 3+ attempts and no resolution).
   *
   * @return array
   *   ['total' => int, 'exhausted' => int, 'rate' => float]
   */
  public function getExhaustionRate() {
    // Members with 3+ attempts
    $high_attempt_query = "
      SELECT uid, COUNT(*) as attempts
      FROM {ms_member_outreach_log}
      GROUP BY uid
      HAVING COUNT(*) >= 3
    ";

    // Of those, how many are unresolved?
    $query = "
      SELECT
        COUNT(*) as total_high_attempts,
        SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) as exhausted
      FROM (
        SELECT
          l.uid,
          MAX(CASE WHEN l.outcome IN ('payment_updated', 'confirmed_cancel') THEN 1 ELSE 0 END) as resolved
        FROM ({$high_attempt_query}) high
        JOIN {ms_member_outreach_log} l ON high.uid = l.uid
        GROUP BY l.uid
      ) subq
    ";

    $result = $this->database->query($query)->fetchAssoc();
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
  public function getAverageDaysToResolution() {
    $query = "
      SELECT AVG(days_to_resolution) as avg_days
      FROM (
        SELECT
          uid,
          DATEDIFF(
            MAX(CASE WHEN outcome IN ('payment_updated', 'confirmed_cancel') THEN contact_date END),
            MIN(contact_date)
          ) as days_to_resolution
        FROM {ms_member_outreach_log}
        WHERE uid IN (
          SELECT DISTINCT uid
          FROM {ms_member_outreach_log}
          WHERE outcome IN ('payment_updated', 'confirmed_cancel')
        )
        GROUP BY uid
      ) resolved
    ";

    $result = $this->database->query($query)->fetchField();
    return round((float) $result, 1);
  }

  /**
   * Get channel effectiveness (resolution rate by contact method).
   *
   * @return array
   *   Array keyed by contact_method with resolution stats
   */
  public function getChannelEffectiveness() {
    $query = "
      SELECT
        contact_method,
        COUNT(DISTINCT uid) as total_contacted,
        COUNT(DISTINCT CASE
          WHEN outcome IN ('payment_updated', 'confirmed_cancel')
          THEN uid
        END) as resolved
      FROM {ms_member_outreach_log}
      GROUP BY contact_method
      ORDER BY contact_method
    ";

    $results = $this->database->query($query)->fetchAll(\PDO::FETCH_ASSOC);
    $effectiveness = [];

    foreach ($results as $row) {
      $total = (int) $row['total_contacted'];
      $resolved = (int) $row['resolved'];
      $rate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

      $effectiveness[$row['contact_method']] = [
        'total' => $total,
        'resolved' => $resolved,
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

    $results = $this->database->query($query)->fetchAll(\PDO::FETCH_ASSOC);
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
  public function getAllMetrics() {
    return [
      'resolution_rate' => $this->getResolutionRate(),
      'avg_attempts_to_success' => $this->getAverageAttemptsToSuccess(),
      'exhaustion_rate' => $this->getExhaustionRate(),
      'avg_days_to_resolution' => $this->getAverageDaysToResolution(),
      'channel_effectiveness' => $this->getChannelEffectiveness(),
      'attempts_distribution' => $this->getAttemptsDistribution(),
    ];
  }

}
