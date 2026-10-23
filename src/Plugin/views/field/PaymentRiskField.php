<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\PauseReason;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display payment risk badges.
 *
 * For a paused member this is the only place the queue says anything about
 * the pause (the action-link column is not on the lists that show paused
 * members), so it also carries when the pause ends and, when the member
 * answered the pause-reason question, why they paused.
 *
 * @ViewsField("payment_risk_field")
 */
class PaymentRiskField extends FieldPluginBase {

  /**
   * Database connection, for the pause details of paused rows.
   */
  protected ?Connection $database = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    return $instance;
  }

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
      $uid = (int) ($values->ms_member_success_snapshot_uid ?? $values->users_field_data_ms_member_success_snapshot_uid ?? 0);
      foreach ($this->pauseDetails($uid) as $detail) {
        $badges[] = $detail;
      }
    }

    // Nothing to flag: an empty cell, as the queue's custom-text column it
    // replaced showed, rather than an "OK" badge on hundreds of rows.
    if (empty($badges)) {
      return [];
    }

    return [
      '#type' => 'markup',
      '#markup' => implode(' ', $badges),
    ];
  }

  /**
   * Resume-date and pause-reason badges for a paused member.
   *
   * For example "Resumes Nov 3" and "Why: Travelling or away".
   *
   * @return string[]
   *   Badge markup, possibly empty.
   */
  protected function pauseDetails(int $uid): array {
    if ($uid <= 0 || $this->database === NULL) {
      return [];
    }
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->fields('ms', ['pause_resume_date']);
    $query->condition('ms.uid', $uid);
    $query->condition('ms.is_latest', 1);
    $query->condition('ms.snapshot_type', 'daily');
    if ($this->database->schema()->tableExists('ms_pause_reason')) {
      $query->leftJoin('ms_pause_reason', 'pr', 'pr.uid = ms.uid AND pr.pause_start_date = ms.pause_start_date');
      $query->addField('pr', 'reason', 'pause_reason');
    }
    $row = $query->range(0, 1)->execute()->fetchAssoc() ?: [];

    $details = [];
    $resume_ts = !empty($row['pause_resume_date']) ? strtotime($row['pause_resume_date'] . ' 00:00:00') : FALSE;
    if ($resume_ts) {
      $details[] = '<span class="badge bg-light text-dark">Resumes ' . date('M j', $resume_ts) . '</span>';
    }
    $label = PauseReason::label((string) ($row['pause_reason'] ?? ''));
    if ($label !== '') {
      $details[] = '<span class="badge bg-info text-dark">Why: ' . Html::escape($label) . '</span>';
    }
    return $details;
  }

}
