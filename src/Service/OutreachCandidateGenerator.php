<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\FirstMonth;
use Drupal\makerspace_member_success\Support\OutreachHoldout;

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
   * @param int $maxAgeDays
   *   Exclude members whose oldest waiting badge is older than this. Holds the
   *   accumulated backlog out of an automated send: 531 current members are
   *   past 90 days, and reaching them is a deliberate one-off decision rather
   *   than something a cron run should do by default. 0 disables the bound.
   *
   * @return array<int, array<string, mixed>>
   *   Enriched candidate rows, oldest wait first.
   */
  public function generateBadgeCandidates(int $minAgeDays = 14, int $maxAgeDays = 90): array {
    $now = $this->now();
    $newest_allowed = $now - (max(0, $minAgeDays) * 86400);
    $oldest_allowed = $maxAgeDays > 0 ? $now - ($maxAgeDays * 86400) : NULL;

    $query = $this->baseQuery();
    $query->condition('ms.badge_pending_count', 0, '>');
    $query->condition('ms.badge_pending_oldest_ts', $newest_allowed, '<=');
    // Upper bound: the accumulated backlog is deliberately NOT nudged. 531
    // current members have a badge that has been waiting more than 90 days
    // (175 between three months and a year, 356 beyond that), and mailing them
    // is a separate decision about a separate audience — see
    // docs/proposals/BADGE_NUDGE_PLAN.md. Without this bound, switching the
    // nudge on would reach all of them on the first cron run.
    if ($oldest_allowed !== NULL) {
      $query->condition('ms.badge_pending_oldest_ts', $oldest_allowed, '>=');
    }
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
   * Settled-dormant members due a re-engagement offer, a small batch at a time.
   *
   * Members who have not come in for months score 0 (see
   * MemberSuccessRiskScorer::REASON_SETTLED_DORMANT), so generate() never
   * returns them and nobody calls them. This is the only way they are
   * reached: a capped batch of an offer, approved by staff before it sends.
   *
   * Recently-settled members come first: someone four months away is far more
   * likely to come back than someone two years away, and with a small batch
   * the order decides who hears from us at all.
   *
   * @param int $limit
   *   Most members to return.
   * @param int $cooldownDays
   *   Skip anyone with a re-engagement row, or any logged contact, in this
   *   many days.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate rows with stage "reengage".
   */
  public function generateReengagementCandidates(int $limit = 10, int $cooldownDays = 180): array {
    if ($limit <= 0) {
      return [];
    }
    $since = $this->now() - (max(0, $cooldownDays) * 86400);

    $query = $this->baseQuery();
    $query->condition('ms.risk_reasons', '%' . $this->database->escapeLike('settled_dormant') . '%', 'LIKE');
    // Onboarding members never started; "come back" copy would be wrong.
    $query->condition('ms.stage', ['engagement', 'retention'], 'IN');
    $recent_contact = $query->orConditionGroup()
      ->isNull('ms.last_contact_date')
      ->condition('ms.last_contact_date', date('Y-m-d', $since), '<');
    $query->condition($recent_contact);

    $recent_offer = $this->database->select('ms_member_outreach_queue', 'rq');
    $recent_offer->addField('rq', 'uid');
    $recent_offer->condition('rq.stage', 'reengage');
    $recent_offer->condition('rq.created_at', $since, '>=');
    $query->condition('ms.uid', $recent_offer, 'NOT IN');

    $query->addField('ms', 'last_visit_ts', 'last_visit_ts');
    $query->orderBy('ms.last_visit_ts', 'DESC');
    $query->range(0, $limit);

    $candidates = $this->mapRows($query->execute()->fetchAll());
    foreach ($candidates as $i => $candidate) {
      $candidates[$i]['stage'] = 'reengage';
    }
    return $candidates;
  }

  /**
   * Paused members whose pause ends within the next $leadDays days.
   *
   * Driven by the Chargebee resume date (pause_resume_date), never by an
   * assumed pause length: a member with no known end date is not a candidate.
   * One message per pause — anyone with a pause_end queue row created since
   * this pause began is skipped, whatever its status.
   *
   * Risk-floor free, like the badge nudge: what makes someone a candidate is
   * the date, not a score.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate rows with stage "pause_end", soonest end first.
   */
  public function generatePauseEndCandidates(int $leadDays = 14): array {
    if ($leadDays <= 0) {
      return [];
    }
    $now = $this->now();
    $today = date('Y-m-d', $now);
    $horizon = date('Y-m-d', $now + $leadDays * 86400);

    $query = $this->baseQuery();
    $query->condition('ms.stage', 'paused');
    $query->condition('ms.pause_resume_date', $today, '>=');
    $query->condition('ms.pause_resume_date', $horizon, '<=');
    $query->addField('ms', 'pause_resume_date', 'pause_resume_date');
    $query->addField('ms', 'pause_start_date', 'pause_start_date');
    $query->orderBy('ms.pause_resume_date', 'ASC');
    $query->range(0, 500);
    $rows = $query->execute()->fetchAll();

    $candidates = [];
    foreach ($this->mapRows($rows) as $i => $candidate) {
      $pause_start_ts = strtotime(((string) ($rows[$i]->pause_start_date ?? '')) . ' 00:00:00') ?: 0;
      $already = $this->database->select('ms_member_outreach_queue', 'pq')
        ->condition('pq.uid', $candidate['uid'])
        ->condition('pq.stage', 'pause_end')
        ->condition('pq.created_at', $pause_start_ts, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ((int) $already > 0) {
        continue;
      }
      $candidate['stage'] = 'pause_end';
      $candidate['pause_resume_date'] = (string) $rows[$i]->pause_resume_date;
      $candidates[] = $candidate;
    }
    return $candidates;
  }

  /**
   * Members whose pause began in the last $windowDays days, not yet asked why.
   *
   * The window keeps switching this on from emailing every current pauser:
   * the question only makes sense near the moment of pausing. One question
   * per pause: anyone with a pause_reason queue row created since this pause
   * began, or an answer already stored for it, is skipped.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate rows with stage "pause_reason", newest pause first.
   */
  public function generatePauseReasonCandidates(int $windowDays = 7): array {
    if ($windowDays <= 0) {
      return [];
    }
    $earliest = date('Y-m-d', $this->now() - $windowDays * 86400);

    $query = $this->baseQuery();
    $query->condition('ms.stage', 'paused');
    $query->condition('ms.pause_start_date', $earliest, '>=');
    // pause_start_date is the day the snapshot first saw the pause, not
    // Chargebee's date. Pauses here run 30-90 days, so a pause that truly
    // began this week ends at least three weeks out; one ending sooner is an
    // old pause whose start was re-recorded, and is about to get the
    // pause-end message instead.
    $not_ending_soon = $query->orConditionGroup()
      ->isNull('ms.pause_resume_date')
      ->condition('ms.pause_resume_date', date('Y-m-d', $this->now() + 21 * 86400), '>');
    $query->condition($not_ending_soon);
    $query->addField('ms', 'pause_start_date', 'pause_start_date');
    $query->leftJoin('ms_pause_reason', 'pr', 'pr.uid = ms.uid AND pr.pause_start_date = ms.pause_start_date');
    $query->isNull('pr.uid');
    $query->orderBy('ms.pause_start_date', 'DESC');
    $query->range(0, 200);
    $rows = $query->execute()->fetchAll();

    $candidates = [];
    foreach ($this->mapRows($rows) as $i => $candidate) {
      $pause_start_ts = strtotime(((string) $rows[$i]->pause_start_date) . ' 00:00:00') ?: 0;
      $already = $this->database->select('ms_member_outreach_queue', 'pq')
        ->condition('pq.uid', $candidate['uid'])
        ->condition('pq.stage', 'pause_reason')
        ->condition('pq.created_at', $pause_start_ts, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ((int) $already > 0) {
        continue;
      }
      $candidate['stage'] = 'pause_reason';
      $candidates[] = $candidate;
    }
    return $candidates;
  }

  /**
   * New members due a step of the "your first month" series.
   *
   * Day 0 is the first daily snapshot with a door card serial recorded (staff
   * type it in at orientation, so it means the member was in the building).
   * Nothing stores when a serial was first set, but snapshots are kept per
   * day, so the earliest one showing it is accurate to the day.
   *
   * Only members whose day 0 is on or after $since are considered, so
   * switching the series on never mails the members who joined before it.
   * A member whose account is older than $maxAccountAgeDays is a returning
   * member, not a new one, and is skipped. The door badge must be active at
   * the time: until it is, the onboarding nudge owns the member and the two
   * must not both write to them.
   *
   * Each step goes out once: a member with any row for the step's stage, in
   * any status (sent, held out, suppressed), is not offered it again.
   *
   * @param string $since
   *   Y-m-d; earliest day 0 considered.
   * @param int $maxAccountAgeDays
   *   Skip accounts created more than this many days before day 0.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate rows with stage first_month_<step>, plus first_month_day0
   *   (Y-m-d) and first_month_step (int).
   */
  public function generateFirstMonthCandidates(string $since, int $maxAccountAgeDays = 120): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
      return [];
    }
    $today = date('Y-m-d', $this->now());
    // Nobody past the last step's window can be due anything.
    $last = max(FirstMonth::STEPS) + FirstMonth::WINDOW_DAYS;
    $floor = max($since, date('Y-m-d', strtotime($today . ' -' . $last . ' days')));

    $first_serial = $this->database->select('ms_member_success_snapshot', 'fs');
    $first_serial->addField('fs', 'uid');
    $first_serial->addExpression('MIN(fs.snapshot_date)', 'day0');
    $first_serial->condition('fs.snapshot_type', 'daily');
    $first_serial->condition('fs.serial_number_present', 1);
    $first_serial->groupBy('fs.uid');
    $first_serial->having('MIN(fs.snapshot_date) >= :floor', [':floor' => $floor]);

    $query = $this->baseQuery();
    $query->innerJoin($first_serial, 'd0', 'd0.uid = ms.uid');
    $query->addField('d0', 'day0', 'day0');
    $query->addField('u', 'created', 'account_created');
    $query->condition('ms.door_badge_status', 'active');
    $query->condition('ms.serial_number_present', 1);
    $query->orderBy('d0.day0', 'ASC');
    $query->range(0, 500);
    $rows = $query->execute()->fetchAll();

    $candidates = [];
    foreach ($this->mapRows($rows) as $i => $candidate) {
      $day0 = (string) $rows[$i]->day0;
      $day0_ts = strtotime($day0 . ' 00:00:00');
      if ($day0_ts === FALSE) {
        continue;
      }
      if ((int) $rows[$i]->account_created < $day0_ts - $maxAccountAgeDays * 86400) {
        continue;
      }
      $age = (int) floor((strtotime($today . ' 00:00:00') - $day0_ts) / 86400);
      $step = FirstMonth::stepForAge($age);
      if ($step === NULL) {
        continue;
      }
      $stage = FirstMonth::stage($step);
      $already = $this->database->select('ms_member_outreach_queue', 'fq')
        ->condition('fq.uid', $candidate['uid'])
        ->condition('fq.stage', $stage)
        ->condition('fq.status', ['sent', OutreachHoldout::STATUS_HELD_OUT], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ((int) $already > 0) {
        continue;
      }
      $candidate['stage'] = $stage;
      $candidate['first_month_day0'] = $day0;
      $candidate['first_month_step'] = $step;
      $candidates[] = $candidate;
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
