<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;

/**
 * Loads what staff need to decide whether a listed pair is one person.
 *
 * The weekly check saves only why it matched ("same email"); staff could not
 * see the email, so they could not judge it. This reads the current values
 * for every record on the page in a few batched queries, at page view time,
 * so a record edited since the check shows as it is now.
 */
class DuplicateEvidence {

  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * Evidence for every record the rows mention.
   *
   * @return array{contacts: array<int, array>, accounts: array<int, array>}
   *   Contact evidence keyed by contact ID, account evidence keyed by uid.
   */
  public function load(array $rows): array {
    $cids = [];
    $uids = [];
    foreach ($rows as $row) {
      if ($row['type'] === 'contact') {
        $cids[(int) $row['keep_id']] = TRUE;
        $cids[(int) $row['other_id']] = TRUE;
        foreach (['keep_uid', 'other_uid'] as $k) {
          if (!empty($row[$k])) {
            $uids[(int) $row[$k]] = TRUE;
          }
        }
      }
      else {
        $uids[(int) $row['keep_id']] = TRUE;
        $uids[(int) $row['other_id']] = TRUE;
        foreach (['keep_cid', 'other_cid'] as $k) {
          if (!empty($row[$k])) {
            $cids[(int) $row[$k]] = TRUE;
          }
        }
      }
    }
    return [
      'contacts' => $cids ? $this->contacts(array_keys($cids)) : [],
      'accounts' => $uids ? $this->accounts(array_keys($uids)) : [],
    ];
  }

