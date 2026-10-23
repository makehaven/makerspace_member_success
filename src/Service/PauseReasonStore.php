<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\PauseReason;

/**
 * Stores members' answers to "why are you pausing?", one row per pause.
 *
 * Keyed by member and the pause start date the daily snapshot recorded
 * (ms_member_success_snapshot.pause_start_date), so a member who pauses
 * twice has two answers rather than the second overwriting the first. A
 * second submit for the same pause replaces the answer: the member changed
 * their mind.
 */
class PauseReasonStore {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Records an answer. Returns FALSE for an unknown answer code.
   */
  public function record(int $uid, string $pauseStart, string $reason, string $note = ''): bool {
    if (PauseReason::label($reason) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pauseStart)) {
      return FALSE;
    }
    $this->database->merge('ms_pause_reason')
      ->keys(['uid' => $uid, 'pause_start_date' => $pauseStart])
      ->fields([
        'reason' => $reason,
        'note' => mb_substr(trim($note), 0, 2000) ?: NULL,
        'answered_at' => $this->time->getCurrentTime(),
      ])
      ->execute();
    return TRUE;
  }

  /**
   * The answer for one pause, or NULL.
   *
   * @return array{reason: string, note: ?string, answered_at: int}|null
   *   The stored answer.
   */
  public function get(int $uid, string $pauseStart): ?array {
    $row = $this->database->select('ms_pause_reason', 'pr')
      ->fields('pr', ['reason', 'note', 'answered_at'])
      ->condition('pr.uid', $uid)
      ->condition('pr.pause_start_date', $pauseStart)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return [
      'reason' => (string) $row['reason'],
      'note' => $row['note'] !== NULL ? (string) $row['note'] : NULL,
      'answered_at' => (int) $row['answered_at'],
    ];
  }

  /**
   * Answer counts since a timestamp, most common first.
   *
   * @return array<string, int>
   *   Answer code => count.
   */
  public function counts(int $since = 0): array {
    $query = $this->database->select('ms_pause_reason', 'pr');
    $query->addField('pr', 'reason');
    $query->addExpression('COUNT(*)', 'n');
    $query->condition('pr.answered_at', $since, '>=');
    $query->groupBy('pr.reason');
    $query->orderBy('n', 'DESC');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

}
