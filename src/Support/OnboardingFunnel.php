<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Canonical new-member onboarding funnel.
 *
 * Pure logic, no Drupal dependencies. Given completion signals for a single
 * member, this returns:
 *   - the ordered step list with done/current/todo state,
 *   - the furthest completed step,
 *   - the next incomplete ("resume") step and its URL,
 *   - how long the member has been stalled at the current step.
 *
 * It is the single source of truth for "where is this member in onboarding"
 * and is consumed by BOTH:
 *   - the staff success queue (server-side, fed from snapshot columns), and
 *   - the member-facing progress timeline block (real-time, current user).
 *
 * Funnel (matches the member-onboarding email and the on-page progress bar):
 *   Pay → Profile → Watch video → Pass quiz → Book orientation → Get involved
 *
 * Signal notes:
 *   - "paid" is implied by account existence: the account is created at the
 *     /user/register step, which only happens AFTER Chargebee payment.
 *   - The safety video has no standalone completion signal — its quiz button
 *     lives at the bottom of the video page — so the video step is treated as
 *     complete once the quiz is passed. A member sitting on the video page
 *     therefore shows "quiz" as their next step, which is the actionable one.
 */
final class OnboardingFunnel {

  // Step machine names, in funnel order.
  public const STEP_ACCOUNT = 'account';
  public const STEP_PROFILE = 'profile';
  public const STEP_VIDEO = 'video';
  public const STEP_QUIZ = 'quiz';
  public const STEP_SCHEDULE = 'schedule';
  public const STEP_INVOLVE = 'involve';

  // Sentinel returned by nextStep() / currentStep() when nothing is left.
  public const STEP_DONE = 'done';

  /**
   * Returns the ordered onboarding steps with completion state.
   *
   * @param array $signals
   *   Completion signals. Recognised keys (all optional, sensible defaults):
   *     - has_account (bool)
   *     - account_ts (int|null) account/role creation unix ts
   *     - profile_present (bool) member 'main' profile row saved
   *     - profile_ts (int|null)
   *     - quiz_passed (bool) safety quiz (qid 1) passed
   *     - quiz_ts (int|null)
   *     - orientation_scheduled (bool) CiviCRM orientation participant exists
   *     - schedule_ts (int|null)
   *     - engaged (bool) goals/interests set OR door badge active
   *     - door_badge_active (bool) onboarding fully complete.
   * @param int $uid
   *   The member's user id, used to build the profile-edit URL. 0 if unknown.
   *
   * @return array[]
   *   Ordered list of step arrays, each with keys:
   *     - id, label, done (bool), ts (int|null), url (string),
   *     - state ('done'|'current'|'todo').
   */
  public static function steps(array $signals, int $uid = 0): array {
    $door_active = !empty($signals['door_badge_active']);

    // When onboarding is fully complete (door badge active), every step is
    // done regardless of which intermediate signals we happened to capture.
    $profile_done = $door_active || !empty($signals['profile_present']);
    $quiz_done = $door_active || !empty($signals['quiz_passed']);
    $schedule_done = $door_active || !empty($signals['orientation_scheduled']);
    $involve_done = $door_active || !empty($signals['engaged']);

    // Carry nextpage=video so profile_redirect advances the member to the
    // orientation video after saving — without it the save is a dead end
    // ("no further redirection was needed"), which stranded a member who
    // re-entered the profile via this block's Resume link (2026-07-11).
    $profile_url = $uid > 0 ? '/user/' . $uid . '/main?nextpage=video' : '/user';

    $steps = [
      [
        'id' => self::STEP_ACCOUNT,
        'label' => 'Create your account',
        'done' => $door_active || !empty($signals['has_account']),
        'ts' => $signals['account_ts'] ?? NULL,
        'url' => '/user/register',
      ],
      [
        'id' => self::STEP_PROFILE,
        'label' => 'Complete your member profile',
        'done' => $profile_done,
        'ts' => $signals['profile_ts'] ?? NULL,
        'url' => $profile_url,
      ],
      [
        'id' => self::STEP_VIDEO,
        'label' => 'Watch the safety video',
        // No standalone signal; complete once the quiz (which follows it on the
        // same page) is passed.
        'done' => $quiz_done,
        'ts' => NULL,
        'url' => '/video',
      ],
      [
        'id' => self::STEP_QUIZ,
        'label' => 'Pass the safety quiz',
        'done' => $quiz_done,
        'ts' => $signals['quiz_ts'] ?? NULL,
        'url' => '/quiz/1/take',
      ],
      [
        'id' => self::STEP_SCHEDULE,
        'label' => 'Book your Site Orientation',
        'done' => $schedule_done,
        'ts' => $signals['schedule_ts'] ?? NULL,
        // /schedule is the key-pickup/orientation booking page used by the
        // rest of the join flow; /facilitator/schedules is the generic tool
        // checkout grid and confused new members.
        'url' => '/schedule',
      ],
      [
        'id' => self::STEP_INVOLVE,
        'label' => 'Set your goals & get involved',
        'done' => $involve_done,
        'ts' => NULL,
        'url' => '/involve',
      ],
    ];

    // Derive per-step display state. The "current" step is the first not-done
    // step; everything before it is done, everything after is todo.
    $found_current = FALSE;
    foreach ($steps as &$step) {
      if ($step['done']) {
        $step['state'] = 'done';
      }
      elseif (!$found_current) {
        $step['state'] = 'current';
        $found_current = TRUE;
      }
      else {
        $step['state'] = 'todo';
      }
    }
    unset($step);

    return $steps;
  }

