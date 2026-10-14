<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds people who probably have two CiviCRM contacts or two website accounts.
 *
 * A second record splits a person's history: an event-only contact created by
 * a class registration never joins up with the contact behind their website
 * account, so event-to-member conversion is undercounted and staff see half
 * the story. On 2026-10-14 live had about 31 such contacts and 9 duplicate
 * account pairs created in the previous year.
 *
 * This service only reads. It never merges, blocks or edits anything; each
 * row is a suggestion for staff, with the record it recommends keeping.
 *
 * Rules:
 * - Contacts: same first and last name, plus a shared email, phone or postal
 *   code, or one of them has a website account and the other only event
 *   registrations. A shared email alone never matches: families register
 *   several people under one parent's email (contacts 10590-10592), and those
 *   are different people with different first names.
 * - Accounts: same first and last name, or the same email local part with a
 *   matching last name. Clusters where every account's first name equals its
 *   last name and none is a member are reported as likely spam.
 */
class DuplicateRecordFinder {

  /**
   * State key holding the last saved run: ran, rows, counts.
   */
  public const STATE_KEY = 'makerspace_member_success.duplicate_check';

  /**
   * State key mapping pair key => first time it was reported.
   */
  public const SEEN_KEY = 'makerspace_member_success.duplicate_check_seen';

  /**
   * State key mapping pair key => [ts, uid] for "not a duplicate" decisions.
   */
  public const DISMISSED_KEY = 'makerspace_member_success.duplicate_check_dismissed';

  /**
   * Minimum gap between cron runs (weekly, with slack for cron timing).
   */
  protected const CRON_INTERVAL = 6 * 86400 + 12 * 3600;

  /**
   * Email local parts that say nothing about who owns the address.
   */
  protected const GENERIC_LOCAL_PARTS = [
    'info', 'hello', 'contact', 'admin', 'office', 'mail', 'email', 'sales',
    'support', 'test', 'none', 'noreply', 'makehaven', 'team', 'studio',
  ];

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * Runs the check from cron once a week and saves it for the staff page.
   */
  public function runFromCron(): void {
    $last = (int) (($this->state->get(self::STATE_KEY) ?? [])['ran'] ?? 0);
    if ($this->time->getRequestTime() - $last < self::CRON_INTERVAL) {
      return;
    }
    $this->runAndSave();
  }

  /**
   * Runs the check and stores the result for the staff page.
   *
   * @return array
   *   The saved run: ran, rows, counts, new.
   */
  public function runAndSave(): array {
    $now = (int) $this->time->getRequestTime();
    $rows = array_merge($this->findContactDuplicates(), $this->findAccountDuplicates());

    // First-seen dates let the page mark what is new since the last check.
    $seen = (array) ($this->state->get(self::SEEN_KEY) ?? []);
    $kept_seen = [];
    $new = 0;
    foreach ($rows as &$row) {
      if (!isset($seen[$row['key']])) {
        $new++;
      }
      $row['first_seen'] = (int) ($seen[$row['key']] ?? $now);
      $kept_seen[$row['key']] = $row['first_seen'];
    }
    unset($row);

    $run = [
      'ran' => $now,
      'rows' => $rows,
      'new' => $new,
    ];
    $this->state->set(self::STATE_KEY, $run);
    $this->state->set(self::SEEN_KEY, $kept_seen);

    $open = $this->openRows($run);
    if ($new > 0) {
      $this->logger->notice('Duplicate check: @open possible duplicate record(s) open, @new new this run.', [
        '@open' => count($open),
        '@new' => $new,
      ]);
    }
    return $run;
  }

  /**
   * The last saved run, or an empty array if the check has never run.
   */
  public function lastRun(): array {
    return (array) ($this->state->get(self::STATE_KEY) ?? []);
  }

  /**
   * Rows from a run that staff have not marked "not a duplicate".
   */
  public function openRows(?array $run = NULL): array {
    $run ??= $this->lastRun();
    $dismissed = (array) ($this->state->get(self::DISMISSED_KEY) ?? []);
    return array_values(array_filter(
      (array) ($run['rows'] ?? []),
      static fn(array $row): bool => !isset($dismissed[$row['key']]),
    ));
  }

