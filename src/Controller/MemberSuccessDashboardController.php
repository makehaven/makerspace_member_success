<?php

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for the Member Success Dashboard.
 */
class MemberSuccessDashboardController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new MemberSuccessDashboardController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Builds the dashboard.
   */
  public function build() {
    // 1. Fetch Summary Stats
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->addExpression('COUNT(uid)', 'total');
    $query->addExpression('SUM(CASE WHEN risk_score > 0 THEN 1 ELSE 0 END)', 'at_risk');
    $query->addExpression('SUM(CASE WHEN risk_score >= 50 THEN 1 ELSE 0 END)', 'critical');
    $summary = $query->execute()->fetchAssoc();

    // 2. Fetch Stage Stats
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->fields('s', ['stage']);
    $query->addExpression('COUNT(uid)', 'count');
    $query->addExpression('SUM(CASE WHEN risk_score >= 20 THEN 1 ELSE 0 END)', 'actionable_risk');
    $query->groupBy('s.stage');
    $results = $query->execute()->fetchAll();

    $stages = [];
    foreach ($results as $row) {
      $stages[$row->stage] = [
        'total' => $row->count,
        'risk' => $row->actionable_risk,
      ];
    }

    $stage_defs = [
      'onboarding' => ['label' => 'Onboarding', 'icon' => '🏁', 'desc' => 'New joins needing access.'],
      'engagement' => ['label' => 'Engagement', 'icon' => '🚀', 'desc' => 'First 6 months activity.'],
      'retention' => ['label' => 'Retention', 'icon' => '❤️', 'desc' => 'Sustaining members.'],
      'recovery' => ['label' => 'Recovery', 'icon' => '💸', 'desc' => 'Payment/pause issues.'],
    ];

    // Generate HTML for Summary Cards
    // Link Critical (50+) to risk_score=1
    // Link At Risk (>0) to risk_score=5
    $summary_html = '<div class="ms-summary-grid">';
    $summary_html .= $this->renderSummaryCard('Total Members', $summary['total'], 'ms-total', '/admin/makerspace/member-success/lifecycle');
    $summary_html .= $this->renderSummaryCard('At Risk (>0)', $summary['at_risk'], 'ms-risk', '/admin/makerspace/member-success/lifecycle?risk_score=5');
    $summary_html .= $this->renderSummaryCard('Critical (50+)', $summary['critical'], 'ms-critical', '/admin/makerspace/member-success/lifecycle?risk_score=1');
    $summary_html .= '</div>';

    // Generate HTML for Stage Cards
    $stages_html = '<div class="ms-dashboard-grid">';
    foreach ($stage_defs as $key => $info) {
      $stats = $stages[$key] ?? ['total' => 0, 'risk' => 0];
      $stages_html .= $this->renderStageCard($key, $info, $stats);
    }
    $stages_html .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => '<div class="ms-dashboard-wrapper">' . $summary_html . '<h3 class="mb-3">Lifecycle Stages</h3>' . $stages_html . '</div>',
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
          'makerspace_member_success/dashboard',
        ],
      ],
    ];
  }

  /**
   * Renders HTML for a summary card.
   */
  private function renderSummaryCard($title, $number, $modifier_class, $url = '#') {
    return '
      <div class="ms-card ms-summary-card ' . $modifier_class . '">
        <h6 class="ms-summary-label">' . $title . '</h6>
        <p class="ms-summary-number">' . $number . '</p>
        <a href="' . $url . '" class="stretched-link"></a>
      </div>';
  }

  /**
   * Renders HTML for a stage card.
   */
  private function renderStageCard($stage_id, $info, $stats) {
    $percent_risk = $stats['total'] > 0 ? round(($stats['risk'] / $stats['total']) * 100) : 0;
    
    return '
      <div class="ms-card ms-stage-card">
        <div class="ms-card-header">
           <span class="ms-stage-icon">' . $info['icon'] . '</span>
           <h5 class="ms-stage-title">' . $info['label'] . '</h5>
        </div>
        
        <div class="ms-card-body">
          <p class="ms-stage-desc">' . $info['desc'] . '</p>
          
          <div class="ms-stat-row">
            <span class="ms-stat-value">' . $stats['total'] . '</span>
            <span class="ms-stat-label">Total</span>
          </div>
          
          <div class="ms-risk-container">
             <div class="ms-risk-header">
                <span class="ms-risk-count">' . $stats['risk'] . '</span>
                <span class="ms-risk-badge">Actionable</span>
             </div>
             <div class="ms-progress-track">
                <div class="ms-progress-fill" style="width: ' . $percent_risk . '%"></div>
             </div>
          </div>

          <a href="/admin/makerspace/member-success/' . $stage_id . '" class="ms-action-btn">Manage Queue &rarr;</a>
        </div>
      </div>';
  }

}