  /**
   * Returns the next incomplete step (the one the member should resume).
   *
   * @param array $signals
   *   Completion signals (see steps()).
   * @param int $uid
   *   Member user id.
   *
   * @return array|null
   *   The step array (with an added 'is_complete' => FALSE), or NULL when
   *   onboarding is fully complete.
   */
  public static function nextStep(array $signals, int $uid = 0): ?array {
    foreach (self::steps($signals, $uid) as $step) {
      if (!$step['done']) {
        return $step;
      }
    }
    return NULL;
  }

  /**
   * Machine name of the next incomplete step, or STEP_DONE.
   */
  public static function nextStepId(array $signals): string {
    $next = self::nextStep($signals);
    return $next['id'] ?? self::STEP_DONE;
  }

  /**
   * Resolves a "stuck_at_<step>" risk reason to its canonical funnel step.
   *
   * The risk scorer emits reasons like "stuck_at_video", "stuck_at_profile_aging"
   * or "stuck_at_schedule_stale". This strips the prefix and the _aging/_stale
   * severity suffix and returns the matching step (id, label, url) so outreach
   * copy can name the exact next action without hardcoding step URLs.
   *
   * @param string $reason
   *   A single risk reason string.
   * @param int $uid
   *   Member user id, used to build the profile-edit URL. 0 if unknown.
   *
   * @return array|null
   *   The step array (id, label, url, …), or NULL if the reason isn't a
   *   recognised stuck-at-step reason.
   */
  public static function stepFromStuckReason(string $reason, int $uid = 0): ?array {
    $prefix = 'stuck_at_';
    if (!str_starts_with($reason, $prefix)) {
      return NULL;
    }
    $id = preg_replace('/_(aging|stale)$/', '', substr($reason, strlen($prefix)));
    foreach (self::steps([], $uid) as $step) {
      if ($step['id'] === $id) {
        return $step;
      }
    }
    return NULL;
  }

  /**
   * Picks the first recognised stuck-at-step from a list of risk reasons.
   *
   * @param string[] $reasons
   *   Risk reason strings.
   * @param int $uid
   *   Member user id.
   *
   * @return array|null
   *   The matching step array, or NULL if none of the reasons name a step.
   */
  public static function stepFromRiskReasons(array $reasons, int $uid = 0): ?array {
    foreach ($reasons as $reason) {
      if (is_string($reason)) {
        $step = self::stepFromStuckReason($reason, $uid);
        if ($step !== NULL) {
          return $step;
        }
      }
    }
    return NULL;
  }

