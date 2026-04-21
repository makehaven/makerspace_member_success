<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays Chargebee dunning-window age and urgency for failed payments.
 *
 * Chargebee retries failed payments for roughly eight days before cancelling
 * the subscription. This field shows "Day X of ~8" with color escalation so
 * staff can triage the hottest cases first.
 *
 * @ViewsField("dunning_days_field")
 */
class DunningDaysField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['payment_failed', 'payment_failed_since']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $payment_failed = (int) ($values->ms_member_success_snapshot_payment_failed ?? 0);
    $failed_since = $values->ms_member_success_snapshot_payment_failed_since ?? NULL;

    if ($payment_failed !== 1) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-muted small">—</span>',
      ];
    }

    if (empty($failed_since)) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="badge bg-secondary" title="Payment is failed but the start date is unknown. Will be tracked from the next successful daily snapshot.">Day ? of ~8</span>',
      ];
    }

    $failed_since_ts = strtotime($failed_since . ' 00:00:00');
    if (!$failed_since_ts) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="text-muted small">—</span>',
      ];
    }

    $days = (int) floor((time() - $failed_since_ts) / 86400);
    if ($days < 0) {
      $days = 0;
    }
    $remaining = max(0, 8 - $days);

    if ($days <= 2) {
      $class = 'bg-warning text-dark';
      $label = 'Fresh';
      $hint = 'Day ' . $days . ' of ~8 — Chargebee just started retrying. High-leverage intervention window.';
    }
    elseif ($days <= 5) {
      $class = 'bg-orange text-white';
      $label = 'Prime';
      $hint = 'Day ' . $days . ' of ~8 — prime outreach window. ' . $remaining . ' day' . ($remaining === 1 ? '' : 's') . ' until Chargebee cancels.';
    }
    elseif ($days <= 8) {
      $class = 'bg-danger';
      $label = 'Last chance';
      $hint = 'Day ' . $days . ' of ~8 — Chargebee is about to cancel. Contact today.';
    }
    else {
      $class = 'bg-secondary';
      $label = 'Stale';
      $hint = 'Day ' . $days . ' — Chargebee dunning window likely exhausted. If member still shows failed they may have been re-attempted; check Chargebee directly.';
    }

    $badge = '<span class="badge ' . $class . '" title="' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '" '
      . 'style="' . ($label === 'Prime' ? 'background-color:#fd7e14;color:#fff;' : '') . '">'
      . 'Day ' . $days . ' of ~8 <span style="opacity:.8;font-weight:normal;">(' . $label . ')</span>'
      . '</span>';

    return [
      '#type' => 'markup',
      '#markup' => $badge,
    ];
  }

}
