<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\civicrm\Civicrm;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MemberSuccessSnapshotBuilder.
 *
 * @group makerspace_member_success
 */
class MemberSuccessSnapshotBuilderTest extends UnitTestCase {

  /**
   * The builder under test.
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $database = $this->createMock(Connection::class);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $civicrm = $this->getMockBuilder(Civicrm::class)
      ->disableOriginalConstructor()
      ->getMock();

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $this->builder = new class($database, $config_factory, $time, $entity_type_manager, $logger_factory, $civicrm) extends MemberSuccessSnapshotBuilder {
      public function publicBuildRiskIndicators(array $data, int $badge_one_days, int $badge_four_days, array $recency_days, int $now_ts): array {
        return $this->buildRiskIndicators($data, $badge_one_days, $badge_four_days, $recency_days, $now_ts);
      }
    };
  }

  /**
   * Tests risk score calculation for payment issues.
   */
  public function testRiskScorePaymentIssue() {
    $data = [
      'payment_failed' => 1,
      'stage' => 'recovery',
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], time());
    
    $this->assertGreaterThanOrEqual(50, $score);
    $this->assertContains('payment_issue', $reasons);
  }

  /**
   * Tests risk score for inactive members in retention.
   */
  public function testRiskScoreInactiveRetention() {
    $now = time();
    $data = [
      'stage' => 'retention',
      'last_visit_ts' => $now - (40 * 86400), // 40 days ago
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], $now);
    
    $this->assertEquals(20, $score);
    $this->assertContains('inactive', $reasons);
  }

  /**
   * Tests zero risk for active engaged members.
   */
  public function testNoRiskForEngagedMember() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (10 * 86400),
      'badge_count_window' => 1,
      'badge_count_total' => 1,
      'door_badge_status' => 'active',
      'serial_present' => 1,
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], $now);
    
    $this->assertEquals(0, $score);
    $this->assertEmpty($reasons);
  }

  /**
   * Tests risk score for onboarding members missing serial numbers.
   */
  public function testRiskScoreOnboardingMissingSerial() {
    $data = [
      'stage' => 'onboarding',
      'door_badge_status' => 'active',
      'serial_present' => 0,
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], time());
    
    $this->assertEquals(10, $score);
    $this->assertContains('missing_serial', $reasons);
  }

  /**
   * Tests risk score for onboarding members with pending door badges.
   */
  public function testRiskScoreOnboardingPendingBadge() {
    $data = [
      'stage' => 'onboarding',
      'door_badge_status' => 'requested',
      'serial_present' => 1,
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], time());
    
    $this->assertEquals(20, $score);
    $this->assertContains('door_badge_pending', $reasons);
  }

  /**
   * Tests engagement risk: no badges in first 28 days.
   */
  public function testRiskScoreEngagementNoBadge1() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (30 * 86400), // 30 days since activation
      'badge_count_window' => 0,
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], $now);
    
    $this->assertEquals(20, $score);
    $this->assertContains('no_badge_1', $reasons);
  }

  /**
   * Tests engagement risk: fewer than 4 badges in 180 days.
   */
  public function testRiskScoreEngagementNoBadge4() {
    $now = time();
    $data = [
      'stage' => 'engagement',
      'activation_ts' => $now - (190 * 86400), // 190 days since activation
      'badge_count_window' => 1,
      'badge_count_total' => 3,
    ];
    
    [$score, $reasons] = $this->builder->publicBuildRiskIndicators($data, 28, 180, [30], $now);
    
    $this->assertEquals(20, $score);
    $this->assertContains('no_badge_4', $reasons);
  }

}
