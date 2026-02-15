<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display payment risk badges.
 *
 * @ViewsField("payment_risk_field")
 */
class PaymentRiskField extends FieldPluginBase {

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
    $payment_failed = $values->ms_member_success_snapshot_payment_failed ?? 0;
    $payment_pause = $values->ms_member_success_snapshot_payment_pause ?? 0;

    $badges = [];

    // Only show badge if value is actually 1 (not just truthy)
    if ((int) $payment_failed === 1) {
      $badges[] = '<span class="badge bg-danger">Failed</span>';
    }

    if ((int) $payment_pause === 1) {
      $badges[] = '<span class="badge bg-warning text-dark">Paused</span>';
    }

    if (empty($badges)) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="badge bg-success">OK</span>',
      ];
    }

    return [
      '#type' => 'markup',
      '#markup' => implode(' ', $badges),
    ];
  }

}
