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
    $steps = array_values(OnboardingFunnel::steps($signals, $uid));
    // Which funnel step this page belongs to (NULL when the page isn't one of
    // them, e.g. the join form). Drives the marker for anonymous visitors,
    // who have no signals at all.
    $page_index = OnboardingFunnel::stepIndexForPath($steps, $path);
    $steps = $is_authenticated
      ? $this->applyPageAwareProgress($steps, $page_index)
      : $this->applyAnonymousPageProgress($steps, $page_index);
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

    // Two presentation-only first steps — Join (the join form) then Pay (the
    // Chargebee hosted checkout), which precede account creation. Payment happens
    // off-site, so "Pay" is never the CURRENT step in our bar (there is no bar on
    // Chargebee): on the join form it shows upcoming, and once the member is past
    // payment — authenticated, or on any page from the register step onward —
    // both show done. Anonymous visitors reach those later pages when their
    // session has lapsed (a member opening the welcome email on a second
    // device); before 2026-08-13 they were told they were on "step 1: Join"
    // while reading the post-join getting-started guide.
    $paid = ($is_authenticated || $page_index !== NULL);
    $display_steps = [
      [
        'id' => 'join',
        'label' => (string) $this->t('Join'),
        'state' => $paid ? 'done' : 'current',
        'url' => '/join-makehaven',
      ],
      [
        'id' => 'pay',
        'label' => (string) $this->t('Pay'),
        'state' => $paid ? 'done' : 'todo',
        'url' => '/join-makehaven',
      ],
    ];
    foreach ($steps as $step) {
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

    // A logged-out visitor on a page past account creation is almost always a
    // member whose session lapsed (welcome-email link opened on a phone). The
    // bar can only show them where the PAGE sits, so offer the one action that
    // restores their real progress. Deliberately quiet, and never on the
    // register page itself — that step is kept free of login/reset links.
    if (!$is_authenticated && $page_index !== NULL && $page_index > 0) {
      $build['login'] = [
        '#type' => 'link',
        '#title' => $this->t('Already a member? Log in to pick up your setup'),
        '#url' => Url::fromRoute('user.login', [], ['query' => ['destination' => $path]]),
        '#attributes' => [
          'class' => ['mh-setup-bar__continue', 'mh-setup-bar__continue--reminder'],
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
  protected function applyPageAwareProgress(array $steps, ?int $page_index): array {
    $steps = array_values($steps);
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
   * Positions the marker for an anonymous visitor purely from the page.
   *
   * There are no signals to read, so the page IS the state: the funnel page
   * being viewed is the current step and everything before it is treated as
   * finished. Off-funnel pages (the join form) leave every step upcoming, so
   * the presentation-only "Join" step stays current there.
   */
  protected function applyAnonymousPageProgress(array $steps, ?int $page_index): array {
    $steps = array_values($steps);
    foreach ($steps as $i => &$step) {
      if ($page_index === NULL) {
        $step['state'] = 'todo';
      }
      else {
        $step['state'] = match (TRUE) {
          $i < $page_index => 'done',
          $i === $page_index => 'current',
          default => 'todo',
        };
      }
    }
    unset($step);
    return $steps;
  }

  /**
   * Whether the given request path is one of this step's pages.
   */
  protected function onStepPage(string $path, array $step): bool {
    return OnboardingFunnel::isStepPath($step['id'], $path);
  }

}
