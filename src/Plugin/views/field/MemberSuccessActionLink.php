<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Static cache for SMS eligibility checks keyed by contact ID.
   *
   * @var array<int, bool>
   */
  protected static $smsEligibilityCache = [];

  /**
   * Static cache for SMS eligibility details keyed by contact ID.
   *
   * @var array<int, array{eligible: bool, reason: string}>
   */
  protected static $smsEligibilityDetailsCache = [];

  /**
   * Whether SMS consent metadata has been loaded.
   *
   * @var bool
   */
  protected $smsMetadataLoaded = FALSE;

  /**
   * CiviCRM custom table name for SMS consent.
   *
   * @var string|null
   */
  protected $smsConsentTable;

  /**
   * CiviCRM custom column name for SMS consent.
   *
   * @var string|null
   */
  protected $smsConsentColumn;

  /**
   * Constructs a MemberSuccessActionLink object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('database')
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
    $sms_template_id = $config->get("sms_template_{$stage}");

    // Build Options
    $query = [
        'action' => 'add',
        'reset' => 1,
        'cid' => $contact_id,
        'selectedChild' => 'activity',
        'atype' => 3, // 3 = Email activity type usually
        // Flag to enable member-success auto-sync on CiviCRM form submit.
        'ms_member_success_auto' => 1,
    ];
    
    if ($template_id) {
        // CiviCRM's email compose form reads "template" on initial load.
        $query['template'] = $template_id;
        // Keep legacy keys for compatibility with older integrations.
        $query['template_id'] = $template_id;
        $query['msg_template_id'] = $template_id;
    }

    // Logic per stage
    $email_label = '✉️ Email';
    $status_badge = '';
    $action_text = '';

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

        case 'paused':
            $status_badge = '<span class="badge bg-warning text-dark">Paused</span>';
            $action_text = '→ Confirm return timeline before pause limit';
            $email_label = 'Email';
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

    $sms_unavailable_reason = '';
    if ($contact_id) {
      $sms_details = $this->getSmsEligibilityDetails((int) $contact_id);
      if ($sms_details['eligible']) {
        try {
          $sms_query = [
            'action' => 'add',
            'reset' => 1,
            'cid' => $contact_id,
            'selectedChild' => 'activity',
          ];
          if (!empty($sms_template_id)) {
            // CiviCRM SMS compose form reads "SMStemplate" on initial load.
            $sms_query['SMStemplate'] = $sms_template_id;
            // Keep legacy keys for compatibility with older integrations.
            // Support either query key used by SMS form integrations.
            $sms_query['template_id'] = $sms_template_id;
            $sms_query['msg_template_id'] = $sms_template_id;
          }
          $sms_url = Url::fromUserInput('/civicrm/activity/sms/add', ['query' => $sms_query])->toString();
          $buttons[] = '<a href="' . $sms_url . '" class="btn btn-sm btn-primary text-white" target="_blank" title="Send SMS via CiviCRM (consented contacts only)">SMS</a>';
        }
        catch (\Exception $e) {
          $sms_unavailable_reason = 'SMS unavailable: link error';
        }
      }
      else {
        $sms_unavailable_reason = $sms_details['reason'];
      }
    }
    else {
      $sms_unavailable_reason = 'SMS unavailable: no CiviCRM contact';
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

    if (!empty($sms_unavailable_reason)) {
      $output .= '<br><small class="text-muted">' . $sms_unavailable_reason . '</small>';
    }

    if (!empty($buttons)) {
      $output .= '<br><div class="btn-group btn-group-sm mt-1" role="group">' . implode('', $buttons) . '</div>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

  /**
   * Determines whether a contact is eligible for SMS outreach.
   */
  protected function isSmsEligible(int $contact_id): bool {
    return $this->getSmsEligibilityDetails($contact_id)['eligible'];
  }

  /**
   * Determines SMS eligibility and reason for non-eligibility.
   *
   * @return array{eligible: bool, reason: string}
   *   Eligibility details.
   */
  protected function getSmsEligibilityDetails(int $contact_id): array {
    if (isset(self::$smsEligibilityDetailsCache[$contact_id])) {
      return self::$smsEligibilityDetailsCache[$contact_id];
    }

    try {
      $query = $this->database->select('civicrm_contact', 'c');
      $query->leftJoin('civicrm_phone', 'phone', 'phone.contact_id = c.id AND phone.is_primary = 1');
      $query->addField('c', 'id', 'contact_id');
      $query->addField('c', 'do_not_sms');
      $query->addField('c', 'is_opt_out');
      $query->addField('phone', 'id', 'phone_id');
      $query->addField('phone', 'phone');
      $query->condition('c.id', $contact_id);

      $sms_alias = $this->applySmsConsentJoin($query);
      if ($sms_alias && $this->smsConsentColumn !== NULL) {
        $query->addField($sms_alias, $this->smsConsentColumn, 'sms_consent');
      }

      $row = $query->range(0, 1)->execute()->fetchAssoc();
      if (!$row) {
        $details = ['eligible' => FALSE, 'reason' => 'SMS unavailable: no CiviCRM contact'];
      }
      elseif ((int) ($row['do_not_sms'] ?? 0) !== 0 || (int) ($row['is_opt_out'] ?? 0) !== 0) {
        $details = ['eligible' => FALSE, 'reason' => 'SMS unavailable: contact opted out'];
      }
      elseif (empty($row['phone_id']) || empty(trim((string) ($row['phone'] ?? '')))) {
        $details = ['eligible' => FALSE, 'reason' => 'SMS unavailable: no primary phone'];
      }
      elseif ($sms_alias && $this->smsConsentColumn !== NULL && (int) ($row['sms_consent'] ?? 0) !== 1) {
        $details = ['eligible' => FALSE, 'reason' => 'SMS unavailable: no SMS consent'];
      }
      else {
        $details = ['eligible' => TRUE, 'reason' => ''];
      }

      self::$smsEligibilityDetailsCache[$contact_id] = $details;
      self::$smsEligibilityCache[$contact_id] = $details['eligible'];
      return $details;
    }
    catch (\Exception $e) {
      $details = ['eligible' => FALSE, 'reason' => 'SMS unavailable: lookup error'];
      self::$smsEligibilityDetailsCache[$contact_id] = $details;
      self::$smsEligibilityCache[$contact_id] = FALSE;
      return $details;
    }
  }

  /**
   * Left joins SMS consent custom table when available.
   */
  protected function applySmsConsentJoin($query): ?string {
    if (!$this->ensureSmsConsentMetadata()) {
      return NULL;
    }
    $alias = 'sms_pref';
    $query->leftJoin($this->smsConsentTable, $alias, "$alias.entity_id = c.id");
    return $alias;
  }

  /**
   * Loads SMS consent custom field metadata.
   */
  protected function ensureSmsConsentMetadata(): bool {
    if ($this->smsMetadataLoaded) {
      return $this->smsConsentTable !== NULL && $this->smsConsentColumn !== NULL;
    }

    $this->smsMetadataLoaded = TRUE;

    try {
      $query = $this->database->select('civicrm_custom_group', 'cg');
      $query->innerJoin('civicrm_custom_field', 'cf', 'cf.custom_group_id = cg.id');
      $query->addField('cg', 'table_name');
      $query->addField('cf', 'column_name');
      $query->condition('cg.name', 'SMS_Preferences');
      $query->condition('cf.name', 'SMS_Consent');
      $record = $query->execute()->fetchAssoc();

      if ($record) {
        $this->smsConsentTable = $record['table_name'] ?? NULL;
        $this->smsConsentColumn = $record['column_name'] ?? NULL;
      }
    }
    catch (\Exception $e) {
      $this->smsConsentTable = NULL;
      $this->smsConsentColumn = NULL;
    }

    return $this->smsConsentTable !== NULL && $this->smsConsentColumn !== NULL;
  }

}
