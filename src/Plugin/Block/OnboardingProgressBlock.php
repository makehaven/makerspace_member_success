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

/**
 * Data-driven new-member onboarding progress timeline.
 *
 * Replaces the static "Member Registration Path Progress Bar" block. Instead of
 * highlighting the current page via CSS, it reads the member's real completion
 * state (profile saved, quiz passed, orientation booked) and shows each step as
 * done / current / to-do, a "you're not done yet" reminder, and a single
 * Resume button pointing at the next incomplete step — so a member who got
 * called away mid-signup can pick up exactly where they left off.
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
    );
  }

  /**
   * {@inheritdoc}
   *
   * Shown to any authenticated member still working through onboarding. The
   * build() method hides it once onboarding is complete.
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    $onboarding_roles = ['member', 'member_pending_approval'];
    return AccessResult::allowedIf(
      $account->isAuthenticated()
      && array_intersect($onboarding_roles, $account->getRoles()) !== [],
    )->addCacheContexts(['user.roles']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return [];
    }

    $signals = $this->snapshotBuilder->loadOnboardingSignals($uid);
    $steps = OnboardingFunnel::steps($signals, $uid);
    $next = OnboardingFunnel::nextStep($signals, $uid);

    $cache = [
      // Per-user, and invalidate when the member's badges/profile change.
      'contexts' => ['user'],
      // profile_list / quiz_result_list: the profile and quiz-pass signals
      // live on their own entities, whose saves don't touch user:{uid} — a
      // member who saved their profile kept seeing "Complete your member
      // profile" for up to an hour without these (found 2026-07-14).
      'tags' => [
        'user:' . $uid,
        'node_list:badge_request',
        'profile_list',
        'quiz_result_list',
      ],
      // Time-sensitive copy ("stuck for N hours") — refresh hourly.
      'max-age' => 3600,
    ];

    // Onboarding complete — render nothing.
    if ($next === NULL) {
      return ['#cache' => $cache];
    }

    $items = [];
    foreach ($steps as $step) {
      $icon = match ($step['state']) {
        'done' => '✓',
        'current' => '→',
        default => '',
      };
      $items[] = [
        '#wrapper_attributes' => [
          'class' => ['mh-onboarding-step', 'mh-onboarding-step--' . $step['state']],
        ],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['mh-onboarding-step__icon']],
          '#value' => $icon,
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['mh-onboarding-step__label']],
          '#value' => $step['label'],
        ],
      ];
    }

    $build = [
      '#cache' => $cache,
      '#attached' => ['library' => ['makerspace_member_success/onboarding_progress']],
      '#type' => 'container',
      '#attributes' => ['class' => ['mh-onboarding-progress']],
    ];

    $build['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#attributes' => ['class' => ['mh-onboarding-progress__heading']],
      '#value' => $this->t("You're almost a maker — finish setting up"),
    ];

    $build['reminder'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['mh-onboarding-progress__reminder']],
      '#value' => $this->t("You're not done yet. Complete these steps to start using the space:"),
    ];

    $build['steps'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#items' => $items,
      '#attributes' => ['class' => ['mh-onboarding-progress__steps']],
    ];

    $build['resume'] = [
      '#type' => 'link',
      '#title' => $this->t('Resume: @step', ['@step' => $next['label']]),
      '#url' => Url::fromUserInput($next['url']),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'mh-onboarding-progress__resume'],
      ],
    ];

    return $build;
  }

}
