<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\civicrm\Civicrm;
use Drupal\makerspace_member_success\Service\CiviFollowupGroupSync;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Civi followup group sync.
 *
 * @group makerspace_member_success
 */
class CiviFollowupGroupSyncTest extends UnitTestCase {

  /**
   * Builds service with injected config map and captures civicrm API calls.
   */
  protected function buildService(array $settings, array &$apiCalls): CiviFollowupGroupSync {
    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturnCallback(fn(string $key) => $settings[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settingsConfig);

    $civicrm = $this->createMock(Civicrm::class);
    $civicrm->method('initialize')->willReturn(NULL);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    return new class($civicrm, $configFactory, $loggerFactory, $apiCalls) extends CiviFollowupGroupSync {
      public function __construct($civicrm, $configFactory, $loggerFactory, private array &$apiCalls) {
        parent::__construct($civicrm, $configFactory, $loggerFactory);
      }
      protected function civicrmApi(string $entity, string $action, array $params): array {
        $this->apiCalls[] = [$entity, $action, $params];
        if ($entity === 'UFMatch' && $action === 'get') {
          return [
            'values' => [
              ['contact_id' => 99],
            ],
          ];
        }
        return ['is_error' => 0];
      }
    };
  }

  /**
   * Builds a basic user mock.
   */
  protected function buildUser(int $uid = 2): User {
    $user = $this->createMock(User::class);
    $user->method('id')->willReturn($uid);
    return $user;
  }

  /**
   * Ensures target group is added and other mapped groups removed.
   */
  public function testSyncAddsTargetAndRemovesOthers(): void {
    $calls = [];
    $service = $this->buildService([
      'civicrm_group_outreach_active' => 11,
      'civicrm_group_outreach_exhausted' => 22,
      'civicrm_group_no_action_needed' => 33,
    ], $calls);

    $service->syncForUser($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE);

    $this->assertNotEmpty($calls);
    $groupCreates = array_values(array_filter($calls, fn($c) => $c[0] === 'GroupContact' && $c[1] === 'create'));
    $groupDeletes = array_values(array_filter($calls, fn($c) => $c[0] === 'GroupContact' && $c[1] === 'delete'));
    $this->assertCount(1, $groupCreates);
    $this->assertSame(11, (int) $groupCreates[0][2]['group_id']);
    $this->assertCount(2, $groupDeletes);
  }

  /**
   * Ensures NULL status removes from all mapped groups.
   */
  public function testNullStatusRemovesAllMappedGroups(): void {
    $calls = [];
    $service = $this->buildService([
      'civicrm_group_outreach_active' => 11,
      'civicrm_group_outreach_exhausted' => 22,
    ], $calls);

    $service->syncForUser($this->buildUser(), NULL);

    $groupCreates = array_values(array_filter($calls, fn($c) => $c[0] === 'GroupContact' && $c[1] === 'create'));
    $groupDeletes = array_values(array_filter($calls, fn($c) => $c[0] === 'GroupContact' && $c[1] === 'delete'));
    $this->assertCount(0, $groupCreates);
    $this->assertCount(2, $groupDeletes);
  }

}

