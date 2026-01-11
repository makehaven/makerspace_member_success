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
    // Add empty option
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

    $form['thresholds']['badge_one_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to earn first additional badge'),
      '#default_value' => $config->get('badge_one_days') ?? 28,
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
      ->set('badge_four_days', (int) $form_state->getValue('badge_four_days'))
      ->set('new_member_days', (int) $form_state->getValue('new_member_days'))
      ->set('retention_recency_days', $retention_days)
      ->set('template_onboarding', $form_state->getValue('template_onboarding'))
      ->set('template_engagement', $form_state->getValue('template_engagement'))
      ->set('template_retention', $form_state->getValue('template_retention'))
      ->set('template_recovery', $form_state->getValue('template_recovery'))
      ->set('payment_risk_fields', $this->sanitizeList($form_state->getValue('payment_risk_fields')))
      ->set('civicrm_preferred_method_field', trim((string) $form_state->getValue('civicrm_preferred_method_field')))
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

}