<?php

namespace Drupal\makerspace_member_success\Plugin\views\field;

use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Field handler to display member name with CiviCRM and Chargebee IDs.
 *
 * @ViewsField("member_name_with_ids_field")
 */
class MemberNameWithIdsField extends FieldPluginBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Static cache for loaded profiles to avoid duplicate queries.
   *
   * @var array
   */
  protected static $profileCache = [];

  /**
   * Static cache for CiviCRM contact IDs.
   *
   * @var array
   */
  protected static $contactIdCache = [];

  /**
   * Constructs a MemberNameWithIdsField object.
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
    // Get UID.
    $uid = $values->ms_member_success_snapshot_uid
        ?? $values->users_field_data_ms_member_success_snapshot_uid
        ?? NULL;

    if (!$uid) {
      return '';
    }

    // Load user entity for name.
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);

    if (!$user) {
      return '';
    }

    // Get user name.
    $name = $user->getDisplayName();
    $username = $user->getAccountName();
    $email = $user->getEmail();

    // Get photo from main profile (with static cache)
    $photo_html = '';
    $profile = NULL;

    if (isset(self::$profileCache[$uid])) {
      $profile = self::$profileCache[$uid];
    }
    else {
      $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
      $profiles = $profile_storage->loadByProperties([
        'uid' => $uid,
        'type' => 'main',
        'status' => 1,
      ]);

      if (!empty($profiles)) {
        $profile = reset($profiles);
        self::$profileCache[$uid] = $profile;
      }
      else {
        self::$profileCache[$uid] = FALSE;
      }
    }

    if ($profile && $profile->hasField('field_member_photo') && !$profile->get('field_member_photo')->isEmpty()) {
      $picture = $profile->get('field_member_photo')->entity;
      if ($picture) {
        // Use thumbnail image style for better performance.
        $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load('thumbnail');
        if ($image_style) {
          $photo_url = $image_style->buildUrl($picture->getFileUri());
        }
        else {
          // Fallback to original if thumbnail style doesn't exist.
          $photo_url = \Drupal::service('file_url_generator')->generateAbsoluteString($picture->getFileUri());
        }
        $photo_html = '<img src="' . $photo_url . '" alt="' . htmlspecialchars($name) . '" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover; vertical-align: middle;">';
      }
    }

    // If no photo, use placeholder.
    if (empty($photo_html)) {
      $photo_html = '<span class="bg-secondary rounded me-2 d-inline-block text-white text-center" style="width: 40px; height: 40px; line-height: 40px; vertical-align: middle; font-size: 18px;">👤</span>';
    }

    // Get CiviCRM contact ID (with static cache)
    $civi_id = NULL;
    if (isset(self::$contactIdCache[$uid])) {
      $civi_id = self::$contactIdCache[$uid];
    }
    else {
      try {
        $civi_id = $this->database->select('civicrm_uf_match', 'uf')
          ->fields('uf', ['contact_id'])
          ->condition('uf.uf_id', $uid)
          ->execute()
          ->fetchField();
        self::$contactIdCache[$uid] = $civi_id;
      }
      catch (\Exception $e) {
        // Skip if CiviCRM not available.
        self::$contactIdCache[$uid] = NULL;
      }
    }

    // Get Chargebee ID.
    $chargebee_id = NULL;
    try {
      $chargebee_id = $this->database->select('user__field_user_chargebee_id', 'cb')
        ->fields('cb', ['field_user_chargebee_id_value'])
        ->condition('cb.entity_id', $uid)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      // Skip if field doesn't exist.
    }

    // Get Stripe ID.
    $stripe_id = NULL;
    try {
      $stripe_id = $this->database->select('user__field_stripe_customer_id', 'st')
        ->fields('st', ['field_stripe_customer_id_value'])
        ->condition('st.entity_id', $uid)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      // Skip if field doesn't exist.
    }

    // Build profile link.
    try {
      $profile_url = Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString();
      $name_link = '<a href="' . $profile_url . '" class="username" title="View user profile">' . htmlspecialchars($name) . '</a>';
    }
    catch (\Exception $e) {
      $name_link = htmlspecialchars($name);
    }

    // Build ID display with links.
    $id_parts = [];

    if ($civi_id) {
      $civi_url = '/civicrm/contact/view?reset=1&cid=' . $civi_id;
      $id_parts[] = '<a href="' . $civi_url . '" target="_blank" title="View in CiviCRM" class="text-decoration-none">CRM</a>';
    }

    if ($chargebee_id) {
      // Get Chargebee site name from config.
      $config = \Drupal::config('chargebee_portal.settings');
      $site = $config->get('site') ?? 'makehaven';
      $cb_url = 'https://' . $site . '.chargebee.com/d/customers/' . urlencode($chargebee_id);
      $id_parts[] = '<a href="' . $cb_url . '" target="_blank" title="View in Chargebee" class="text-decoration-none">CB</a>';
    }

    if ($stripe_id) {
      $stripe_url = 'https://dashboard.stripe.com/customers/' . urlencode($stripe_id);
      $id_parts[] = '<a href="' . $stripe_url . '" target="_blank" title="View in Stripe" class="text-decoration-none">Stripe</a>';
    }

    $id_string = !empty($id_parts) ? implode(' | ', $id_parts) : '';

    // Combine photo, name, and links.
    $output = '<div class="d-flex align-items-start">';
    $output .= $photo_html;
    $output .= '<div>';
    $output .= $name_link;
    $meta_parts = [];
    if (!empty($username)) {
      $meta_parts[] = '@' . htmlspecialchars($username);
    }
    if (!empty($email)) {
      $meta_parts[] = htmlspecialchars($email);
    }
    $meta_parts[] = 'UID ' . (int) $uid;
    $output .= '<br><small class="text-muted">' . implode(' | ', $meta_parts) . '</small>';
    if ($id_string) {
      $output .= '<br><small class="text-muted">' . $id_string . '</small>';
    }
    $output .= '</div>';
    $output .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $output,
      '#cache' => [
        'tags' => [
          'user:' . $uid,
          'profile:main:' . $uid,
        ],
        'contexts' => ['user'],
        // Cache for 1 hour.
        'max-age' => 3600,
      ],
    ];
  }

}
