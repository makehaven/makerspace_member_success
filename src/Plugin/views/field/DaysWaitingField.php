<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display days since member joined (for onboarding).
 *
 * @ViewsField("days_waiting_field")
 */
class DaysWaitingField extends FieldPluginBase {

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
    $join_date = $values->ms_member_success_snapshot_join_date ?? NULL;

    if (!$join_date) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-muted">—</span>',
      ];
    }

    $join_ts = strtotime($join_date . ' 00:00:00');
    if (!$join_ts) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-muted">—</span>',
      ];
    }

    $now = time();
    $days_waiting = floor(($now - $join_ts) / 86400);

    // Color code based on urgency
    if ($days_waiting >= 21) {
      $color = 'danger';
      $weight = 'bold';
    }
    elseif ($days_waiting >= 14) {
      $color = 'warning';
      $weight = 'bold';
    }
    elseif ($days_waiting >= 7) {
      $color = 'info';
      $weight = 'normal';
    }
    else {
      $color = 'success';
      $weight = 'normal';
    }

    $join_date_formatted = date('M j, Y', $join_ts);

    return [
      '#type' => 'markup',
      '#markup' => sprintf(
        '<span class="text-%s" style="font-weight: %s;" title="Joined: %s">%d days</span>',
        $color,
        $weight,
        $join_date_formatted,
        $days_waiting
      ),
    ];
  }

}
