<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends onboarding members from a passed safety quiz straight to booking.
 *
 * Staff walkthroughs (2026-07-16) found the "You Passed" result page offered
 * two competing paths and stalled momentum; the agreed flow is quiz pass →
 * Site Orientation booking page, with the getting-started content living at
 * the bottom of that page instead.
 *
 * Scoped exactly like OnboardingQuizRedirectSubscriber: safety quiz only
 * (id 1), member_pending_approval role only, and additionally only for the
 * member's own evaluated, 100% result — staff reviewing someone else's
 * result, retake reviews, and failed attempts all still see the normal
 * result page (a failed attempt needs its feedback).
 */
final class OnboardingQuizPassRedirectSubscriber implements EventSubscriberInterface {

  private const SAFETY_QUIZ_ID = 1;

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority below the router (32) so the route match is populated.
    return [KernelEvents::REQUEST => ['onRequest', 20]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if ($this->routeMatch->getRouteName() !== 'entity.quiz_result.canonical') {
      return;
    }
    $quiz = $this->routeMatch->getParameter('quiz');
    $quiz_id = is_object($quiz) ? (int) $quiz->id() : (int) $quiz;
    if ($quiz_id !== self::SAFETY_QUIZ_ID) {
      return;
    }
    if (!in_array('member_pending_approval', $this->currentUser->getRoles(), TRUE)) {
      return;
    }
    $result = $this->routeMatch->getParameter('quiz_result');
    if (!is_object($result)) {
      return;
    }
    $own = (int) $result->get('uid')->target_id === (int) $this->currentUser->id();
    $passed = !empty($result->get('is_evaluated')->value)
      && (int) $result->get('score')->value === 100;
    if ($own && $passed) {
      $event->setResponse(new LocalRedirectResponse('/schedule'));
    }
  }

}
