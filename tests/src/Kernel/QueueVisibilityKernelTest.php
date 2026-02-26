<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\makerspace_member_success\Support\MemberSuccessQueueRules;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for member success queue visibility and snoozing.
 *
 * @group makerspace_member_success
 */
#[RunTestsInSeparateProcesses]
class QueueVisibilityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'profile',
    'node',
    'taxonomy',
    'text',
    'views',
    'makerspace_member_success',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(\Drupal\Core\DependencyInjection\ContainerBuilder $container): void {
    parent::register($container);

    // Provide a test-local civicrm service for snapshot builder wiring.
    $container->register('civicrm', 'Drupal\civicrm\Civicrm')
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot']);

    // Import the view configuration.
    $this->importViewConfig();

    // Mock request stack with a default request so query applier doesn't crash.
    $request = new Request();
    $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
    $request_stack = new RequestStack();
    $request_stack->push($request);
    $this->container->set('request_stack', $request_stack);
  }

  /**
   * Imports the member_success_queue view configuration.
   */
  protected function importViewConfig(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('makerspace_member_success');
    $config_path = DRUPAL_ROOT . '/' . $module_path . '/config/install/views.view.member_success_queue.yml';
    $data = Yaml::decode(file_get_contents($config_path));
    
    $this->container->get('config.factory')->getEditable('views.view.member_success_queue')
      ->setData($data)
      ->save();
  }

  /**
   * Tests that snoozed and resolved members are correctly filtered from the queue.
   */
  public function testQueueVisibilityFilters(): void {
    $today = MemberSuccessQueueRules::todayYmd();
    $future = date('Y-m-d', strtotime('+5 days'));
    $past = date('Y-m-d', strtotime('-5 days'));

    // 1. Normal at-risk member (VISIBLE)
    $uid_visible = $this->createSnapshotUser('member-visible', [
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'risk_score' => 30,
    ]);

    // 2. Snoozed member (HIDDEN)
    $uid_snoozed = $this->createSnapshotUser('member-snoozed', [
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'risk_score' => 30,
      'next_followup_date' => $future,
    ]);

    // 3. Snooze expired member (VISIBLE)
    $uid_expired = $this->createSnapshotUser('member-expired', [
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'risk_score' => 30,
      'next_followup_date' => $past,
    ]);

    // 4. Outreach exhausted member (HIDDEN)
    $uid_exhausted = $this->createSnapshotUser('member-exhausted', [
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'risk_score' => 30,
      'outreach_status' => MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED,
    ]);

    // 5. Confirmed cancellation member (HIDDEN)
    $uid_cancelled = $this->createSnapshotUser('member-cancelled', [
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'risk_score' => 30,
      'member_followup_status' => MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION,
    ]);

    // Execute the view.
    $view = Views::getView('member_success_queue');
    $view->setDisplay('retention');

    // Strip CiviCRM relationships and fields that might break in Kernel test.
    $display = &$view->displayHandlers->get('retention');
    $relationships = $display->getOption('relationships');
    unset($relationships['civicrm_contact']);
    $display->setOption('relationships', $relationships);

    $fields = $display->getOption('fields');
    foreach ($fields as $id => $field) {
      if (isset($field['relationship']) && $field['relationship'] === 'civicrm_contact') {
        unset($fields[$id]);
      }
    }
    // Add uid field explicitly to ensure it's in results.
    $fields['uid_raw'] = [
      'id' => 'uid_raw',
      'table' => 'ms_member_success_snapshot',
      'field' => 'uid',
      'plugin_id' => 'standard',
    ];
    $display->setOption('fields', $fields);

    $view->execute();

    $results = [];
    foreach ($view->result as $row) {
      // Views aliases the field based on table and field name.
      $results[] = (int) ($row->ms_member_success_snapshot_uid ?? $row->uid_raw ?? 0);
    }

    $this->assertContains($uid_visible, $results, 'Normal at-risk member should be visible.');
    $this->assertContains($uid_expired, $results, 'Member with expired snooze should be visible.');
    
    $this->assertNotContains($uid_snoozed, $results, 'Snoozed member should be hidden.');
    $this->assertNotContains($uid_exhausted, $results, 'Exhausted member should be hidden.');
    $this->assertNotContains($uid_cancelled, $results, 'Cancelled member should be hidden.');
    
    $this->assertCount(2, $results, 'Exactly 2 members should be in the queue results.');
  }

  /**
   * Helper to create a user and a corresponding snapshot.
   */
  protected function createSnapshotUser(string $name, array $snapshot_data): int {
    $user = User::create([
      'name' => $name,
      'mail' => "$name@example.com",
      'status' => 1,
    ]);
    $user->save();
    $uid = (int) $user->id();

    $fields = array_merge([
      'uid' => $uid,
      'snapshot_date' => date('Y-m-d'),
      'snapshot_type' => 'daily',
      'is_latest' => 1,
      'created_at' => time(),
      'risk_score' => 0,
      'stage' => MemberSuccessLifecycle::STAGE_RETENTION,
      'serial_number_present' => 1,
      'badge_count_total' => 5,
      'badge_count_window' => 1,
      'visit_count_30d' => 2,
      'payment_failed' => 0,
      'payment_pause' => 0,
    ], $snapshot_data);

    $this->container->get('database')->insert('ms_member_success_snapshot')
      ->fields($fields)
      ->execute();

    return $uid;
  }

}
