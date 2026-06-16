<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_success\Service\CiviCrmActivityLogger;
use Drupal\makerspace_member_success\Service\OutreachService;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;

/**
 * Form for logging contact attempts with at-risk members.
 */
class LogContactForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The CiviCRM activity logger (used for phone number lookup).
   *
   * @var \Drupal\makerspace_member_success\Service\CiviCrmActivityLogger
   */
  protected $activityLogger;

  /**
   * The outreach service.
   *
   * @var \Drupal\makerspace_member_success\Service\OutreachService
   */
  protected $outreachService;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Stage-to-queue-route mapping.
   */
  protected const STAGE_ROUTES = [
    MemberSuccessLifecycle::STAGE_ONBOARDING => 'view.member_success_queue.onboarding',
    MemberSuccessLifecycle::STAGE_ENGAGEMENT => 'view.member_success_queue.engagement',
    MemberSuccessLifecycle::STAGE_RETENTION => 'view.member_success_queue.retention',
    MemberSuccessLifecycle::STAGE_RECOVERY => 'view.member_success_queue.recovery',
  ];

  /**
   * Constructs a LogContactForm object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CiviCrmActivityLogger $activity_logger,
    OutreachService $outreach_service,
    Connection $database,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->activityLogger = $activity_logger;
    $this->outreachService = $outreach_service;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('makerspace_member_success.activity_logger'),
      $container->get('makerspace_member_success.outreach_service'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_member_success_log_contact';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?User $user = NULL) {
    if (!$user) {
      $this->messenger()->addError($this->t('Invalid user.'));
      return $form;
    }

    $form_state->set('user', $user);

    $form['#attached']['library'][] = 'makerspace_member_success/log_contact';

    // Load the member's current snapshot (stage + summary fields).
    $snapshot = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['stage', 'risk_score', 'outreach_status', 'contact_count'])
      ->condition('ms.uid', $user->id())
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->execute()
      ->fetchAssoc();

    $stage         = $snapshot['stage'] ?? MemberSuccessLifecycle::STAGE_RECOVERY;
    $risk_score    = (int) ($snapshot['risk_score'] ?? 0);
    $contact_count = (int) ($snapshot['contact_count'] ?? 0);

    $form_state->set('stage', $stage);

    // CiviCRM contact ID and phone — fetched together to avoid two lookups.
    $civicrm_contact_id = $this->activityLogger->getContactId($user->id());
    $phone_number = $this->getPhoneNumberForContact($civicrm_contact_id);

    // Risk badge CSS class.
    $risk_class = $risk_score >= 50 ? 'high' : ($risk_score >= 20 ? 'mid' : 'low');

    // Build the member header.
    $header_parts = [];

    $name_html = '<p class="ms-member-header__name">' . htmlspecialchars($user->getDisplayName());
    if ($civicrm_contact_id) {
      $civicrm_url = '/civicrm/contact/view?cid=' . (int) $civicrm_contact_id . '&reset=1';
      $name_html .= ' <a href="' . $civicrm_url . '" target="_blank" rel="noopener" class="ms-civicrm-link" title="' . $this->t('View in CiviCRM') . '">CiviCRM ↗</a>';
    }
    $name_html .= '</p>';
    $header_parts[] = $name_html;
    $header_parts[] = '<p class="ms-member-header__meta">' . htmlspecialchars($user->getEmail()) . '</p>';

    $contacts_html = '<dl class="ms-member-header__contacts">';
    if ($phone_number) {
      $contacts_html .= '<span class="pair"><dt>' . $this->t('Phone') . ':</dt> <dd>' . htmlspecialchars($phone_number) . '</dd></span>';
    }
    else {
      $contacts_html .= '<span class="pair" style="color:#999"><em>' . $this->t('No phone on file') . '</em></span>';
    }
    $contacts_html .= '<span class="pair"><dt>' . $this->t('Stage') . ':</dt> <dd><span class="ms-badge ms-badge--' . htmlspecialchars($stage) . '">' . htmlspecialchars($stage) . '</span></dd></span>';
    $contacts_html .= '<span class="pair"><dt>' . $this->t('Risk') . ':</dt> <dd><span class="ms-badge ms-badge--risk-' . $risk_class . '">' . $risk_score . '</span></dd></span>';
    $contacts_html .= '<span class="pair"><dt>' . $this->t('Contacts') . ':</dt> <dd>' . $contact_count . '</dd></span>';
    $contacts_html .= '</dl>';
    $header_parts[] = $contacts_html;

    $form['member_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ms-member-header">' . implode('', $header_parts) . '</div>',
    ];

    // Recent interaction history.
    $form['member_history'] = [
      '#type' => 'markup',
      '#markup' => $this->buildHistoryMarkup($user->id()),
    ];

    // Contact method.
    $form['contact_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Contact Method'),
      '#options' => [
        'phone' => $this->t('Phone Call'),
        'sms' => $this->t('SMS / Text'),
        'email' => $this->t('Email'),
        'in_person' => $this->t('In-Person'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => 'phone',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateOutcomeOptions',
        'wrapper' => 'outcome-wrapper',
        'event' => 'change',
      ],
    ];

    // Get selected method (from form state or default)
    $selected_method = $form_state->getValue('contact_method') ?? 'phone';

    // Outcome (conditional based on method and stage)
    $form['outcome'] = [
      '#type' => 'select',
      '#title' => $this->t('Outcome'),
      '#options' => $this->getOutcomeOptions($selected_method, $stage),
      '#required' => TRUE,
      '#prefix' => '<div id="outcome-wrapper">',
      '#suffix' => '</div>',
    ];

    // Notes.
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Optional notes about the conversation or next steps.'),
      '#rows' => 4,
    ];

    // Cancellation reason (conditional - only shown for confirmed_cancel)
    $form['cancellation_reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Cancellation Reason'),
      '#description' => $this->t('Record why the member is canceling. This helps track trends.'),
      '#options' => [
        '' => $this->t('- Select reason -'),
        'time' => $this->t('Time Constraints'),
        'engagement' => $this->t('Engagement (Lack of)'),
        'cost' => $this->t('Financial Considerations'),
        'relocation' => $this->t('Relocation'),
        'equipment' => $this->t('Facility & Equipment Insufficiencies'),
        'management' => $this->t('Management & Communication Dissatisfaction'),
        'community' => $this->t('Community/Culture Dissatisfaction'),
        'orientation' => $this->t('Orientation, Did Not Complete'),
        'predefined' => $this->t('Term/Project (planned) Completed'),
        '3rdparty' => $this->t('Payment End (External Employer/Program)'),
        'removed' => $this->t('Disciplinary Removal'),
        'other' => $this->t('Other'),
        'unknown' => $this->t('Unknown'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="outcome"]' => ['value' => 'confirmed_cancel'],
        ],
        'required' => [
          ':input[name="outcome"]' => ['value' => 'confirmed_cancel'],
        ],
      ],
    ];

    // Outreach exhausted override.
    $form['mark_exhausted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark as Outreach Exhausted'),
      '#description' => $this->t('Check this if member is unresponsive after multiple attempts. Overrides automatic status.'),
      '#default_value' => FALSE,
    ];

    // Log in CiviCRM checkbox.
    $form['log_in_civicrm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create CiviCRM Activity'),
      '#description' => $this->t('Automatically log this contact in CiviCRM as an activity.'),
      '#default_value' => TRUE,
    ];

    // Actions.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log Interaction'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->stageUrl($stage),
      '#attributes' => ['class' => ['btn', 'btn-secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = $form_state->get('user');
    $stage = $form_state->get('stage');

    $result = $this->outreachService->recordContact(
      $user,
      $stage,
      $form_state->getValue('contact_method'),
      $form_state->getValue('outcome'),
      (string) $form_state->getValue('notes'),
      (bool) $form_state->getValue('mark_exhausted'),
      (bool) $form_state->getValue('log_in_civicrm'),
      (string) $form_state->getValue('cancellation_reason')
    );

    $this->addResultMessages(
      $form_state->getValue('outcome'),
      $result,
      $user->getDisplayName(),
      (bool) $form_state->getValue('log_in_civicrm')
    );

    if ($result['contact_count'] >= 3) {
      $this->messenger()->addWarning($this->t(
        'This is attempt #@count for this member. Consider marking as "Outreach Exhausted" if no response.',
        ['@count' => $result['contact_count']]
      ));
    }

    // Redirect to destination param if provided, otherwise stage-aware queue.
    $destination = $this->getRequest()->query->get('destination');
    if ($destination) {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
    }
    else {
      $form_state->setRedirectUrl($this->stageUrl($stage));
    }
  }

  /**
   * AJAX callback to update outcome options based on contact method.
   */
  public function updateOutcomeOptions(array &$form, FormStateInterface $form_state) {
    $stage = $form_state->get('stage') ?? MemberSuccessLifecycle::STAGE_RECOVERY;
    $method = $form_state->getValue('contact_method') ?? 'phone';
    $form['outcome']['#options'] = $this->getOutcomeOptions($method, $stage);
    return $form['outcome'];
  }

  /**
   * Returns the URL for the queue matching the given stage.
   *
   * @param string $stage
   *   Lifecycle stage machine name.
   *
   * @return \Drupal\Core\Url
   *   Queue URL.
   */
  protected function stageUrl(string $stage): Url {
    $route = self::STAGE_ROUTES[$stage] ?? 'view.member_success_queue.recovery';
    return Url::fromRoute($route);
  }

  /**
   * Returns outcome options filtered by contact method and lifecycle stage.
   *
   * @param string $method
   *   Contact method.
   * @param string $stage
   *   Lifecycle stage.
   *
   * @return array
   *   Filtered outcome options.
   */
  protected function getOutcomeOptions(string $method, string $stage): array {
    $suppressed_by_stage = MemberSuccessLifecycle::getSuppressedOutcomesForStage($stage);

    $all = ['' => $this->t('- Select outcome -')];

    switch ($method) {
      case 'phone':
        $all += [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Spoke - Payment Updated'),
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Spoke - Will Return'),
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED => $this->t('Spoke - No Action Needed'),
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Spoke - Confirmed Cancellation'),
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Spoke - Needs Time to Decide'),
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER => $this->t('No Answer'),
          MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE => $this->t('Left Voicemail'),
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT => $this->t('Invalid Contact Info'),
        ];
        break;

      case 'sms':
        $all += [
          MemberSuccessLifecycle::OUTCOME_SMS_SENT => $this->t('SMS Sent - Awaiting Reply'),
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Replied - Payment Updated'),
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Replied - Will Return'),
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Replied - Confirmed Cancellation'),
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Replied - Needs Time to Decide'),
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT => $this->t('Invalid Contact Info'),
        ];
        break;

      case 'email':
        $all += [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Replied - Payment Updated'),
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Replied - Will Return'),
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED => $this->t('Replied - No Action Needed'),
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Replied - Confirmed Cancellation'),
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Replied - Needs Time to Decide'),
          MemberSuccessLifecycle::OUTCOME_EMAIL_SENT => $this->t('Sent - Awaiting Reply'),
          MemberSuccessLifecycle::OUTCOME_EMAIL_BOUNCED => $this->t('Bounced/Undeliverable'),
          MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT => $this->t('Invalid Contact Info'),
        ];
        break;

      case 'in_person':
        $all += [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Spoke - Payment Updated'),
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Spoke - Will Return'),
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED => $this->t('Spoke - No Action Needed'),
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Spoke - Confirmed Cancellation'),
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Spoke - Needs Time to Decide'),
        ];
        break;

      case 'other':
        $all += [
          MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Contact Made - Payment Updated'),
          MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Contact Made - Will Return'),
          MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED => $this->t('Contact Made - No Action Needed'),
          MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Contact Made - Confirmed Cancellation'),
          MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Contact Made - Needs Time to Decide'),
          MemberSuccessLifecycle::OUTCOME_NO_ANSWER => $this->t('No Response'),
          MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE => $this->t('Left Message'),
        ];
        break;
    }

    // Filter out outcomes suppressed by the member's current stage.
    foreach ($suppressed_by_stage as $suppressed_outcome) {
      unset($all[$suppressed_outcome]);
    }

    return $all;
  }

  /**
   * Adds messenger feedback messages based on the outreach result.
   *
   * @param string $outcome
   *   The contact outcome.
   * @param array $result
   *   Return value from OutreachService::recordContact().
   * @param string $display_name
   *   Member display name for the status message.
   * @param bool $log_in_civicrm
   *   Whether CiviCRM logging was requested.
   */
  protected function addResultMessages(string $outcome, array $result, string $display_name, bool $log_in_civicrm = FALSE): void {
    $outcome_labels = [
      MemberSuccessLifecycle::OUTCOME_WILL_RETURN => $this->t('Member will return'),
      MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL => $this->t('Confirmed cancellation'),
      MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED => $this->t('Payment updated'),
      MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED => $this->t('No action needed'),
      MemberSuccessLifecycle::OUTCOME_NEEDS_TIME => $this->t('Needs time to decide'),
      MemberSuccessLifecycle::OUTCOME_NO_ANSWER => $this->t('No answer'),
      MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE => $this->t('Left message'),
      MemberSuccessLifecycle::OUTCOME_EMAIL_SENT => $this->t('Email sent'),
      MemberSuccessLifecycle::OUTCOME_EMAIL_BOUNCED => $this->t('Email bounced'),
      MemberSuccessLifecycle::OUTCOME_SMS_SENT => $this->t('SMS sent'),
      MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT => $this->t('Invalid contact info'),
    ];

    $this->messenger()->addStatus($this->t('Interaction logged for @name: @outcome', [
      '@name' => $display_name,
      '@outcome' => $outcome_labels[$outcome] ?? $outcome,
    ]));

    $civicrm_activity_id = $result['civicrm_activity_id'] ?? NULL;
    if ($civicrm_activity_id) {
      $this->messenger()->addStatus($this->t('CiviCRM activity @id created.', ['@id' => $civicrm_activity_id]));
    }
    elseif ($log_in_civicrm && !$civicrm_activity_id) {
      $this->messenger()->addWarning($this->t('Contact logged in Drupal, but CiviCRM activity creation failed. Please check logs.'));
    }

    $cb = $result['chargebee_sync'] ?? NULL;
    if ($cb && !empty($cb['error'])) {
      $this->messenger()->addWarning($this->t('Followup status updated in Drupal, but failed to sync to Chargebee: @message', [
        '@message' => $cb['error'],
      ]));
    }
  }

  /**
   * Builds the recent interaction history markup for a member.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return string
   *   HTML string for the history table.
   */
  protected function buildHistoryMarkup(int $uid): string {
    $outcome_labels = [
      MemberSuccessLifecycle::OUTCOME_WILL_RETURN       => $this->t('Will Return'),
      MemberSuccessLifecycle::OUTCOME_CONFIRMED_CANCEL  => $this->t('Confirmed Cancel'),
      MemberSuccessLifecycle::OUTCOME_PAYMENT_UPDATED   => $this->t('Payment Updated'),
      MemberSuccessLifecycle::OUTCOME_NO_ACTION_NEEDED  => $this->t('No Action Needed'),
      MemberSuccessLifecycle::OUTCOME_NEEDS_TIME        => $this->t('Needs Time'),
      MemberSuccessLifecycle::OUTCOME_NO_ANSWER         => $this->t('No Answer'),
      MemberSuccessLifecycle::OUTCOME_LEFT_MESSAGE      => $this->t('Left Message'),
      MemberSuccessLifecycle::OUTCOME_EMAIL_SENT        => $this->t('Email Sent'),
      MemberSuccessLifecycle::OUTCOME_EMAIL_BOUNCED     => $this->t('Email Bounced'),
      MemberSuccessLifecycle::OUTCOME_SMS_SENT          => $this->t('SMS Sent'),
      MemberSuccessLifecycle::OUTCOME_INVALID_CONTACT   => $this->t('Invalid Contact'),
    ];

    $method_labels = [
      'phone'     => $this->t('Phone'),
      'sms'       => $this->t('SMS'),
      'email'     => $this->t('Email'),
      'in_person' => $this->t('In-Person'),
      'other'     => $this->t('Other'),
    ];

    $rows = $this->database->select('ms_member_outreach_log', 'log')
      ->fields('log', ['contact_date', 'contact_method', 'outcome', 'notes', 'staff_uid'])
      ->condition('log.uid', $uid)
      ->orderBy('log.created_at', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAll();

    $html = '<div class="ms-history">';
    $html .= '<p class="ms-history__heading">' . $this->t('Recent Interactions') . '</p>';

    if (!$rows) {
      $html .= '<p class="ms-history__empty">' . $this->t('No previous interactions recorded.') . '</p>';
      $html .= '</div>';
      return $html;
    }

    $html .= '<table class="ms-history__table">';
    $html .= '<thead><tr>';
    $html .= '<th class="col-date">' . $this->t('Date') . '</th>';
    $html .= '<th class="col-method">' . $this->t('Method') . '</th>';
    $html .= '<th class="col-outcome">' . $this->t('Outcome') . '</th>';
    $html .= '<th class="col-notes">' . $this->t('Notes') . '</th>';
    $html .= '<th class="col-staff">' . $this->t('Staff') . '</th>';
    $html .= '</tr></thead><tbody>';

    // Pre-load staff display names for all unique staff UIDs.
    $staff_uids = array_unique(array_filter(array_column($rows, 'staff_uid')));
    $staff_names = [];
    if ($staff_uids) {
      $staff_users = $this->entityTypeManager->getStorage('user')->loadMultiple($staff_uids);
      foreach ($staff_users as $staff) {
        $staff_names[$staff->id()] = $staff->getDisplayName();
      }
    }

    foreach ($rows as $row) {
      $outcome_label = (string) ($outcome_labels[$row->outcome] ?? ucwords(str_replace('_', ' ', $row->outcome)));
      $method_label  = (string) ($method_labels[$row->contact_method] ?? ucwords($row->contact_method));
      $staff_name    = $staff_names[$row->staff_uid] ?? '—';
      $notes         = $row->notes ? htmlspecialchars(mb_strimwidth($row->notes, 0, 80, '…')) : '<span style="color:#bbb">—</span>';

      $html .= '<tr>';
      $html .= '<td class="col-date">' . htmlspecialchars($row->contact_date) . '</td>';
      $html .= '<td class="col-method">' . htmlspecialchars($method_label) . '</td>';
      $html .= '<td class="col-outcome">' . htmlspecialchars($outcome_label) . '</td>';
      $html .= '<td class="col-notes">' . $notes . '</td>';
      $html .= '<td class="col-staff">' . htmlspecialchars((string) $staff_name) . '</td>';
      $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
  }

  /**
   * Get phone number from CiviCRM given an already-resolved contact ID.
   *
   * @param int|null $contact_id
   *   The CiviCRM contact ID.
   *
   * @return string|null
   *   The primary phone number, or NULL if not found.
   */
  protected function getPhoneNumberForContact(?int $contact_id): ?string {
    if (!$contact_id) {
      return NULL;
    }
    try {
      $phone = $this->database->select('civicrm_phone', 'p')
        ->fields('p', ['phone'])
        ->condition('p.contact_id', $contact_id)
        ->condition('p.is_primary', 1)
        ->execute()
        ->fetchField();
      return $phone ?: NULL;
    }
    catch (\Exception $e) {
      $this->logger('makerspace_member_success')->error(
        'Failed to fetch phone for CiviCRM contact @cid: @message',
        ['@cid' => $contact_id, '@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

}
