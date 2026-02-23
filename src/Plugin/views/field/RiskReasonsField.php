<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display risk reasons in human-readable format.
 *
 * @ViewsField("risk_reasons_field")
 */
class RiskReasonsField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Leave empty to avoid a query on this field.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get risk reasons and risk score
    $risk_reasons_raw = $values->ms_member_success_snapshot_risk_reasons ?? '';
    $risk_score = $values->ms_member_success_snapshot_risk_score ?? 0;

    // If no risk, show nothing
    if ($risk_score < 20) {
      return '';
    }

    // Unserialize risk reasons
    $risk_reasons = [];
    if (!empty($risk_reasons_raw)) {
      $unserialized = @unserialize($risk_reasons_raw);
      if (is_array($unserialized)) {
        $risk_reasons = $unserialized;
      }
    }

    // Map reasons to short codes
    $code_map = [
      'payment_issue' => 'PAY',
      'payment_failed' => 'PAY',
      'door_badge_pending' => 'DOOR',
      'missing_serial' => 'KEY',
      'no_badge_1' => 'NO-BADGE',
      'no_badge_4' => 'STALLED',
      'inactive' => 'ABSENT',
      'pause_ending' => 'PAUSE-END',
    ];

    $codes = [];
    foreach ($risk_reasons as $reason) {
      if (isset($code_map[$reason])) {
        $codes[] = $code_map[$reason];
      }
      elseif (strpos($reason, 'inactive_') === 0) {
        $codes[] = 'ABSENT';
      }
    }

    // Build output with urgency indicator
    $output = '';

    // Add urgency indicator for critical members
    if ($risk_score >= 50) {
      $output .= '<span class="badge bg-danger me-1" title="Critical - Immediate Action Required">🔴 ' . $risk_score . '</span>';
    }
    elseif ($risk_score >= 20) {
      $output .= '<span class="badge bg-warning text-dark me-1" title="Actionable">' . $risk_score . '</span>';
    }

    // Add reason codes
    if (!empty($codes)) {
      $output .= '<span class="text-muted small">' . implode(' + ', $codes) . '</span>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

}