  /**
   * Whether a stalled member's step is in an allowed-steps list.
   *
   * Shared by the auto-nudge cron and its dry-run preview so both scope to the
   * same funnel steps. An empty allow-list means "no step restriction" — every
   * stalled member is allowed (the original stage-only behaviour).
   *
   * @param string[] $reasons
   *   The member's risk reasons (e.g. ["stuck_at_profile_aging"]).
   * @param string[] $allowed_steps
   *   Step machine names to allow (e.g. ["profile"]). Empty = allow all.
   * @param int $uid
   *   Member user id (only used to build step URLs; not needed for matching).
   *
   * @return bool
   *   TRUE if the member should be nudged under this step scoping.
   */
  public static function stuckStepAllowed(array $reasons, array $allowed_steps, int $uid = 0): bool {
    if ($allowed_steps === []) {
      return TRUE;
    }
    $step = self::stepFromRiskReasons($reasons, $uid);
    return $step !== NULL && in_array($step['id'], $allowed_steps, TRUE);
  }

  /**
   * Unix timestamp the member reached their current position in the funnel.
   *
   * This is the most recent timestamp among completed steps that carry one
   * (account → profile → quiz → schedule). It anchors stall measurement: time
   * since this ts with the next step still incomplete = time stuck.
   *
   * @return int|null
   *   Unix timestamp, or NULL if none is known.
   */
  public static function currentStepSince(array $signals): ?int {
    $latest = NULL;
    foreach (self::steps($signals) as $step) {
      if (!$step['done']) {
        break;
      }
      if (!empty($step['ts'])) {
        $latest = max((int) $latest, (int) $step['ts']);
      }
    }
    return $latest;
  }

  /**
   * Hours the member has been stalled at their current step.
   *
   * @param array $signals
   *   Completion signals (see steps()).
   * @param int $now_ts
   *   Current unix timestamp.
   *
   * @return int
   *   Whole hours since the last completed step; 0 if unknown or complete.
   */
  public static function hoursStalled(array $signals, int $now_ts): int {
    if (self::nextStep($signals) === NULL) {
      return 0;
    }
    $since = self::currentStepSince($signals);
    if ($since === NULL || $now_ts <= $since) {
      return 0;
    }
    return (int) floor(($now_ts - $since) / 3600);
  }

  /**
   * TRUE when onboarding is fully complete (no incomplete steps remain).
   */
  public static function isComplete(array $signals): bool {
    return self::nextStep($signals) === NULL;
  }

  /**
   * Request-path prefixes of the member-facing funnel pages.
   *
   * Includes both alias variants where a page has more than one. The member
   * profile form (/user/{uid}/main) is covered by isMemberFacingPath(), not
   * listed here, because it embeds a user id.
   *
   * @return string[]
   *   Path prefixes as requested by the browser (aliases, not internal
   *   paths).
   */
  public static function memberPagePrefixes(): array {
    return [
      '/user/register',
      '/video',
      '/orientation-video',
      '/quiz/1',
      '/schedule',
      '/key-pickup-and-site-safety-intro',
      '/involve',
      '/thank-you-joining-makehaven',
    ];
  }

  /**
   * Whether a request path is one of the member-facing funnel pages.
   *
   * @param string $path
   *   Request path (Request::getPathInfo(), no query string).
   */
  public static function isMemberFacingPath(string $path): bool {
    if (preg_match('#^/user/\d+/main(/|$)#', $path)) {
      return TRUE;
    }
    foreach (self::memberPagePrefixes() as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Short human label for a step machine name.
   *
   * Used by staff-facing views where only the stored step id is available.
   *
   * @param string|null $step_id
   *   A STEP_* constant, STEP_DONE, or NULL.
   *
   * @return string
   *   Human-readable short label.
   */
  public static function stepLabel(?string $step_id): string {
    return match ($step_id) {
      self::STEP_ACCOUNT => 'Account',
      self::STEP_PROFILE => 'Profile',
      self::STEP_VIDEO => 'Safety video',
      self::STEP_QUIZ => 'Safety quiz',
      self::STEP_SCHEDULE => 'Site Orientation',
      self::STEP_INVOLVE => 'Get involved',
      self::STEP_DONE => 'Complete',
      default => 'Unknown',
    };
  }

}
