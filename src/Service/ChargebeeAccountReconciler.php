<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * Role staff review new members under ("pending member").
   */
  public const PENDING_ROLE = 'member_pending_approval';

  /**
   * Business days staff have to approve a pending member before it is a miss.
   *
   * Paying, then waiting for staff to check Member, is the normal join flow.
   * Flagging those after six hours emailed Kate about people already being
   * handled (Charli Zaretsky, Nate Merrill, Claire Andersen; 2026-10).
   */
  public const PENDING_BUSINESS_DAYS = 2;

  /**
   * Minimum gap between cron runs.
   */
  protected const CRON_INTERVAL = 20 * 3600;

  /**
   * Suggested accounts may predate the subscription by this much.
   */
  protected const SUGGEST_LOOKBACK_SECONDS = 30 * 86400;

  /**
   * Shared email local-part prefix that counts as "similar email".
   */
  public const SUGGEST_EMAIL_PREFIX = 8;

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
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
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
      if ($kind === self::KIND_NO_ROLE
        && $this->hasRole($uid, self::PENDING_ROLE)
        && $now < static::addBusinessDays((int) ($sub['created_at'] ?? 0), self::PENDING_BUSINESS_DAYS)) {
        // Awaiting staff approval and still on time: not listed, so an open
        // row resolves and comes back (with an email) only once it is late.
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
   * Adds weekdays (Monday to Friday) to a timestamp, keeping the clock time.
   *
   * Holidays are not skipped.
   */
  public static function addBusinessDays(int $timestamp, int $days): int {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    while ($days > 0) {
      $date = $date->modify('+1 day');
      if ((int) $date->format('N') <= 5) {
        $days--;
      }
    }
    return $date->getTimestamp();
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
   *
   * The note is kept on the row even when it was also logged in CiviCRM, so
   * people with no CiviCRM contact still leave a record.
   */
  public function dismiss(string $subscription_id, int $actor_uid, string $note = ''): void {
    $this->database->update('ms_billing_mismatch')
      ->fields([
        'dismissed_ts' => (int) $this->time->getRequestTime(),
        'dismissed_by' => $actor_uid,
        'dismiss_note' => $note !== '' ? $note : NULL,
      ])
      ->condition('subscription_id', $subscription_id)
      ->execute();
  }

  /**
   * Accounts that might belong to a no_account row's payer.
   *
   * People often pay first and create their account later under a different
   * email (Valeria Perez, 2026-10: paid as valeriaperez12344321@, signed up
   * six days later as valeriaperezlovescats@). Nothing linked the two, so
   * staff had to ask around. This only SUGGESTS: candidates are accounts
   * created no earlier than 30 days before the subscription, not linked to
   * any Chargebee customer, whose name matches the payer's (Drupal user
   * fields or the CiviCRM contact) or whose email shares a long prefix.
   * Linking stays a staff action.
   *
   * @param array $row
   *   An ms_billing_mismatch row.
   * @param int $limit
   *   Maximum suggestions.
   *
   * @return array
   *   List of ['uid', 'mail', 'name', 'reason'].
   */
  public function suggestAccounts(array $row, int $limit = 3): array {
    if (($row['kind'] ?? '') !== self::KIND_NO_ACCOUNT || !empty($row['uid'])) {
      return [];
    }
    $cutoff = (int) ($row['sub_created'] ?? 0) - self::SUGGEST_LOOKBACK_SECONDS;

    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'mail', 'created']);
    $query->condition('u.uid', 0, '>');
    $query->condition('u.created', $cutoff, '>=');
    $query->leftJoin('user__field_user_chargebee_id', 'cb', 'cb.entity_id = u.uid');
    $query->where("(cb.field_user_chargebee_id_value IS NULL OR cb.field_user_chargebee_id_value = '')");
    $schema = $this->database->schema();
    if ($schema->tableExists('user__field_first_name')) {
      $query->leftJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid');
      $query->addField('fn', 'field_first_name_value', 'first_name');
    }
    if ($schema->tableExists('user__field_last_name')) {
      $query->leftJoin('user__field_last_name', 'ln', 'ln.entity_id = u.uid');
      $query->addField('ln', 'field_last_name_value', 'last_name');
    }
    if ($schema->tableExists('civicrm_uf_match') && $schema->tableExists('civicrm_contact')) {
      $query->leftJoin('civicrm_uf_match', 'ufm', 'ufm.uf_id = u.uid');
      $query->leftJoin('civicrm_contact', 'cc', 'cc.id = ufm.contact_id');
      $query->addField('cc', 'first_name', 'civi_first_name');
      $query->addField('cc', 'last_name', 'civi_last_name');
    }
    $query->orderBy('u.created', 'DESC');

    $suggestions = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
      $uid = (int) $candidate['uid'];
      if (isset($suggestions[$uid])) {
        continue;
      }
      $reason = static::matchReason((string) ($row['name'] ?? ''), (string) ($row['email'] ?? ''), $candidate);
      if ($reason === NULL) {
        continue;
      }
      $name = trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''));
      if ($name === '') {
        $name = trim(($candidate['civi_first_name'] ?? '') . ' ' . ($candidate['civi_last_name'] ?? ''));
      }
      $suggestions[$uid] = [
        'uid' => $uid,
        'mail' => (string) $candidate['mail'],
        'name' => $name,
        'reason' => $reason,
      ];
      if (count($suggestions) >= $limit) {
        break;
      }
    }
    return array_values($suggestions);
  }

  /**
   * Why a candidate account might be the payer's, or NULL.
   *
   * @param string $payer_name
   *   Chargebee "First Last".
   * @param string $payer_email
   *   Chargebee email.
   * @param array $candidate
   *   Keys: mail, first_name, last_name, civi_first_name, civi_last_name.
   */
  public static function matchReason(string $payer_name, string $payer_email, array $candidate): ?string {
    $payer = static::normalizeName($payer_name);
    if ($payer !== '') {
      foreach ([['first_name', 'last_name'], ['civi_first_name', 'civi_last_name']] as [$first, $last]) {
        $candidate_name = static::normalizeName(($candidate[$first] ?? '') . ' ' . ($candidate[$last] ?? ''));
        if ($candidate_name !== '' && $candidate_name === $payer) {
          return 'same name';
        }
      }
    }
    $payer_local = mb_strtolower(strstr($payer_email, '@', TRUE) ?: '');
    $candidate_local = mb_strtolower(strstr((string) ($candidate['mail'] ?? ''), '@', TRUE) ?: '');
    if ($payer_local !== '' && $candidate_local !== '' && static::commonPrefixLength($payer_local, $candidate_local) >= self::SUGGEST_EMAIL_PREFIX) {
      return 'similar email';
    }
    return NULL;
  }

  /**
   * Lower-cases a name and strips whitespace.
   */
  public static function normalizeName(string $name): string {
    return (string) preg_replace('/\s+/u', '', mb_strtolower($name));
  }

  /**
   * Length of the shared leading run of two strings.
   */
  public static function commonPrefixLength(string $a, string $b): int {
    $max = min(mb_strlen($a), mb_strlen($b));
    $i = 0;
    while ($i < $max && mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) {
      $i++;
    }
    return $i;
  }

  /**
   * Links an account to a no_account row's Chargebee customer.
   *
   * Re-checks everything at submit time, because the queue page may be stale:
   * the row must still be an open no_account row, the account must exist and
   * hold no Chargebee id, and no other account may hold this customer.
   *
   * @return string|null
   *   NULL on success, else a reason the link was refused.
   */
  public function linkAccount(string $subscription_id, int $uid, int $actor_uid): ?string {
    $row = $this->database->select('ms_billing_mismatch', 'm')
      ->fields('m')
      ->condition('subscription_id', $subscription_id)
      ->isNull('resolved_ts')
      ->execute()
      ->fetchAssoc();
    if (!$row || $row['kind'] !== self::KIND_NO_ACCOUNT || !empty($row['uid'])) {
      return 'This row is no longer an open "no account" row.';
    }
    $customer_id = (string) $row['customer_id'];
    if ($customer_id === '') {
      return 'This row has no Chargebee customer id.';
    }
    $existing = $this->uidForCustomer($customer_id);
    if ($existing !== NULL) {
      return sprintf('Account %d is already linked to this Chargebee customer.', $existing);
    }
    $user = $this->entityTypeManager?->getStorage('user')->load($uid);
    if (!$user || !$user->hasField('field_user_chargebee_id')) {
      return 'That account does not exist.';
    }
    if (trim((string) $user->get('field_user_chargebee_id')->value) !== '') {
      return 'That account is already linked to a different Chargebee customer.';
    }

    $user->set('field_user_chargebee_id', $customer_id);
    $user->save();
    // The next daily check reclassifies the row (resolved, or "missing the
    // member role"); until then show it against the linked account.
    $this->database->update('ms_billing_mismatch')
      ->fields(['uid' => $uid])
      ->condition('subscription_id', $subscription_id)
      ->execute();
    $this->logger->notice('Billing queue: user @actor linked account @uid to Chargebee customer @cid (subscription @sub, payer @email).', [
      '@actor' => $actor_uid,
      '@uid' => $uid,
      '@cid' => $customer_id,
      '@sub' => $subscription_id,
      '@email' => (string) ($row['email'] ?? ''),
    ]);
    return NULL;
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
      $fields['dismiss_note'] = NULL;
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
    $body[] = 'This check runs once a day. Each person is reported once. People marked "pending member" are only reported after ' . self::PENDING_BUSINESS_DAYS . ' business days without approval. Use "No action needed" (with a note) for anyone who needs nothing done.';

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
    return $this->fetchSubscriptions(self::PAYING_STATUSES);
  }

  /**
   * Lists every subscription in the given Chargebee statuses.
   *
   * Read-only. Throws on any failed page, so a caller never acts on a
   * partial listing. Also used by PauseScheduleSync for paused subscriptions.
   *
   * @param string[] $statuses
   *   Chargebee subscription statuses, e.g. ['paused'].
   */
  public function fetchSubscriptions(array $statuses): array {
    [$base, $auth] = $this->authContext();
    $rows = [];
    $offset = NULL;
    $pages = 0;
    do {
      $query = ['limit' => 100, 'status[in]' => json_encode(array_values($statuses))];
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
    return $this->hasRole($uid, 'member');
  }

  /**
   * Whether an account has a role.
   */
  protected function hasRole(int $uid, string $role): bool {
    return (bool) $this->database->select('user__roles', 'r')
      ->fields('r', ['entity_id'])
      ->condition('entity_id', $uid)
      ->condition('roles_target_id', $role)
      ->execute()
      ->fetchField();
  }

}
