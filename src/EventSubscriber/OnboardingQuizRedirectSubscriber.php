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
 * Sends onboarding members straight into the safety quiz questions.
 *
 * The quiz entity's canonical page (/quiz/1) is a summary table (question
 * count, pass grade, "Start Quiz" button) that staff flagged as a needless
 * extra step in the join funnel (2026-07-14). Quiz contrib has no setting to
 * skip it, so members still working through onboarding are redirected to
 * /quiz/1/take, whose controller drops them directly on question 1.
 *
 * Scoped to the safety quiz (id 1, hardcoded like OnboardingFunnel's step
 * URL) and to the member_pending_approval role, so staff and full members
 * can still reach the summary page.
 */
final class OnboardingQuizRedirectSubscriber implements EventSubscriberInterface {

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
    if ($this->routeMatch->getRouteName() !== 'entity.quiz.canonical') {
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
    $event->setResponse(new LocalRedirectResponse('/quiz/' . self::SAFETY_QUIZ_ID . '/take'));
  }

}
