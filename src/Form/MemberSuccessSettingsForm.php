<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_member_success\Service\CiviCrmHelper;

/**
 * Configure Member Success thresholds and mappings.
 */
class MemberSuccessSettingsForm extends ConfigFormBase {

  /**
   * The CiviCRM helper service.
   *
   * @var \Drupal\makerspace_member_success\Service\CiviCrmHelper
   */
  protected $civiCrmHelper;

  /**
   * Constructs a MemberSuccessSettingsForm object.
   */
  public function __construct(CiviCrmHelper $civi_crm_helper) {
    $this->civiCrmHelper = $civi_crm_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_member_success.civicrm_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['makerspace_member_success.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('makerspace_member_success.settings');
    $templates = $this->civiCrmHelper->getMessageTemplates();
    // Add empty option.
    $template_options = ['' => $this->t('- Select a template -')] + $templates;

    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Thresholds & Configuration'),
      '#open' => TRUE,
    ];

    $form['thresholds']['door_badge_tid'] = [
      '#type' => 'number',
      '#title' => $this->t('Door badge term ID'),
      '#default_value' => $config->get('door_badge_tid') ?? 1519,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['badge_watch_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Engagement watch threshold (days)'),
      '#description' => $this->t('After this many days since activation with no badge activity, the member enters the Watch tier (risk +10).'),
      '#default_value' => $config->get('badge_watch_days') ?? 30,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['badge_one_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Engagement actionable threshold (days)'),
      '#description' => $this->t('After this many days since activation with no badge activity, the member becomes Actionable (risk +20).'),
      '#default_value' => $config->get('badge_one_days') ?? 60,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['badge_four_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to earn four badges'),
      '#default_value' => $config->get('badge_four_days') ?? 180,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['new_member_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days considered a new member'),
      '#default_value' => $config->get('new_member_days') ?? 180,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['retention_recency_days'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Retention recency windows (days)'),
      '#description' => $this->t('Comma-separated list of day thresholds (e.g., 30, 60, 90).'),
      '#default_value' => implode(', ', (array) $config->get('retention_recency_days')),
    ];

    $form['thresholds']['onboarding_stall_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Onboarding step stall threshold (hours)'),
      '#description' => $this->t('A new member who sits on the same onboarding step (profile, safety video/quiz, or booking orientation) for this many hours is flagged as Actionable in the onboarding queue. Default 48.'),
      '#default_value' => (int) ($config->get('onboarding_stall_hours') ?? 48),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['orientation_quiz_nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Safety quiz node ID'),
      '#description' => $this->t('Quiz id used to detect whether a new member has passed the safety quiz. Default 1.'),
      '#default_value' => (int) ($config->get('orientation_quiz_nid') ?? 1),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['thresholds']['onboarding_auto_nudge_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically email members stalled in onboarding'),
      '#description' => $this->t('When ON, cron emails paid members who have stalled at a join-funnel step past the stall threshold above, reusing the onboarding cooldown / max-attempts / suppression rules below. Requires the CiviCRM "onboarding stage" email template to be set. Leave OFF and run <code>drush ms-nudge-preview</code> first to review exactly who would be emailed. Default OFF.'),
      '#default_value' => (bool) $config->get('onboarding_auto_nudge_enabled'),
    ];

    $form['lead_followup'] = [
      '#type' => 'details',
      '#title' => $this->t('Unpaid lead follow-up'),
      '#description' => $this->t('People who fill the join form but never pay create no account, so they never reach the queue above. These settings track them and can auto-email a single nudge. Auto-send is OFF until you enable it.'),
      '#open' => FALSE,
    ];

    $form['lead_followup']['lead_followup_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically email unpaid leads a follow-up'),
      '#description' => $this->t('When off, leads are still tracked and listed for staff, but no email is sent. Emails go only to the exact address submitted, at most once per address.'),
      '#default_value' => (bool) $config->get('lead_followup_enabled'),
    ];

