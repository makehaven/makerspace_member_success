<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display a member success action link.
 *
 * @ViewsField("member_success_action_link")
 */
class MemberSuccessActionLink extends FieldPluginBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a MemberSuccessActionLink object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
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
    // Note: We deliberately do NOT call $this->getEntity($values) here because
    // this pseudo-field is attached to a custom table, not a standard entity type,
    // and calling it triggers 'Undefined array key "entity_type"' warnings.

    $stage = $values->ms_member_success_snapshot_stage ?? 'onboarding';

    // Determine Contact ID - try all possible field names from view
    $contact_id = $values->civicrm_contact_civicrm_uf_match_id
        ?? $values->civicrm_contact_users_field_data_id
        ?? $values->id_1
        ?? $values->contact_id_raw
        ?? NULL;

    // Get Configured Template
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $template_id = $config->get("template_{$stage}");

    // Build Options
    $query = [
        'action' => 'add',
        'reset' => 1,
        'cid' => $contact_id,
        'selectedChild' => 'activity',
        'atype' => 3, // 3 = Email activity type usually
    ];
    
    if ($template_id) {
        $query['template_id'] = $template_id;
    }

    // Logic per stage
    $email_label = '✉️ Email';
    $status_badge = '';
    $action_text = '';
    $show_buttons = TRUE;

    switch ($stage) {
        case 'onboarding':
            $status = $values->ms_member_success_snapshot_door_badge_status ?? '';
            $serial = $values->ms_member_success_snapshot_serial_number_present ?? 0;
            if ($status !== 'active') {
                $status_badge = '<span class="badge bg-warning text-dark">Pending Door Badge</span>';
                $action_text = '→ Email or call about quiz';
                $email_label = 'Email';
            } elseif (empty($serial)) {
                $status_badge = '<span class="badge bg-info text-dark">Needs Key</span>';
                $action_text = '→ Remind to pick up key';
                $email_label = 'Email';
            } else {
                $status_badge = '<span class="badge bg-success">On Track</span>';
                $action_text = '→ Check in on experience';
                $email_label = 'Email';
            }
            break;

        case 'engagement':
            $badges = $values->ms_member_success_snapshot_badge_count_window ?? 0;
            if ($badges == 0) {
                $status_badge = '<span class="badge bg-secondary">Stalled (0 Recent)</span>';
                $action_text = '→ Invite to workshops';
                $email_label = 'Email';
            } else {
                $status_badge = '<span class="badge bg-success">Active</span>';
                $action_text = '→ Encourage continued learning';
                $email_label = 'Email';
            }
            break;

        case 'retention':
            $visits = $values->ms_member_success_snapshot_visit_count_30d ?? 0;
            if ($visits == 0) {
                $status_badge = '<span class="badge bg-warning text-dark">Absent (30d+)</span>';
                $action_text = '→ Reach out: "We miss you!"';
                $email_label = 'Email';
            } else {
                $status_badge = '<span class="badge bg-success">Visiting</span>';
                $action_text = '→ Keep them engaged';
                $email_label = 'Email';
            }
            break;

        case 'recovery':
            $failed = $values->ms_member_success_snapshot_payment_failed ?? 0;
            $paused = $values->ms_member_success_snapshot_payment_pause ?? 0;
            if ($failed) {
                $status_badge = '<span class="badge bg-danger">Payment Failed</span>';
                $action_text = '<strong>→ Call to update payment method</strong>';
                $email_label = 'Email';
            } elseif ($paused) {
                $status_badge = '<span class="badge bg-warning text-dark">Paused</span>';
                $action_text = '→ Check if ready to resume';
                $email_label = 'Email';
            } else {
                $status_badge = '<span class="badge bg-success">Resolved</span>';
                $action_text = '→ Welcome back message';
                $email_label = 'Email';
            }
            break;
    }

    // Build action buttons
    $buttons = [];

    // Determine UID - try multiple field names
    $uid = $values->ms_member_success_snapshot_uid
        ?? $values->users_field_data_ms_member_success_snapshot_uid
        ?? NULL;

    // Email button (primary action)
    try {
        $email_url = Url::fromUserInput("/civicrm/activity/email/add", ['query' => $query])->toString();
        $buttons[] = '<a href="' . $email_url . '" class="btn btn-sm btn-primary text-white" target="_blank" title="Send email via CiviCRM">' . $email_label . '</a>';
    } catch (\Exception $e) {
        // Skip email button if URL fails
    }

    // Log Contact button (renamed for clarity)
    if ($uid) {
        try {
            $log_contact_url = Url::fromRoute('makerspace_member_success.log_contact', ['user' => $uid]);
            if ($log_contact_url->access(\Drupal::currentUser())) {
              $log_url = $log_contact_url->toString();
              $buttons[] = '<a href="' . $log_url . '" class="btn btn-sm btn-success text-white" title="Record an outreach interaction with this member">Log Interaction</a>';
            }
        } catch (\Exception $e) {
            // Skip if route fails
        }
    }

    // CRM button removed - now appears with member name instead

    // Build the complete display
    $output = $status_badge;

    if ($action_text) {
      $output .= '<br><small class="text-muted">' . $action_text . '</small>';
    }

    if (!empty($buttons)) {
      $output .= '<br><div class="btn-group btn-group-sm mt-1" role="group">' . implode('', $buttons) . '</div>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

}
