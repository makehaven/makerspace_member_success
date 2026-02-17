<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_success\Service\CiviCrmActivityLogger;

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
   * The CiviCRM activity logger.
   *
   * @var \Drupal\makerspace_member_success\Service\CiviCrmActivityLogger
   */
  protected $activityLogger;

  /**
   * Constructs a LogContactForm object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CiviCrmActivityLogger $activity_logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('makerspace_member_success.activity_logger')
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

    // Get phone number from CiviCRM
    $phone_number = $this->getPhoneNumber($user->id());
    $phone_display = $phone_number ? '<strong>' . $this->t('Phone:') . '</strong> ' . htmlspecialchars($phone_number) : '<em>' . $this->t('No phone number on file') . '</em>';

    // Member info header
    $form['member_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="alert alert-info">' .
        '<strong>' . $this->t('Member:') . '</strong> ' . $user->getDisplayName() .
        ' (' . $user->getEmail() . ')<br>' .
        $phone_display .
        '</div>',
    ];

    // Contact method
    $form['contact_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Contact Method'),
      '#options' => [
        'phone' => $this->t('Phone Call'),
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

    // Outcome (conditional based on method)
    $form['outcome'] = [
      '#type' => 'select',
      '#title' => $this->t('Outcome'),
      '#options' => $this->getOutcomeOptions($selected_method),
      '#required' => TRUE,
      '#prefix' => '<div id="outcome-wrapper">',
      '#suffix' => '</div>',
    ];

    // Notes
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

    // Outreach exhausted override
    $form['mark_exhausted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark as Outreach Exhausted'),
      '#description' => $this->t('Check this if member is unresponsive after multiple attempts. Overrides automatic status.'),
      '#default_value' => FALSE,
    ];

    // Log in CiviCRM checkbox
    $form['log_in_civicrm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create CiviCRM Activity'),
      '#description' => $this->t('Automatically log this contact in CiviCRM as an activity.'),
      '#default_value' => TRUE,
    ];

    // Actions
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log Interaction'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.member_success_queue.recovery'),
      '#attributes' => ['class' => ['btn', 'btn-secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = $form_state->get('user');
    $contact_method = $form_state->getValue('contact_method');
    $outcome = $form_state->getValue('outcome');
    $notes = $form_state->getValue('notes');
    $mark_exhausted = $form_state->getValue('mark_exhausted');
    $log_in_civicrm = $form_state->getValue('log_in_civicrm');

    // Calculate sleep period based on outcome
    $sleep_days = $this->getSleepPeriod($outcome);
    $today = date('Y-m-d');
    $next_followup_date = NULL;

    if ($sleep_days > 0) {
      // Calculate next followup date
      $next_followup_date = date('Y-m-d', strtotime($today . ' +' . $sleep_days . ' days'));
    }
    elseif ($sleep_days === -1) {
      // Permanent resolution - set far future date
      $next_followup_date = '9999-12-31';
    }

    // Auto-map outcome to followup status
    $followup_status = $this->mapOutcomeToFollowupStatus($outcome, $mark_exhausted);

    // Update followup status if changed
    if (!empty($followup_status) && $user->hasField('field_chargebee_followup')) {
      $current_status = $user->get('field_chargebee_followup')->value;
      if ($followup_status !== $current_status) {
        $user->set('field_chargebee_followup', $followup_status);
        $user->save();
        $this->logger('makerspace_member_success')->notice(
          'Updated followup status for user @uid from @old to @new (auto-mapped from outcome: @outcome)',
          [
            '@uid' => $user->id(),
            '@old' => $current_status ?? 'empty',
            '@new' => $followup_status,
            '@outcome' => $outcome,
          ]
        );
      }
    }

    // Update cancellation reason on profile if provided
    $cancellation_reason = $form_state->getValue('cancellation_reason');
    if ($outcome === 'confirmed_cancel' && !empty($cancellation_reason)) {
      // Load main profile
      $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
        'uid' => $user->id(),
        'type' => 'main',
        'is_default' => TRUE,
        'status' => TRUE,
      ]);

      if (!empty($profiles)) {
        $profile = reset($profiles);
        if ($profile->hasField('field_member_end_reason')) {
          $old_reason = $profile->get('field_member_end_reason')->value;
          $profile->set('field_member_end_reason', $cancellation_reason);
          $profile->save();

          $this->logger('makerspace_member_success')->notice(
            'Updated cancellation reason for user @uid from @old to @new',
            [
              '@uid' => $user->id(),
              '@old' => $old_reason ?? 'empty',
              '@new' => $cancellation_reason,
            ]
          );

          $this->messenger()->addStatus(
            $this->t('Cancellation reason updated to: @reason', [
              '@reason' => $cancellation_reason,
            ])
          );
        }
      }
    }

    // Create CiviCRM activity if requested
    $civicrm_activity_id = NULL;
    if ($log_in_civicrm) {
      $civicrm_activity_id = $this->activityLogger->logRetentionContact(
        $user,
        $contact_method,
        $outcome,
        $notes
      );

      if ($civicrm_activity_id) {
        $this->messenger()->addStatus(
          $this->t('CiviCRM activity @id created.', ['@id' => $civicrm_activity_id])
        );
      }
      else {
        $this->messenger()->addWarning(
          $this->t('Contact logged in Drupal, but CiviCRM activity creation failed. Please check logs.')
        );
      }
    }

    // Update snapshot with outreach tracking
    $this->updateSnapshotOutreach($user->id(), $today, $next_followup_date, $outcome);

    // Log to outreach log table for metrics tracking
    $this->insertOutreachLog([
      'uid' => $user->id(),
      'contact_date' => $today,
      'contact_method' => $contact_method,
      'outcome' => $outcome,
      'notes' => $notes,
      'followup_status' => $followup_status,
      'staff_uid' => \Drupal::currentUser()->id(),
      'civicrm_activity_id' => $civicrm_activity_id,
      'sleep_days' => $sleep_days,
      'created_at' => time(),
    ]);

    // Display success message
    $outcome_labels = [
      'will_return' => $this->t('Member will return'),
      'confirmed_cancel' => $this->t('Confirmed cancellation'),
      'payment_updated' => $this->t('Payment updated'),
      'all_good' => $this->t('Check-in conversation (all good)'),
      'needs_time' => $this->t('Needs time to decide'),
      'no_answer' => $this->t('No answer'),
      'left_message' => $this->t('Left message'),
      'email_sent' => $this->t('Email sent'),
      'email_bounced' => $this->t('Email bounced'),
    ];

    $this->messenger()->addStatus(
      $this->t('Interaction logged for @name: @outcome', [
        '@name' => $user->getDisplayName(),
        '@outcome' => $outcome_labels[$outcome] ?? $outcome,
      ])
    );

    // Redirect back to recovery queue
    $form_state->setRedirect('view.member_success_queue.recovery');
  }

  /**
   * Calculate sleep period in days based on contact outcome.
   *
   * @param string $outcome
   *   The outcome value from the form.
   *
   * @return int
   *   Number of days to sleep, or -1 for permanent resolution.
   */
  protected function getSleepPeriod($outcome) {
    $sleep_periods = [
      'left_message' => 7,           // Left voicemail → 7 days
      'email_sent' => 7,             // Email sent → 7 days
      'no_answer' => 3,              // No answer → 3 days
      'will_return' => 14,           // Will return → 14 days
      'needs_time' => 14,            // Needs time → 14 days
      'all_good' => 30,              // Positive check-in → follow up later
      'payment_updated' => -1,       // Payment updated → resolved
      'confirmed_cancel' => -1,      // Confirmed cancel → resolved
      'email_bounced' => 0,          // Email bounced → reappear immediately
    ];

    return $sleep_periods[$outcome] ?? 0;
  }

  /**
   * Insert outreach log entry for metrics tracking.
   *
   * @param array $data
   *   Log entry data.
   */
  protected function insertOutreachLog(array $data) {
    $database = \Drupal::database();

    try {
      $database->insert('ms_member_outreach_log')
        ->fields($data)
        ->execute();

      $this->logger('makerspace_member_success')->notice(
        'Outreach logged for UID @uid: @method → @outcome',
        [
          '@uid' => $data['uid'],
          '@method' => $data['contact_method'],
          '@outcome' => $data['outcome'],
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger('makerspace_member_success')->error(
        'Failed to log outreach: @message',
        ['@message' => $e->getMessage()]
      );
    }
  }

  /**
   * Update snapshot with outreach tracking data.
   *
   * @param int $uid
   *   The user ID.
   * @param string $contact_date
   *   The contact date (YYYY-MM-DD).
   * @param string|null $next_followup_date
   *   The next followup date (YYYY-MM-DD) or NULL.
   * @param string $outcome
   *   The contact outcome.
   */
  protected function updateSnapshotOutreach($uid, $contact_date, $next_followup_date, $outcome) {
    $database = \Drupal::database();

    // Get current contact count from latest snapshot
    $current_count = $database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['contact_count'])
      ->condition('ms.uid', $uid)
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->execute()
      ->fetchField();

    $new_count = ((int) $current_count) + 1;

    // Update all latest snapshots for this user
    $database->update('ms_member_success_snapshot')
      ->fields([
        'last_contact_date' => $contact_date,
        'next_followup_date' => $next_followup_date,
        'contact_count' => $new_count,
        'last_outreach_ts' => time(),
      ])
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute();

    // Log the update
    $this->logger('makerspace_member_success')->notice(
      'Updated outreach tracking for user @uid: contact_count=@count, next_followup=@next',
      [
        '@uid' => $uid,
        '@count' => $new_count,
        '@next' => $next_followup_date ?? 'none',
      ]
    );

    // Warn if contact count >= 3
    if ($new_count >= 3) {
      $this->messenger()->addWarning(
        $this->t('This is attempt #@count for this member. Consider marking as "Outreach Exhausted" if no response.', [
          '@count' => $new_count,
        ])
      );
    }
  }

  /**
   * AJAX callback to update outcome options based on contact method.
   */
  public function updateOutcomeOptions(array &$form, FormStateInterface $form_state) {
    return $form['outcome'];
  }

  /**
   * Get outcome options based on contact method.
   *
   * @param string $method
   *   The contact method (phone, email, in_person, other).
   *
   * @return array
   *   Outcome options appropriate for the method.
   */
  protected function getOutcomeOptions(string $method): array {
    $options = ['' => $this->t('- Select outcome -')];

    switch ($method) {
      case 'phone':
        $options += [
          'payment_updated' => $this->t('Spoke - Payment Updated'),
          'will_return' => $this->t('Spoke - Will Return'),
          'all_good' => $this->t('Spoke - All Good (Check-in)'),
          'confirmed_cancel' => $this->t('Spoke - Confirmed Cancellation'),
          'needs_time' => $this->t('Spoke - Needs Time to Decide'),
          'no_answer' => $this->t('No Answer'),
          'left_message' => $this->t('Left Voicemail'),
        ];
        break;

      case 'email':
        $options += [
          'payment_updated' => $this->t('Replied - Payment Updated'),
          'will_return' => $this->t('Replied - Will Return'),
          'all_good' => $this->t('Replied - All Good (Check-in)'),
          'confirmed_cancel' => $this->t('Replied - Confirmed Cancellation'),
          'needs_time' => $this->t('Replied - Needs Time to Decide'),
          'email_sent' => $this->t('Sent - Awaiting Reply'),
          'email_bounced' => $this->t('Bounced/Undeliverable'),
        ];
        break;

      case 'in_person':
        $options += [
          'payment_updated' => $this->t('Spoke - Payment Updated'),
          'will_return' => $this->t('Spoke - Will Return'),
          'all_good' => $this->t('Spoke - All Good (Check-in)'),
          'confirmed_cancel' => $this->t('Spoke - Confirmed Cancellation'),
          'needs_time' => $this->t('Spoke - Needs Time to Decide'),
        ];
        break;

      case 'other':
        // Show all options for 'other'
        $options += [
          'payment_updated' => $this->t('Contact Made - Payment Updated'),
          'will_return' => $this->t('Contact Made - Will Return'),
          'all_good' => $this->t('Contact Made - All Good (Check-in)'),
          'confirmed_cancel' => $this->t('Contact Made - Confirmed Cancellation'),
          'needs_time' => $this->t('Contact Made - Needs Time to Decide'),
          'no_answer' => $this->t('No Response'),
          'left_message' => $this->t('Left Message'),
        ];
        break;
    }

    return $options;
  }

  /**
   * Map contact outcome to followup status.
   *
   * @param string $outcome
   *   The contact outcome.
   * @param bool $mark_exhausted
   *   Whether to force "outreach_exhausted" status.
   *
   * @return string|null
   *   The followup status to set, or NULL for no change.
   */
  protected function mapOutcomeToFollowupStatus(string $outcome, bool $mark_exhausted): ?string {
    // Manual override takes precedence
    if ($mark_exhausted) {
      return 'outreach_exhausted';
    }

    // Auto-mapping based on outcome
    $mapping = [
      'payment_updated' => NULL,                    // Resolved - remove from queue
      'confirmed_cancel' => 'confirmed_cancellation',
      'will_return' => 'return_intent',
      'needs_time' => 'outreach_active',
      'no_answer' => 'outreach_active',
      'left_message' => 'outreach_active',
      'email_sent' => 'outreach_active',
      'email_bounced' => 'outreach_active',
    ];

    return $mapping[$outcome] ?? NULL;
  }

  /**
   * Get phone number from CiviCRM for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return string|null
   *   The phone number, or NULL if not found.
   */
  protected function getPhoneNumber(int $uid): ?string {
    try {
      // Get CiviCRM contact ID
      $contact_id = $this->activityLogger->getContactId($uid);
      if (!$contact_id) {
        return NULL;
      }

      // Query CiviCRM for phone number
      $database = \Drupal::database();
      $phone = $database->select('civicrm_phone', 'p')
        ->fields('p', ['phone'])
        ->condition('p.contact_id', $contact_id)
        ->condition('p.is_primary', 1)
        ->execute()
        ->fetchField();

      return $phone ?: NULL;
    }
    catch (\Exception $e) {
      $this->logger('makerspace_member_success')->error(
        'Failed to fetch phone number for uid @uid: @message',
        ['@uid' => $uid, '@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

}
