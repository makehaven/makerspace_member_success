<?php

namespace Drupal\Tests\makerspace_member_success\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;

/**
 * Tests the MemberSuccessSnapshotBuilder service.
 *
 * @group makerspace_member_success
 */
class MemberSuccessSnapshotBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'profile',
    'node',
    'taxonomy',
    'civicrm',
    'civicrm_entity',
    'makerspace_member_success',
  ];

  /**
   * The snapshot builder service.
   *
   * @var \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder
   */
  protected $snapshotBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('makerspace_member_success', ['ms_member_success_snapshot']);

  }

  /**
   * Tests that the service can be instantiated.
   */
  public function testServiceInstantiation() {
    $this->snapshotBuilder = $this->container->get('makerspace_member_success.snapshot_builder');
    $this->assertInstanceOf(MemberSuccessSnapshotBuilder::class, $this->snapshotBuilder);
  }

}