  /**
   * Contact evidence, keyed by contact ID.
   */
  protected function contacts(array $ids): array {
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
      $args = [':ids[]' => $chunk];
      foreach ($this->database->query("SELECT c.id, c.display_name, c.birth_date, c.created_date, c.is_deleted,
          (SELECT MIN(uf.uf_id) FROM civicrm_uf_match uf WHERE uf.contact_id = c.id) AS uid
        FROM civicrm_contact c WHERE c.id IN (:ids[])", $args) as $r) {
        $out[(int) $r->id] = [
          'id' => (int) $r->id,
          'name' => (string) $r->display_name,
          'deleted' => (bool) $r->is_deleted,
          'birth_date' => substr((string) ($r->birth_date ?? ''), 0, 10),
          'created' => substr((string) ($r->created_date ?? ''), 0, 10),
          'uid' => $r->uid !== NULL ? (int) $r->uid : NULL,
          'emails' => [],
          'phones' => [],
          'zips' => [],
          'streets' => [],
          'events' => 0,
          'last_event' => '',
          'payments' => 0,
          'paid' => 0.0,
          'last_payment' => '',
          'memberships' => 0,
        ];
      }
      foreach ($this->database->query("SELECT contact_id, LOWER(TRIM(email)) AS v FROM civicrm_email
        WHERE contact_id IN (:ids[]) AND TRIM(email) <> '' ORDER BY is_primary DESC", $args) as $r) {
        $out[(int) $r->contact_id]['emails'][] = (string) $r->v;
      }
      foreach ($this->database->query("SELECT contact_id, phone, phone_numeric FROM civicrm_phone
        WHERE contact_id IN (:ids[]) AND CHAR_LENGTH(phone_numeric) >= 7 ORDER BY is_primary DESC", $args) as $r) {
        $out[(int) $r->contact_id]['phones'][] = static::formatPhone((string) $r->phone_numeric, (string) $r->phone);
      }
      foreach ($this->database->query("SELECT contact_id, TRIM(street_address) AS street, LEFT(TRIM(postal_code), 5) AS zip FROM civicrm_address
        WHERE contact_id IN (:ids[]) ORDER BY is_primary DESC", $args) as $r) {
        if ((string) $r->zip !== '') {
          $out[(int) $r->contact_id]['zips'][] = static::normalizeZip((string) $r->zip);
        }
        if ((string) $r->street !== '') {
          $out[(int) $r->contact_id]['streets'][] = (string) $r->street;
        }
      }
      foreach ($this->database->query("SELECT p.contact_id, COUNT(*) AS n,
          SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(LEFT(e.start_date, 10), ' ', e.title) ORDER BY e.start_date DESC SEPARATOR '\n'), '\n', 1) AS last
        FROM civicrm_participant p JOIN civicrm_event e ON e.id = p.event_id
        WHERE p.contact_id IN (:ids[]) AND p.is_test = 0 GROUP BY p.contact_id", $args) as $r) {
        $out[(int) $r->contact_id]['events'] = (int) $r->n;
        // CiviCRM stores event titles HTML-encoded ("Dough &amp; Donations").
        $out[(int) $r->contact_id]['last_event'] = Html::decodeEntities((string) $r->last);
      }
      foreach ($this->database->query("SELECT contact_id, COUNT(*) AS n, SUM(total_amount) AS paid, LEFT(MAX(receive_date), 10) AS last
        FROM civicrm_contribution WHERE contact_id IN (:ids[]) AND is_test = 0 GROUP BY contact_id", $args) as $r) {
        $out[(int) $r->contact_id]['payments'] = (int) $r->n;
        $out[(int) $r->contact_id]['paid'] = (float) $r->paid;
        $out[(int) $r->contact_id]['last_payment'] = (string) $r->last;
      }
      foreach ($this->database->query("SELECT contact_id, COUNT(*) AS n FROM civicrm_membership
        WHERE contact_id IN (:ids[]) AND is_test = 0 GROUP BY contact_id", $args) as $r) {
        $out[(int) $r->contact_id]['memberships'] = (int) $r->n;
      }
    }
    foreach ($out as &$c) {
      foreach (['emails', 'phones', 'zips', 'streets'] as $k) {
        $c[$k] = array_values(array_unique($c[$k]));
      }
    }
    unset($c);
    return $out;
  }

  /**
   * Account evidence, keyed by uid.
   */
  protected function accounts(array $uids): array {
    $out = [];
    foreach (array_chunk($uids, 500) as $chunk) {
      $args = [':uids[]' => $chunk];
      foreach ($this->database->query("SELECT u.uid, u.name, u.mail, u.status, u.created, u.access,
          f.field_first_name_value AS first, l.field_last_name_value AS last,
          TRIM(cb.field_user_chargebee_id_value) AS chargebee,
          (SELECT MIN(uf.contact_id) FROM civicrm_uf_match uf WHERE uf.uf_id = u.uid) AS contact_id
        FROM users_field_data u
        LEFT JOIN user__field_first_name f ON f.entity_id = u.uid
        LEFT JOIN user__field_last_name l ON l.entity_id = u.uid
        LEFT JOIN user__field_user_chargebee_id cb ON cb.entity_id = u.uid
        WHERE u.uid IN (:uids[])", $args) as $r) {
        $out[(int) $r->uid] = [
          'uid' => (int) $r->uid,
          'username' => (string) $r->name,
          'name' => trim(trim((string) $r->first) . ' ' . trim((string) $r->last)),
          'mail' => mb_strtolower(trim((string) $r->mail)),
          'blocked' => !(bool) $r->status,
          'created' => (int) $r->created,
          'access' => (int) $r->access,
          'chargebee' => (string) ($r->chargebee ?? ''),
          'contact_id' => $r->contact_id !== NULL ? (int) $r->contact_id : NULL,
          'roles' => [],
        ];
      }
      foreach ($this->database->query("SELECT entity_id, roles_target_id FROM user__roles WHERE entity_id IN (:uids[])", $args) as $r) {
        $out[(int) $r->entity_id]['roles'][] = (string) $r->roles_target_id;
      }
    }
    return $out;
  }

  /**
   * Values shared by two lists, compared case- and space-insensitively.
   *
   * @return string[]
   *   The shared values as they appear in $a.
   */
  public static function shared(array $a, array $b): array {
    $norm = static fn(string $v): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($v)));
    $in_b = array_flip(array_map($norm, $b));
    return array_values(array_filter($a, static fn(string $v): bool => isset($in_b[$norm($v)])));
  }

  /**
   * A ZIP that lost its leading zero in a spreadsheet ("6511") gets it back.
   */
  public static function normalizeZip(string $zip): string {
    return (ctype_digit($zip) && strlen($zip) === 4) ? '0' . $zip : $zip;
  }

  /**
   * Phone as (203) 555-1234 when it is a 10-digit US number.
   */
  public static function formatPhone(string $numeric, string $raw): string {
    $digits = substr(preg_replace('/\D/', '', $numeric), -10);
    if (strlen($digits) === 10) {
      return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }
    return trim($raw);
  }

}
