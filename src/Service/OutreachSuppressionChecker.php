<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\makerspace_member_success\Support\SuppressionResult;

/**
 * Central suppression checker for outreach channels.
 */
class OutreachSuppressionChecker implements OutreachSuppressionCheckerInterface {

  /**
   * Constructs a checker.
   */
  public function __construct(protected Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public function check(int $uid, string $channel, array $context = []): SuppressionResult {
    $channel = strtolower(trim($channel));
    if (!in_array($channel, ['sms', 'email'], TRUE)) {
      return new SuppressionResult(FALSE, 'suppressed_unknown_channel');
    }

    if (!empty($context['pause_all']) || !empty($context["pause_$channel"])) {
      return new SuppressionResult(FALSE, 'suppressed_paused');
    }

    $snapshot = $this->loadSnapshot($uid);
    $is_opt_out = (int) ($context['is_opt_out'] ?? 0);
    $sms_consent = $context['sms_consent'] ?? NULL;

    if ($channel === 'sms') {
      $do_not_sms = (int) ($context['do_not_sms'] ?? ($snapshot['civicrm_do_not_sms'] ?? 0));
      $phone = trim((string) ($context['phone'] ?? ''));
      if ($is_opt_out) {
        return new SuppressionResult(FALSE, 'suppressed_opt_out');
      }
      if ($do_not_sms) {
        return new SuppressionResult(FALSE, 'suppressed_do_not_sms');
      }
      if ($phone === '') {
        return new SuppressionResult(FALSE, 'suppressed_no_phone');
      }
      // Require explicit consent for SMS. Missing consent counts as no consent.
      if ((int) $sms_consent !== 1) {
        return new SuppressionResult(FALSE, 'suppressed_no_sms_consent');
      }
      return new SuppressionResult(TRUE);
    }

    $do_not_email = (int) ($context['do_not_email'] ?? ($snapshot['civicrm_do_not_email'] ?? 0));
    $email = trim((string) ($context['email'] ?? ''));
    if ($is_opt_out) {
      return new SuppressionResult(FALSE, 'suppressed_opt_out');
    }
    if ($do_not_email) {
      return new SuppressionResult(FALSE, 'suppressed_do_not_email');
    }
    if ($email === '') {
      return new SuppressionResult(FALSE, 'suppressed_no_email');
    }
    return new SuppressionResult(TRUE);
  }

  /**
   * Loads latest snapshot do-not fields for fallback checks.
   */
  protected function loadSnapshot(int $uid): array {
    $row = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', ['civicrm_do_not_email', 'civicrm_do_not_sms'])
      ->condition('ms.uid', $uid)
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return is_array($row) ? $row : [];
  }

}
