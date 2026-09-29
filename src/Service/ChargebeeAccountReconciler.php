<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds people paying in Chargebee whom the website does not treat as members.
 *
 * Accounts are created after payment, and the webhook links a subscription to
 * an account by Chargebee customer id. When that hand-off fails (a 503 after
 * checkout, a rejoin under a new customer id, a household add-on for someone
 * who never made an account) the person keeps paying and nothing on the site
 * notices: the snapshot queues only see people who have an account. On
 * 2026-09-29 five such subscriptions were found by hand, the oldest paying
 * since 2024.
 *
 * This service lists every paying subscription, matches it to an account, and
 * records the ones that do not line up in ms_billing_mismatch for staff. It
 * only reads from Chargebee and never changes an account; fixing each row is a
 * staff decision.
 */
class ChargebeeAccountReconciler {

  /**
   * Subscription statuses that mean the person is paying for access.
   */
  public const PAYING_STATUSES = ['active', 'in_trial', 'non_renewing'];

  /**
   * No account matches the subscription's customer id or email.
   */
  public const KIND_NO_ACCOUNT = 'no_account';

  /**
   * An account has the subscription's email but is linked to another customer.
   */
  public const KIND_RELINK = 'relink';

  /**
   * The linked account is missing the member role.
   */
  public const KIND_NO_ROLE = 'no_member_role';

  /**
   * Subscriptions younger than this are still inside the normal join flow.
   */
  protected const GRACE_SECONDS = 6 * 3600;

  /**
   * Minimum gap between cron runs.
   */
  protected const CRON_INTERVAL = 20 * 3600;

  /**
   * State key holding the last run summary.
   */
  public const STATE_KEY = 'makerspace_member_success.billing_reconcile';

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly MailManagerInterface $mailManager,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * Runs the reconciliation from cron, once a day, on live only.
   *
   * Non-live environments hold a copy of live's database and the live
   * Chargebee key; running there would compare live billing with a stale user
   * table and email staff about it.
   */
  public function runFromCron(): void {
    if (getenv('PANTHEON_ENVIRONMENT') !== 'live') {
      return;
    }
    $last = (int) (($this->state->get(self::STATE_KEY) ?? [])['ran'] ?? 0);
    if ($this->time->getRequestTime() - $last < self::CRON_INTERVAL) {
      return;
    }
    $this->reconcile(TRUE);
  }

