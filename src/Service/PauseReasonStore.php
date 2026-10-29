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
 *
 * An answer given on our page before the member opens the Chargebee portal
 * (/membership/pause) has no pause yet. It is filed under the day it was
 * given with pending = 1, and adoptPending() moves it onto the pause once the
 * snapshot sees one. A pause can start weeks after the answer (Chargebee can
 * pause at the end of the billing term), hence the adoption window.
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
    return $this->write($uid, $pauseStart, $reason, $note, 'email', 0);
  }

  /**
   * Records an answer given before pausing, filed under today until adopted.
   *
   * $fromCancel marks a member who came from "Before you cancel" and chose
   * to pause instead (source cancel_offer).
   *
   * Returns FALSE for an unknown answer code.
   */
  public function recordBeforePause(int $uid, string $reason, string $note = '', bool $fromCancel = FALSE): bool {
    $today = date('Y-m-d', $this->time->getCurrentTime());
    return $this->write($uid, $today, $reason, $note, $fromCancel ? 'cancel_offer' : 'before_pause', 1);
  }

  /**
   * Moves pending before-pause answers onto the pause that followed them.
   *
   * A pending answer is adopted by the member's current pause when that pause
   * started between the day before the answer (a pause the snapshot dated
   * across midnight) and $windowDays after it. An answer for a pause that
   * already has one (the member also answered the email) is dropped: the
   * later answer stands. Answers whose member never paused stay pending —
   * they are members who considered pausing and stayed.
   *
   * @return int
   *   Answers adopted.
   */
  public function adoptPending(int $windowDays = 45): int {
    $query = $this->database->select('ms_pause_reason', 'pr');
    $query->fields('pr', ['uid', 'pause_start_date', 'answered_at']);
    $query->innerJoin('ms_member_success_snapshot', 'ms', 'ms.uid = pr.uid AND ms.is_latest = 1 AND ms.snapshot_type = :daily', [':daily' => 'daily']);
    $query->addField('ms', 'pause_start_date', 'actual_start');
    $query->condition('pr.pending', 1);
    $query->condition('ms.payment_pause', 1);
    $query->isNotNull('ms.pause_start_date');

    $adopted = 0;
    foreach ($query->execute()->fetchAll() as $row) {
      $answered = (string) $row->pause_start_date;
      $actual = (string) $row->actual_start;
      $earliest = date('Y-m-d', strtotime($answered . ' -1 day'));
      $latest = date('Y-m-d', strtotime($answered . ' +' . $windowDays . ' days'));
      if ($actual < $earliest || $actual > $latest) {
        continue;
      }
      $key = ['uid' => (int) $row->uid, 'pause_start_date' => $answered];
      if ($actual === $answered) {
        $this->database->update('ms_pause_reason')->fields(['pending' => 0])
          ->condition('uid', $key['uid'])->condition('pause_start_date', $answered)->execute();
        $adopted++;
        continue;
      }
      $existing = $this->database->select('ms_pause_reason', 'x')
        ->fields('x', ['answered_at'])
        ->condition('x.uid', $key['uid'])
        ->condition('x.pause_start_date', $actual)
        ->execute()
        ->fetchField();
      if ($existing !== FALSE && (int) $existing >= (int) $row->answered_at) {
        $this->database->delete('ms_pause_reason')
          ->condition('uid', $key['uid'])->condition('pause_start_date', $answered)->execute();
        continue;
      }
      if ($existing !== FALSE) {
        $this->database->delete('ms_pause_reason')
          ->condition('uid', $key['uid'])->condition('pause_start_date', $actual)->execute();
      }
      $this->database->update('ms_pause_reason')
        ->fields(['pause_start_date' => $actual, 'pending' => 0])
        ->condition('uid', $key['uid'])->condition('pause_start_date', $answered)->execute();
      $adopted++;
    }
    return $adopted;
  }

  /**
   * Writes one answer row.
   */
  protected function write(int $uid, string $pauseStart, string $reason, string $note, string $source, int $pending): bool {
    if (PauseReason::label($reason) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pauseStart)) {
      return FALSE;
    }
    $this->database->merge('ms_pause_reason')
      ->keys(['uid' => $uid, 'pause_start_date' => $pauseStart])
      ->fields([
        'reason' => $reason,
        'note' => mb_substr(trim($note), 0, 2000) ?: NULL,
        'answered_at' => $this->time->getCurrentTime(),
        'source' => $source,
        'pending' => $pending,
      ])
      ->execute();
    return TRUE;
  }

  /**
   * The member's latest answer still waiting for a pause, or NULL.
   *
   * @return array{reason: string, note: ?string, answered_at: int}|null
   *   The stored answer.
   */
  public function getPending(int $uid): ?array {
    $row = $this->database->select('ms_pause_reason', 'pr')
      ->fields('pr', ['pause_start_date'])
      ->condition('pr.uid', $uid)
      ->condition('pr.pending', 1)
      ->orderBy('pr.answered_at', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $row === FALSE ? NULL : $this->get($uid, (string) $row);
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
