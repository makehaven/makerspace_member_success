<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\chargebee_portal\Plugin\WebformHandler\ChargebeeCheckoutEmailHandler;
use Drupal\chargebee_portal\Plugin\WebformHandler\ChargebeeCheckoutRedirectHandler;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks join-form submitters who never created a paid account.
 *
 * The new-member flow creates a Drupal account only AFTER Chargebee payment, so
 * someone who fills the join form but abandons before paying leaves no user
 * record — invisible to the snapshot-based success queue. This service closes
 * that gap: it scans join-form submissions, matches them to accounts by email,
 * records the ones with no account as "unpaid leads", and (optionally) sends a
 * single follow-up email nudging them to finish.
 *
 * Abuse/cost guardrails (the join form is public, unauthenticated input):
 *   - Email only, and only to the exact address that was submitted.
 *   - At most ONE follow-up per email address, ever (followup_sent_ts).
 *   - A hard per-run send cap so a backlog or retry loop can't fan out.
 *   - Auto-send is OFF by default (lead_followup_enabled); flagging for staff
 *     works regardless, sending is a deliberate opt-in.
 *   - Only the configured join webform is scanned; invalid emails are skipped.
 */
class OnboardingLeadTracker {

  /**
   * Default join webform id.
   */
  protected const DEFAULT_WEBFORM_ID = 'webform_24707';

