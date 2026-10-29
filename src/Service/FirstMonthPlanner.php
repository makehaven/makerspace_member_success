<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Works out what each "your first month" email says for one member.
 *
 * Everything comes from the member's own picks on the thank-you page: areas
 * of interest (profile field_member_areas_interest), starred tools
 * (tool_favorite flag) and projects they want to make (want_to_make flag).
 * Checkout times are real open facilitator shifts from appointment_facilitator,
 * the same ones the badge pages offer, so an email never names a time that
 * cannot be booked.
 *
 * plan() returns a skip reason instead of tokens when the step has nothing
 * to say: day 7 when every starred tool is already unlocked and a checkout is
 * booked or done.
 */
class FirstMonthPlanner {

  /**
   * Most open shifts listed in one email.
   */
  protected const MAX_SLOTS = 3;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * Plans one step for one member.
   *
   * @return array{skip: ?string, tokens: array<string, string>}
   *   skip is a reason code when the step should not go out.
   */
  public function plan(int $uid, int $step, string $baseUrl): array {
    $interests = $this->interestNames($uid);
    $badges = $this->badgeStatuses($uid);
    $tools = $this->starredTools($uid);
    $projects = $this->wantedProjects($uid);
    $next = $this->nextTool($tools, $badges);

    $tokens = [
      'first_month.picks' => $this->picksText($interests, $tools, $projects, $baseUrl),
      'first_month.tool' => $next ? $next['name'] : '',
      'first_month.tool_heading' => $next ? 'Your first tool, the ' . $next['name'] . ':' : 'Your first tool:',
      'first_month.tool_steps' => $next ? $this->toolSteps($uid, $next, $baseUrl) : $this->noToolText($baseUrl),
      'first_month.project' => $this->projectText($projects, $badges, $baseUrl),
      'first_month.badges_earned' => (string) count(array_filter($badges, static fn(string $s): bool => $s === 'active')),
      'first_month.ask_red_url' => $baseUrl . '/member/resources',
      'first_month.getting_started_url' => $baseUrl . '/thank-you-joining-makehaven',
      'first_month.events_url' => $baseUrl . '/events',
    ];
    $tokens['first_month.subject_tool'] = $next
      ? 'Your next step on the ' . $next['name']
      : 'Pick your first tool';

    $skip = NULL;
    if ($step === 7 && $next === NULL && $tools) {
      // Every starred tool is unlocked already: the email would only repeat
      // what the member has done.
      $skip = 'first_month_tools_done';
    }
    if ($step === 7 && $next !== NULL && $next['booked']) {
      $skip = 'first_month_checkout_booked';
    }
    return ['skip' => $skip, 'tokens' => $tokens];
  }

  /**
   * Badge term id => status ('active', 'pending', ...) for the member.
   *
   * Active wins when a member has more than one request for a badge.
   */
  protected function badgeStatuses(int $uid): array {
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id AND n.status = 1');
    $query->addField('b', 'field_badge_requested_target_id', 'tid');
    $query->addField('s', 'field_badge_status_value', 'status');
    $query->condition('m.field_member_to_badge_target_id', $uid);
    $statuses = [];
    foreach ($query->execute() as $row) {
      $tid = (int) $row->tid;
      if (($statuses[$tid] ?? '') !== 'active') {
        $statuses[$tid] = (string) $row->status;
      }
    }
    return $statuses;
  }

