<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Controller\MemberSuccessDashboardController;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for the Member Success Dashboard Controller.
 *
 * @group makerspace_member_success
 */
class DashboardControllerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'makerspace_member_success',
    'user',
    'system',
    'field',
    'views',
    // We omit civicrm to avoid settings.php requirement in Kernel test.
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // Mock the civicrm service which is a dependency of the module.
    $container->register('civicrm', 'Drupal\civicrm\Civicrm')
      ->setSynthetic(TRUE)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot']);

    // Satisfy the synthetic civicrm service.
    $this->container->set('civicrm', $this->createMock(\Drupal\civicrm\Civicrm::class));

    // Insert a mock snapshot row so build() doesn't early-exit.
    \Drupal::database()->insert('ms_member_success_snapshot')
      ->fields([
        'uid' => 1,
        'snapshot_date' => date('Y-m-d'),
        'snapshot_type' => 'daily',
        'stage' => 'onboarding',
        'risk_score' => 50,
        'risk_reasons' => serialize(['payment_failed']),
        'serial_number_present' => 1,
        'badge_count_total' => 0,
        'badge_count_window' => 0,
        'visit_count_30d' => 0,
        'payment_failed' => 1,
        'payment_pause' => 0,
        'outreach_status' => 'pending',
        'contact_count' => 0,
        'is_latest' => 1,
        'created_at' => time(),
      ])
      ->execute();
  }

  /**
   * Tests that the dashboard build method executes without runtime errors.
   *
   * This would have caught the "Class not found" error.
   */
  public function testDashboardBuild(): void {
    $controller = MemberSuccessDashboardController::create($this->container);
    $build = $controller->build();

    $this->assertIsArray($build);
    $this->assertSame('markup', $build['#type']);
    $this->assertStringContainsString('ms-dashboard-wrapper', (string) $build['#markup']);
    $this->assertStringContainsString('Lifecycle Stages', (string) $build['#markup']);
  }

}
