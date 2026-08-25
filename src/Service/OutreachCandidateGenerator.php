<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Builds outreach candidate rows from the latest daily member snapshots.
 *
 * Extracted from OutreachQueueReviewForm so the staff "Generate candidates"
 * button and the cron-driven onboarding auto-nudge share one query and never
 * drift apart. Returns plain candidate arrays in the exact shape
 * OutreachQueueService::enqueueCandidate() expects; it does not write anything.
 */
class OutreachCandidateGenerator {

  public function __construct(
    protected Connection $database,
    protected ?TimeInterface $time = NULL,
  ) {}

  /**
   * Current request time, tolerating a generator built without the time service.
   */
  protected function now(): int {
    return $this->time ? $this->time->getCurrentTime() : time();
  }

  /**
   * Loads outreach candidates from the latest daily snapshots.
   *
   * @param string[] $stages
   *   Restrict to these lifecycle stages (e.g. ['onboarding']). Empty = all.
   * @param int $minRisk
   *   Only include members whose risk score is at least this value.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate rows keyed for enqueueCandidate().
   */
  public function generate(array $stages = [], int $minRisk = 1): array {
    $query = $this->baseQuery();
    $query->condition('ms.risk_score', max(1, $minRisk), '>=');
    if (!empty($stages)) {
      $query->condition('ms.stage', $stages, 'IN');
    }
    $query->orderBy('ms.risk_score', 'DESC');
    $query->range(0, 2000);

    return $this->mapRows($query->execute()->fetchAll());
  }

  /**
   * Members with a badge waiting on a facilitator checkout.
   *
   * Deliberately NOT risk-filtered. The rest of this generator triages members
   * who are in trouble; a member with an unfinished badge is usually perfectly
   * healthy and scores 0, so the risk floor in generate() would return none of
   * them. What makes them a candidate is the daily badge_pending_count written
   * by MemberSuccessSnapshotBuilder, so the nudge can never chase someone the
   * reports do not show as waiting.
   *
   * @param int $minAgeDays
   *   Only include members whose oldest waiting badge is at least this old, so
   *   someone who passed a quiz this week is not chased for not having been in
   *   yet.
   *
   * @return array<int, array<string, mixed>>
   *   Enriched candidate rows, oldest wait first.
   */
  public function generateBadgeCandidates(int $minAgeDays = 14): array {
    $cutoff = $this->now() - (max(0, $minAgeDays) * 86400);

    $query = $this->baseQuery();
    $query->condition('ms.badge_pending_count', 0, '>');
    $query->condition('ms.badge_pending_oldest_ts', $cutoff, '<=');
    $query->addField('ms', 'badge_pending_count', 'badge_pending_count');
    $query->addField('ms', 'badge_pending_oldest_ts', 'badge_pending_oldest_ts');
    $query->orderBy('ms.badge_pending_oldest_ts', 'ASC');
    $query->range(0, 2000);

    $rows = $query->execute()->fetchAll();
    $candidates = $this->mapRows($rows);
    foreach ($rows as $i => $row) {
      $candidates[$i]['badge_pending_count'] = (int) $row->badge_pending_count;
      $candidates[$i]['badge_pending_oldest_ts'] = (int) $row->badge_pending_oldest_ts;
      // Contacted under the badge campaign, not the member's lifecycle stage:
      // that is what the queue's stage_badge_* settings and template_badge key
      // are looked up by.
      $candidates[$i]['stage'] = 'badge';
    }
    return $candidates;
  }

  /**
   * The shared, enriched candidate query.
   *
   * Carries the joins every campaign needs — email, CiviCRM contact and
   * opt-out, primary phone, SMS consent — so a new campaign cannot accidentally
   * skip a consent signal by writing its own query.
   */
  protected function baseQuery() {
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->fields('ms', [
      'uid',
      'stage',
      'risk_score',
      'risk_reasons',
      'preferred_outreach_method',
      'civicrm_do_not_email',
      'civicrm_do_not_sms',
      'last_contact_date',
      'contact_count',
      'door_badge_status',
    ]);
    $query->innerJoin('users_field_data', 'u', 'u.uid = ms.uid');
    $query->addField('u', 'mail', 'email');
    $query->leftJoin('civicrm_uf_match', 'uf', 'uf.uf_id = ms.uid');
    $query->addField('uf', 'contact_id', 'civicrm_contact_id');
    $query->leftJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->addField('c', 'is_opt_out', 'is_opt_out');
    $query->leftJoin('civicrm_phone', 'p', 'p.contact_id = c.id AND p.is_primary = 1');
    $query->addField('p', 'phone', 'phone');

    [$sms_table, $sms_column] = $this->loadSmsConsentMetadata();
    if ($sms_table && $sms_column) {
      $query->leftJoin($sms_table, 'sp', 'sp.entity_id = c.id');
      $query->addField('sp', $sms_column, 'sms_consent');
    }

    $query->condition('ms.is_latest', 1);
    $query->condition('ms.snapshot_type', 'daily');

    return $query;
  }

  /**
   * Maps raw snapshot rows to candidate arrays.
   */
  protected function mapRows(array $rows): array {
    $candidates = [];
    foreach ($rows as $row) {
      $candidates[] = [
        'uid' => (int) $row->uid,
        'stage' => (string) $row->stage,
        'risk_score' => (int) $row->risk_score,
        'risk_reasons' => $row->risk_reasons,
        'preferred_outreach_method' => (string) ($row->preferred_outreach_method ?? ''),
        'do_not_email' => (int) ($row->civicrm_do_not_email ?? 0),
        'do_not_sms' => (int) ($row->civicrm_do_not_sms ?? 0),
        'last_contact_date' => (string) ($row->last_contact_date ?? ''),
        'contact_count' => (int) ($row->contact_count ?? 0),
        'door_badge_status' => (string) ($row->door_badge_status ?? ''),
        'email' => (string) ($row->email ?? ''),
        'phone' => (string) ($row->phone ?? ''),
        'is_opt_out' => (int) ($row->is_opt_out ?? 0),
        'sms_consent' => isset($row->sms_consent) ? (int) $row->sms_consent : NULL,
        'civicrm_contact_id' => (int) ($row->civicrm_contact_id ?? 0),
      ];
    }

    return $candidates;
  }

  /**
   * Finds the optional custom SMS consent table + column.
   *
   * @return array{0: string|null, 1: string|null}
   *   Table and column names.
   */
  protected function loadSmsConsentMetadata(): array {
    $query = $this->database->select('civicrm_custom_group', 'cg');
    $query->innerJoin('civicrm_custom_field', 'cf', 'cf.custom_group_id = cg.id');
    $query->addField('cg', 'table_name');
    $query->addField('cf', 'column_name');
    $query->condition('cg.name', 'SMS_Preferences');
    $query->condition('cf.name', 'SMS_Consent');
    $record = $query->execute()->fetchAssoc();

    if (!$record) {
      return [NULL, NULL];
    }
    return [$record['table_name'] ?? NULL, $record['column_name'] ?? NULL];
  }

}