  /**
   * Names of the member's areas of interest.
   */
  protected function interestNames(int $uid): array {
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'main',
      'status' => 1,
    ]);
    $profile = reset($profiles);
    if (!$profile || !$profile->hasField('field_member_areas_interest')) {
      return [];
    }
    $names = [];
    foreach ($profile->get('field_member_areas_interest')->referencedEntities() as $term) {
      $names[] = $term->label();
    }
    return $names;
  }

  /**
   * Starred tools, most recent first, each with the badge terms it needs.
   *
   * @return array<int, array{nid: int, name: string, url: string, badges: \Drupal\taxonomy\TermInterface[]}>
   */
  protected function starredTools(int $uid): array {
    $nids = $this->flagged($uid, 'tool_favorite');
    if (!$nids) {
      return [];
    }
    $tools = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node || !$node->isPublished()) {
        continue;
      }
      $badges = [];
      foreach (['field_member_badges', 'field_additional_badges'] as $field) {
        if ($node->hasField($field)) {
          foreach ($node->get($field)->referencedEntities() as $term) {
            if ($term instanceof TermInterface) {
              $badges[(int) $term->id()] = $term;
            }
          }
        }
      }
      $tools[] = [
        'nid' => (int) $nid,
        'name' => $node->label(),
        'url' => $node->toUrl('canonical', ['absolute' => FALSE])->toString(),
        'badges' => array_values($badges),
      ];
    }
    return $tools;
  }

  /**
   * Projects the member wants to make and has not marked made.
   *
   * @return array<int, array{name: string, url: string, badge_tids: int[], badge_names: string[]}>
   */
  protected function wantedProjects(int $uid): array {
    $made = array_flip($this->flagged($uid, 'made_it'));
    $nids = array_values(array_filter($this->flagged($uid, 'want_to_make'), static fn($nid) => !isset($made[$nid])));
    if (!$nids) {
      return [];
    }
    $projects = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node || !$node->isPublished()) {
        continue;
      }
      $tids = [];
      $names = [];
      if ($node->hasField('field_project_required_badges')) {
        foreach ($node->get('field_project_required_badges')->referencedEntities() as $term) {
          $tids[] = (int) $term->id();
          $names[(int) $term->id()] = $term->label();
        }
      }
      $projects[] = [
        'name' => $node->label(),
        'url' => $node->toUrl('canonical', ['absolute' => FALSE])->toString(),
        'badge_tids' => $tids,
        'badge_names' => $names,
      ];
    }
    return $projects;
  }

  /**
   * Flagged entity ids for one flag, most recent first.
   */
  protected function flagged(int $uid, string $flag): array {
    if (!$this->database->schema()->tableExists('flagging')) {
      return [];
    }
    return array_map('intval', $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.flag_id', $flag)
      ->condition('f.uid', $uid)
      ->orderBy('f.created', 'DESC')
      ->execute()
      ->fetchCol());
  }

  /**
   * The first starred tool the member cannot use yet, with what is missing.
   *
   * @return array{name: string, url: string, missing: \Drupal\taxonomy\TermInterface[], pending: \Drupal\taxonomy\TermInterface[], booked: bool}|null
   *   NULL when every starred tool is unlocked (or there are none).
   */
  protected function nextTool(array $tools, array $badges): ?array {
    foreach ($tools as $tool) {
      $missing = [];
      $pending = [];
      foreach ($tool['badges'] as $term) {
        $status = $badges[(int) $term->id()] ?? '';
        if ($status === 'active') {
          continue;
        }
        if ($status === 'pending') {
          $pending[] = $term;
        }
        else {
          $missing[] = $term;
        }
      }
      if ($missing || $pending) {
        return $tool + ['missing' => $missing, 'pending' => $pending, 'booked' => FALSE];
      }
    }
    return NULL;
  }

  /**
   * Numbered steps to unlock a tool: quiz first, then real checkout times.
   */
  protected function toolSteps(int $uid, array &$tool, string $baseUrl): string {
    $lines = [];
    $n = 1;
    foreach ($tool['missing'] as $term) {
      $lines[] = $n++ . '. Watch the video and take the quiz for the ' . $term->label() . ' badge: ' . $baseUrl . $term->toUrl()->toString();
    }
    $checkout_terms = array_merge($tool['pending'], $tool['missing']);
    if ($this->hasUpcomingCheckout($uid, $checkout_terms)) {
      $tool['booked'] = TRUE;
      $lines[] = $n . '. Your checkout is booked. See you then.';
      return implode("\n", $lines);
    }
    $slots = $this->openSlots($checkout_terms);
    $label = $tool['missing']
      ? 'Then book a short checkout with a facilitator'
      : 'You passed the quiz, so the last step is a short checkout with a facilitator';
    if ($slots) {
      $lines[] = $n . '. ' . $label . '. Next open times:';
      foreach ($slots as $slot) {
        $lines[] = '   - ' . $slot;
      }
      $lines[] = '   More times: ' . $baseUrl . '/badges/complete';
    }
    else {
      $lines[] = $n . '. ' . $label . ': ' . $baseUrl . '/badges/complete (new facilitator times are added each week).';
    }
    return implode("\n", $lines);
  }

  /**
   * Text for a member with no tool left to unlock or none starred.
   */
  protected function noToolText(string $baseUrl): string {
    return "Not sure where to start? Browse the tools and star the ones you want to use:\n"
      . $baseUrl . "/equipment\n"
      . 'Or tell Red, our assistant, what you want to make and it will suggest a first tool: '
      . $baseUrl . '/member/resources';
  }

  /**
   * Whether the member has a checkout booked for any of these badges.
   */
  protected function hasUpcomingCheckout(int $uid, array $terms): bool {
    if (!$terms) {
      return FALSE;
    }
    $tids = array_map(static fn(TermInterface $t): int => (int) $t->id(), $terms);
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_badges.target_id', $tids, 'IN')
      ->condition('field_appointment_timerange.value', time(), '>=')
      ->condition('field_appointment_status', 'canceled', '!=')
      ->condition('field_appointment_attendees.target_id', $uid)
      ->range(0, 1);
    try {
      return (bool) $query->execute();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Up to MAX_SLOTS open facilitator shifts for these badges, soonest first.
   *
   * @return string[]
   *   Lines such as "Tue Nov 4, 5:00pm-8:00pm with Gabe".
   */
  protected function openSlots(array $terms): array {
    $class = 'Drupal\appointment_facilitator\Controller\BadgeNextStepsController';
    if (!$terms || !class_exists($class)) {
      return [];
    }
    try {
      $controller = $this->classResolver->getInstanceFromDefinition($class);
      foreach ($terms as $term) {
        if ($controller->isBadgeToolOffline($term)) {
          return [];
        }
      }
      $all = [];
      foreach ($controller->getFacilitatorsForTerms(array_values($terms)) as $entry) {
        $name = explode(' ', trim((string) $entry['user']->getDisplayName()), 2)[0];
        foreach ($entry['slots'] ?? [] as $slot) {
          $start = (int) ($slot['start'] ?? 0);
          $end = (int) ($slot['end'] ?? 0);
          if ($start < time()) {
            continue;
          }
          $all[] = [$start, $this->dateFormatter->format($start, 'custom', 'D M j, g:ia')
            . ($end > $start ? '-' . $this->dateFormatter->format($end, 'custom', 'g:ia') : '')
            . ' with ' . $name];
        }
      }
      usort($all, static fn($a, $b) => $a[0] <=> $b[0]);
      return array_column(array_slice($all, 0, self::MAX_SLOTS), 1);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * "What you told us" lines for the welcome email.
   */
  protected function picksText(array $interests, array $tools, array $projects, string $baseUrl): string {
    $lines = [];
    if ($interests) {
      $lines[] = '- Interested in: ' . implode(', ', array_slice($interests, 0, 6));
    }
    if ($tools) {
      $lines[] = '- Tools you starred: ' . implode(', ', array_slice(array_column($tools, 'name'), 0, 6));
    }
    if ($projects) {
      $lines[] = '- Want to make: ' . implode(', ', array_slice(array_column($projects, 'name'), 0, 3));
    }
    if (!$lines) {
      return "You haven't picked any tools or projects yet. It takes two minutes and shapes what we send you:\n"
        . $baseUrl . '/thank-you-joining-makehaven';
    }
    return "Here is what you told us:\n" . implode("\n", $lines)
      . "\nChange these any time: " . $baseUrl . '/thank-you-joining-makehaven';
  }

  /**
   * The project paragraph for day 14.
   */
  protected function projectText(array $projects, array $badges, string $baseUrl): string {
    if (!$projects) {
      return "Members' projects are a good way to find a first thing to make, with the tools and badges each one needs:\n"
        . $baseUrl . "/projects\n"
        . 'Mark one "I want to make this" and we will help you get there.';
    }
    $project = $projects[0];
    $text = 'You want to make ' . $project['name'] . ': ' . $baseUrl . $project['url'];
    $still = [];
    foreach ($project['badge_tids'] as $tid) {
      if (($badges[$tid] ?? '') !== 'active') {
        $still[] = $project['badge_names'][$tid];
      }
    }
    if ($still) {
      $text .= "\nBadges it needs that you don't have yet: " . implode(', ', $still) . '.';
    }
    elseif ($project['badge_tids']) {
      $text .= "\nYou already have every badge it needs. Time to make it!";
    }
    return $text;
  }

}
