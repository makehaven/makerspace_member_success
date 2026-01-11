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
    
    // Determine Contact ID
    $contact_id = NULL;
    if (isset($values->civicrm_contact_users_field_data_id)) {
        $contact_id = $values->civicrm_contact_users_field_data_id;
    } elseif (isset($values->id_1)) {
        $contact_id = $values->id_1; 
    } elseif (isset($values->contact_id_raw)) {
        $contact_id = $values->contact_id_raw;
    }

    if (!$contact_id) {
        return ''; 
    }

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
    $label = '✉️ Email';
    $status_badge = '';
    
    switch ($stage) {
        case 'onboarding':
            $status = $values->ms_member_success_snapshot_door_badge_status ?? '';
            $serial = $values->ms_member_success_snapshot_serial_number_present ?? 0;
            if ($status !== 'active') {
                $status_badge = '<span class="badge bg-warning text-dark">Pending Door Badge</span>';
                $label = '✉️ Email: Quiz Help';
            } elseif (empty($serial)) {
                $status_badge = '<span class="badge bg-info text-dark">Needs Key</span>';
                $label = '✉️ Email: Pickup';
            } else {
                return '<span class="badge bg-success">On Track</span>';
            }
            break;
            
        case 'engagement':
            $badges = $values->ms_member_success_snapshot_badge_count_window ?? 0;
            if ($badges == 0) {
                $status_badge = '<span class="badge bg-secondary">Stalled (0 Recent)</span>';
                $label = '✉️ Email: Workshop';
            } else {
                return '<span class="badge bg-success">Active</span>';
            }
            break;
            
        case 'retention':
            $visits = $values->ms_member_success_snapshot_visit_count_30d ?? 0;
            if ($visits == 0) {
                $status_badge = '<span class="badge bg-warning text-dark">Absent (30d+)</span>';
                $label = '✉️ Email: We Miss You';
            } else {
                return '<span class="badge bg-success">Visiting</span>';
            }
            break;
            
        case 'recovery':
            $failed = $values->ms_member_success_snapshot_payment_failed ?? 0;
            $paused = $values->ms_member_success_snapshot_payment_pause ?? 0;
            if ($failed) {
                $status_badge = '<span class="badge bg-danger">Payment Failed</span>';
                $label = '✉️ Email: Update Payment';
            } elseif ($paused) {
                return '<span class="badge bg-warning text-dark">Paused</span>';
            } else {
                return '<span class="badge bg-success">Resolved</span>';
            }
            break;
    }

    // Use Url::fromUserInput for legacy/CiviCRM paths
    try {
        $url = Url::fromUserInput("/civicrm/activity/email/add", ['query' => $query])->toString();
    } catch (\Exception $e) {
        return '';
    }
    
    return [
      '#type' => 'markup',
      '#markup' => $status_badge . '<br><a href="' . $url . '" class="btn btn-sm btn-outline-primary mt-1" target="_blank">' . $label . '</a>',
    ];
  }

}
