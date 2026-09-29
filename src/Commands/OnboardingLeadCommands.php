<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Commands;

use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Drupal\makerspace_member_success\Service\OnboardingLeadTracker;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for unpaid onboarding-lead tracking.
 */
class OnboardingLeadCommands extends DrushCommands {

  public function __construct(
    protected readonly OnboardingLeadTracker $leadTracker,
    protected readonly ChargebeeAccountReconciler $billingReconciler,
  ) {
    parent::__construct();
  }

  /**
   * Scan join-form submissions for unpaid leads and send due follow-ups.
   *
   * @param array $options
   *   Command options.
   *
   * @option dry-run
   *   Record/convert leads but never send email, regardless of config.
   *
   * @command ms:scan-leads
   * @aliases ms-scan-leads
   * @usage drush ms:scan-leads --dry-run
   *   Scan and report without sending any follow-up email.
   */
  public function scanLeads(array $options = ['dry-run' => FALSE]): void {
    $allow_send = empty($options['dry-run']);
    $stats = $this->leadTracker->scan($allow_send);

    $this->logger()->success(dt(
      'Lead scan complete: @scanned scanned, @new tracked unpaid, @conv converted, @sent follow-ups sent@dry.',
      [
        '@scanned' => $stats['scanned'],
        '@new' => $stats['new_or_updated'],
        '@conv' => $stats['converted'],
        '@sent' => $stats['sent'],
        '@dry' => $allow_send ? '' : ' (dry run — sending suppressed)',
      ]
    ));
  }

  /**
   * Compare paying Chargebee subscriptions with website accounts.
   *
   * Reads Chargebee only. Records mismatches in the "Paying, Not Set Up"
   * queue and lists them. Cron runs this daily on live.
   *
   * @param array $options
   *   Command options.
   *
   * @option notify
   *   Also email staff about rows that are new since the last run.
   *
   * @command ms:billing-reconcile
   * @usage drush ms:billing-reconcile
   *   Refresh the queue and print the open rows.
   */
  public function billingReconcile(array $options = ['notify' => FALSE]): void {
    $stats = $this->billingReconciler->reconcile(!empty($options['notify']));
    if ($stats['error'] !== NULL) {
      $this->logger()->error(dt('Billing reconciliation failed: @e', ['@e' => $stats['error']]));
      return;
    }
    foreach ($this->billingReconciler->getOpen() as $row) {
      $this->output()->writeln(sprintf('%s | %s | %s | %s | %s',
        $row['kind'], $row['name'] ?? '-', $row['email'] ?? '-', $row['plan_id'] ?? '-', $row['customer_id']));
    }
    $this->logger()->success(dt('@scanned paying subscriptions; @open open, @new new, @resolved resolved.', [
      '@scanned' => $stats['scanned'],
      '@open' => $stats['open'],
      '@new' => $stats['new'],
      '@resolved' => $stats['resolved'],
    ]));
  }

}
