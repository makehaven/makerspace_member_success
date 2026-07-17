<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Data-driven new-member onboarding progress bar.
 *
 * Renders the funnel as a horizontal left-to-right stepper (the layout of the
 * retired static "Member Registration Path Progress Bar" block, which staff
 * preferred), driven by the member's real completion state (profile saved,
 * quiz passed, orientation booked) instead of the current page.
 *
 * Also shown to anonymous visitors on the join/register pages so the bar is
 * visible from the very start of the flow; their step state is derived from
 * the page they are on (no signals exist yet).
 *
 * Copy note: the heading avoids "you're almost a maker" phrasing — staff
 * feedback (2026-07-14) was that people are makers before they join.
 *
 * Returns empty (renders nothing) once onboarding is complete.
 *
 * @Block(
 *   id = "makerspace_member_success_onboarding_progress",
 *   admin_label = @Translation("Onboarding progress timeline"),
 *   category = @Translation("MakeHaven")
 * )
 */
class OnboardingProgressBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly AccountInterface $currentUser,
    protected readonly MemberSuccessSnapshotBuilder $snapshotBuilder,
    protected readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('makerspace_member_success.snapshot_builder'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Anonymous visitors are allowed so the bar shows from the start of the
   * join flow (block placement limits it to funnel pages). Authenticated
   * users must be members or pending members; build() hides the bar once
   * onboarding is complete.
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    if ($account->isAnonymous()) {
      return AccessResult::allowed()->addCacheContexts(['user.roles:anonymous']);
    }
    $onboarding_roles = ['member', 'member_pending_approval'];
    return AccessResult::allowedIf(
      array_intersect($onboarding_roles, $account->getRoles()) !== [],
    )->addCacheContexts(['user.roles']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    $is_authenticated = $uid > 0;
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '/';

    $signals = $is_authenticated ? $this->snapshotBuilder->loadOnboardingSignals($uid) : [];
    // Completion is judged from real signals only — the page can advance the
    // "current" marker (below) but must never un-hide a finished bar.
    $data_complete = $is_authenticated && OnboardingFunnel::nextStep($signals, $uid) === NULL;
    $steps = $this->applyPageAwareProgress(OnboardingFunnel::steps($signals, $uid), $path, $is_authenticated);
    // Resume target = first not-done step AFTER the page-aware pass, so a
    // "Continue" link can never contradict the highlighted current step.
    $next = NULL;
    if ($is_authenticated) {
      foreach ($steps as $candidate) {
        if ($candidate['state'] !== 'done') {
          $next = $candidate;
          break;
        }
      }
    }

    $cache = [
      // Per-user; url.path because the current page decides whether the
      // continue link is redundant (and, for anonymous, the step state).
      'contexts' => ['user', 'url.path'],
      // profile_list / quiz_result_list: the profile and quiz-pass signals
      // live on their own entities, whose saves don't touch user:{uid} — a
      // member who saved their profile kept seeing "Complete your member
      // profile" for up to an hour without these (found 2026-07-14).
      'tags' => array_merge(
        $is_authenticated ? ['user:' . $uid] : [],
        ['node_list:badge_request', 'profile_list', 'quiz_result_list'],
      ),
      'max-age' => 3600,
    ];

    // Onboarding complete — render nothing.
    if ($data_complete) {
      return ['#cache' => $cache];
    }

    // Presentation-only first step covering the join form + Chargebee payment,
    // which precede account creation. Anyone authenticated in this flow has
    // already paid; anonymous visitors are placed on it (or past it, once they
    // reach the register page).
    $on_register = $this->pathMatches($path, ['/user/register']);
    $display_steps = [
      [
        'id' => 'join',
        'label' => (string) $this->t('Join & pay'),
        'state' => ($is_authenticated || $on_register) ? 'done' : 'current',
        'url' => '/join-makehaven',
      ],
    ];
    foreach ($steps as $step) {
      if (!$is_authenticated) {
        // No signals yet: everything after payment is upcoming, except the
        // account step becomes current on the register page itself.
        $step['state'] = ($on_register && $step['id'] === OnboardingFunnel::STEP_ACCOUNT) ? 'current' : 'todo';
      }
      $display_steps[] = $step;
    }

    $total = count($display_steps);
    $current_position = 1;
    $current_label = $display_steps[0]['label'];
    foreach (array_values($display_steps) as $i => $step) {
      if ($step['state'] === 'current') {
        $current_position = $i + 1;
        $current_label = $step['label'];
        break;
      }
    }

    $items = [];
    foreach (array_values($display_steps) as $i => $step) {
      $dot_text = $step['state'] === 'done' ? '✓' : (string) ($i + 1);
      // A single template per item — multiple child elements would be
      // re-wrapped as a nested list by the theme's item-list handling.
      $content = [
        '#type' => 'inline_template',
        '#template' => '<span class="mh-setup-step__dot" aria-hidden="true">{{ dot }}</span><span class="mh-setup-step__label">{{ label }}</span>',
        '#context' => [
          'dot' => $dot_text,
          'label' => $step['label'],
        ],
      ];

      // Only the current step is clickable — earlier steps are finished and
      // later ones aren't actionable yet; keeping them inert keeps the
      // funnel focused.
      if ($step['state'] === 'current' && $is_authenticated) {
        $content = [
          '#type' => 'link',
          '#title' => $content,
          '#url' => Url::fromUserInput($step['url']),
          '#attributes' => ['class' => ['mh-setup-step__link']],
        ];
      }

      $wrapper_attributes = [
        'class' => ['mh-setup-step', 'mh-setup-step--' . $step['state']],
      ];
      if ($step['state'] === 'current') {
        $wrapper_attributes['aria-current'] = 'step';
      }
      $content['#wrapper_attributes'] = $wrapper_attributes;
      $items[] = $content;
    }

    $build = [
      '#cache' => $cache,
      '#attached' => ['library' => ['makerspace_member_success/onboarding_progress']],
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mh-setup-bar'],
        'role' => 'navigation',
        'aria-label' => (string) $this->t('Member setup progress'),
      ],
    ];

    $build['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['mh-setup-bar__status']],
      '#value' => $this->t('Member setup — step @position of @total: <strong>@label</strong>', [
        '@position' => $current_position,
        '@total' => $total,
        '@label' => $current_label,
      ]),
    ];

    $build['steps'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#items' => $items,
      '#attributes' => ['class' => ['mh-setup-bar__steps']],
      '#wrapper_attributes' => ['class' => ['mh-setup-bar__steps-wrapper']],
    ];

    // A single "Continue" link to the next incomplete step — shown only when
    // the member is NOT already on that step's page (a "Resume: book your
    // orientation" button on the booking page itself read as noise in the
    // 2026-07-14 staff review). After applyPageAwareProgress, $next is the
    // page's own step whenever the member is on a funnel page, so this only
    // renders when they have browsed BACK to an earlier, already-finished
    // page — a genuine "pick up where you left off" affordance.
    if ($is_authenticated && $next !== NULL && !$this->onStepPage($path, $next)) {
      $build['continue'] = [
        '#type' => 'link',
        '#title' => $this->t('Continue: @step', ['@step' => $next['label']]),
        '#url' => Url::fromUserInput($next['url']),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'mh-setup-bar__continue'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Advances the "current" step marker to match the page the member is on.
   *
   * Several steps can't be completed from real-time signals: the video and
   * quiz share one completion signal (passing the quiz), and the orientation
   * booking is only detected from the once-daily snapshot. Left to data
   * alone, the bar reads a step (or two) behind where the member actually
   * is — "stuck" on the video all through the quiz, and never reaching the
   * final "get involved" step even while sitting on that page
   * (staff feedback 2026-07-15/17; JR asked for the page/URL to drive this).
   *
   * So: find the funnel step whose page the member is on and, if they aren't
   * already further along by real signals, mark everything before it done
   * and that step current. This only ever moves the marker FORWARD — if the
   * page's step is already complete (they browsed back), the data states are
   * left untouched so the bar doesn't rewind. Funnel data (snapshots, staff
   * queues) is never affected; this is display only.
   */
  protected function applyPageAwareProgress(array $steps, string $path, bool $is_authenticated): array {
    if (!$is_authenticated) {
      return $steps;
    }
    $steps = array_values($steps);
    // LAST match wins: the video step's aliases include /quiz/1 (for the
    // continue-link suppression in onStepPage), so on the quiz pages we want
    // the later-listed quiz step, not the video step.
    $page_index = NULL;
    foreach ($steps as $i => $step) {
      if ($this->onStepPage($path, $step)) {
        $page_index = $i;
      }
    }
    // Not on a funnel page, or already past this page by real signals — leave
    // the data-driven states alone.
    if ($page_index === NULL || $steps[$page_index]['state'] === 'done') {
      return $steps;
    }
    foreach ($steps as $i => &$step) {
      if ($i < $page_index) {
        $step['state'] = 'done';
      }
      elseif ($i === $page_index) {
        $step['state'] = 'current';
      }
    }
    unset($step);
    return $steps;
  }

  /**
   * Whether the given request path is one of this step's pages.
   */
  protected function onStepPage(string $path, array $step): bool {
    $aliases = match ($step['id']) {
      // The quiz pages count as the video step's pages too: passing the quiz
      // is how the video step completes, so "Continue: watch the safety
      // video" mid-quiz would be pure noise.
      OnboardingFunnel::STEP_VIDEO => ['/video', '/orientation-video', '/quiz/1'],
      OnboardingFunnel::STEP_QUIZ => ['/quiz/1'],
      OnboardingFunnel::STEP_SCHEDULE => ['/schedule', '/key-pickup-and-site-safety-intro'],
      OnboardingFunnel::STEP_INVOLVE => ['/involve', '/thank-you-joining-makehaven'],
      default => [parse_url($step['url'], PHP_URL_PATH) ?: $step['url']],
    };
    return $this->pathMatches($path, $aliases);
  }

  /**
   * Prefix-match a request path against a list of path aliases.
   */
  protected function pathMatches(string $path, array $aliases): bool {
    foreach ($aliases as $alias) {
      if ($path === $alias || str_starts_with($path, $alias . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
