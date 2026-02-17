<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display how long a member has been active.
 *
 * @ViewsField("member_tenure_field")
 */
class MemberTenureField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['join_date']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $join_date = (string) ($this->getValue($values, 'join_date') ?? '');
    $join_ts = $join_date !== '' ? strtotime($join_date . ' 00:00:00') : FALSE;

    if (empty($join_ts)) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-muted">Unknown</span>',
      ];
    }

    $tenure = \Drupal::service('date.formatter')->formatTimeDiffSince((int) $join_ts, [
      'granularity' => 2,
    ]);
    $joined_on = date('M j, Y', (int) $join_ts);

    return [
      '#type' => 'markup',
      '#markup' => '<span title="Joined: ' . $joined_on . '">' . $tenure . ' ago</span>',
    ];
  }

}
