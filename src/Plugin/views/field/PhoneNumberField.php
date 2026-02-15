<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display member phone number from CiviCRM.
 *
 * @ViewsField("phone_number_field")
 */
class PhoneNumberField extends FieldPluginBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a PhoneNumberField object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
    // Get UID
    $uid = $values->ms_member_success_snapshot_uid
        ?? $values->users_field_data_ms_member_success_snapshot_uid
        ?? NULL;

    if (!$uid) {
      return '';
    }

    // Get CiviCRM contact ID
    $contact_id = NULL;
    try {
      $contact_id = $this->database->select('civicrm_uf_match', 'uf')
        ->fields('uf', ['contact_id'])
        ->condition('uf.uf_id', $uid)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return '';
    }

    if (!$contact_id) {
      return '';
    }

    // Get primary phone number
    try {
      $phone = $this->database->select('civicrm_phone', 'p')
        ->fields('p', ['phone'])
        ->condition('p.contact_id', $contact_id)
        ->condition('p.is_primary', 1)
        ->execute()
        ->fetchField();

      if ($phone) {
        return [
          '#type' => 'markup',
          '#markup' => '<a href="tel:' . preg_replace('/[^0-9]/', '', $phone) . '" class="text-decoration-none">' . htmlspecialchars($phone) . '</a>',
        ];
      }
    }
    catch (\Exception $e) {
      // CiviCRM not available or no phone
    }

    return [
      '#type' => 'markup',
      '#markup' => '<span class="text-muted">—</span>',
    ];
  }

}
