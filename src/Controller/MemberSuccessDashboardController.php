<?php

namespace Drupal\makerspace_member_success\Controller;

use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;
use Drupal\makerspace_member_success\Support\MemberSuccessBuckets;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;

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
    $today = MemberSuccessQueueRules::todayYmd();
    $resolved_statuses = MemberSuccessLifecycle::resolvedFollowupStatuses();

    // SQL fragment for visibility check:
    // (next_followup_date IS NULL OR next_followup_date <= :today)
    // AND (outreach_status IS NULL OR outreach_status NOT IN (:resolved))
    // AND (member_followup_status IS NULL OR member_followup_status NOT IN (:resolved))
    $is_visible = "(s.next_followup_date IS NULL OR s.next_followup_date <= '" . $today . "') ";
    $is_visible .= "AND (s.outreach_status IS NULL OR s.outreach_status NOT IN ('" . implode("','", $resolved_statuses) . "')) ";
    $is_visible .= "AND (s.member_followup_status IS NULL OR s.member_followup_status NOT IN ('" . implode("','", $resolved_statuses) . "'))";

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->condition('is_latest', 1);
    $query->addExpression('COUNT(uid)', 'total');
    $query->addExpression('SUM(CASE WHEN risk_score > 0 AND ' . $is_visible . ' THEN 1 ELSE 0 END)', 'at_risk');
    $query->addExpression('SUM(CASE WHEN risk_score >= 20 AND ' . $is_visible . ' THEN 1 ELSE 0 END)', 'actionable');
    $query->addExpression('SUM(CASE WHEN risk_score >= 50 AND ' . $is_visible . ' THEN 1 ELSE 0 END)', 'critical');
    $summary = $query->execute()->fetchAssoc();

    if (empty($summary['total'])) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('No member success snapshots found. Please run "drush ms-build" to generate data.'),
      ];
    }

    // 2. Fetch Stage Stats
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->condition('snapshot_type', 'daily');
    $query->condition('is_latest', 1);
    $query->fields('s', ['stage']);
    $query->addExpression('COUNT(uid)', 'count');
    $query->addExpression('SUM(CASE WHEN risk_score >= 20 AND ' . $is_visible . ' THEN 1 ELSE 0 END)', 'actionable_risk');
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
      'recovery' => ['label' => 'Recovery', 'icon' => '💸', 'desc' => 'Payment failed — needs immediate contact.'],
      'paused' => ['label' => 'Paused', 'icon' => '⏸️', 'desc' => 'Payment paused — approaching 3-month limit.'],
    ];

    // Generate HTML for Summary Cards. The four counts form a funnel:
    // Total Tracked  ⊇ At Risk (score > 0 AND visible)
    //                ⊇ Actionable (score ≥ 20 AND visible)
    //                ⊇ Critical (score ≥ 50 AND visible)
    // "Visible" = not snoozed (next_followup_date in future) and not suppressed.
    $summary_html = '<p class="text-muted small mb-2">'
      . 'Each card below narrows the previous one. <strong>Total Tracked</strong> is everyone in today\'s snapshot (including snoozed and suppressed). '
      . '<strong>Actionable</strong> is the subset that needs contact today — suppressed and snoozed members are excluded, so Actionable can be <em>0</em> even when Total is positive.'
      . '</p>';
    $summary_html .= '<div class="ms-summary-grid">';
    $summary_html .= $this->renderSummaryCard(
      'Total Tracked',
      $summary['total'],
      'ms-total',
      $this->safeRouteUrl('view.member_success_queue.lifecycle'),
      'All members in today\'s daily snapshot, regardless of risk score or suppression. Includes snoozed and suppressed members.'
    );
    $summary_html .= $this->renderSummaryCard(
      'At Risk (>0)',
      $summary['at_risk'],
      'ms-risk',
      $this->safeRouteUrl('view.member_success_queue.lifecycle', ['risk_score' => 5]),
      'Members with any risk score above 0 who are not currently snoozed or suppressed.'
    );
    $summary_html .= $this->renderSummaryCard(
      'Actionable (20+)',
      $summary['actionable'],
      'ms-actionable',
      $this->safeRouteUrl('view.member_success_queue.lifecycle'),
      'Members with risk score ≥ 20 who are ready for outreach today (not snoozed, not suppressed). This is your work queue.'
    );
    $summary_html .= $this->renderSummaryCard(
      'Critical (50+)',
      $summary['critical'],
      'ms-critical',
      $this->safeRouteUrl('view.member_success_queue.lifecycle', ['risk_score' => 1]),
      'Highest-urgency subset: risk score ≥ 50, ready for outreach today. Payment-failed members land here.'
    );
    $summary_html .= '</div>';

    // Add prominent link to Intervention Performance dashboard.
    $performance_url = $this->safeRouteUrl('makerspace_member_success.intervention_performance');
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

    $needs_review_count = (int) $this->database->select('ms_member_success_snapshot', 's')
      ->condition('snapshot_type', 'daily')
      ->condition('is_latest', 1)
      ->condition('member_followup_status', 'needs_review')
      ->countQuery()
      ->execute()
      ->fetchField();
    $needs_review_url = $this->safeRouteUrl('makerspace_member_success.needs_review_queue');
    $summary_html .= '
      <div class="alert alert-warning mt-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <strong>Needs Review Queue</strong>
            <p class="mb-0 small">' . $needs_review_count . ' members are saved for later review and hidden from the main action queues.</p>
          </div>
          <div class="d-flex gap-2">
            <a href="' . $needs_review_url . '" class="btn btn-warning">Open Needs Review Queue →</a>
          </div>
        </div>
      </div>
    ';

    $suppressed_statuses = MemberSuccessLifecycle::resolvedFollowupStatuses();
    $suppressed_count = (int) $this->database->select('ms_member_success_snapshot', 's')
      ->condition('snapshot_type', 'daily')
      ->condition('is_latest', 1)
      ->condition('member_followup_status', $suppressed_statuses, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    $suppressed_url = $this->safeRouteUrl('makerspace_member_success.suppressed_queue');
    $summary_html .= '
      <div class="alert alert-secondary mt-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <strong>Suppressed Members (No More Outreach)</strong>
            <p class="mb-0 small">' . $suppressed_count . ' members are hidden from the main action queues. Grouped by reason (confirmed cancellation, outreach exhausted, no action needed, needs review) with recent contact history.</p>
          </div>
          <div class="d-flex gap-2">
            <a href="' . $suppressed_url . '" class="btn btn-outline-secondary">Open Suppressed List →</a>
          </div>
        </div>
      </div>
    ';

    // Generate HTML for Stage Cards — 4 main stages in the grid, paused below.
    $stages_html = '<div class="ms-dashboard-grid">';
    foreach (['onboarding', 'engagement', 'retention', 'recovery'] as $key) {
      $info = $stage_defs[$key];
      $stats = $stages[$key] ?? ['total' => 0, 'risk' => 0];
      $stages_html .= $this->renderStageCard(
        $key,
        $info,
        $stats,
        $this->safeRouteUrl('view.member_success_queue.' . $key, ['bucket' => MemberSuccessBuckets::ACTIONABLE])
      );
    }
    $stages_html .= '</div>';

    // Paused stage as a wide card below the 4-stage grid.
    $paused_info = $stage_defs['paused'];
    $paused_stats = $stages['paused'] ?? ['total' => 0, 'risk' => 0];
    $paused_url = $this->safeRouteUrl('view.member_success_queue.paused');
    $paused_percent = $paused_stats['total'] > 0 ? round(($paused_stats['risk'] / $paused_stats['total']) * 100) : 0;
    $stages_html .= '
      <div class="ms-card ms-stage-card" style="margin-top:1rem;">
        <div class="ms-card-header">
          <span class="ms-stage-icon">' . $paused_info['icon'] . '</span>
          <h5 class="ms-stage-title">' . $paused_info['label'] . '</h5>
        </div>
        <div class="ms-card-body" style="display:flex; align-items:center; gap:2rem; flex-wrap:wrap;">
          <div style="flex:1; min-width:200px;">
            <p class="ms-stage-desc" style="margin-bottom:0;">' . $paused_info['desc'] . ' Risk only scores when pause reaches 61+ days.</p>
          </div>
          <div style="display:flex; gap:2rem; align-items:baseline; white-space:nowrap;">
            <div style="text-align:center;" title="All paused members (includes snoozed and suppressed).">
              <div class="ms-stat-value">' . $paused_stats['total'] . '</div>
              <div class="ms-stat-label">In Stage</div>
            </div>
            <div style="text-align:center;" title="Paused members ready to contact today (risk ≥ 20, not snoozed, not suppressed).">
              <div class="ms-risk-count">' . $paused_stats['risk'] . '</div>
              <div class="ms-stat-label">Actionable today</div>
            </div>
          </div>
          <a href="' . $paused_url . '" class="ms-action-btn" style="white-space:nowrap; position:relative;">Review Paused Queue &rarr;</a>
        </div>
      </div>
    ';

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
  private function renderSummaryCard($title, $number, $modifier_class, $url = '#', $tooltip = '') {
    $tooltip_attr = $tooltip !== ''
      ? ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"'
      : '';
    $help_icon = $tooltip !== ''
      ? ' <span class="ms-summary-help" aria-hidden="true" style="cursor:help;opacity:.6;font-size:.85em;">ⓘ</span>'
      : '';
    return '
      <div class="ms-card ms-summary-card ' . $modifier_class . '"' . $tooltip_attr . '>
        <h6 class="ms-summary-label">' . $title . $help_icon . '</h6>
        <p class="ms-summary-number">' . $number . '</p>
        <a href="' . $url . '" class="stretched-link"></a>
      </div>';
  }

  /**
   * Renders HTML for a stage card.
   */
  private function renderStageCard($stage_id, $info, $stats, $queue_url) {
    $percent_risk = $stats['total'] > 0 ? round(($stats['risk'] / $stats['total']) * 100) : 0;
    $total_tip = 'Members currently in this stage (includes snoozed and suppressed).';
    $actionable_tip = 'Subset with risk score ≥ 20 who are ready to contact today (not snoozed, not suppressed).';

    return '
      <div class="ms-card ms-stage-card">
        <div class="ms-card-header">
           <span class="ms-stage-icon">' . $info['icon'] . '</span>
           <h5 class="ms-stage-title">' . $info['label'] . '</h5>
        </div>

        <div class="ms-card-body">
          <p class="ms-stage-desc">' . $info['desc'] . '</p>

          <div class="ms-stat-row" title="' . htmlspecialchars($total_tip, ENT_QUOTES, 'UTF-8') . '">
            <span class="ms-stat-value">' . $stats['total'] . '</span>
            <span class="ms-stat-label">In Stage</span>
          </div>

          <div class="ms-risk-container" title="' . htmlspecialchars($actionable_tip, ENT_QUOTES, 'UTF-8') . '">
             <div class="ms-risk-header">
                <span class="ms-risk-count">' . $stats['risk'] . '</span>
                <span class="ms-risk-badge">Actionable today</span>
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
    $request = \Drupal::request();
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');

    // Default to last 90 days when no date range is provided.
    $using_default = FALSE;
    if (empty($start_date) && empty($end_date)) {
      $using_default = TRUE;
      $start_date = date('Y-m-d', strtotime('-90 days'));
      $end_date = date('Y-m-d');
    }

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);
    $retention_value = $this->recoveryMetrics->getRetentionValue($start_date, $end_date);
    $monthly_trends = $this->recoveryMetrics->getMonthlyTrends(6);
    $all_metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);

    $build = [
      '#prefix' => '<div class="ms-performance-dashboard">',
      '#suffix' => '</div>',
    ];

    // Page title.
    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Intervention Performance & Recovery Metrics'),
      '#attributes' => ['class' => ['mb-4']],
    ];

    // Date filter and export buttons.
    // Markup::create() is required because #markup strips <input>/<form> tags.
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'mb-4', 'p-3']],
      'form' => [
        '#markup' => Markup::create($this->buildFilterForm($start_date, $end_date)),
      ],
    ];

    // Overview description.
    $build['overview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-info', 'mb-4']],
      'content' => [
        '#markup' => $this->t('<strong>How metrics are calculated:</strong><ul class="mb-0 mt-2">
          <li><strong>Members Contacted:</strong> Distinct members with at least one outreach contact logged in this date range</li>
          <li><strong>Annual Value Saved:</strong> Sum of monthly payments × 12 for members retained after outreach</li>
          <li><strong>Resolution Rate:</strong> % of contacted members with a positive case-closing outcome — <em>payment updated</em>, <em>will return</em>, or <em>no action needed</em>. <em>Confirmed cancellation</em> is case-closing but counted separately as a loss.</li>
          <li><strong>Avg Days to Resolution:</strong> Average time from first contact to the resolved outcome across resolved members</li>
          <li><strong>Channel Success Rate:</strong> Resolution rate grouped by contact method (phone, email, sms, in-person, other, system). <em>System</em> rows are auto-written when a recovery member quietly pays via Chargebee — these are now back-attributed to the staff member whose recent outreach drove the recovery, so they no longer all collapse into a 100%/system row.</li>
          </ul>'),
      ],
    ];

    // ROI Summary Section.
    $build['roi_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mb-4']],
    ];

    $build['roi_section']['total_at_risk'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Members Contacted',
        $retention_value['total_members_at_risk'],
        'primary',
        'Distinct members with outreach logged'
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

    // Staff Performance Table.
    $build['staff_table'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mb-4']],
    ];

    $build['staff_table']['header'] = [
      '#markup' => '<h3 class="mb-3">Performance by Person</h3>',
    ];

    $build['staff_table']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Shows individual effectiveness for anyone who has logged an intervention. "Resolved" counts members reached with a positive case-closing outcome (payment updated, will return, no action needed). "Confirmed Cancel" is shown separately — case-closing but lost. Sorted by number of resolutions (highest first).'),
      '#attributes' => ['class' => ['text-muted', 'small', 'mb-2']],
    ];

    $rows = [];
    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['confirmed_cancel'] ?? 0,
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
        $this->t('Confirmed Cancel'),
        $this->t('Resolution Rate'),
        $this->t('Avg Days to Resolve'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No outreach data available yet.'),
      '#attributes' => ['class' => ['table', 'table-striped']],
    ];

    // Channel Effectiveness Table.
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
      '#value' => $this->t('Compares resolution rates by contact method. "Resolved" = payment updated, will return, or no action needed. "Confirmed Cancel" is case-closing but lost. Use this to optimize outreach strategy. The <em>system</em> channel reflects automated Chargebee payment-recovery events; rows are back-attributed to the staff member whose recent outreach drove the recovery (within 30 days).'),
      '#attributes' => ['class' => ['text-muted', 'small', 'mb-2']],
    ];

    $channel_rows = [];
    foreach ($all_metrics['channel_effectiveness'] as $method => $stats) {
      $channel_rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['confirmed_cancel'] ?? 0,
        $stats['rate'] . '%',
      ];
    }

    $build['channel_table']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Contact Method'),
        $this->t('Members Contacted'),
        $this->t('Resolved'),
        $this->t('Confirmed Cancel'),
        $this->t('Success Rate'),
      ],
      '#rows' => $channel_rows,
      '#empty' => $this->t('No channel data available yet.'),
      '#attributes' => ['class' => ['table', 'table-striped']],
    ];

    // Monthly Trends Table.
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

    // Data Sources & Methodology.
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
          <dd>For each member with a resolved outcome (payment_updated, will_return, or no_action_needed), we retrieve their monthly payment amount and multiply by 12. These annual values are summed across resolved members.</dd>

          <dt>Resolution Rate</dt>
          <dd>(Members with positive case-closing outcomes ÷ Total members contacted) × 100. Successful outcomes = <code>payment_updated</code>, <code>will_return</code>, <code>no_action_needed</code>. <code>confirmed_cancel</code> is case-closing but lost — tracked in its own column and excluded from the resolved count.</dd>

          <dt>Avg Days to Resolution</dt>
          <dd>For each retained member, calculate days from their first contact to their successful retention contact. Average these values across retained members.</dd>

          <dt>Performance by Person</dt>
          <dd>
            <ul>
              <li><strong>Members Contacted:</strong> Distinct count of member UIDs contacted by this person</li>
              <li><strong>Total Attempts:</strong> Count of all contact records logged by this person</li>
              <li><strong>Resolved:</strong> Count of distinct members this person successfully resolved</li>
              <li><strong>Resolution Rate:</strong> (Resolved ÷ Members Contacted) × 100</li>
              <li><strong>Avg Days to Resolve:</strong> Average time from first to last contact for members this person resolved</li>
            </ul>
          </dd>

          <dt>Channel Success Rate</dt>
          <dd>Same as resolution rate, but grouped by contact_method field (phone, email, in-person, etc.).</dd>

          <dt>Monthly Trends</dt>
          <dd>All metrics recalculated for each calendar month based on contact_date field. Shows performance changes over time.</dd>
        </dl>

        <h5>What Counts as "Resolved"?</h5>
        <p>A member is considered resolved when at least one of their outreach log rows has one of these outcomes:</p>
        <ul>
          <li><strong>payment_updated:</strong> Member fixed their payment (also auto-recorded by the daily snapshot when a recovery member quietly pays via Chargebee; the auto row is back-attributed to the staff member with the most recent human outreach within 30 days, or recorded as <em>system</em>/unattributed if none exists)</li>
          <li><strong>will_return:</strong> Member committed to returning</li>
          <li><strong>no_action_needed:</strong> Outreach revealed nothing to fix</li>
        </ul>
        <p><strong>confirmed_cancel</strong> is case-closing but lost — it is surfaced in its own column and not counted toward the resolved/success rate.</p>
        <p>Holding outcomes (no_answer, left_message, email_sent, sms_sent, email_bounced, invalid_contact, needs_time) do NOT count as resolved — the case is still open or pending.</p>

        <h5>Paused Members</h5>
        <p>Members with a payment pause are tracked in the <strong>Paused</strong> stage. They receive a risk score only when their pause reaches 61+ days (approaching Chargebee\'s 90-day limit). Paused members are <em>not</em> included in resolution-rate calculations — their outreach goal is re-engagement/pause-extension, not payment recovery.</p>

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

    // Map color names to Bootstrap variants.
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
    $start_val = htmlspecialchars((string) ($start_date ?? ''), ENT_QUOTES, 'UTF-8');
    $end_val = htmlspecialchars((string) ($end_date ?? ''), ENT_QUOTES, 'UTF-8');

    // Quick-select preset links.
    $presets = [
      'Last 30 days'  => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
      'Last 90 days'  => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
      'Last 6 months' => [date('Y-m-d', strtotime('-6 months')), date('Y-m-d')],
      'This year'     => [date('Y-01-01'), date('Y-m-d')],
    ];
    $preset_html = '<div class="mb-2">';
    foreach ($presets as $label => [$ps, $pe]) {
      $active = ($start_val === $ps && $end_val === $pe) ? ' btn-secondary' : ' btn-outline-secondary';
      $preset_html .= '<a href="' . $current_path . '?start_date=' . $ps . '&end_date=' . $pe . '" class="btn btn-sm' . $active . ' me-1">' . $label . '</a>';
    }
    $preset_html .= '</div>';

    $html = '
      <h5 class="mb-2">Filter by Date Range</h5>
      ' . $preset_html . '
      <form method="get" action="' . $current_path . '" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Start Date</label>
          <input type="date" name="start_date" class="form-control" value="' . $start_val . '">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">End Date</label>
          <input type="date" name="end_date" class="form-control" value="' . $end_val . '">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary">Apply</button>
          <a href="' . $current_path . '" class="btn btn-secondary">Reset</a>
        </div>
        <div class="col-md-4 text-end">
          <span class="me-2 text-muted small fw-bold">Export:</span>
          <a href="' . Url::fromRoute('makerspace_member_success.export_staff_performance', [], ['query' => ['start_date' => $start_val, 'end_date' => $end_val]])->toString() . '" class="btn btn-outline-success btn-sm me-1" title="Per-person breakdown: contacts made, resolved, resolution rate">📥 By Person</a>
          <a href="' . Url::fromRoute('makerspace_member_success.export_all', [], ['query' => ['start_date' => $start_val, 'end_date' => $end_val]])->toString() . '" class="btn btn-success btn-sm" title="Every outreach contact log row: member, staff, channel, outcome, date">📊 Full Contact Log</a>
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
    $rows[] = ['Staff Member', 'Members Contacted', 'Total Attempts', 'Resolved', 'Confirmed Cancel', 'Resolution Rate (%)', 'Avg Days to Resolution'];

    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['confirmed_cancel'] ?? 0,
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
    $rows[] = ['Contact Method', 'Members Contacted', 'Resolved', 'Confirmed Cancel', 'Success Rate (%)'];

    foreach ($channel_data as $method => $stats) {
      $rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['confirmed_cancel'] ?? 0,
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

    // ROI Summary.
    $rows[] = ['ROI SUMMARY'];
    $rows[] = ['Metric', 'Value'];
    $rows[] = ['Members at Risk', $retention_value['total_members_at_risk']];
    $rows[] = ['Annual Value Saved', '$' . number_format($retention_value['annual_value_saved'], 0)];
    $rows[] = ['Resolution Rate', $metrics['resolution_rate']['rate'] . '%'];
    $rows[] = ['Avg Days to Resolution', round($metrics['avg_days_to_resolution'], 1)];
    $rows[] = [];

    // Staff Performance.
    $rows[] = ['STAFF PERFORMANCE'];
    $rows[] = ['Staff Member', 'Members Contacted', 'Total Attempts', 'Resolved', 'Confirmed Cancel', 'Resolution Rate (%)', 'Avg Days'];

    foreach ($staff_performance as $staff) {
      $rows[] = [
        $staff['staff_name'],
        $staff['members_contacted'],
        $staff['total_attempts'],
        $staff['resolved'],
        $staff['confirmed_cancel'] ?? 0,
        $staff['resolution_rate'],
        $staff['avg_days_to_resolution'],
      ];
    }

    $rows[] = [];

    // Channel Effectiveness.
    $rows[] = ['CHANNEL EFFECTIVENESS'];
    $rows[] = ['Contact Method', 'Members Contacted', 'Resolved', 'Confirmed Cancel', 'Success Rate (%)'];

    foreach ($metrics['channel_effectiveness'] as $method => $stats) {
      $rows[] = [
        ucfirst($method),
        $stats['total'],
        $stats['resolved'],
        $stats['confirmed_cancel'] ?? 0,
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

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
