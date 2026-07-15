<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\makerspace_member_success\Support\OnboardingFunnel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Evergreen "pick up where you left off" redirect for member setup.
 *
 * /continue-setup always routes the member to their CURRENT next onboarding
 * step, computed live from OnboardingFunnel signals. Emails link here
 * instead of deep-linking individual steps, so a message read days later
 * can never re-enter someone at the wrong stage (staff feedback
 * 2026-07-15). Also usable anywhere else a stable "resume setup" link is
 * needed.
 */
final class ContinueSetupController extends ControllerBase {

  public function __construct(
    protected readonly MemberSuccessSnapshotBuilder $snapshotBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.snapshot_builder'),
    );
  }

  /**
   * Redirects to the member's next incomplete onboarding step.
   */
  public function redirectToNextStep(): RedirectResponse {
    $account = $this->currentUser();

    if ($account->isAnonymous()) {
      // Log in first, then come straight back here.
      return new RedirectResponse('/user/login?destination=/continue-setup');
    }

    $uid = (int) $account->id();
    $signals = $this->snapshotBuilder->loadOnboardingSignals($uid);
    $next = OnboardingFunnel::nextStep($signals, $uid);

    // Onboarding finished (or the member never had funnel steps): the
    // getting-started guide is the best "what now" landing.
    $target = $next !== NULL ? $next['url'] : '/involve';

    return new RedirectResponse($target);
  }

}
