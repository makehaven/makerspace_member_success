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
    $steps = $this->mergeVideoAndQuiz(OnboardingFunnel::steps($signals, $uid));
    $next = $is_authenticated ? OnboardingFunnel::nextStep($signals, $uid) : NULL;

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
    if ($is_authenticated && $next === NULL) {
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

    // A single link to the next incomplete step — shown only when the member
    // is NOT already on that step's page (a "Resume: book your orientation"
    // button on the booking page itself read as noise in the 2026-07-14
    // staff review). Two variants:
    //  - pointing FORWARD (member behind the page's step): a primary
    //    "Continue" button;
    //  - pointing BACK at a step the member skipped: a small "Still to do"
    //    reminder pill, so it reads as a loose end rather than the main
    //    action of the page (staff follow-up 2026-07-15).
    if ($is_authenticated && $next !== NULL && !$this->onStepPage($path, $next)) {
      $page_step_index = NULL;
      $next_step_index = NULL;
      foreach (array_values($steps) as $i => $step) {
        if ($page_step_index === NULL && $this->onStepPage($path, $step)) {
          $page_step_index = $i;
        }
        if ($step['id'] === $next['id']) {
          $next_step_index = $i;
        }
      }
      $skipped_back = $page_step_index !== NULL && $next_step_index !== NULL
        && $next_step_index < $page_step_index;

      // The video step has no completion signal of its own — passing the
      // quiz is what finishes it — so a reminder pointing at it should name
      // the whole loose end, not imply there's a new video to watch.
      $label = $next['id'] === OnboardingFunnel::STEP_VIDEO
        ? $this->t('watch the safety video & pass the quiz')
        : $next['label'];

      $build['continue'] = [
        '#type' => 'link',
        '#title' => $skipped_back
          ? $this->t('Still to do: @step', ['@step' => $label])
          : $this->t('Continue: @step', ['@step' => $label]),
        '#url' => Url::fromUserInput($next['url']),
        '#attributes' => [
          'class' => $skipped_back
            ? ['mh-setup-bar__continue', 'mh-setup-bar__continue--reminder']
            : ['button', 'button--primary', 'mh-setup-bar__continue'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Folds the quiz row into the video row for display.
   *
   * The two funnel steps share a single completion signal (passing the quiz
   * is the only evidence the video was watched), so the quiz row can never
   * become "current" — the bar kept saying "Watch the safety video" all the
   * way through the quiz, which staff read as the bar being stuck
   * (feedback 2026-07-15/16). One combined row keeps every displayed
   * step's state truthful. Funnel data (snapshots, staff queues) still
   * tracks the two ids separately.
   */
  protected function mergeVideoAndQuiz(array $steps): array {
    $merged = [];
    foreach ($steps as $step) {
      if ($step['id'] === OnboardingFunnel::STEP_QUIZ) {
        continue;
      }
      if ($step['id'] === OnboardingFunnel::STEP_VIDEO) {
        $step['label'] = (string) $this->t('Watch the video & pass the quiz');
      }
      $merged[] = $step;
    }
    return $merged;
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