    $form['lead_followup']['lead_followup_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Wait before follow-up (hours)'),
      '#description' => $this->t('How long after a join submission with no account before the follow-up email is sent. Keep this comfortably longer than a normal pay-then-register takes, so active sign-ups are not interrupted. Default 2.'),
      '#default_value' => (int) ($config->get('lead_followup_hours') ?? 2),
      '#min' => 1,
    ];

    $form['lead_followup']['lead_followup_max_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max follow-up emails per cron run'),
      '#description' => $this->t('Hard cap so a backlog or retry loop cannot fan out. Default 25.'),
      '#default_value' => (int) ($config->get('lead_followup_max_per_run') ?? 25),
      '#min' => 0,
    ];

    $form['lead_followup']['lead_join_webform_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Join webform machine name'),
      '#default_value' => $config->get('lead_join_webform_id') ?: 'webform_24707',
    ];

    $form['lead_followup']['lead_resume_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resume link (path or absolute URL)'),
      '#description' => $this->t('Where the follow-up email sends the lead to finish joining. Default /join-makehaven.'),
      '#default_value' => $config->get('lead_resume_url') ?: '/join-makehaven',
    ];

    $form['lead_followup']['lead_site_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site base URL for email links'),
      '#description' => $this->t('Used to make the resume link absolute in cron-sent email. Default https://www.makehaven.org.'),
      '#default_value' => $config->get('lead_site_base_url') ?: 'https://www.makehaven.org',
    ];

    $form['thresholds']['outreach_send_hour_local'] = [
      '#type' => 'number',
      '#title' => $this->t('Default outreach send hour (local time)'),
      '#description' => $this->t('Newly queued items default to this hour (0-23) to avoid overnight sends.'),
      '#default_value' => (int) ($config->get('outreach_send_hour_local') ?? 10),
      '#min' => 0,
      '#max' => 23,
      '#required' => TRUE,
    ];

    $form['email_templates'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Templates (CiviCRM)'),
      '#description' => $this->t('Select the default message template to preload when clicking the action button.'),
      '#open' => TRUE,
    ];

    $form['email_templates']['template_onboarding'] = [
      '#type' => 'select',
      '#title' => $this->t('Onboarding Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('template_onboarding'),
    ];

    $form['email_templates']['template_engagement'] = [
      '#type' => 'select',
      '#title' => $this->t('Engagement Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('template_engagement'),
    ];

    $form['email_templates']['template_retention'] = [
      '#type' => 'select',
      '#title' => $this->t('Retention Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('template_retention'),
    ];

    $form['email_templates']['template_recovery'] = [
      '#type' => 'select',
      '#title' => $this->t('Recovery Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('template_recovery'),
    ];

    $form['sms_templates'] = [
      '#type' => 'details',
      '#title' => $this->t('SMS Templates (CiviCRM)'),
      '#description' => $this->t('Optional: select a default SMS template to preload when clicking SMS.'),
      '#open' => FALSE,
    ];

    $form['sms_templates']['sms_template_onboarding'] = [
      '#type' => 'select',
      '#title' => $this->t('Onboarding SMS Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('sms_template_onboarding'),
    ];

    $form['sms_templates']['sms_template_engagement'] = [
      '#type' => 'select',
      '#title' => $this->t('Engagement SMS Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('sms_template_engagement'),
    ];

    $form['sms_templates']['sms_template_retention'] = [
      '#type' => 'select',
      '#title' => $this->t('Retention SMS Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('sms_template_retention'),
    ];

    $form['sms_templates']['sms_template_recovery'] = [
      '#type' => 'select',
      '#title' => $this->t('Recovery SMS Template'),
      '#options' => $template_options,
      '#default_value' => $config->get('sms_template_recovery'),
    ];

    $form['template_variants'] = [
      '#type' => 'details',
      '#title' => $this->t('Template Variants By Issue'),
      '#description' => $this->t('Optional overrides by stage + risk reason. Format each line as <code>stage.reason=template_id</code> (example: <code>recovery.payment_failed=123</code>). Wildcards supported: <code>*.payment_failed=123</code>, <code>retention.inactive_*=456</code>.'),
      '#open' => FALSE,
    ];

    $form['template_variants']['email_template_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email template overrides'),
      '#description' => $this->t('These overrides are checked before the stage default email template.'),
      '#default_value' => implode("\n", (array) $config->get('email_template_overrides')),
    ];

    $form['template_variants']['sms_template_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SMS template overrides'),
      '#description' => $this->t('These overrides are checked before the stage default SMS template.'),
      '#default_value' => implode("\n", (array) $config->get('sms_template_overrides')),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Mappings'),
      '#open' => FALSE,
    ];

    $form['advanced']['payment_risk_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Payment risk field machine names'),
      '#description' => $this->t('One field machine name per line.'),
      '#default_value' => implode("\n", (array) $config->get('payment_risk_fields')),
    ];

    $form['advanced']['civicrm_preferred_method_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CiviCRM preferred communication method field'),
      '#default_value' => $config->get('civicrm_preferred_method_field') ?? 'preferred_communication_method',
    ];

    $form['advanced']['civicrm_member_followup_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CiviCRM member followup field'),
      '#description' => $this->t('The machine name of the CiviCRM custom field (e.g., custom_123).'),
      '#default_value' => $config->get('civicrm_member_followup_field'),
    ];

    $form['advanced']['civicrm_group_outreach_active'] = [
      '#type' => 'number',
      '#title' => $this->t('CiviCRM group ID: Outreach Active'),
      '#description' => $this->t('Optional group for members with followup status outreach_active.'),
      '#default_value' => (int) ($config->get('civicrm_group_outreach_active') ?? 0),
      '#min' => 0,
    ];
    $form['advanced']['civicrm_group_outreach_exhausted'] = [
      '#type' => 'number',
      '#title' => $this->t('CiviCRM group ID: Outreach Exhausted'),
      '#description' => $this->t('Optional group for members with followup status outreach_exhausted.'),
      '#default_value' => (int) ($config->get('civicrm_group_outreach_exhausted') ?? 0),
      '#min' => 0,
    ];
    $form['advanced']['civicrm_group_no_action_needed'] = [
      '#type' => 'number',
      '#title' => $this->t('CiviCRM group ID: No Action Needed'),
      '#description' => $this->t('Optional group for members with followup status no_action_needed.'),
      '#default_value' => (int) ($config->get('civicrm_group_no_action_needed') ?? 0),
      '#min' => 0,
    ];
    $form['advanced']['civicrm_group_return_intent'] = [
      '#type' => 'number',
      '#title' => $this->t('CiviCRM group ID: Return Intent'),
      '#description' => $this->t('Optional group for members with followup status return_intent.'),
      '#default_value' => (int) ($config->get('civicrm_group_return_intent') ?? 0),
      '#min' => 0,
    ];
    $form['advanced']['civicrm_group_confirmed_cancellation'] = [
      '#type' => 'number',
      '#title' => $this->t('CiviCRM group ID: Confirmed Cancellation'),
      '#description' => $this->t('Optional group for members with followup status confirmed_cancellation.'),
      '#default_value' => (int) ($config->get('civicrm_group_confirmed_cancellation') ?? 0),
      '#min' => 0,
    ];
    $form['advanced']['needs_review_notification_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email designated reviewers when a member is sent to Needs Review'),
      '#default_value' => (bool) ($config->get('needs_review_notification_enabled') ?? FALSE),
    ];
    $form['advanced']['needs_review_notification_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Needs Review notification recipients'),
      '#description' => $this->t('One email address per line. These people are notified when someone is sent to the Needs Review queue.'),
      '#default_value' => implode("\n", (array) $config->get('needs_review_notification_emails')),
      '#states' => [
        'visible' => [
          ':input[name="needs_review_notification_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['advanced']['chargebee_followup_push_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Push followup status updates to Chargebee'),
      '#description' => $this->t('If enabled, followup status changes in Member Success will be mirrored to Chargebee custom fields.'),
      '#default_value' => (bool) ($config->get('chargebee_followup_push_enabled') ?? FALSE),
    ];
    $form['advanced']['chargebee_followup_push_field_param'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chargebee followup field API parameter'),
      '#description' => $this->t('Custom field parameter used in Chargebee API updates (for example: cf_Cancelation_Followup).'),
      '#default_value' => $config->get('chargebee_followup_push_field_param') ?? 'cf_Cancelation_Followup',
      '#states' => [
        'visible' => [
          ':input[name="chargebee_followup_push_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['advanced']['civicrm_do_not_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CiviCRM do-not fields'),
      '#description' => $this->t('One field machine name per line.'),
      '#default_value' => implode("\n", (array) $config->get('civicrm_do_not_fields')),
    ];

    $form['advanced']['outreach_activity_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CiviCRM outreach activity types'),
      '#description' => $this->t('One activity type per line.'),
      '#default_value' => implode("\n", (array) $config->get('outreach_activity_types')),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $retention_values = array_filter(array_map('trim', explode(',', (string) $form_state->getValue('retention_recency_days'))));
    $retention_days = array_values(array_filter(array_map('intval', $retention_values)));

    $config = $this->config('makerspace_member_success.settings');
    $config
      ->set('door_badge_tid', (int) $form_state->getValue('door_badge_tid'))
      ->set('badge_one_days', (int) $form_state->getValue('badge_one_days'))
      ->set('badge_watch_days', (int) $form_state->getValue('badge_watch_days'))
      ->set('badge_four_days', (int) $form_state->getValue('badge_four_days'))
      ->set('new_member_days', (int) $form_state->getValue('new_member_days'))
      ->set('onboarding_stall_hours', (int) $form_state->getValue('onboarding_stall_hours'))
      ->set('onboarding_auto_nudge_enabled', (bool) $form_state->getValue('onboarding_auto_nudge_enabled'))
      ->set('orientation_quiz_nid', (int) $form_state->getValue('orientation_quiz_nid'))
      ->set('lead_followup_enabled', (bool) $form_state->getValue('lead_followup_enabled'))
      ->set('lead_followup_hours', (int) $form_state->getValue('lead_followup_hours'))
      ->set('lead_followup_max_per_run', (int) $form_state->getValue('lead_followup_max_per_run'))
      ->set('lead_join_webform_id', trim((string) $form_state->getValue('lead_join_webform_id')))
      ->set('lead_resume_url', trim((string) $form_state->getValue('lead_resume_url')))
      ->set('lead_site_base_url', trim((string) $form_state->getValue('lead_site_base_url')))
      ->set('retention_recency_days', $retention_days)
      ->set('outreach_send_hour_local', (int) $form_state->getValue('outreach_send_hour_local'))
      ->set('template_onboarding', $form_state->getValue('template_onboarding'))
      ->set('template_engagement', $form_state->getValue('template_engagement'))
      ->set('template_retention', $form_state->getValue('template_retention'))
      ->set('template_recovery', $form_state->getValue('template_recovery'))
      ->set('sms_template_onboarding', $form_state->getValue('sms_template_onboarding'))
      ->set('sms_template_engagement', $form_state->getValue('sms_template_engagement'))
      ->set('sms_template_retention', $form_state->getValue('sms_template_retention'))
      ->set('sms_template_recovery', $form_state->getValue('sms_template_recovery'))
      ->set('email_template_overrides', $this->sanitizeTemplateOverrides($form_state->getValue('email_template_overrides')))
      ->set('sms_template_overrides', $this->sanitizeTemplateOverrides($form_state->getValue('sms_template_overrides')))
      ->set('payment_risk_fields', $this->sanitizeList($form_state->getValue('payment_risk_fields')))
      ->set('civicrm_preferred_method_field', trim((string) $form_state->getValue('civicrm_preferred_method_field')))
      ->set('civicrm_member_followup_field', trim((string) $form_state->getValue('civicrm_member_followup_field')))
      ->set('civicrm_group_outreach_active', (int) $form_state->getValue('civicrm_group_outreach_active'))
      ->set('civicrm_group_outreach_exhausted', (int) $form_state->getValue('civicrm_group_outreach_exhausted'))
      ->set('civicrm_group_no_action_needed', (int) $form_state->getValue('civicrm_group_no_action_needed'))
      ->set('civicrm_group_return_intent', (int) $form_state->getValue('civicrm_group_return_intent'))
      ->set('civicrm_group_confirmed_cancellation', (int) $form_state->getValue('civicrm_group_confirmed_cancellation'))
      ->set('needs_review_notification_enabled', (bool) $form_state->getValue('needs_review_notification_enabled'))
      ->set('needs_review_notification_emails', $this->sanitizeList($form_state->getValue('needs_review_notification_emails')))
      ->set('chargebee_followup_push_enabled', (bool) $form_state->getValue('chargebee_followup_push_enabled'))
      ->set('chargebee_followup_push_field_param', trim((string) $form_state->getValue('chargebee_followup_push_field_param')))
      ->set('civicrm_do_not_fields', $this->sanitizeList($form_state->getValue('civicrm_do_not_fields')))
      ->set('outreach_activity_types', $this->sanitizeList($form_state->getValue('outreach_activity_types')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Normalizes newline-separated settings into a list.
   */
  private function sanitizeList(?string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', (string) $value);
    $lines = array_map('trim', $lines ?: []);
    return array_values(array_filter($lines, 'strlen'));
  }

  /**
   * Normalizes template override lines and keeps valid entries only.
   *
   * Expected format: stage.reason=template_id
   * Example: recovery.payment_failed=123
   */
  private function sanitizeTemplateOverrides(?string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', (string) $value);
    $lines = array_map('trim', $lines ?: []);
    $clean = [];

    foreach ($lines as $line) {
      if ($line === '' || !str_contains($line, '=')) {
        continue;
      }
      [$left, $template] = array_map('trim', explode('=', $line, 2));
      if ($left === '' || $template === '' || !str_contains($left, '.')) {
        continue;
      }
      [$stage, $reason] = array_map('trim', explode('.', $left, 2));
      if ($stage === '' || $reason === '') {
        continue;
      }

      // Keep only numeric CiviCRM template IDs and safe key chars.
      if (!preg_match('/^\d+$/', $template)) {
        continue;
      }
      if (!preg_match('/^[a-z0-9_*.-]+$/', strtolower($stage . '.' . $reason))) {
        continue;
      }

      $clean[] = strtolower($stage . '.' . $reason) . '=' . $template;
    }

    return array_values(array_unique($clean));
  }

}
