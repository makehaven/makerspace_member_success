<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\makerspace_member_success\Support\OutreachDecision;

/**
 * Decides recommended channel/template for outreach.
 */
interface OutreachPolicyDeciderInterface {

  /**
   * Returns a recommendation for a member snapshot.
   */
  public function decide(int $uid, array $snapshot): OutreachDecision;

}
