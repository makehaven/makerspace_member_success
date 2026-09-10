<?php

namespace Drupal\makerspace_member_success\Controller;

use Drupal\makerspace_member_success\Support\ReportRange;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
    $query->addExpression('MIN(snapshot_date)', 'oldest');
    $query->addExpression('MAX(snapshot_date)', 'newest');
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
    $freshness = $this->t('<p><strong>Snapshot dates:</strong> @oldest to @newest. @notice</p>', [
      '@oldest' => $summary['oldest'],
      '@newest' => $summary['newest'],
      '@notice' => $summary['oldest'] < $today ? 'Some data predates today; check refresh before acting on changed membership or payment status.' : 'Latest daily snapshots are dated today.',
    ]);
    $summary_html = $freshness . '<p class="text-muted small mb-2">'
      . 'Each card below narrows the previous one. <strong>Total Tracked</strong> is everyone in the latest stored snapshot (including snoozed and suppressed). '
      . '<strong>Actionable</strong> is the subset that needs contact today — suppressed and snoozed members are excluded, so Actionable can be <em>0</em> even when Total is positive.'
      . '</p>';
    $summary_html .= '<div class="ms-summary-grid">';
    $summary_html .= $this->renderSummaryCard(
      'Total Tracked',
      $summary['total'],
      'ms-total',
      $this->safeRouteUrl('view.member_success_queue.lifecycle'),
      'All members in the latest stored daily snapshot, regardless of risk score or suppression. Includes snoozed and suppressed members.'
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
            <p class="mb-0 small">Review logged outcomes, contact timing, and outreach activity</p>
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
      '#cache' => ['max-age' => 0],
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
    [$start_date, $end_date] = $this->reportRange();

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);
    $monthly_trends = $this->recoveryMetrics->getMonthlyTrends(6, $start_date, $end_date);
    $all_metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);
    $resolution_details = $this->recoveryMetrics->getResolutionDetails($start_date, $end_date);

    $build = [
      '#cache' => ['max-age' => 0],
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
          <li><strong>Logged Confirmed Cancellations:</strong> Distinct contacted members with a cancellation outcome recorded in this range. Outcomes do not establish revenue saved or lost.</li>
          <li><strong>Resolution Rate:</strong> % of contacted members with a positive case-closing outcome — <em>payment updated</em>, <em>will return</em>, or <em>no action needed</em>. <em>Confirmed cancellation</em> is case-closing but counted separately as a loss. The individual members behind both counts are listed in the "Who is behind these numbers?" section below.</li>
          <li><strong>Confirmed Cancel:</strong> logged when staff record a cancellation via Log Contact, and (since 2026-08) automatically when a member\'s subscription ends while they are in payment recovery — with credit going to the staff member who last reached out within 30 days. Cancellations handled directly in Chargebee before this automation never reached this log, so older date ranges under-count cancels.</li>
          <li><strong>Avg Days to Resolution:</strong> Days from first contact in this range to first positive outcome in this range; not a reconstruction of separate recovery episodes</li>
          <li><strong>Channel Success Rate:</strong> Resolution rate grouped by contact method (phone, email, sms, in-person, other, system). <em>System</em> rows are auto-written when a recovery member quietly pays via Chargebee — these are now back-attributed to the staff member with recent outreach under the attribution rule; this does not establish causation, so they no longer all collapse into a 100%/system row.</li>
          </ul>'),
      ],
    ];

    // Outcome Summary Section.
    $build['roi_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mb-4']],
    ];

    $build['roi_section']['total_at_risk'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Members Contacted',
        $all_metrics['resolution_rate']['total'],
        'primary',
        'Distinct members with outreach logged'
      ),
    ];

    $build['roi_section']['value_saved'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Logged Confirmed Cancellations',
        $all_metrics['resolution_rate']['confirmed_cancel'],
        'secondary',
        'Recorded outcomes, not inferred revenue lost'
      ),
    ];

    $build['roi_section']['resolution_rate'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-3']],
      '#markup' => $this->renderMetricCard(
        'Resolution Rate',
        $all_metrics['resolution_rate']['rate'] . '%',
        'info',
        $all_metrics['resolution_rate']['resolved'] . ' of ' . $all_metrics['resolution_rate']['total'] . ' contacted members resolved — names listed below'
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

    // Drill-down: the individual members behind the resolution numbers.
    $build['resolution_detail'] = $this->buildResolutionDetailSection($resolution_details);

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
      '#value' => $this->t('Compares resolution rates by contact method. "Resolved" = payment updated, will return, or no action needed. "Confirmed Cancel" is case-closing but lost. Use this to optimize outreach strategy. The <em>system</em> channel reflects automated Chargebee payment-recovery events; rows are associated with the most recent staff contact within 30 days. This attribution does not establish that outreach caused recovery.'),
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
      '#value' => $this->t('Track performance over time. Look for improving or declining resolution rates. "Avg Attempts per Member" shows how many contacts were logged per member in the selected period.'),
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

          <dt>Logged Confirmed Cancellations</dt>
          <dd>Distinct members with a confirmed-cancellation outcome in the selected range. Raw profile dues are not reported as savings: billing periods and causal impact are not verified.</dd>

          <dt>Resolution Rate</dt>
          <dd>(Members with positive case-closing outcomes ÷ Total members contacted) × 100. Successful outcomes = <code>payment_updated</code>, <code>will_return</code>, <code>no_action_needed</code>. <code>confirmed_cancel</code> is case-closing but lost — tracked in its own column and excluded from the resolved count.</dd>

          <dt>Avg Days to Resolution</dt>
          <dd>Days from the first contact in the selected range to the first positive outcome in that range. The staff table applies the same rule per staff/member pair. These are range-bounded observations, not reconstructed membership or payment episodes.</dd>

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
        <p><strong>confirmed_cancel</strong> is case-closing but lost — it is surfaced in its own column and not counted toward the resolved/success rate. It is recorded two ways: staff logging a cancellation conversation via Log Contact, and (since 2026-08) an automatic row written by the daily snapshot when a member\'s membership ends while they are in payment recovery — the mirror image of the automatic payment-updated row, using the same 30-day staff back-attribution. Before that automation, cancellations completed inside Chargebee never produced a log row here, which is why ranges between 2026-04-20 and the automation\'s deploy showed zero confirmed cancels until the <code>ms:backfill-cancellations</code> repair ran.</p>
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
   * Builds the drill-down section listing members behind the counts.
   *
   * @param array{resolved: array, cancelled: array} $details
   *   Per-member rows from RecoveryMetrics::getResolutionDetails().
   */
  private function buildResolutionDetailSection(array $details): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('👥 Who is behind these numbers? (@resolved resolved, @cancelled confirmed cancellations)', [
        '@resolved' => count($details['resolved']),
        '@cancelled' => count($details['cancelled']),
      ]),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mb-4']],
    ];

    $section['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Each member counted in the Resolution Rate card, with the first case-closing contact in this date range. "Credited to" is the staff member on that log row; automatic payment recoveries are credited to the staff member whose outreach preceded them by up to 30 days, or shown as self-recovery when nobody had reached out.'),
      '#attributes' => ['class' => ['text-muted', 'small']],
    ];

    $lists = [
      'resolved' => $this->t('Resolved members'),
      'cancelled' => $this->t('Confirmed cancellations'),
    ];
    foreach ($lists as $key => $title) {
      $rows = [];
      foreach ($details[$key] as $detail) {
        $member_cell = $detail['member_name'] ?: ('uid ' . $detail['uid']);
        if (!empty($detail['uid'])) {
          $member_cell = [
            'data' => [
              '#type' => 'link',
              '#title' => $member_cell,
              '#url' => Url::fromRoute('entity.user.canonical', ['user' => $detail['uid']]),
            ],
          ];
        }
        $rows[] = [
          $member_cell,
          $detail['contact_date'],
          $this->outcomeLabel((string) $detail['outcome']),
          $detail['contact_method'],
          $this->staffAttributionLabel($detail),
        ];
      }

      $section[$key . '_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h5',
        '#value' => $title,
        '#attributes' => ['class' => ['mt-3']],
      ];
      $section[$key . '_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Member'),
          $this->t('Date'),
          $this->t('Outcome'),
          $this->t('Channel'),
          $this->t('Credited to'),
        ],
        '#rows' => $rows,
        '#empty' => $key === 'cancelled'
          ? $this->t('No confirmed cancellations were logged in this date range. Note: before the automatic cancellation detection shipped, cancellations handled directly in Chargebee never reached this log.')
          : $this->t('No members were resolved in this date range.'),
        '#attributes' => ['class' => ['table', 'table-sm', 'table-striped']],
      ];
    }

    return $section;
  }

  /**
   * Human label for an outreach outcome machine name.
   */
  private function outcomeLabel(string $outcome): string {
    $labels = [
      'payment_updated' => 'Payment updated',
      'will_return' => 'Will return',
      'no_action_needed' => 'No action needed',
      'confirmed_cancel' => 'Confirmed cancellation',
    ];
    return $labels[$outcome] ?? $outcome;
  }

  /**
   * Describes who gets credit for a case-closing log row.
   */
  private function staffAttributionLabel(array $detail): string {
    $staff_name = trim((string) ($detail['staff_name'] ?? ''));
    if ($staff_name !== '') {
      return $staff_name;
    }
    if ($detail['staff_uid'] === NULL) {
      return 'Self-recovery (no staff outreach in prior 30 days)';
    }
    return ((int) $detail['staff_uid'] === 0)
      ? 'Automated nudge (cron)'
      : 'Unknown (uid ' . $detail['staff_uid'] . ')';
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
    $summary_url = Url::fromRoute('makerspace_member_success.export_summary', [], [
      'query' => ['start_date' => $start_val, 'end_date' => $end_val],
    ])->toString();

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
          <a href="' . $summary_url . '" class="btn btn-outline-success btn-sm me-1" title="The summary tables on this page (outcomes, per-person, per-channel) as one CSV">📄 Summary</a>
          <a href="' . Url::fromRoute('makerspace_member_success.export_all', [], ['query' => ['start_date' => $start_val, 'end_date' => $end_val]])->toString() . '" class="btn btn-success btn-sm" title="Every outreach contact log row: member, staff, channel, outcome, date, notes">📊 Full Contact Log</a>
        </div>
      </form>
    ';

    return $html;
  }

  /**
   * Export staff performance data as CSV.
   */
  public function exportStaffPerformance() {
    [$start_date, $end_date] = $this->reportRange();

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
    [$start_date, $end_date] = $this->reportRange();

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
    [$start_date, $end_date] = $this->reportRange();
    $trends = $this->recoveryMetrics->getMonthlyTrends(6, $start_date, $end_date);

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
   * Export the row-level contact log as CSV.
   *
   * This is the "Full Contact Log" download: one row per logged contact, not
   * a summary. Staff asked for the underlying transactions (Kate, 2026-08-20)
   * and the button already promised them.
   */
  public function exportAll() {
    [$start_date, $end_date] = $this->reportRange();

    $log_rows = $this->recoveryMetrics->getContactLogRows($start_date, $end_date);

    $rows = [];
    $rows[] = ['Contact Date', 'Member', 'Member UID', 'Logged By', 'Channel', 'Outcome', 'Notes'];

    foreach ($log_rows as $log) {
      $staff = trim((string) ($log['staff_name'] ?? ''));
      if ($staff === '') {
        if ($log['staff_uid'] === NULL) {
          $staff = 'System (automatic)';
        }
        else {
          $staff = ((int) $log['staff_uid'] === 0) ? 'Automated nudge (cron)' : 'Unknown (uid ' . $log['staff_uid'] . ')';
        }
      }

      // Notes arrive with HTML entities and literal \n sequences from the
      // CiviCRM backfill; flatten them so the CSV cell reads cleanly.
      $notes = (string) ($log['notes'] ?? '');
      $notes = str_replace('\n', "\n", $notes);
      $notes = trim(html_entity_decode(strip_tags($notes), ENT_QUOTES | ENT_HTML5));

      $rows[] = [
        $log['contact_date'],
        $log['member_name'] ?? ('uid ' . $log['uid']),
        $log['uid'],
        $staff,
        $log['contact_method'],
        $log['outcome'],
        $notes,
      ];
    }

    return $this->generateCsvResponse($rows, 'contact-log');
  }

  /**
   * Export the dashboard's summary tables as CSV.
   */
  public function exportSummary() {
    [$start_date, $end_date] = $this->reportRange();

    $staff_performance = $this->recoveryMetrics->getStaffPerformance($start_date, $end_date);
    $metrics = $this->recoveryMetrics->getAllMetrics($start_date, $end_date);

    $rows = [];

    // Outcome Summary.
    $rows[] = ['OUTCOME SUMMARY'];
    $rows[] = ['Date range', $start_date . ' to ' . $end_date];
    $rows[] = ['Metric', 'Value'];
    $rows[] = ['Members Contacted', $metrics['resolution_rate']['total']];
    $rows[] = ['Logged Confirmed Cancellations', $metrics['resolution_rate']['confirmed_cancel']];
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

    return $this->generateCsvResponse($rows, 'intervention-performance-summary');
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

  /**
   * Keeps page and export defaults identical and rejects malformed ranges.
   */
  private function reportRange(): array {
    try {
      return ReportRange::resolve(
        \Drupal::request()->query->get('start_date'),
        \Drupal::request()->query->get('end_date'), date('Y-m-d')
      );
    }
    catch (\InvalidArgumentException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
  }

}
