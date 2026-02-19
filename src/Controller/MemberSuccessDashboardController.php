<?php

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;

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
   * The recovery metrics service.
   *
   * @var \Drupal\makerspace_member_success\Service\RecoveryMetrics
   */
  protected $recoveryMetrics;

  /**
   * Constructs a new MemberSuccessDashboardController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\makerspace_member_success\Service\RecoveryMetrics $recovery_metrics
   *   The recovery metrics service.
   */
  public function __construct(Connection $database, RecoveryMetrics $recovery_metrics) {
    $this->database = $database;
    $this->recoveryMetrics = $recovery_metrics;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('makerspace_member_success.recovery_metrics')
    );
  }

  /**
   * Builds the dashboard.
   */
  public function build() {
    // 1. Fetch Summary Stats
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->condition('is_latest', 1);
    $query->addExpression('COUNT(uid)', 'total');
    $query->addExpression('SUM(CASE WHEN risk_score > 0 THEN 1 ELSE 0 END)', 'at_risk');
    $query->addExpression('SUM(CASE WHEN risk_score >= 20 THEN 1 ELSE 0 END)', 'actionable');
    $query->addExpression('SUM(CASE WHEN risk_score >= 50 THEN 1 ELSE 0 END)', 'critical');
    $summary = $query->execute()->fetchAssoc();

    if (empty($summary['total'])) {
      return [
        '#markup' => $this->t('No member success snapshots found. Please run "drush ms-build" to generate data.'),
      ];
    }

    // 2. Fetch Stage Stats
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->condition('is_latest', 1);
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
    $summary_html .= $this->renderSummaryCard(
      'Total Members',
      $summary['total'],
      'ms-total',
      $this->safeRouteUrl('view.member_success_queue.lifecycle')
    );
    $summary_html .= $this->renderSummaryCard(
      'At Risk (>0)',
      $summary['at_risk'],
      'ms-risk',
      $this->safeRouteUrl('view.member_success_queue.lifecycle', ['risk_score' => 5])
    );
    $summary_html .= $this->renderSummaryCard(
      'Actionable (20+)',
      $summary['actionable'],
      'ms-actionable',
      $this->safeRouteUrl('view.member_success_queue.lifecycle')
    );
    $summary_html .= $this->renderSummaryCard(
      'Critical (50+)',
      $summary['critical'],
      'ms-critical',
      $this->safeRouteUrl('view.member_success_queue.lifecycle', ['risk_score' => 1])
    );
    $summary_html .= '</div>';

    // Add prominent link to Intervention Performance dashboard
    $performance_url = $this->safeRouteUrl('makerspace_member_success.contractor_performance');
    $queue_review_url = $this->safeRouteUrl('makerspace_member_success.queue_review');
    $summary_html .= '
      <div class="alert alert-info mt-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <strong>📊 Intervention Performance Dashboard</strong>
            <p class="mb-0 small">View staff effectiveness, ROI calculations, and outreach metrics</p>
          </div>
          <div class="d-flex gap-2">
            <a href="' . $queue_review_url . '" class="btn btn-outline-primary">Queue Review</a>
            <a href="' . $performance_url . '" class="btn btn-primary">View Performance Stats →</a>
          </div>
        </div>
      </div>
    ';

    // Generate HTML for Stage Cards
    $stages_html = '<div class="ms-dashboard-grid">';
    foreach ($stage_defs as $key => $info) {
      $stats = $stages[$key] ?? ['total' => 0, 'risk' => 0];
      $route_name = 'view.member_success_queue.' . $key;
      $stages_html .= $this->renderStageCard($key, $info, $stats, $this->safeRouteUrl($route_name));
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
  private function renderStageCard($stage_id, $info, $stats, $queue_url) {
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

          <a href="' . $queue_url . '" class="ms-action-btn stretched-link">Manage Queue &rarr;</a>
        </div>
      </div>';
  }

  /**
   * Builds a route URL, falling back to a safe placeholder if missing.
   */
  private function safeRouteUrl(string $route_name, array $query = []): string {
    try {
      return Url::fromRoute($route_name, [], ['query' => $query])->toString();
    }
    catch (RouteNotFoundException $exception) {
      return '#';
    }
  }

  /**
   * Builds the intervention performance dashboard (staff/volunteer outreach metrics).
   */
  public function contractorPerformance() {
    // Get date range from query parameters
    $request = \Drupal::request();
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);
    $retention_value = $this->recoveryMetrics->getRetentionValue($start_date, $end_date);
    $monthly_trends = $this->recoveryMetrics->getMonthlyTrends(6);
    $all_metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);

    $build = [
      '#prefix' => '<div class="ms-performance-dashboard">',
      '#suffix' => '</div>',
    ];

    // Page title
    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Intervention Performance & Recovery Metrics'),
      '#attributes' => ['class' => ['mb-4']],
    ];

    // Date filter and export buttons
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'mb-4', 'p-3']],
      'form' => [
        '#markup' => $this->buildFilterForm($start_date, $end_date),
      ],
    ];

    // Overview description
    $build['overview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-info', 'mb-4']],
      'content' => [
        '#markup' => $this->t('<strong>How metrics are calculated:</strong><ul class="mb-0 mt-2">
          <li><strong>Annual Value Saved:</strong> Sum of monthly payments × 12 for members retained after outreach (payment updated)</li>
          <li><strong>Resolution Rate:</strong> Percentage of contacted members retained after outreach (payment updated)</li>
          <li><strong>Avg Days to Resolution:</strong> Average time from first contact to retention outcome across retained members</li>
          <li><strong>Channel Success Rate:</strong> Resolution rate grouped by contact method (phone, email, in-person)</li>
          </ul>'),
      ],
    ];

    // ROI Summary Section
    $build['roi_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mb-4']],
    ];

    $build['roi_section']['total_at_risk'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Members at Risk',
        $retention_value['total_members_at_risk'],
        'primary',
        NULL
      ),
    ];

    $build['roi_section']['value_saved'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Annual Value Saved',
        '$' . number_format($retention_value['annual_value_saved'], 0),
        'success',
        NULL
      ),
    ];

    $build['roi_section']['resolution_rate'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Resolution Rate',
        $all_metrics['resolution_rate']['rate'] . '%',
        'info',
        $all_metrics['resolution_rate']['resolved'] . ' of ' . $all_metrics['resolution_rate']['total'] . ' resolved'
      ),
    ];

    $build['roi_section']['avg_days'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Avg Days to Resolution',
        round($all_metrics['avg_days_to_resolution'], 1),
        'warning',
        NULL
      ),
    ];

    // Staff Performance Table
    $build['staff_table'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mb-4']],
    ];

    $build['staff_table']['header'] = [
      '#markup' => '
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h3 class="mb-0">Staff Performance</h3>
          <a href="' . Url::fromRoute('makerspace_member_success.export_staff_performance', [], ['query' => ['start_date' => $start_date, 'end_date' => $end_date]])->toString() . '" class="btn btn-sm btn-outline-success">📥 Export CSV</a>
        </div>
      ',
    ];

    $build['staff_table']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Shows individual staff/volunteer effectiveness. "Resolution Rate" = resolved members ÷ total members contacted. Sorted by number of resolutions (highest first).'),
      '#attributes' => ['class' => ['text-muted', 'small', 'mb-2']],
    ];

    $rows = [];
    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['resolution_rate'] . '%',
        round($staff['avg_days_to_resolution'], 1) . ' days',
      ];
    }

    $build['staff_table']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Staff Member'),
        $this->t('Members Contacted'),
        $this->t('Total Attempts'),
        $this->t('Resolved'),
        $this->t('Resolution Rate'),
        $this->t('Avg Days to Resolve'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No outreach data available yet.'),
      '#attributes' => ['class' => ['table', 'table-striped']],
    ];

    // Channel Effectiveness Table
    $build['channel_table'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mb-4']],
    ];

    $build['channel_table']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Channel Effectiveness'),
      '#attributes' => ['class' => ['mb-3']],
    ];

    $build['channel_table']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Compares resolution rates by contact method. Use this to optimize outreach strategy (e.g., if phone calls have 60% success but emails have 30%, prioritize phone outreach).'),
      '#attributes' => ['class' => ['text-muted', 'small', 'mb-2']],
    ];

    $channel_rows = [];
    foreach ($all_metrics['channel_effectiveness'] as $method => $stats) {
      $channel_rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['rate'] . '%',
      ];
    }

    $build['channel_table']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Contact Method'),
        $this->t('Members Contacted'),
        $this->t('Resolved'),
        $this->t('Success Rate'),
      ],
      '#rows' => $channel_rows,
      '#empty' => $this->t('No channel data available yet.'),
      '#attributes' => ['class' => ['table', 'table-striped']],
    ];

    // Monthly Trends Table
    $build['trends_table'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mb-4']],
    ];

    $build['trends_table']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Monthly Trends (Last 6 Months)'),
      '#attributes' => ['class' => ['mb-3']],
    ];

    $build['trends_table']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Track performance over time. Look for improving or declining resolution rates. "Avg Attempts per Member" shows how many contacts it typically takes before resolution.'),
      '#attributes' => ['class' => ['text-muted', 'small', 'mb-2']],
    ];

    $trend_rows = [];
    foreach ($monthly_trends as $trend) {
      $trend_rows[] = [
        $trend['month'],
        $trend['members_contacted'],
        $trend['resolved'],
        $trend['resolution_rate'] . '%',
        round($trend['avg_attempts_per_member'], 1),
      ];
    }

    $build['trends_table']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Month'),
        $this->t('Contacted'),
        $this->t('Resolved'),
        $this->t('Resolution Rate'),
        $this->t('Avg Attempts/Member'),
      ],
      '#rows' => $trend_rows,
      '#empty' => $this->t('No trend data available yet.'),
      '#attributes' => ['class' => ['table', 'table-striped']],
    ];

    // Data Sources & Methodology
    $build['methodology'] = [
      '#type' => 'details',
      '#title' => $this->t('📘 Data Sources & Methodology'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mt-4']],
    ];

    $build['methodology']['content'] = [
      '#markup' => $this->t('
        <h5>Data Sources</h5>
        <ul>
          <li><strong>Contact Logs:</strong> All data comes from the <code>ms_member_outreach_log</code> table, populated when staff log contacts via member success queues.</li>
          <li><strong>Member Payments:</strong> Monthly payment amounts retrieved from member profiles (<code>field_member_payment_monthly</code>).</li>
          <li><strong>Date Range:</strong> Includes all historical contact data unless filtered by date in specific queries.</li>
        </ul>

        <h5>Calculation Details</h5>
        <dl>
          <dt>Members at Risk</dt>
          <dd>Count of unique members who have been contacted for recovery/retention (distinct UIDs in outreach log).</dd>

          <dt>Annual Value Saved</dt>
          <dd>For each member with outcome = "payment_updated", we retrieve their monthly payment amount and multiply by 12. These annual values are summed across retained members.</dd>

          <dt>Resolution Rate</dt>
          <dd>(Members with successful outcomes ÷ Total members contacted) × 100. Successful outcomes = "payment_updated". Confirmed cancellations are tracked but not counted as retained.</dd>

          <dt>Avg Days to Resolution</dt>
          <dd>For each retained member, calculate days from their first contact to their successful retention contact. Average these values across retained members.</dd>

          <dt>Staff Performance Metrics</dt>
          <dd>
            <ul>
              <li><strong>Members Contacted:</strong> Distinct count of member UIDs contacted by this staff member</li>
              <li><strong>Total Attempts:</strong> Count of all contact records for this staff member</li>
              <li><strong>Resolved:</strong> Count of distinct members this staff member successfully resolved</li>
              <li><strong>Resolution Rate:</strong> (Resolved ÷ Members Contacted) × 100</li>
              <li><strong>Avg Days to Resolve:</strong> Average time from first to last contact for members this staff member resolved</li>
            </ul>
          </dd>

          <dt>Channel Success Rate</dt>
          <dd>Same as resolution rate, but grouped by contact_method field (phone, email, in-person, etc.).</dd>

          <dt>Monthly Trends</dt>
          <dd>All metrics recalculated for each calendar month based on contact_date field. Shows performance changes over time.</dd>
        </dl>

        <h5>What Counts as "Retained"?</h5>
        <p>A member is considered retained when their outcome is recorded as:</p>
        <ul>
          <li><strong>payment_updated:</strong> Member fixed their payment issue</li>
          <li><strong>confirmed_cancel:</strong> Tracked as contacted/lost, and excluded from retention success</li>
        </ul>
        <p>Other outcomes (no_response, left_message, etc.) do NOT count as retained.</p>

        <h5>Limitations</h5>
        <ul>
          <li>Data quality depends on staff consistently logging all contact attempts</li>
          <li>Monthly payment amounts may change over time; calculations use current values</li>
          <li>Members contacted before this system was implemented are not included</li>
        </ul>
      '),
    ];

    $build['#attached']['library'][] = 'makerspace_member_success/dashboard';

    return $build;
  }

  /**
   * Renders a metric card.
   */
  private function renderMetricCard(string $title, $value, string $color, $subtitle = NULL): string {
    $subtitle_html = $subtitle ? '<div class="text-muted small mt-2">' . $subtitle . '</div>' : '';

    // Map color names to Bootstrap variants
    $color_classes = [
      'primary' => 'primary',
      'success' => 'success',
      'info' => 'info',
      'warning' => 'warning',
      'danger' => 'danger',
    ];
    $variant = $color_classes[$color] ?? 'primary';

    return '
      <div class="card h-100 border-' . $variant . ' shadow-sm" style="border-width: 2px !important;">
        <div class="card-body text-center p-4" style="background-color: #f8f9fa;">
          <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem; letter-spacing: 0.5px;">' . $title . '</h6>
          <div class="display-5 fw-bold text-' . $variant . ' mb-1" style="font-size: 2.5rem;">' . $value . '</div>
          ' . $subtitle_html . '
        </div>
      </div>';
  }

  /**
   * Build filter form HTML.
   */
  private function buildFilterForm($start_date, $end_date) {
    $current_path = htmlspecialchars((string) \Drupal::request()->getPathInfo(), ENT_QUOTES, 'UTF-8');
    $start_date = htmlspecialchars((string) ($start_date ?? ''), ENT_QUOTES, 'UTF-8');
    $end_date = htmlspecialchars((string) ($end_date ?? ''), ENT_QUOTES, 'UTF-8');

    $html = '
      <form method="get" action="' . $current_path . '" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Start Date</label>
          <input type="date" name="start_date" class="form-control" value="' . $start_date . '">
        </div>
        <div class="col-md-3">
          <label class="form-label">End Date</label>
          <input type="date" name="end_date" class="form-control" value="' . $end_date . '">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary">Filter</button>
          <a href="' . $current_path . '" class="btn btn-secondary">Clear</a>
        </div>
        <div class="col-md-4 text-end">
          <strong>Export:</strong>
          <a href="' . Url::fromRoute('makerspace_member_success.export_all', [], ['query' => ['start_date' => $start_date, 'end_date' => $end_date]])->toString() . '" class="btn btn-success btn-sm">📊 All Data</a>
        </div>
      </form>
    ';

    return $html;
  }

  /**
   * Export staff performance data as CSV.
   */
  public function exportStaffPerformance() {
    $start_date = \Drupal::request()->query->get('start_date');
    $end_date = \Drupal::request()->query->get('end_date');

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);

    $rows = [];
    $rows[] = ['Staff Member', 'Members Contacted', 'Total Attempts', 'Resolved', 'Resolution Rate (%)', 'Avg Days to Resolution'];

    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['resolution_rate'],
        $staff['avg_days_to_resolution'],
      ];
    }

    return $this->generateCsvResponse($rows, 'staff-performance');
  }

  /**
   * Export channel effectiveness data as CSV.
   */
  public function exportChannelEffectiveness() {
    $start_date = \Drupal::request()->query->get('start_date');
    $end_date = \Drupal::request()->query->get('end_date');

    $metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);
    $channel_data = $metrics['channel_effectiveness'];

    $rows = [];
    $rows[] = ['Contact Method', 'Members Contacted', 'Resolved', 'Success Rate (%)'];

    foreach ($channel_data as $method => $stats) {
      $rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['rate'],
      ];
    }

    return $this->generateCsvResponse($rows, 'channel-effectiveness');
  }

  /**
   * Export monthly trends data as CSV.
   */
  public function exportMonthlyTrends() {
    $months = \Drupal::request()->query->get('months', 12);

    $trends = $this->recoveryMetrics->getMonthlyTrends((int) $months);

    $rows = [];
    $rows[] = ['Month', 'Contacted', 'Resolved', 'Resolution Rate (%)', 'Avg Attempts/Member'];

    foreach ($trends as $trend) {
      $rows[] = [
        $trend['month'],
        $trend['members_contacted'],
        $trend['resolved'],
        $trend['resolution_rate'],
        round($trend['avg_attempts_per_member'], 1),
      ];
    }

    return $this->generateCsvResponse($rows, 'monthly-trends');
  }

  /**
   * Export all intervention performance data as CSV.
   */
  public function exportAll() {
    $start_date = \Drupal::request()->query->get('start_date');
    $end_date = \Drupal::request()->query->get('end_date');

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);
    $retention_value = $this->recoveryMetrics->getRetentionValue($start_date, $end_date);
    $metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);

    $rows = [];

    // ROI Summary
    $rows[] = ['ROI SUMMARY'];
    $rows[] = ['Metric', 'Value'];
    $rows[] = ['Members at Risk', $retention_value['total_members_at_risk']];
    $rows[] = ['Annual Value Saved', '$' . number_format($retention_value['annual_value_saved'], 0)];
    $rows[] = ['Resolution Rate', $metrics['resolution_rate']['rate'] . '%'];
    $rows[] = ['Avg Days to Resolution', round($metrics['avg_days_to_resolution'], 1)];
    $rows[] = [];

    // Staff Performance
    $rows[] = ['STAFF PERFORMANCE'];
    $rows[] = ['Staff Member', 'Members Contacted', 'Total Attempts', 'Resolved', 'Resolution Rate (%)', 'Avg Days'];

    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['resolution_rate'],
        $staff['avg_days_to_resolution'],
      ];
    }

    $rows[] = [];

    // Channel Effectiveness
    $rows[] = ['CHANNEL EFFECTIVENESS'];
    $rows[] = ['Contact Method', 'Members Contacted', 'Resolved', 'Success Rate (%)'];

    foreach ($metrics['channel_effectiveness'] as $method => $stats) {
      $rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['rate'],
      ];
    }

    return $this->generateCsvResponse($rows, 'intervention-performance-full');
  }

  /**
   * Helper to generate CSV response.
   */
  private function generateCsvResponse(array $rows, string $filename) {
    $date_suffix = date('Y-m-d');
    $filename = $filename . '-' . $date_suffix . '.csv';

    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $response = new \Symfony\Component\HttpFoundation\Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
