<?php

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\makerspace_member_success\Service\ChargebeeFollowupStatusSync;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Chargebee followup status push service.
 *
 * @group makerspace_member_success
 */
class ChargebeeFollowupStatusSyncTest extends UnitTestCase {

  /**
   * Builds service with configurable settings.
   */
  protected function buildService(array $settings, array $chargebee, array &$calls, ?\Closure $requestHandler = NULL): ChargebeeFollowupStatusSync {
    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturnCallback(fn(string $key) => $settings[$key] ?? NULL);

    $chargebeeConfig = $this->createMock(ImmutableConfig::class);
    $chargebeeConfig->method('get')->willReturnCallback(fn(string $key) => $chargebee[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(function (string $name) use ($settingsConfig, $chargebeeConfig) {
      return $name === 'makerspace_member_success.settings' ? $settingsConfig : $chargebeeConfig;
    });

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')->willReturnCallback(function (string $method, string $url, array $options = []) use (&$calls, $requestHandler) {
      $calls[] = [$method, $url, $options];
      if ($requestHandler instanceof \Closure) {
        return $requestHandler($method, $url, $options);
      }
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => ['id' => 'sub_1', 'cf_Cancelation_Followup' => 'Outreach Active']],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    return new ChargebeeFollowupStatusSync($httpClient, $configFactory, $loggerFactory);
  }

  /**
   * Builds a user mock with Chargebee ID support.
   */
  protected function buildUser(int $uid = 2, ?string $chargebeeId = 'cust_123'): User {
    $user = $this->createMock(User::class);
    $user->method('id')->willReturn($uid);
    $user->method('hasField')->willReturnCallback(function (string $field) use ($chargebeeId): bool {
      if ($field === 'field_user_chargebee_id') {
        return TRUE;
      }
      return FALSE;
    });
    $user->method('get')->willReturnCallback(function (string $field) use ($chargebeeId) {
      if ($field === 'field_user_chargebee_id') {
        return new class($chargebeeId) {
          public function __construct(public ?string $value) {}
          public function isEmpty(): bool {
            return $this->value === NULL || trim($this->value) === '';
          }
        };
      }
      return new class {
        public ?string $value = NULL;
        public function isEmpty(): bool {
          return TRUE;
        }
      };
    });
    return $user;
  }

  /**
   * Builds a user mock with canonical followup field support.
   */
  protected function buildPullUser(
    int $uid = 2,
    ?string $chargebeeId = 'cust_123',
    ?string $currentFollowup = NULL,
    int $expectedSaves = 0
  ): User {
    $chargebeeItem = new class($chargebeeId) {
      public function __construct(public ?string $value) {}
      public function isEmpty(): bool {
        return $this->value === NULL || trim($this->value) === '';
      }
    };
    $followupItem = new class($currentFollowup) {
      public function __construct(public ?string $value) {}
      public function isEmpty(): bool {
        return $this->value === NULL || trim($this->value) === '';
      }
    };

    $fields = [
      'field_user_chargebee_id' => $chargebeeItem,
      'field_member_followup_status' => $followupItem,
    ];

    $user = $this->createMock(User::class);
    $user->method('id')->willReturn($uid);
    $user->method('hasField')->willReturnCallback(static function (string $field) use (&$fields): bool {
      return array_key_exists($field, $fields);
    });
    $user->method('get')->willReturnCallback(static function (string $field) use (&$fields) {
      return $fields[$field] ?? new class {
        public ?string $value = NULL;
        public function isEmpty(): bool {
          return TRUE;
        }
      };
    });
    $user->method('set')->willReturnCallback(static function (string $field, ?string $value) use (&$fields) {
      if (isset($fields[$field])) {
        $fields[$field]->value = $value;
      }
      return NULL;
    });
    $user->expects($this->exactly($expectedSaves))->method('save');
    return $user;
  }

  /**
   * Ensures push is skipped when disabled.
   */
  public function testPushSkippedWhenDisabled(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => FALSE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls);

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE);
    $this->assertSame('push_disabled', $result['skipped_reason']);
    $this->assertCount(0, $calls);
  }

  /**
   * Ensures force mode bypasses disabled setting and performs GET only.
   */
  public function testForceAndDryRunBypassDisableAndAvoidPost(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => FALSE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls);

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, TRUE, TRUE);
    $this->assertSame(1, $result['subscriptions_examined']);
    $this->assertSame(1, $result['subscriptions_skipped_same']);
    $this->assertCount(1, $calls);
    $this->assertSame('GET', $calls[0][0]);
  }

  /**
   * Ensures mismatched status triggers POST update.
   */
  public function testMismatchedStatusPostsUpdate(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls);

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED);

    $this->assertSame(1, $result['subscriptions_examined']);
    $this->assertSame(1, $result['subscriptions_updated']);
    $this->assertCount(2, $calls);
    $this->assertSame('GET', $calls[0][0]);
    $this->assertSame('POST', $calls[1][0]);
  }

  /**
   * Ensures no_action_needed maps and posts update.
   */
  public function testNoActionNeededMapsAndPostsUpdate(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls);

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED);
    $this->assertSame('No Action Needed', $result['mapped_value']);
    $this->assertSame(1, $result['subscriptions_examined']);
    $this->assertSame(1, $result['subscriptions_updated']);
    $this->assertCount(2, $calls);
    $this->assertSame('GET', $calls[0][0]);
    $this->assertSame('POST', $calls[1][0]);
  }

  /**
   * Ensures no POST occurs when subscription already has mapped value.
   */
  public function testMatchingStatusSkipsPost(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => ['id' => 'sub_1', 'cf_Cancelation_Followup' => 'Outreach Exhausted']],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED);
    $this->assertSame(1, $result['subscriptions_examined']);
    $this->assertSame(1, $result['subscriptions_skipped_same']);
    $this->assertSame(0, $result['subscriptions_updated']);
    $this->assertCount(1, $calls);
    $this->assertSame('GET', $calls[0][0]);
  }

  /**
   * Ensures Product Catalog 2.0 failure falls back to PC1 endpoint.
   */
  public function testPc2FallbackPostsToPc1Endpoint(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method, string $url) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => ['id' => 'sub_1', 'cf_Cancelation_Followup' => 'Outreach Active']],
          ],
        ]));
      }

      if ($method === 'POST' && str_contains($url, '/update_for_items')) {
        throw new \Exception('This API is incompatible with the product catalog version');
      }

      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pushUserStatus($this->buildUser(), MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT);
    $this->assertSame(1, $result['subscriptions_examined']);
    $this->assertSame(1, $result['subscriptions_updated']);
    $this->assertCount(3, $calls);
    $this->assertSame('GET', $calls[0][0]);
    $this->assertSame('POST', $calls[1][0]);
    $this->assertStringContainsString('/update_for_items', $calls[1][1]);
    $this->assertSame('POST', $calls[2][0]);
    $this->assertStringNotContainsString('/update_for_items', $calls[2][1]);
  }

  /**
   * Ensures pull maps Chargebee status and saves to Drupal followup field.
   */
  public function testPullMapsAndUpdatesWhenEmpty(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => [
              'id' => 'sub_1',
              'status' => 'active',
              'updated_at' => 123456,
              'cf_Cancelation_Followup' => 'Return Intent',
            ]],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pullUserStatus($this->buildPullUser(2, 'cust_123', NULL, 1));
    $this->assertSame('Return Intent', $result['chargebee_value']);
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT, $result['mapped_status']);
    $this->assertTrue($result['changed']);
    $this->assertSame(1, $result['updated']);
    $this->assertCount(1, $calls);
    $this->assertSame('GET', $calls[0][0]);
  }

  /**
   * Ensures pull dry-run reports changes but does not save.
   */
  public function testPullDryRunSkipsSave(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => [
              'id' => 'sub_1',
              'status' => 'active',
              'updated_at' => 123456,
              'cf_Cancelation_Followup' => 'No Action Needed',
            ]],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pullUserStatus($this->buildPullUser(2, 'cust_123', NULL, 0), TRUE, FALSE);
    $this->assertTrue($result['changed']);
    $this->assertSame(0, $result['updated']);
    $this->assertTrue($result['dry_run']);
    $this->assertCount(1, $calls);
  }

  /**
   * Ensures pull skips with already_synced when Drupal value matches Chargebee.
   */
  public function testPullSkipsWhenAlreadySynced(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => [
              'id' => 'sub_1',
              'status' => 'active',
              'updated_at' => 123456,
              'cf_Cancelation_Followup' => 'Outreach Active',
            ]],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pullUserStatus(
      $this->buildPullUser(2, 'cust_123', MemberSuccessLifecycle::FOLLOWUP_OUTREACH_ACTIVE, 0),
      FALSE,
      FALSE
    );
    $this->assertSame('already_synced', $result['skipped_reason']);
    $this->assertFalse($result['changed']);
    $this->assertSame(0, $result['updated']);
    $this->assertCount(1, $calls);
  }

  /**
   * Ensures pull overwrites existing differing status when overwrite is set.
   */
  public function testPullOverwritesWhenFlagSet(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => [
              'id' => 'sub_1',
              'status' => 'active',
              'updated_at' => 123456,
              'cf_Cancelation_Followup' => 'Outreach Exhausted',
            ]],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    // User currently has return_intent; Chargebee says outreach_exhausted.
    $result = $service->pullUserStatus(
      $this->buildPullUser(2, 'cust_123', MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT, 1),
      FALSE,
      TRUE
    );
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED, $result['mapped_status']);
    $this->assertSame(MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT, $result['current_status']);
    $this->assertTrue($result['changed']);
    $this->assertSame(1, $result['updated']);
    $this->assertNull($result['skipped_reason']);
    $this->assertCount(1, $calls);
  }

  /**
   * Ensures pull does not overwrite existing differing status by default.
   */
  public function testPullSkipsWhenCurrentValueExistsWithoutOverwrite(): void {
    $calls = [];
    $service = $this->buildService([
      'chargebee_followup_push_enabled' => TRUE,
      'chargebee_followup_push_field_param' => 'cf_Cancelation_Followup',
    ], [
      'live_api_key' => 'key_live',
      'live_portal_url' => 'https://makehaven.chargebee.com/portal',
    ], $calls, function (string $method) {
      if ($method === 'GET') {
        return new Response(200, [], json_encode([
          'list' => [
            ['subscription' => [
              'id' => 'sub_1',
              'status' => 'active',
              'updated_at' => 123456,
              'cf_Cancelation_Followup' => 'Outreach Exhausted',
            ]],
          ],
        ]));
      }
      return new Response(200, [], json_encode(['ok' => TRUE]));
    });

    $result = $service->pullUserStatus($this->buildPullUser(2, 'cust_123', MemberSuccessLifecycle::FOLLOWUP_RETURN_INTENT, 0), FALSE, FALSE);
    $this->assertSame('existing_value_present', $result['skipped_reason']);
    $this->assertFalse($result['changed']);
    $this->assertSame(0, $result['updated']);
    $this->assertCount(1, $calls);
  }

}