  /**
   * How far back to scan submissions.
   *
   * Bounds the lead universe so we never resurrect ancient abandoned
   * submissions.
   */
  protected const LOOKBACK_DAYS = 30;

  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly MailManagerInterface $mailManager,
    protected readonly TimeInterface $time,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('makerspace_member_success');
  }

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Scans join submissions, updates lead rows, and sends due follow-ups.
   *
   * Safe to run every cron. Conversion detection and lead recording always
   * run; sending is gated by config and the per-run cap.
   *
   * @param bool $allow_send
   *   When FALSE, never sends (e.g. a dry run); only records/marks leads.
   *
   * @return array
   *   Stats keyed: scanned, new_or_updated, converted, sent, skipped_disabled.
   */
  public function scan(bool $allow_send = TRUE): array {
    $config = $this->configFactory->get('makerspace_member_success.settings');
    $webform_id = (string) ($config->get('lead_join_webform_id') ?: self::DEFAULT_WEBFORM_ID);
    $enabled = (bool) $config->get('lead_followup_enabled');
    $grace_seconds = max(0, (int) ($config->get('lead_followup_hours') ?? 2)) * 3600;
    $max_per_run = max(0, (int) ($config->get('lead_followup_max_per_run') ?? 25));
    $now = (int) $this->time->getRequestTime();

    $stats = [
      'scanned' => 0,
      'new_or_updated' => 0,
      'converted' => 0,
      'sent' => 0,
      'skipped_disabled' => 0,
    ];

    foreach ($this->loadRecentSubmissions($webform_id, $now) as $row) {
      $stats['scanned']++;
      $email = mb_strtolower(trim((string) $row['email']));
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }

      $account_uid = $this->accountUidForEmail($email);
      if ($account_uid !== NULL) {
        // They have an account → paid/registered. Mark any lead converted.
        if ($this->markConverted($email, $account_uid, $now)) {
          $stats['converted']++;
        }
        continue;
      }

      $this->upsertLead($email, $row, $now);
      $stats['new_or_updated']++;
    }

    // Send due follow-ups (oldest first), within the per-run cap.
    if ($max_per_run > 0) {
      foreach ($this->loadSendableLeads($now - $grace_seconds, $max_per_run) as $lead) {
        if (!$allow_send || !$enabled) {
          $stats['skipped_disabled']++;
          continue;
        }
        if ($this->sendFollowup($lead, $config)) {
          $this->database->update('ms_onboarding_lead')
            ->fields(['followup_sent_ts' => $now, 'updated_at' => $now])
            ->condition('id', $lead['id'])
            ->execute();
          $stats['sent']++;
        }
      }
    }

    if ($stats['sent'] > 0 || $stats['converted'] > 0) {
      $this->logger->info('Onboarding lead scan: @new tracked, @conv converted, @sent follow-ups sent.', [
        '@new' => $stats['new_or_updated'],
        '@conv' => $stats['converted'],
        '@sent' => $stats['sent'],
      ]);
    }

    return $stats;
  }

  /**
   * Returns lead rows for the staff queue.
   *
   * @param string|null $status
   *   Optional status filter ('unpaid', 'converted', 'dismissed').
   *
   * @return array[]
   *   Lead rows, newest submission first.
   */
  public function getLeads(?string $status = 'unpaid'): array {
    $query = $this->database->select('ms_onboarding_lead', 'l')
      ->fields('l')
      ->orderBy('submitted_ts', 'DESC');
    if ($status !== NULL) {
      $query->condition('status', $status);
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Marks a lead as dismissed (staff decided no follow-up is needed).
   */
  public function dismiss(int $lead_id): void {
    $now = (int) $this->time->getRequestTime();
    $this->database->update('ms_onboarding_lead')
      ->fields(['status' => 'dismissed', 'updated_at' => $now])
      ->condition('id', $lead_id)
      ->execute();
  }

  /**
   * Loads the latest submission per email within the lookback window.
   *
   * @return \Generator
   *   Yields ['email','name','sid','created','count'] per distinct email.
   */
  protected function loadRecentSubmissions(string $webform_id, int $now): \Generator {
    $since = $now - (self::LOOKBACK_DAYS * 86400);

    // Pull email + first/last name values for submissions in the window. One
    // query, pivoted in PHP so we don't depend on DB-specific aggregation.
    $query = $this->database->select('webform_submission', 's');
    $query->innerJoin('webform_submission_data', 'd', 'd.sid = s.sid');
    $query->fields('s', ['sid', 'created']);
    $query->fields('d', ['name', 'value']);
    $query->condition('s.webform_id', $webform_id);
    $query->condition('s.created', $since, '>=');
    $query->condition('d.name', ['email', 'first_name', 'last_name'], 'IN');
    $query->orderBy('s.created', 'ASC');
    $results = $query->execute();

    // Group raw rows by sid.
    $submissions = [];
    foreach ($results as $r) {
      $sid = (int) $r->sid;
      $submissions[$sid]['sid'] = $sid;
      $submissions[$sid]['created'] = (int) $r->created;
      $submissions[$sid][$r->name] = (string) $r->value;
    }

    foreach (self::aggregateByEmail($submissions) as $entry) {
      yield $entry;
    }
  }

  /**
   * Reduces per-submission rows to one entry per email (latest wins).
   *
   * Pure logic (no DB) so the dedupe — repeat submissions, name carry-forward,
   * latest sid/timestamp — is unit-testable.
   *
   * @param array[] $submissions
   *   Rows keyed by sid, each with 'sid', 'created', and optional 'email',
   *   'first_name', 'last_name'.
   *
   * @return array[]
   *   One entry per email: ['email','name','sid','created','count'].
   */
  public static function aggregateByEmail(array $submissions): array {
    $by_email = [];
    foreach ($submissions as $data) {
      $email = mb_strtolower(trim($data['email'] ?? ''));
      if ($email === '') {
        continue;
      }
      $sid = (int) ($data['sid'] ?? 0);
      $created = (int) ($data['created'] ?? 0);
      $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
      if (!isset($by_email[$email])) {
        $by_email[$email] = ['email' => $email, 'name' => $name, 'sid' => $sid, 'created' => $created, 'count' => 0];
      }
      $by_email[$email]['count']++;
      // Keep the most recent submission's sid/name/created.
      if ($created >= $by_email[$email]['created']) {
        $by_email[$email]['sid'] = $sid;
        $by_email[$email]['created'] = $created;
        if ($name !== '') {
          $by_email[$email]['name'] = $name;
        }
      }
    }

    return array_values($by_email);
  }

  /**
   * Returns the uid of an active-or-any account with this email, or NULL.
   */
  protected function accountUidForEmail(string $email): ?int {
    $uid = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->where('LOWER(u.mail) = :mail', [':mail' => $email])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $uid !== FALSE ? (int) $uid : NULL;
  }

  /**
   * Inserts or updates an unpaid lead row (keyed by email).
   */
  protected function upsertLead(string $email, array $submission, int $now): void {
    $existing = $this->database->select('ms_onboarding_lead', 'l')
      ->fields('l', ['id', 'status'])
      ->condition('email', $email)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if ($existing) {
      // Don't resurrect a converted/dismissed lead just because an old
      // submission is still in the window.
      if ($existing['status'] !== 'unpaid') {
        return;
      }
      $this->database->update('ms_onboarding_lead')
        ->fields([
          'name' => $submission['name'] ?: NULL,
          'first_sid' => $submission['sid'],
          'submitted_ts' => $submission['created'],
          'submission_count' => (int) $submission['count'],
          'updated_at' => $now,
        ])
        ->condition('id', $existing['id'])
        ->execute();
      return;
    }

    $this->database->insert('ms_onboarding_lead')
      ->fields([
        'email' => $email,
        'name' => $submission['name'] ?: NULL,
        'first_sid' => $submission['sid'],
        'submitted_ts' => $submission['created'],
        'submission_count' => (int) $submission['count'],
        'status' => 'unpaid',
        'created_at' => $now,
        'updated_at' => $now,
      ])
      ->execute();
  }

  /**
   * Marks an existing unpaid lead as converted. Returns TRUE if one changed.
   */
  protected function markConverted(string $email, int $uid, int $now): bool {
    return (bool) $this->database->update('ms_onboarding_lead')
      ->fields([
        'status' => 'converted',
        'converted_uid' => $uid,
        'converted_ts' => $now,
        'updated_at' => $now,
      ])
      ->condition('email', $email)
      ->condition('status', 'unpaid')
      ->execute();
  }

  /**
   * Loads unpaid leads that are due for a (first, only) follow-up email.
   */
  protected function loadSendableLeads(int $sendable_before_ts, int $limit): array {
    return $this->database->select('ms_onboarding_lead', 'l')
      ->fields('l', ['id', 'email', 'name', 'first_sid', 'submitted_ts'])
      ->condition('status', 'unpaid')
      ->isNull('followup_sent_ts')
      ->condition('submitted_ts', $sendable_before_ts, '<=')
      ->orderBy('submitted_ts', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Sends the single follow-up email to a lead's submitted address.
   */
  protected function sendFollowup(array $lead, $config): bool {
    $first = trim((string) ($lead['name'] ?? ''));
    $first = $first !== '' ? strtok($first, ' ') : 'there';
    $checkout = $this->checkoutDetails((int) ($lead['first_sid'] ?? 0));
    $body = static::buildFollowupBody($first, $checkout['url'], $checkout['summary']);

    $params = [
      'subject' => 'Finish joining MakeHaven',
      'body' => $body,
    ];

    $result = $this->mailManager->mail(
      'makerspace_member_success',
      'lead_followup',
      $lead['email'],
      'en',
      $params,
      NULL,
      TRUE
    );

    if (empty($result['result'])) {
      $this->logger->warning('Lead follow-up email failed for @email.', ['@email' => $lead['email']]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Loads the original submission and rebuilds its hosted-checkout details.
   */
  protected function checkoutDetails(int $sid): array {
    if ($sid <= 0) {
      return ['url' => NULL, 'summary' => ''];
    }

    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load($sid);
    if (!$submission instanceof WebformSubmissionInterface) {
      return ['url' => NULL, 'summary' => ''];
    }

    $data = $submission->getData();
    foreach ($submission->getWebform()->getHandlers() as $handler) {
      if ($handler instanceof ChargebeeCheckoutRedirectHandler) {
        return [
          'url' => $handler->buildCheckoutUrl($data),
          'summary' => ChargebeeCheckoutEmailHandler::planSummary($data),
        ];
      }
    }

    return ['url' => NULL, 'summary' => ''];
  }

  /**
   * Builds cautious recovery copy for an applicant who has no account yet.
   */
  public static function buildFollowupBody(string $first, ?string $checkout_url, string $summary = ''): string {
    $body = [
      "Hi $first,",
      '',
      'It looks like you started signing up for MakeHaven, but we do not see your account yet.',
      '',
    ];

    if ($checkout_url !== NULL) {
      $body[] = 'You can pick up where you left off using your personal checkout link:';
      $body[] = $checkout_url;
      if ($summary !== '') {
        $body[] = '';
        $body[] = 'What you chose: ' . $summary;
      }
    }
    else {
      $body[] = 'We could not generate your personal checkout link, but we have your request. You do not need to submit the form again.';
    }

    $body[] = '';
    $body[] = 'If you already completed payment, or if anything went wrong, just reply to this email and a real person will help.';
    $body[] = '';
    $body[] = 'The MakeHaven Team';
    $body[] = '770 Chapel St., New Haven, CT 06510';

    return implode("\n", $body);
  }

}
