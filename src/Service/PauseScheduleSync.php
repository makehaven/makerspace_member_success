<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Records when each paused member's Chargebee pause is due to end.
 *
 * The webhook stores only a paused/not-paused boolean, so the member-success
 * console assumed every pause ran the 90-day maximum. They do not: of the
 * pauses that ended since February, as many ran one or two months as three.
 * The pause end is the one dated decision point in a membership we can
 * schedule outreach against (half of resolved pauses resume, against about a
 * quarter of cancellations ever returning), so it has to be the real date.
 *
 * Once a day this lists Chargebee's paused subscriptions and stores
 * uid => resume date (Y-m-d) in state. The snapshot build copies it onto the
 * member's row. Read-only against Chargebee; a failed listing keeps the
 * previous dates rather than wiping them.
 */
class PauseScheduleSync {

  /**
   * State key: ['synced' => ts, 'dates' => [uid => 'Y-m-d'], stats...].
   */
  public const STATE_KEY = 'makerspace_member_success.pause_schedule';

  /**
   * Minimum seconds between cron runs.
   */
  protected const CRON_INTERVAL = 20 * 3600;

  /**
   * The module logger channel.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected readonly ChargebeeAccountReconciler $chargebee,
    protected readonly Connection $database,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * The stored resume date for a member, or NULL when unknown.
   */
  public static function resumeDateFor(?StateInterface $state, int $uid): ?string {
    if ($state === NULL) {
      return NULL;
    }
    $dates = (array) (($state->get(self::STATE_KEY) ?? [])['dates'] ?? []);
    $date = $dates[$uid] ?? NULL;
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : NULL;
  }

  /**
   * Runs the sync from cron, once a day, on live only.
   *
   * Non-live environments hold live's Chargebee key; listing is harmless but
   * the result would describe live's members on a stale copy, so it waits
   * for an explicit drush run there.
   */
  public function runFromCron(): void {
    if (getenv('PANTHEON_ENVIRONMENT') !== 'live') {
      return;
    }
    $last = (int) (($this->state->get(self::STATE_KEY) ?? [])['synced'] ?? 0);
    if ($this->time->getRequestTime() - $last < self::CRON_INTERVAL) {
      return;
    }
    $this->sync();
  }

  /**
   * Lists paused subscriptions and stores each member's resume date.
   *
   * @return array
   *   Keys: paused, matched, no_resume_date, unmatched, error.
   */
  public function sync(): array {
    $stats = ['paused' => 0, 'matched' => 0, 'no_resume_date' => 0, 'unmatched' => 0, 'error' => NULL];
    try {
      $rows = $this->chargebee->fetchSubscriptions(['paused']);
    }
    catch (\Throwable $e) {
      $stats['error'] = $e->getMessage();
      $this->logger->error('Pause schedule sync could not list paused Chargebee subscriptions: @msg', ['@msg' => $e->getMessage()]);
      return $stats;
    }

    $dates = self::datesFromRows($rows, fn(string $customer_id) => $this->uidForCustomer($customer_id), $stats);
    $this->state->set(self::STATE_KEY, [
      'synced' => (int) $this->time->getRequestTime(),
      'dates' => $dates,
    ] + $stats);
    $this->logger->notice('Pause schedule sync: @paused paused subscription(s), @matched with a resume date, @none with no end date, @unmatched with no website account.', [
      '@paused' => $stats['paused'],
      '@matched' => $stats['matched'],
      '@none' => $stats['no_resume_date'],
      '@unmatched' => $stats['unmatched'],
    ]);
    return $stats;
  }

  /**
   * Maps Chargebee list rows to uid => resume date.
   *
   * @param array $rows
   *   Chargebee list entries, each with a 'subscription' key.
   * @param callable $uidForCustomer
   *   Customer id => uid or NULL.
   * @param array $stats
   *   Counters, updated in place.
   */
  public static function datesFromRows(array $rows, callable $uidForCustomer, array &$stats): array {
    $dates = [];
    foreach ($rows as $row) {
      $subscription = $row['subscription'] ?? $row;
      if (($subscription['status'] ?? '') !== 'paused') {
        continue;
      }
      $stats['paused']++;
      $uid = $uidForCustomer(trim((string) ($subscription['customer_id'] ?? '')));
      if ($uid === NULL) {
        $stats['unmatched']++;
        continue;
      }
      $resume = (int) ($subscription['resume_date'] ?? 0);
      if ($resume <= 0) {
        $stats['no_resume_date']++;
        continue;
      }
      // Two subscriptions on one account: the later resume date governs.
      $date = date('Y-m-d', $resume);
      if (!isset($dates[$uid]) || $date > $dates[$uid]) {
        $dates[$uid] = $date;
      }
      $stats['matched']++;
    }
    return $dates;
  }

  /**
   * Account linked to a Chargebee customer id.
   */
  protected function uidForCustomer(string $customer_id): ?int {
    if ($customer_id === '') {
      return NULL;
    }
    $uid = $this->database->select('user__field_user_chargebee_id', 'c')
      ->fields('c', ['entity_id'])
      ->condition('field_user_chargebee_id_value', $customer_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $uid !== FALSE ? (int) $uid : NULL;
  }

}