  /**
   * Staff decided a pair is two different people (or needs no action).
   */
  public function dismiss(string $key, int $actor_uid): void {
    $dismissed = (array) ($this->state->get(self::DISMISSED_KEY) ?? []);
    $dismissed[$key] = ['ts' => (int) $this->time->getRequestTime(), 'uid' => $actor_uid];
    $this->state->set(self::DISMISSED_KEY, $dismissed);
  }

  /**
   * Likely duplicate CiviCRM contacts, one row per record to merge away.
   */
  public function findContactDuplicates(): array {
    // Group by name in PHP: a same-name self-join in SQL compares every
    // contact with every other (half a minute on 15k contacts).
    $groups = [];
    $result = $this->database->query("SELECT id, first_name, last_name FROM civicrm_contact
      WHERE is_deleted = 0 AND contact_type = 'Individual'
        AND TRIM(first_name) <> '' AND TRIM(last_name) <> ''");
    foreach ($result as $record) {
      $groups[static::nameKey((string) $record->first_name, (string) $record->last_name)][] = (int) $record->id;
    }
    $pairs = static::pairsFromGroups($groups);
    if ($pairs === []) {
      return [];
    }

    $ids = array_values(array_unique(array_merge(...array_values(array_filter($groups, static fn(array $g): bool => count($g) > 1)))));
    $emails = $this->valuesByContact("SELECT contact_id, LOWER(TRIM(email)) AS v FROM civicrm_email
      WHERE contact_id IN (:ids[]) AND TRIM(email) <> ''", $ids);
    $phones = $this->valuesByContact("SELECT contact_id, RIGHT(phone_numeric, 10) AS v FROM civicrm_phone
      WHERE contact_id IN (:ids[]) AND CHAR_LENGTH(phone_numeric) >= 7", $ids);
    $postals = $this->valuesByContact("SELECT contact_id, LEFT(TRIM(postal_code), 5) AS v FROM civicrm_address
      WHERE contact_id IN (:ids[]) AND TRIM(postal_code) <> ''", $ids);
    $shares = static fn(array $map, int $a, int $b): bool => array_intersect_key($map[$a] ?? [], $map[$b] ?? []) !== [];
    foreach ($pairs as &$pair) {
      $pair['email'] = $shares($emails, $pair['a'], $pair['b']);
      $pair['phone'] = $shares($phones, $pair['a'], $pair['b']);
      $pair['postal'] = $shares($postals, $pair['a'], $pair['b']);
    }
    unset($pair);
    $facts = $this->contactFacts($ids);

    $edges = [];
    foreach ($pairs as $pair) {
      $a = $facts[(int) $pair['a']] ?? NULL;
      $b = $facts[(int) $pair['b']] ?? NULL;
      if ($a === NULL || $b === NULL) {
        continue;
      }
      $reasons = static::contactPairReasons($a, $b, (bool) $pair['email'], (bool) $pair['phone'], (bool) $pair['postal']);
      if ($reasons !== []) {
        $edges[static::pairKey('c', $a['id'], $b['id'])] = $reasons;
      }
    }

    $rows = [];
    foreach (static::clusters($edges) as $members) {
      $cluster = array_map(static fn(int $id): array => $facts[$id], $members);
      usort($cluster, [static::class, 'compareContactKeepers']);
      $keep = array_shift($cluster);
      foreach ($cluster as $other) {
        $key = static::pairKey('c', $keep['id'], $other['id']);
        $reasons = $edges[$key] ?? ['same name; linked through another record in this group'];
        $both_accounts = $keep['uf_id'] !== NULL && $other['uf_id'] !== NULL;
        $rows[] = [
          'key' => $key,
          'type' => 'contact',
          'confidence' => static::contactConfidence($reasons),
          'name' => $keep['name'],
          'keep_id' => $keep['id'],
          'other_id' => $other['id'],
          'keep_uid' => $keep['uf_id'],
          'other_uid' => $other['uf_id'],
          'keep_label' => static::describeContact($keep),
          'other_label' => static::describeContact($other),
          'reasons' => $reasons,
          'recommendation' => $both_accounts
            ? sprintf('Both contacts have website accounts (%d and %d). Resolve the duplicate account first: CiviCRM keeps only one account link per contact.', $keep['uf_id'], $other['uf_id'])
            : sprintf('Merge contact %d into %d in CiviCRM.', $other['id'], $keep['id']),
        ];
      }
    }
    return $rows;
  }

  /**
   * Likely duplicate website accounts, one row per account to retire.
   */
  public function findAccountDuplicates(): array {
    $by_name = [];
    $by_local = [];
    $result = $this->database->query("SELECT u.uid, u.mail, f.field_first_name_value AS first, l.field_last_name_value AS last
      FROM users_field_data u
      LEFT JOIN user__field_first_name f ON f.entity_id = u.uid
      LEFT JOIN user__field_last_name l ON l.entity_id = u.uid
      WHERE u.uid > 1");
    foreach ($result as $record) {
      $first = trim((string) $record->first);
      $last = trim((string) $record->last);
      if ($first !== '' && $last !== '') {
        $by_name[static::nameKey($first, $last)][] = (int) $record->uid;
      }
      $local = mb_strtolower(trim((string) strstr((string) $record->mail, '@', TRUE)));
      if (mb_strlen($local) >= 4) {
        $by_local[$local][] = (int) $record->uid;
      }
    }

    $raw = [];
    foreach (static::pairsFromGroups($by_name) as $pair) {
      $raw[static::pairKey('u', $pair['a'], $pair['b'])]['name'] = TRUE;
    }
    foreach (static::pairsFromGroups($by_local) as $pair) {
      $raw[static::pairKey('u', $pair['a'], $pair['b'])]['local'] = TRUE;
    }
    if ($raw === []) {
      return [];
    }
    $uids = [];
    foreach (array_keys($raw) as $key) {
      [, $a, $b] = explode('-', $key);
      $uids[(int) $a] = TRUE;
      $uids[(int) $b] = TRUE;
    }
    $facts = $this->accountFacts(array_keys($uids));

    $now = (int) $this->time->getRequestTime();
    $edges = [];
    foreach ($raw as $key => $match) {
      [, $a, $b] = explode('-', $key);
      if (!isset($facts[(int) $a], $facts[(int) $b])) {
        continue;
      }
      $reasons = static::accountPairReasons($facts[(int) $a], $facts[(int) $b], !empty($match['name']), !empty($match['local']));
      if ($reasons !== [] && static::accountPairWorthReporting($facts[(int) $a], $facts[(int) $b], $reasons, $now)) {
        $edges[$key] = $reasons;
      }
    }

    $rows = [];
    foreach (static::clusters($edges) as $members) {
      $cluster = array_map(static fn(int $uid): array => $facts[$uid], $members);
      $spam = static::looksLikeSpamCluster($cluster);
      usort($cluster, [static::class, 'compareAccountKeepers']);
      $keep = array_shift($cluster);
      foreach ($cluster as $other) {
        // An account staff already blocked has been dealt with.
        if (!$other['status']) {
          continue;
        }
        $key = static::pairKey('u', $keep['uid'], $other['uid']);
        $reasons = $edges[$key] ?? ['linked through another account in this group'];
        $rows[] = [
          'key' => $key,
          'type' => 'account',
          'confidence' => $spam ? 'spam' : static::accountConfidence($reasons),
          'name' => $keep['name'],
          'keep_id' => $keep['uid'],
          'other_id' => $other['uid'],
          'keep_cid' => $keep['contact_id'],
          'other_cid' => $other['contact_id'],
          'keep_label' => static::describeAccount($keep),
          'other_label' => static::describeAccount($other),
          'reasons' => $reasons,
          'recommendation' => static::accountRecommendation($keep, $other, $spam),
        ];
      }
    }
    return $rows;
  }

  /**
   * Why two same-name contacts look like one person; empty if they do not.
   */
  public static function contactPairReasons(array $a, array $b, bool $email, bool $phone, bool $postal): array {
    $reasons = [];
    if ($email) {
      $reasons[] = 'same email';
    }
    if ($phone) {
      $reasons[] = 'same phone';
    }
    if ($postal) {
      $reasons[] = 'same postal code';
    }
    $a_event_only = $a['uf_id'] === NULL && $a['participants'] > 0;
    $b_event_only = $b['uf_id'] === NULL && $b['participants'] > 0;
    if (($a['uf_id'] !== NULL && $b_event_only) || ($b['uf_id'] !== NULL && $a_event_only)) {
      $reasons[] = 'one has a website account, the other only event registrations';
    }
    return $reasons;
  }

  /**
   * High when a contact detail matches; medium on name plus circumstance.
   */
  public static function contactConfidence(array $reasons): string {
    return (in_array('same email', $reasons, TRUE) || in_array('same phone', $reasons, TRUE)) ? 'high' : 'medium';
  }

  /**
   * Why two accounts look like one person; empty if they do not.
   */
  public static function accountPairReasons(array $a, array $b, bool $same_name, bool $same_local): array {
    $reasons = [];
    if ($same_name) {
      $reasons[] = 'same first and last name';
    }
    if ($same_local) {
      $local = mb_strtolower((string) strstr($a['mail'], '@', TRUE));
      $last_a = mb_strtolower(trim($a['last']));
      $last_b = mb_strtolower(trim($b['last']));
      $generic = in_array($local, self::GENERIC_LOCAL_PARTS, TRUE);
      $name_agrees = ($last_a !== '' && $last_a === $last_b)
        || (mb_strlen($last_a) >= 3 && str_contains($local, $last_a))
        || (mb_strlen($last_b) >= 3 && str_contains($local, $last_b));
      if (!$generic && $name_agrees) {
        $reasons[] = 'same email name (' . $local . '@…)';
      }
    }
    return $reasons;
  }

  /**
   * Drops account pairs that are noise for a weekly staff list.
   *
   * Two current members with a common name (Kevin Williams, Thomas Anderson
   * on 2026-10-14) are two people unless their emails agree too. A pair where
   * neither is a member and neither has been used in two years is history:
   * blocking either changes nothing, and their contacts are still listed in
   * the contact section if they look like one person.
   */
  public static function accountPairWorthReporting(array $a, array $b, array $reasons, int $now): bool {
    if ($a['member'] && $b['member'] && count($reasons) < 2) {
      return FALSE;
    }
    if (!$a['member'] && !$b['member']) {
      $last_used = max($a['access'], $b['access'], $a['created'], $b['created']);
      if ($last_used < $now - 2 * 365 * 86400) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * What staff should do about an extra account, in plain words.
   */
  public static function accountRecommendation(array $keep, array $other, bool $spam): string {
    if ($spam) {
      return 'Likely spam sign-ups (first name equals last name, no member). Block the accounts; there is nothing to merge.';
    }
    if ($other['member']) {
      return sprintf('Both accounts %d and %d are members. Ask the person which one they use before changing either; billing may be attached to both.', $keep['uid'], $other['uid']);
    }
    $merge = ($keep['contact_id'] && $other['contact_id'] && $keep['contact_id'] !== $other['contact_id'])
      ? sprintf(' and merge contact %d into %d', $other['contact_id'], $keep['contact_id'])
      : '';
    $billing = ($other['chargebee'] !== '' && $other['chargebee'] !== $keep['chargebee'])
      ? ' It has its own Chargebee customer: confirm nothing still bills it.'
      : '';
    return sprintf('Keep account %d. Check account %d for registrations, badges or store history, then block it%s.%s', $keep['uid'], $other['uid'], $merge, $billing);
  }

  /**
   * High when both the name and the email name match.
   */
  public static function accountConfidence(array $reasons): string {
    return count($reasons) >= 2 ? 'high' : 'medium';
  }

  /**
   * Bot sign-ups put one word in both name fields; none ever pays.
   */
  public static function looksLikeSpamCluster(array $accounts): bool {
    foreach ($accounts as $account) {
      $first = mb_strtolower(trim($account['first']));
      if ($account['member'] || $first === '' || $first !== mb_strtolower(trim($account['last']))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Orders contacts best keeper first: member account, any account, history.
   */
  public static function compareContactKeepers(array $a, array $b): int {
    return [$b['member'], $b['uf_id'] !== NULL, $b['history'], -$b['id']]
      <=> [$a['member'], $a['uf_id'] !== NULL, $a['history'], -$a['id']];
  }

  /**
   * Orders accounts best keeper first: member, active, billing, recent login.
   */
  public static function compareAccountKeepers(array $a, array $b): int {
    return [$b['member'], $b['status'], $b['chargebee'] !== '', $b['access'], -$b['uid']]
      <=> [$a['member'], $a['status'], $a['chargebee'] !== '', $a['access'], -$a['uid']];
  }

  /**
   * Groups linked pairs so a person with three records gets one keeper.
   *
   * @param array $edges
   *   Keyed by pairKey().
   *
   * @return int[][]
   *   Each cluster's ids, ascending.
   */
  public static function clusters(array $edges): array {
    $parent = [];
    $find = static function (int $x) use (&$parent, &$find): int {
      $parent[$x] ??= $x;
      if ($parent[$x] !== $x) {
        $parent[$x] = $find($parent[$x]);
      }
      return $parent[$x];
    };
    foreach (array_keys($edges) as $key) {
      [, $a, $b] = explode('-', $key);
      $parent[$find((int) $a)] = $find((int) $b);
    }
    $groups = [];
    foreach (array_keys($parent) as $id) {
      $groups[$find($id)][] = $id;
    }
    foreach ($groups as &$group) {
      sort($group);
    }
    unset($group);
    return array_values($groups);
  }

  /**
   * Normalizes a first and last name for grouping.
   */
  public static function nameKey(string $first, string $last): string {
    // Accent-insensitive, as MySQL's collation is: Desiree and Desirée are
    // the same person in two sign-ups.
    $clean = static function (string $v): string {
      $v = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($v)));
      if (class_exists(\Normalizer::class)) {
        $v = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($v, \Normalizer::FORM_D));
      }
      return $v;
    };
    return $clean($first) . '|' . $clean($last);
  }

  /**
   * Every pair inside each group of two or more ids.
   *
   * @return array
   *   List of ['a' => lower id, 'b' => higher id].
   */
  public static function pairsFromGroups(array $groups): array {
    $pairs = [];
    foreach ($groups as $group) {
      $group = array_values(array_unique($group));
      sort($group);
      $n = count($group);
      for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
          $pairs[] = ['a' => $group[$i], 'b' => $group[$j]];
        }
      }
    }
    return $pairs;
  }

  /**
   * Stable key for an unordered pair: "c-12-34" or "u-5-9".
   */
  public static function pairKey(string $type, int $a, int $b): string {
    return sprintf('%s-%d-%d', $type, min($a, $b), max($a, $b));
  }

  /**
   * One-line summary of a contact for staff.
   */
  public static function describeContact(array $c): string {
    $parts = [];
    $parts[] = $c['uf_id'] !== NULL
      ? sprintf('account %d%s', $c['uf_id'], $c['member'] ? ' (member)' : '')
      : 'no account';
    $parts[] = sprintf('%d event(s), %d contribution(s), %d membership(s)', $c['participants'], $c['contributions'], $c['memberships']);
    if ($c['created'] !== '') {
      $parts[] = 'created ' . substr($c['created'], 0, 10);
    }
    return implode('; ', $parts);
  }

  /**
   * One-line summary of an account for staff.
   */
  public static function describeAccount(array $u): string {
    $parts = [$u['member'] ? 'member' : 'not a member'];
    if (!$u['status']) {
      $parts[] = 'blocked';
    }
    $parts[] = $u['access'] > 0 ? 'last login ' . date('Y-m-d', $u['access']) : 'never logged in';
    $parts[] = 'created ' . date('Y-m-d', $u['created']);
    if ($u['chargebee'] !== '') {
      $parts[] = 'Chargebee linked';
    }
    return implode('; ', $parts);
  }

  /**
   * Runs a contact_id, v query in chunks: contact id => [value => TRUE].
   */
  protected function valuesByContact(string $sql, array $ids): array {
    $map = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
      foreach ($this->database->query($sql, [':ids[]' => $chunk]) as $record) {
        $map[(int) $record->contact_id][(string) $record->v] = TRUE;
      }
    }
    return $map;
  }

  /**
   * Facts used to pick a keeper, per contact id.
   */
  protected function contactFacts(array $ids): array {
    $facts = [];
    foreach (array_chunk($ids, 500) as $chunk) {
      $result = $this->database->query("SELECT c.id, c.display_name, c.created_date, uf.uf_id,
          (SELECT COUNT(*) FROM user__roles r WHERE r.entity_id = uf.uf_id AND r.roles_target_id = 'member') AS member,
          (SELECT COUNT(*) FROM civicrm_participant p WHERE p.contact_id = c.id AND p.is_test = 0) AS participants,
          (SELECT COUNT(*) FROM civicrm_contribution co WHERE co.contact_id = c.id AND co.is_test = 0) AS contributions,
          (SELECT COUNT(*) FROM civicrm_membership m WHERE m.contact_id = c.id AND m.is_test = 0) AS memberships
        FROM civicrm_contact c
        LEFT JOIN civicrm_uf_match uf ON uf.contact_id = c.id
        WHERE c.id IN (:ids[])", [':ids[]' => $chunk]);
      foreach ($result as $row) {
        $id = (int) $row->id;
        // A contact can have more than one uf_match row; the first is enough.
        if (isset($facts[$id])) {
          continue;
        }
        $facts[$id] = [
          'id' => $id,
          'name' => (string) $row->display_name,
          'created' => (string) ($row->created_date ?? ''),
          'uf_id' => $row->uf_id !== NULL ? (int) $row->uf_id : NULL,
          'member' => (int) $row->member > 0,
          'participants' => (int) $row->participants,
          'contributions' => (int) $row->contributions,
          'memberships' => (int) $row->memberships,
          'history' => (int) $row->participants + (int) $row->contributions + (int) $row->memberships,
        ];
      }
    }
    return $facts;
  }

  /**
   * Facts used to pick a keeper, per uid.
   */
  protected function accountFacts(array $uids): array {
    $facts = [];
    foreach (array_chunk($uids, 500) as $chunk) {
      $result = $this->database->query("SELECT u.uid, u.name, u.mail, u.status, u.created, u.access,
          f.field_first_name_value AS first, l.field_last_name_value AS last,
          TRIM(cb.field_user_chargebee_id_value) AS chargebee,
          (SELECT COUNT(*) FROM user__roles r WHERE r.entity_id = u.uid AND r.roles_target_id = 'member') AS member,
          (SELECT MIN(uf.contact_id) FROM civicrm_uf_match uf WHERE uf.uf_id = u.uid) AS contact_id
        FROM users_field_data u
        LEFT JOIN user__field_first_name f ON f.entity_id = u.uid
        LEFT JOIN user__field_last_name l ON l.entity_id = u.uid
        LEFT JOIN user__field_user_chargebee_id cb ON cb.entity_id = u.uid
        WHERE u.uid IN (:uids[])", [':uids[]' => $chunk]);
      foreach ($result as $row) {
        $first = trim((string) ($row->first ?? ''));
        $last = trim((string) ($row->last ?? ''));
        $facts[(int) $row->uid] = [
          'uid' => (int) $row->uid,
          'name' => trim($first . ' ' . $last) ?: (string) $row->name,
          'first' => $first,
          'last' => $last,
          'mail' => (string) $row->mail,
          'status' => (bool) $row->status,
          'created' => (int) $row->created,
          'access' => (int) $row->access,
          'chargebee' => (string) ($row->chargebee ?? ''),
          'member' => (int) $row->member > 0,
          'contact_id' => $row->contact_id !== NULL ? (int) $row->contact_id : NULL,
        ];
      }
    }
    return $facts;
  }

}
