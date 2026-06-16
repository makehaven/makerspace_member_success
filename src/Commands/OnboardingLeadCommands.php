<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Commands;

use Drupal\makerspace_member_success\Service\OnboardingLeadTracker;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for unpaid onboarding-lead tracking.
 */
class OnboardingLeadCommands extends DrushCommands {

  public function __construct(
    protected readonly OnboardingLeadTracker $leadTracker,
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

}
