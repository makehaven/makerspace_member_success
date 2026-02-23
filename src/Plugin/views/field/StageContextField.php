<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to display stage-relevant contextual information.
 *
 * @ViewsField("stage_context_field")
 */
class StageContextField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['pause_start_date']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $stage = $values->ms_member_success_snapshot_stage ?? '';
    $join_date = $values->ms_member_success_snapshot_join_date ?? '';
    $last_visit_ts = $values->ms_member_success_snapshot_last_visit_ts ?? 0;
    $last_badge_ts = $values->ms_member_success_snapshot_last_badge_ts ?? 0;
    $badge_total = $values->ms_member_success_snapshot_badge_count_total ?? 0;
    $badge_window = $values->ms_member_success_snapshot_badge_count_window ?? 0;
    $orientation_date = $values->ms_member_success_snapshot_orientation_date ?? '';
    $visit_count_30d = $values->ms_member_success_snapshot_visit_count_30d ?? 0;

    $output = '';
    $now = time();

    switch ($stage) {
      case 'onboarding':
        // Show days waiting and orientation status
        if ($join_date) {
          $join_ts = strtotime($join_date);
          $days_waiting = floor(($now - $join_ts) / 86400);
          $output .= '<span class="text-muted">Waiting: <strong>' . $days_waiting . ' days</strong></span>';
        }
        if ($orientation_date) {
          $output .= '<br><span class="text-success small">✓ Oriented: ' . date('M j', strtotime($orientation_date)) . '</span>';
        } else {
          $output .= '<br><span class="text-warning small">Orientation pending</span>';
        }
        break;

      case 'engagement':
        // Show badge progress
        $output .= '<span class="text-muted">Badges: <strong>' . $badge_total . ' total</strong></span>';
        $output .= '<br><span class="small">' . $badge_window . ' in last 6mo</span>';
        if ($last_badge_ts) {
          $days_ago = floor(($now - $last_badge_ts) / 86400);
          $output .= '<br><span class="text-muted small">Last: ' . $days_ago . 'd ago</span>';
        } else {
          $output .= '<br><span class="text-danger small">No badges yet</span>';
        }
        break;

      case 'retention':
        // Show last visit and absence
        if ($last_visit_ts) {
          $days_absent = floor(($now - $last_visit_ts) / 86400);
          $last_visit_date = date('M j', $last_visit_ts);

          if ($days_absent > 60) {
            $color = 'danger';
          } elseif ($days_absent > 30) {
            $color = 'warning';
          } else {
            $color = 'success';
          }

          $output .= '<span class="text-' . $color . '">Absent: <strong>' . $days_absent . ' days</strong></span>';
          $output .= '<br><span class="small text-muted">Last visit: ' . $last_visit_date . '</span>';
          $output .= '<br><span class="small">Visits (30d): ' . $visit_count_30d . '</span>';
        } else {
          $output .= '<span class="text-muted">No recent visits</span>';
        }
        break;

      case 'recovery':
        // Show payment issue urgency
        // TODO: Add payment_failed_date when available
        $output .= '<span class="text-danger"><strong>⚠️ URGENT</strong></span>';
        $output .= '<br><span class="small">Contact within 24-48h</span>';
        $output .= '<br><span class="small text-muted">Payment failed</span>';
        break;

      case 'paused':
        $pause_start_date = $values->ms_member_success_snapshot_pause_start_date ?? '';
        if ($pause_start_date) {
          $pause_start_ts = strtotime($pause_start_date . ' 00:00:00');
          $pause_days = (int) floor(($now - $pause_start_ts) / 86400);
          $days_remaining = max(0, 90 - $pause_days);

          if ($pause_days >= 61) {
            $color = 'danger';
            $label = 'Ending soon';
          }
          else {
            $color = 'muted';
            $label = 'On pause';
          }

          $output .= '<span class="text-' . $color . '">' . $label . ': <strong>' . $pause_days . ' days</strong></span>';
          $output .= '<br><span class="small text-muted">~' . $days_remaining . ' days remaining</span>';
          $output .= '<br><span class="small text-muted">Since: ' . date('M j', $pause_start_ts) . '</span>';
        }
        else {
          $output .= '<span class="text-muted">Payment paused</span>';
        }
        break;
    }

    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

}