  /**
   * Compares every paying subscription with website accounts.
   *
   * @param bool $notify
   *   Email staff about rows that are new since the last run.
   *
   * @return array
   *   Keys: scanned, open, new, resolved, error.
   */
  public function reconcile(bool $notify = FALSE): array {
    $now = (int) $this->time->getRequestTime();
    $stats = ['scanned' => 0, 'open' => 0, 'new' => 0, 'resolved' => 0, 'error' => NULL];

    try {
      $subscriptions = $this->fetchPayingSubscriptions();
    }
    catch (\Throwable $e) {
      // Fail closed: a partial or failed listing must never resolve rows.
      $stats['error'] = $e->getMessage();
      $this->logger->error('Billing reconciliation could not list Chargebee subscriptions: @msg', ['@msg' => $e->getMessage()]);
      return $stats;
    }
    if ($subscriptions === []) {
      $stats['error'] = 'Chargebee returned no paying subscriptions';
      $this->logger->error('Billing reconciliation stopped: Chargebee returned no paying subscriptions.');
      return $stats;
    }

    $seen = [];
    $new_rows = [];
    foreach ($subscriptions as $row) {
      $sub = $row['subscription'] ?? [];
      $customer = $row['customer'] ?? [];
      $stats['scanned']++;
      if ((int) ($sub['created_at'] ?? 0) > $now - self::GRACE_SECONDS) {
        continue;
      }

      $customer_id = (string) ($sub['customer_id'] ?? '');
      $email = mb_strtolower(trim((string) ($customer['email'] ?? '')));
      $uid_by_customer = $this->uidForCustomer($customer_id);
      $uid_by_email = $email !== '' ? $this->uidForEmail($email) : NULL;
      $uid = $uid_by_customer ?? $uid_by_email;
      $kind = static::classify($uid_by_customer, $uid_by_email, $uid !== NULL && $this->hasMemberRole($uid));
      if ($kind === NULL) {
        continue;
      }

      $record = [
        'subscription_id' => (string) $sub['id'],
        'customer_id' => $customer_id,
        'email' => $email !== '' ? $email : NULL,
        'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: NULL,
        'plan_id' => (string) ($sub['plan_id'] ?? ($sub['subscription_items'][0]['item_price_id'] ?? '')) ?: NULL,
        'sub_status' => (string) ($sub['status'] ?? ''),
        'sub_created' => (int) ($sub['created_at'] ?? 0),
        'kind' => $kind,
        'uid' => $uid,
      ];
      $seen[] = $record['subscription_id'];
      if ($this->upsert($record, $now)) {
        $new_rows[] = $record;
      }
    }

    // Anything open that no longer mismatches has been fixed.
    $resolve = $this->database->update('ms_billing_mismatch')
      ->fields(['resolved_ts' => $now])
      ->isNull('resolved_ts');
    if ($seen !== []) {
      $resolve->condition('subscription_id', $seen, 'NOT IN');
    }
    $stats['resolved'] = (int) $resolve->execute();

    $stats['new'] = count($new_rows);
    $stats['open'] = count($this->getOpen());
    $this->state->set(self::STATE_KEY, [
      'ran' => $now,
      'scanned' => $stats['scanned'],
      'open' => $stats['open'],
    ]);

    if ($notify && $new_rows !== []) {
      $this->notifyStaff($new_rows);
    }
    if ($stats['new'] > 0 || $stats['resolved'] > 0) {
      $this->logger->notice('Billing reconciliation: @scanned subscriptions, @new new mismatch(es), @resolved resolved, @open open.', [
        '@scanned' => $stats['scanned'],
        '@new' => $stats['new'],
        '@resolved' => $stats['resolved'],
        '@open' => $stats['open'],
      ]);
    }

    return $stats;
  }

  /**
   * Decides whether a paying subscription lines up with a member account.
   *
   * @param int|null $uid_by_customer
   *   Account whose Chargebee id is the subscription's customer.
   * @param int|null $uid_by_email
   *   Account with the customer's email.
   * @param bool $has_member_role
   *   Whether the matched account has the member role.
   *
   * @return string|null
   *   A KIND_* constant, or NULL when everything lines up.
   */
  public static function classify(?int $uid_by_customer, ?int $uid_by_email, bool $has_member_role): ?string {
    if ($uid_by_customer === NULL && $uid_by_email === NULL) {
      return self::KIND_NO_ACCOUNT;
    }
    if ($uid_by_customer === NULL) {
      // The webhook finds accounts by customer id, so this account never gets
      // the subscription's role changes until it is relinked.
      return self::KIND_RELINK;
    }
    return $has_member_role ? NULL : self::KIND_NO_ROLE;
  }

  /**
   * Says what staff should do about a row, in plain words.
   */
  public static function describe(string $kind, ?string $plan_id): string {
    $household = $plan_id !== NULL && str_contains($plan_id, 'household');
    return match ($kind) {
      self::KIND_NO_ACCOUNT => $household
        ? 'Household add-on with no account for the extra person. Ask the payer who it is for, then send that person an account invite.'
        : 'Paying, but has no website account. Create or invite the account, then link it to this Chargebee customer.',
      self::KIND_RELINK => 'Has an account under this email, linked to a different Chargebee customer (often a rejoin). Set the account\'s Chargebee ID to this customer.',
      self::KIND_NO_ROLE => 'Linked account is missing the member role. Check the plan, then re-sync the account.',
      default => '',
    };
  }

