<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\OutreachQueueServiceInterface;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for manual review and bulk actions on outreach queue items.
 */
class OutreachQueueReviewForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected Connection $database,
    protected OutreachQueueServiceInterface $queueService
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('makerspace_member_success.outreach_queue_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_outreach_queue_review';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $status_filter = $this->getRequest()->query->get('status');
    $allowed_statuses = ['queued', 'approved', 'suppressed', 'failed', 'sent', 'cancelled'];
    if (!in_array($status_filter, $allowed_statuses, TRUE)) {
      $status_filter = 'queued';
    }

    $stage_filter = (string) ($this->getRequest()->query->get('stage') ?? '');
    $allowed_stages = ['', 'onboarding', 'engagement', 'retention', 'recovery', 'paused'];
    if (!in_array($stage_filter, $allowed_stages, TRUE)) {
      $stage_filter = '';
    }

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#title_display' => 'invisible',
      '#options' => array_combine($allowed_statuses, $allowed_statuses),
      '#default_value' => $status_filter,
    ];
    $form['filters']['stage'] = [
      '#type' => 'select',
      '#title' => $this->t('Stage'),
      '#title_display' => 'invisible',
      '#options' => [
        '' => $this->t('All stages'),
        'onboarding' => $this->t('Onboarding'),
        'engagement' => $this->t('Engagement'),
        'retention' => $this->t('Retention'),
        'recovery' => $this->t('Recovery'),
        'paused' => $this->t('Paused'),
      ],
      '#default_value' => $stage_filter,
    ];
    $form['filters']['apply'] = [
      '#type' => 'submit',
      '#name' => 'apply_filter',
      '#value' => $this->t('Filter'),
      '#submit' => ['::submitFilter'],
    ];
    $form['filters']['select_all'] = [
      '#type' => 'submit',
      '#name' => 'select_all_visible',
      '#value' => $this->t('Select all visible'),
      '#submit' => ['::submitSelectAllVisible'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button', 'button--small']],
    ];
    $form['filters']['generate'] = [
      '#type' => 'submit',
      '#name' => 'generate_candidates',
      '#value' => $this->t('Generate/Refresh Queue Candidates'),
      '#submit' => ['::submitGenerateCandidates'],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $status_counts = $this->loadStatusCounts($allowed_statuses);
    $form['status_summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    foreach ($allowed_statuses as $status) {
      $query = ['status' => $status];
      if ($stage_filter !== '') {
        $query['stage'] = $stage_filter;
      }
      $form['status_summary']['status_' . $status] = [
        '#type' => 'link',
        '#title' => $this->t('@status: @count', [
          '@status' => ucfirst($status),
          '@count' => $status_counts[$status] ?? 0,
        ]),
        '#url' => Url::fromRoute('makerspace_member_success.queue_review', [], ['query' => $query]),
        '#attributes' => [
          'class' => $status === $status_filter ? ['button', 'button--primary', 'button--small'] : ['button', 'button--small'],
        ],
      ];
    }

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status">'
        . '<p><strong>' . $this->t('Manual mode') . ':</strong> '
        . $this->t('Queued items are NOT sent automatically at this time. Staff must select rows and apply actions manually.')
        . '</p>'
        . '<strong>' . $this->t('How to use this queue') . ':</strong> '
        . $this->t('1) Click "Generate/Refresh Queue Candidates" to refresh recommendations from latest snapshots. ')
        . $this->t('2) Review rows and reasons. ')
        . $this->t('3) Select rows, choose a bulk action, and apply. ')
        . $this->t('Tip: not every generated candidate lands in "queued". Many will be intentionally placed in "suppressed" based on risk threshold, cooldown, max attempts, consent, or missing templates.')
        . $this->t('SMS is only recommended when explicit CiviCRM SMS consent is present. ')
        . $this->t('Rows are suppressed when templates are missing.')
        . '</div>',
    ];

    $form['bulk'] = ['#type' => 'details', '#title' => $this->t('Bulk actions'), '#open' => TRUE];
    $form['bulk']['howto'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">'
        . '<strong>' . $this->t('Bulk action behavior') . ':</strong> '
        . $this->t('Approve selected: marks rows ready to send. ')
        . $this->t('Mark selected as sent (manual): records rows as already handled outside the queue. ')
        . $this->t('Suppress selected: hides rows from active queue work using the selected reason code.')
        . '</div>',
    ];

    $form['bulk']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => [
        'approve' => $this->t('Approve selected'),
        'mark_sent_manual' => $this->t('Mark selected as sent (manual)'),
        'suppress' => $this->t('Suppress selected'),
      ],
      '#default_value' => 'approve',
      '#required' => TRUE,
    ];
    $form['bulk']['reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Reason code (for suppress)'),
      '#options' => [
        'manual_suppressed' => $this->t('Manual review suppression'),
        'handled_offline' => $this->t('Handled offline'),
        'not_actionable' => $this->t('Not actionable'),
        'bad_contact_data' => $this->t('Bad contact data'),
        'waiting_on_context' => $this->t('Waiting on more context'),
      ],
      '#default_value' => 'manual_suppressed',
      '#states' => [
        'visible' => [
          [
            ':input[name="action"]' => ['value' => 'suppress'],
          ],
          'or',
          [
            ':input[name="bulk[action]"]' => ['value' => 'suppress'],
          ],
        ],
        'required' => [
          [
            ':input[name="action"]' => ['value' => 'suppress'],
          ],
          'or',
          [
            ':input[name="bulk[action]"]' => ['value' => 'suppress'],
          ],
        ],
      ],
    ];
    $form['bulk']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to selected'),
      '#button_type' => 'primary',
    ];

    $rows = $this->loadRows($status_filter, $stage_filter);
    $uids = [];
    $template_ids = [];
    foreach ($rows as $row) {
      $uids[] = (int) $row->uid;
      $template_id = isset($row->recommended_template_id) ? (int) $row->recommended_template_id : 0;
      if ($template_id > 0) {
        $template_ids[] = $template_id;
      }
    }
    $user_names = $this->loadUserNames(array_unique($uids));
    $template_titles = $this->loadTemplateTitles(array_unique($template_ids));

    $form['queue_items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'id' => $this->t('ID'),
        'member' => $this->t('Member'),
        'stage' => $this->t('Stage'),
        'risk' => $this->t('Risk'),
        'recommended' => $this->t('Recommended'),
        'reason' => $this->t('Queue reason'),
        'preview' => $this->t('Preview'),
        'status' => $this->t('Status'),
        'scheduled' => $this->t('Scheduled'),
      ],
      '#options' => [],
      '#default_value' => (array) ($form_state->get('selected_queue_items') ?? []),
      '#empty' => $this->t('No queue items found for status "@status".', ['@status' => $status_filter]),
    ];

    foreach ($rows as $row) {
      $uid = (int) $row->uid;
      $member_label = ($user_names[$uid] ?? ('User ' . $uid)) . ' (#' . $uid . ')';
      $member_link = Link::fromTextAndUrl($member_label, Url::fromRoute('makerspace_member_success.log_contact', [
        'user' => $uid,
      ], [
        'query' => [
          'destination' => '/admin/makerspace/member-success/queue-review?status=' . $status_filter,
        ],
      ]))->toRenderable();
      $scheduled = !empty($row->scheduled_at) ? date('Y-m-d H:i', (int) $row->scheduled_at) : '—';
      $recommended = (string) $row->recommended_channel;
      if (!empty($row->recommended_template_id)) {
        $template_id = (int) $row->recommended_template_id;
        $template_label = $template_titles[$template_id] ?? ('tpl ' . $template_id);
        $recommended .= ' / ' . $template_label . ' (#' . $template_id . ')';
      }

      $form['queue_items']['#options'][(int) $row->id] = [
        'id' => (int) $row->id,
        'member' => ['data' => $member_link],
        'stage' => (string) $row->stage,
        'risk' => (int) $row->risk_score,
        'recommended' => $recommended,
        'reason' => $this->formatQueueReason($row),
        'preview' => ['data' => $this->buildPreviewLink($row)],
        'status' => (string) $row->status,
        'scheduled' => $scheduled,
      ];
    }

    return $form;
  }

  /**
   * Filter submit handler.
   */
  public function submitFilter(array &$form, FormStateInterface $form_state): void {
    $status = (string) $form_state->getValue('status');
    $stage = (string) ($form_state->getValue('stage') ?? '');
    $form_state->set('selected_queue_items', []);
    $query = ['status' => $status];
    if ($stage !== '') {
      $query['stage'] = $stage;
    }
    $form_state->setRedirect('makerspace_member_success.queue_review', [], ['query' => $query]);
  }

  /**
   * Generates queue candidates from latest daily snapshots.
   */
  public function submitGenerateCandidates(array &$form, FormStateInterface $form_state): void {
    $generated = 0;
    foreach ($this->loadLatestCandidates() as $candidate) {
      $this->queueService->enqueueCandidate((int) $candidate['uid'], (string) $candidate['stage'], $candidate);
      $generated++;
    }

    $counts = $this->loadStatusCounts(['queued', 'approved', 'suppressed', 'failed', 'sent', 'cancelled']);
    $this->messenger()->addStatus($this->t('Generated/updated queue candidates from @count latest snapshots.', ['@count' => $generated]));
    $this->messenger()->addStatus($this->t(
      'Current queue totals: queued @queued, suppressed @suppressed, approved @approved, failed @failed, sent @sent, cancelled @cancelled.',
      [
        '@queued' => $counts['queued'] ?? 0,
        '@suppressed' => $counts['suppressed'] ?? 0,
        '@approved' => $counts['approved'] ?? 0,
        '@failed' => $counts['failed'] ?? 0,
        '@sent' => $counts['sent'] ?? 0,
        '@cancelled' => $counts['cancelled'] ?? 0,
      ]
    ));
    $form_state->set('selected_queue_items', []);
    $status = (string) $form_state->getValue('status', 'queued');
    $stage = (string) ($this->getRequest()->query->get('stage') ?? '');
    $query = ['status' => $status];
    if ($stage !== '') {
      $query['stage'] = $stage;
    }
    $form_state->setRedirect('makerspace_member_success.queue_review', [], ['query' => $query]);
  }

  /**
   * Selects all currently visible rows (respecting active status + stage filter).
   */
  public function submitSelectAllVisible(array &$form, FormStateInterface $form_state): void {
    $status = (string) $this->getRequest()->query->get('status', 'queued');
    $allowed_statuses = ['queued', 'approved', 'suppressed', 'failed', 'sent', 'cancelled'];
    if (!in_array($status, $allowed_statuses, TRUE)) {
      $status = 'queued';
    }
    $stage = (string) ($this->getRequest()->query->get('stage') ?? '');
    $allowed_stages = ['', 'onboarding', 'engagement', 'retention', 'recovery', 'paused'];
    if (!in_array($stage, $allowed_stages, TRUE)) {
      $stage = '';
    }

    $selected = [];
    foreach ($this->loadRows($status, $stage) as $row) {
      $id = (int) $row->id;
      if ($id > 0) {
        $selected[$id] = $id;
      }
    }

    $form_state->set('selected_queue_items', $selected);

    // Drupal tableselect ignores #default_value on rebuild because submitted
    // POST values take precedence. Override the user input so the checkboxes
    // actually render as checked after setRebuild().
    $input = $form_state->getUserInput();
    $input['queue_items'] = [];
    foreach (array_keys($selected) as $id) {
      $input['queue_items'][$id] = (string) $id;
    }
    $form_state->setUserInput($input);

    $form_state->setRebuild(TRUE);
    $this->messenger()->addStatus($this->t('Selected @count visible rows.', ['@count' => count($selected)]));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_keys(array_filter((array) $form_state->getValue('queue_items', [])));
    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No queue rows selected.'));
      return;
    }

    $action = (string) ($form_state->getValue(['bulk', 'action']) ?? $form_state->getValue('action') ?? '');
    $reason = trim((string) ($form_state->getValue(['bulk', 'reason']) ?? $form_state->getValue('reason') ?? ''));
    if ($action === '') {
      $this->messenger()->addWarning($this->t('No bulk action was detected. Please choose an action and try again.'));
      return;
    }
    $count = 0;
    foreach ($selected as $id) {
      $queue_id = (int) $id;
      if ($queue_id <= 0) {
        continue;
      }
      if ($action === 'approve') {
        $this->queueService->approve($queue_id, (int) $this->currentUser()->id());
        $count++;
      }
      elseif ($action === 'suppress') {
        $this->queueService->suppress($queue_id, $reason !== '' ? $reason : 'manual_suppressed');
        $count++;
      }
      elseif ($action === 'mark_sent_manual') {
        $this->queueService->markSent($queue_id, ['provider_message_id' => 'manual']);
        $count++;
      }
    }

    $this->messenger()->addStatus($this->t('Updated @count queue items.', ['@count' => $count]));
    $form_state->set('selected_queue_items', []);
    $status = (string) $this->getRequest()->query->get('status', 'queued');
    $stage = (string) ($this->getRequest()->query->get('stage') ?? '');
    $query = ['status' => $status];
    if ($stage !== '') {
      $query['stage'] = $stage;
    }
    $form_state->setRedirect('makerspace_member_success.queue_review', [], ['query' => $query]);
  }

  /**
   * Loads queue rows for the review table.
   */
  protected function loadRows(string $status, string $stage = ''): array {
    $query = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q')
      ->condition('q.status', $status);
    if ($stage !== '') {
      $query->condition('q.stage', $stage);
    }

    // Hide stale/snoozed queued work so recent manual outreach does not lead to
    // duplicate bulk sends. We only apply this to actionable queue states.
    if (in_array($status, ['queued', 'approved'], TRUE)) {
      $today = MemberSuccessQueueRules::todayYmd();
      $resolved = MemberSuccessLifecycle::resolvedFollowupStatuses();

      $query->innerJoin(
        'ms_member_success_snapshot',
        'ms',
        "ms.uid = q.uid AND ms.is_latest = 1 AND ms.snapshot_type = 'daily'"
      );
      $query->where('ms.stage = q.stage');

      $followup_group = $query->orConditionGroup()
        ->isNull('ms.next_followup_date')
        ->condition('ms.next_followup_date', $today, '<=');
      $query->condition($followup_group);

      $outreach_group = $query->orConditionGroup()
        ->isNull('ms.outreach_status')
        ->condition('ms.outreach_status', $resolved, 'NOT IN');
      $query->condition($outreach_group);

      $member_followup_group = $query->orConditionGroup()
        ->isNull('ms.member_followup_status')
        ->condition('ms.member_followup_status', $resolved, 'NOT IN');
      $query->condition($member_followup_group);

      // If any outreach was logged after this queue row was generated, treat
      // the row as stale to avoid duplicate sends in bulk queue review.
      $query->where(
        'NOT EXISTS (SELECT 1 FROM {ms_member_outreach_log} log WHERE log.uid = q.uid AND log.created_at >= q.created_at)'
      );
      $recent_cutoff = date('Y-m-d', strtotime($today . ' -7 days'));
      $query->where(
        'NOT EXISTS (SELECT 1 FROM {ms_member_outreach_log} recent_log WHERE recent_log.uid = q.uid AND recent_log.contact_date >= :ms_recent_cutoff)',
        [':ms_recent_cutoff' => $recent_cutoff]
      );
    }

    return $query
      ->orderBy('q.scheduled_at', 'ASC')
      ->orderBy('q.id', 'ASC')
      ->range(0, 250)
      ->execute()
      ->fetchAll();
  }

  /**
   * Loads status counts for quick queue visibility.
   */
  protected function loadStatusCounts(array $allowedStatuses): array {
    $counts = array_fill_keys($allowedStatuses, 0);
    $query = $this->database->select('ms_member_outreach_queue', 'q');
    $query->addExpression('COUNT(*)', 'count');
    $query->fields('q', ['status']);
    $query->condition('q.status', $allowedStatuses, 'IN');
    $query->groupBy('q.status');
    $rows = $query->execute()->fetchAllAssoc('status');
    foreach ($rows as $status => $row) {
      $counts[$status] = (int) ($row->count ?? 0);
    }

    return $counts;
  }

  /**
   * Loads display names by UID.
   */
  protected function loadUserNames(array $uids): array {
    $names = [];
    if (empty($uids)) {
      return $names;
    }
    $users = User::loadMultiple($uids);
    foreach ($users as $user) {
      $names[(int) $user->id()] = $user->getDisplayName();
    }
    return $names;
  }

  /**
   * Loads CiviCRM message template titles by template ID.
   */
  protected function loadTemplateTitles(array $templateIds): array {
    $titles = [];
    $templateIds = array_values(array_unique(array_map('intval', $templateIds)));
    if (empty($templateIds)) {
      return $titles;
    }

    $query = $this->database->select('civicrm_msg_template', 'mt');
    $query->fields('mt', ['id', 'msg_title']);
    $query->condition('mt.id', $templateIds, 'IN');
    foreach ($query->execute()->fetchAllAssoc('id') as $id => $row) {
      $titles[(int) $id] = trim((string) ($row->msg_title ?? ''));
    }

    return $titles;
  }

  /**
   * Loads latest member snapshots to enqueue as outreach candidates.
   */
  protected function loadLatestCandidates(): array {
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->fields('ms', [
      'uid',
      'stage',
      'risk_score',
      'risk_reasons',
      'preferred_outreach_method',
      'civicrm_do_not_email',
      'civicrm_do_not_sms',
      'last_contact_date',
      'contact_count',
    ]);
    $query->innerJoin('users_field_data', 'u', 'u.uid = ms.uid');
    $query->addField('u', 'mail', 'email');
    $query->leftJoin('civicrm_uf_match', 'uf', 'uf.uf_id = ms.uid');
    $query->addField('uf', 'contact_id', 'civicrm_contact_id');
    $query->leftJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->addField('c', 'is_opt_out', 'is_opt_out');
    $query->leftJoin('civicrm_phone', 'p', 'p.contact_id = c.id AND p.is_primary = 1');
    $query->addField('p', 'phone', 'phone');

    [$sms_table, $sms_column] = $this->loadSmsConsentMetadata();
    if ($sms_table && $sms_column) {
      $query->leftJoin($sms_table, 'sp', 'sp.entity_id = c.id');
      $query->addField('sp', $sms_column, 'sms_consent');
    }

    $query->condition('ms.is_latest', 1);
    $query->condition('ms.snapshot_type', 'daily');
    $query->condition('ms.risk_score', 0, '>');
    $query->orderBy('ms.risk_score', 'DESC');
    $query->range(0, 2000);

    $rows = $query->execute()->fetchAll();
    $candidates = [];
    foreach ($rows as $row) {
      $candidates[] = [
        'uid' => (int) $row->uid,
        'stage' => (string) $row->stage,
        'risk_score' => (int) $row->risk_score,
        'risk_reasons' => $row->risk_reasons,
        'preferred_outreach_method' => (string) ($row->preferred_outreach_method ?? ''),
        'do_not_email' => (int) ($row->civicrm_do_not_email ?? 0),
        'do_not_sms' => (int) ($row->civicrm_do_not_sms ?? 0),
        'last_contact_date' => (string) ($row->last_contact_date ?? ''),
        'contact_count' => (int) ($row->contact_count ?? 0),
        'email' => (string) ($row->email ?? ''),
        'phone' => (string) ($row->phone ?? ''),
        'is_opt_out' => (int) ($row->is_opt_out ?? 0),
        'sms_consent' => isset($row->sms_consent) ? (int) $row->sms_consent : NULL,
        'civicrm_contact_id' => (int) ($row->civicrm_contact_id ?? 0),
      ];
    }

    return $candidates;
  }

  /**
   * Finds the optional custom SMS consent table + column.
   *
   * @return array{0: string|null, 1: string|null}
   *   Table and column names.
   */
  protected function loadSmsConsentMetadata(): array {
    $query = $this->database->select('civicrm_custom_group', 'cg');
    $query->innerJoin('civicrm_custom_field', 'cf', 'cf.custom_group_id = cg.id');
    $query->addField('cg', 'table_name');
    $query->addField('cf', 'column_name');
    $query->condition('cg.name', 'SMS_Preferences');
    $query->condition('cf.name', 'SMS_Consent');
    $record = $query->execute()->fetchAssoc();

    if (!$record) {
      return [NULL, NULL];
    }
    return [$record['table_name'] ?? NULL, $record['column_name'] ?? NULL];
  }

  /**
   * Formats a human-readable reason for why this row is in queue.
   */
  protected function formatQueueReason(object $row): string {
    $suppression_labels = [
      'suppressed_below_threshold' => (string) $this->t('Below stage risk threshold'),
      'suppressed_max_attempts' => (string) $this->t('Max attempts reached'),
      'suppressed_cooldown' => (string) $this->t('In cooldown window'),
      'suppressed_no_sms_consent' => (string) $this->t('No SMS consent'),
      'suppressed_missing_template_sms' => (string) $this->t('Missing SMS template'),
      'suppressed_missing_template_email' => (string) $this->t('Missing email template'),
      'suppressed_no_allowed_channel' => (string) $this->t('No allowed contact channel'),
      'manual_suppressed' => (string) $this->t('Manually suppressed'),
      'handled_offline' => (string) $this->t('Handled offline'),
      'not_actionable' => (string) $this->t('Not actionable'),
      'bad_contact_data' => (string) $this->t('Bad contact data'),
      'waiting_on_context' => (string) $this->t('Waiting on context'),
    ];

    if (!empty($row->suppression_reason_code) && isset($suppression_labels[$row->suppression_reason_code])) {
      return $suppression_labels[$row->suppression_reason_code];
    }
    if (!empty($row->suppression_reason_code)) {
      return str_replace('_', ' ', (string) $row->suppression_reason_code);
    }

    $reasons = $this->decodeRiskReasons($row->risk_reasons ?? NULL);
    if (empty($reasons)) {
      return (string) $this->t('Actionable risk score');
    }

    $labels = [];
    foreach (array_slice($reasons, 0, 3) as $reason) {
      $labels[] = $this->humanizeRiskReason($reason);
    }

    return implode('; ', $labels);
  }

  /**
   * Builds a preview link to the actual compose page in CiviCRM.
   */
  protected function buildPreviewLink(object $row): array|string {
    $contact_id = (int) ($row->civicrm_contact_id ?? 0);
    if ($contact_id <= 0) {
      return (string) $this->t('—');
    }

    $channel = (string) ($row->recommended_channel ?? '');
    $template_id = (string) ($row->recommended_template_id ?? '');
    [$can_preview, $block_reason] = $this->canPreviewChannel($contact_id, $channel);
    if (!$can_preview) {
      return (string) $this->t('Unavailable: @reason', ['@reason' => $block_reason]);
    }
    try {
      if ($channel === 'sms') {
        $query = [
          'action' => 'add',
          'reset' => 1,
          'cid' => $contact_id,
          'selectedChild' => 'activity',
        ];
        if ($template_id !== '') {
          $query['template_id'] = $template_id;
          $query['msg_template_id'] = $template_id;
        }
        $url = Url::fromUserInput('/civicrm/activity/sms/add', ['query' => $query]);
      }
      elseif ($channel === 'email') {
        $query = [
          'action' => 'add',
          'reset' => 1,
          'cid' => $contact_id,
          'selectedChild' => 'activity',
          'atype' => 3,
        ];
        if ($template_id !== '') {
          $query['template_id'] = $template_id;
          $query['msg_template_id'] = $template_id;
        }
        $url = Url::fromUserInput('/civicrm/activity/email/add', ['query' => $query]);
      }
      else {
        return (string) $this->t('—');
      }

      $build = Link::fromTextAndUrl((string) $this->t('Preview ↗'), $url)->toRenderable();
      $build['#attributes']['target'] = '_blank';
      $build['#attributes']['rel'] = 'noopener noreferrer';
      return $build;
    }
    catch (\Exception $e) {
      return (string) $this->t('—');
    }
  }

  /**
   * Checks whether preview compose can open cleanly for the given channel.
   *
   * @return array{0: bool, 1: string}
   *   Eligibility and explanation.
   */
  protected function canPreviewChannel(int $contact_id, string $channel): array {
    if ($channel === 'sms') {
      $query = $this->database->select('civicrm_contact', 'c');
      $query->leftJoin('civicrm_phone', 'p', 'p.contact_id = c.id AND p.is_primary = 1');
      $query->addField('c', 'do_not_sms');
      $query->addField('c', 'is_opt_out');
      $query->addField('c', 'is_deceased');
      $query->addField('p', 'phone');
      $query->condition('c.id', $contact_id);

      [$sms_table, $sms_column] = $this->loadSmsConsentMetadata();
      if ($sms_table && $sms_column) {
        $query->leftJoin($sms_table, 'spv', 'spv.entity_id = c.id');
        $query->addField('spv', $sms_column, 'sms_consent');
      }

      $row = $query->range(0, 1)->execute()->fetchAssoc() ?: [];
      if (empty($row)) {
        return [FALSE, (string) $this->t('missing contact')];
      }
      if (!empty($row['is_deceased'])) {
        return [FALSE, (string) $this->t('contact is marked deceased')];
      }
      if (!empty($row['is_opt_out']) || !empty($row['do_not_sms'])) {
        return [FALSE, (string) $this->t('SMS opt-out/do-not-SMS')];
      }
      if (empty(trim((string) ($row['phone'] ?? '')))) {
        return [FALSE, (string) $this->t('no primary phone')];
      }
      if ($sms_table && $sms_column && (int) ($row['sms_consent'] ?? 0) !== 1) {
        return [FALSE, (string) $this->t('no SMS consent')];
      }
      return [TRUE, ''];
    }

    if ($channel === 'email') {
      $query = $this->database->select('civicrm_contact', 'c');
      $query->leftJoin('civicrm_email', 'e', 'e.contact_id = c.id AND e.is_primary = 1');
      $query->addField('c', 'do_not_email');
      $query->addField('c', 'is_opt_out');
      $query->addField('c', 'is_deceased');
      $query->addField('e', 'email');
      $query->addField('e', 'on_hold');
      $query->condition('c.id', $contact_id);
      $row = $query->range(0, 1)->execute()->fetchAssoc() ?: [];
      if (empty($row)) {
        return [FALSE, (string) $this->t('missing contact')];
      }
      if (!empty($row['is_deceased'])) {
        return [FALSE, (string) $this->t('contact is marked deceased')];
      }
      if (!empty($row['is_opt_out']) || !empty($row['do_not_email'])) {
        return [FALSE, (string) $this->t('email opt-out/do-not-email')];
      }
      if (!empty($row['on_hold'])) {
        return [FALSE, (string) $this->t('email on hold')];
      }
      if (empty(trim((string) ($row['email'] ?? '')))) {
        return [FALSE, (string) $this->t('no primary email')];
      }
      return [TRUE, ''];
    }

    return [FALSE, (string) $this->t('manual channel')];
  }

  /**
   * Decodes queue risk reasons from blob/serialized storage.
   */
  protected function decodeRiskReasons(mixed $raw): array {
    if (is_array($raw)) {
      return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
      return [];
    }
    $value = @unserialize($raw);
    if (is_array($value)) {
      return $value;
    }
    if (is_string($value) && trim($value) !== '') {
      $nested = @unserialize($value);
      if (is_array($nested)) {
        return $nested;
      }
    }
    return [];
  }

  /**
   * Converts reason machine names to short operator labels.
   */
  protected function humanizeRiskReason(string $reason): string {
    $map = [
      'payment_failed' => (string) $this->t('Payment failed'),
      'door_badge_pending' => (string) $this->t('Door badge pending'),
      'missing_serial' => (string) $this->t('Missing key serial'),
      'no_badge_1' => (string) $this->t('No first badge in window'),
      'no_badge_4' => (string) $this->t('Low badge count'),
      'pause_ending' => (string) $this->t('Pause ending soon'),
    ];
    if (isset($map[$reason])) {
      return $map[$reason];
    }
    if (str_starts_with($reason, 'inactive_')) {
      $days = (int) substr($reason, strlen('inactive_'));
      return (string) $this->t('Inactive @days+ days', ['@days' => $days]);
    }
    return ucwords(str_replace('_', ' ', $reason));
  }

}
