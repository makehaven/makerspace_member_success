<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display last access time from access control logs.
 *
 * @ViewsField("last_access_field")
 */
class LastAccessField extends FieldPluginBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a LastAccessField object.
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
    // Get UID from the row
    $uid = $values->users_field_data_ms_member_success_snapshot_uid
        ?? $values->ms_member_success_snapshot_uid
        ?? NULL;

    if (!$uid) {
      return '';
    }

    // Query access control logs for last access
    // Assuming there's an access_log table or similar
    // Adjust table/column names based on actual access control module schema
    try {
      $last_access = $this->database->select('access_display_log', 'a')
        ->fields('a', ['timestamp'])
        ->condition('a.uid', $uid)
        ->orderBy('a.timestamp', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($last_access) {
        $now = time();
        $days_ago = floor(($now - $last_access) / 86400);
        $date = date('M j, g:i a', $last_access);

        if ($days_ago == 0) {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="text-success small" title="' . $date . '">Today</span>',
          ];
        }
        elseif ($days_ago == 1) {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="text-success small" title="' . $date . '">Yesterday</span>',
          ];
        }
        elseif ($days_ago < 7) {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="text-success small" title="' . $date . '">' . $days_ago . 'd ago</span>',
          ];
        }
        elseif ($days_ago < 30) {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="text-warning small" title="' . $date . '">' . $days_ago . 'd ago</span>',
          ];
        }
        else {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="text-danger small" title="' . $date . '">' . $days_ago . 'd ago</span>',
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Access log table might not exist or have different schema
      // Fail silently
    }

    return [
      '#type' => 'markup',
      '#markup' => '<span class="text-muted small">No data</span>',
    ];
  }

}