  /**
   * Whether Chargebee has a paying subscription for this email.
   *
   * @return bool|null
   *   NULL when Chargebee cannot be asked, so callers can fail closed.
   */
  public function hasPayingSubscription(string $email): ?bool {
    $email = trim($email);
    if ($email === '') {
      return FALSE;
    }
    try {
      [$base, $auth] = $this->authContext();
      $customers = $this->get($base, $auth, '/api/v2/customers', ['email[is]' => $email, 'limit' => 10]);
      foreach ($customers['list'] ?? [] as $row) {
        $customer_id = (string) ($row['customer']['id'] ?? '');
        if ($customer_id === '') {
          continue;
        }
        $subs = $this->get($base, $auth, '/api/v2/subscriptions', [
          'customer_id[is]' => $customer_id,
          'status[in]' => json_encode(self::PAYING_STATUSES),
          'limit' => 10,
        ]);
        if (!empty($subs['list'])) {
          return TRUE;
        }
      }
      return FALSE;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not check Chargebee for @email: @msg', ['@email' => $email, '@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Open rows for the staff queue, oldest subscription first.
   */
  public function getOpen(bool $include_dismissed = FALSE): array {
    $query = $this->database->select('ms_billing_mismatch', 'm')
      ->fields('m')
      ->isNull('resolved_ts')
      ->orderBy('sub_created');
    if (!$include_dismissed) {
      $query->isNull('dismissed_ts');
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Staff decided a row needs no action (for example a comped test account).
   */
  public function dismiss(string $subscription_id, int $actor_uid): void {
    $this->database->update('ms_billing_mismatch')
      ->fields(['dismissed_ts' => (int) $this->time->getRequestTime(), 'dismissed_by' => $actor_uid])
      ->condition('subscription_id', $subscription_id)
      ->execute();
  }

  /**
   * Last run summary: ran, scanned, open.
   */
  public function lastRun(): array {
    return (array) ($this->state->get(self::STATE_KEY) ?? []);
  }

  /**
   * Inserts or refreshes a row. Returns TRUE when staff have not seen it yet.
   */
  protected function upsert(array $record, int $now): bool {
    $existing = $this->database->select('ms_billing_mismatch', 'm')
      ->fields('m', ['kind', 'resolved_ts', 'dismissed_ts'])
      ->condition('subscription_id', $record['subscription_id'])
      ->execute()
      ->fetchAssoc();

    if (!$existing) {
      $this->database->insert('ms_billing_mismatch')
        ->fields($record + ['first_seen' => $now, 'last_seen' => $now])
        ->execute();
      return TRUE;
    }

    $fields = $record + ['last_seen' => $now, 'resolved_ts' => NULL];
    // A dismissal covers the problem staff looked at, not a different one.
    $changed = $existing['kind'] !== $record['kind'];
    if ($changed) {
      $fields['dismissed_ts'] = NULL;
      $fields['dismissed_by'] = NULL;
    }
    $this->database->update('ms_billing_mismatch')
      ->fields($fields)
      ->condition('subscription_id', $record['subscription_id'])
      ->execute();

    // Tell staff when a fixed row breaks again or the problem changed, but not
    // about one they already dismissed.
    $still_dismissed = !$changed && $existing['dismissed_ts'] !== NULL;
    return !$still_dismissed && ($existing['resolved_ts'] !== NULL || $changed);
  }

  /**
   * Emails the configured staff list about new rows.
   */
  protected function notifyStaff(array $rows): void {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $to = OnboardingLeadTracker::normalizeBcc((string) ($config->get('billing_mismatch_notify') ?? ''));
    if ($to === '') {
      return;
    }
    $base = rtrim(trim((string) ($config->get('lead_site_base_url') ?: 'https://www.makehaven.org')), '/');

    $body = [
      count($rows) === 1
        ? 'One person is paying in Chargebee but is not set up as a member on the website.'
        : count($rows) . ' people are paying in Chargebee but are not set up as members on the website.',
      '',
    ];
    foreach ($rows as $row) {
      $body[] = sprintf('- %s <%s>, plan %s', $row['name'] ?? '(no name)', $row['email'] ?? 'no email', $row['plan_id'] ?? '?');
      $body[] = '  ' . static::describe($row['kind'], $row['plan_id']);
    }
    $body[] = '';
    $body[] = 'Queue: ' . $base . '/admin/makerspace/member-success/billing';
    $body[] = '';
    $body[] = 'This check runs once a day. Each person is reported once; dismiss anyone who needs no action.';

    $result = $this->mailManager->mail('makerspace_member_success', 'billing_mismatch', $to, 'en', [
      'subject' => count($rows) === 1
        ? 'Paying member not set up on the website'
        : count($rows) . ' paying members not set up on the website',
      'body' => implode("\n", $body),
    ], NULL, TRUE);
    if (empty($result['result'])) {
      $this->logger->warning('Billing mismatch notice to staff failed to send.');
    }
  }

  /**
   * Lists every paying subscription with its customer.
   */
  protected function fetchPayingSubscriptions(): array {
    [$base, $auth] = $this->authContext();
    $rows = [];
    $offset = NULL;
    $pages = 0;
    do {
      $query = ['limit' => 100, 'status[in]' => json_encode(self::PAYING_STATUSES)];
      if ($offset !== NULL) {
        $query['offset'] = $offset;
      }
      $page = $this->get($base, $auth, '/api/v2/subscriptions', $query);
      foreach ($page['list'] ?? [] as $row) {
        $rows[] = $row;
      }
      $offset = $page['next_offset'] ?? NULL;
      if (++$pages > 100) {
        throw new \RuntimeException('Chargebee pagination did not end after 100 pages');
      }
    } while ($offset !== NULL);
    return $rows;
  }

  /**
   * GET with backoff on rate limits.
   */
  protected function get(string $base, string $auth, string $path, array $query): array {
    $attempt = 0;
    while (TRUE) {
      try {
        $response = $this->httpClient->request('GET', $base . $path, [
          'headers' => ['Authorization' => $auth],
          'query' => $query,
          'timeout' => 60,
        ]);
        $payload = json_decode((string) $response->getBody(), TRUE);
        if (!is_array($payload)) {
          throw new \RuntimeException('Chargebee returned an unreadable response');
        }
        return $payload;
      }
      catch (\Throwable $e) {
        $attempt++;
        $rate_limited = (int) $e->getCode() === 429 || str_contains($e->getMessage(), '429');
        if (!$rate_limited || $attempt >= 5) {
          throw $e;
        }
        usleep(min(8, 1 << ($attempt - 1)) * 1000000);
      }
    }
  }

  /**
   * Chargebee base URL and Authorization header from chargebee_portal config.
   */
  protected function authContext(): array {
    $chargebee = $this->configFactory->get('chargebee_portal.settings');
    $key = trim((string) $chargebee->get('live_api_key'));
    $parsed = parse_url(trim((string) $chargebee->get('live_portal_url')));
    if ($key === '' || !isset($parsed['scheme'], $parsed['host'])) {
      throw new \RuntimeException('Chargebee is not configured');
    }
    return [$parsed['scheme'] . '://' . $parsed['host'], 'Basic ' . base64_encode($key . ':')];
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

  /**
   * Account with this email.
   */
  protected function uidForEmail(string $email): ?int {
    $uid = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->where('LOWER(u.mail) = :mail', [':mail' => $email])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $uid !== FALSE ? (int) $uid : NULL;
  }

  /**
   * Whether an account has the member role.
   */
  protected function hasMemberRole(int $uid): bool {
    return (bool) $this->database->select('user__roles', 'r')
      ->fields('r', ['entity_id'])
      ->condition('entity_id', $uid)
      ->condition('roles_target_id', 'member')
      ->execute()
      ->fetchField();
  }

}
