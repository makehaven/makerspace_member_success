<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display days since last visit with urgency tiers.
 *
 * @ViewsField("days_since_last_visit_field")
 */
class DaysSinceLastVisitField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['last_visit_ts']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $last_visit_ts = (int) ($this->getValue($values, 'last_visit_ts') ?? 0);

    if (empty($last_visit_ts)) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-danger fw-bold" title="No recorded access">Never</span>',
      ];
    }

    $days = (int) floor((time() - (int) $last_visit_ts) / 86400);
    $last_visit_date = date('M j, Y', (int) $last_visit_ts);

    if ($days >= 90) {
      $class = 'text-danger fw-bold';
    }
    elseif ($days >= 60) {
      $class = 'text-warning fw-bold';
    }
    elseif ($days >= 30) {
      $class = 'text-info fw-bold';
    }
    else {
      $class = 'text-success';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<span class="' . $class . '" title="Last visit: ' . $last_visit_date . '">' . $days . ' days ago</span>',
    ];
  }

}
