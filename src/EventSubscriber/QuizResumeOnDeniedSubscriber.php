<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends a member stuck on an answered quiz question to the next open one.
 *
 * Quiz contrib refuses a question that has already been answered when
 * backwards navigation is off. If the answer is saved but the session's
 * "current question" never moves on (a double-clicked Next, or a request the
 * browser abandoned after the save), every way back in, including "resume",
 * lands on that answered question and gets Access denied. The member cannot
 * finish, and the only record is a 403 in the log: 21 new members hit it on
 * the safety quiz between 2026-08 and 2026-10 (feedback #44600), one of them
 * stuck on question 1 after answering it correctly.
 *
 * Only acts while the session holds an attempt for this quiz and it still
 * has an unanswered question; anything else falls through to the normal 403.
 */
final class QuizResumeOnDeniedSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ?object $quizSession = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of the 403 page subscribers (-128 and below).
    return [KernelEvents::EXCEPTION => ['onException', 50]];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$this->quizSession || !$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'quiz.question.take') {
      return;
    }
    $quiz = $request->attributes->get('quiz');
    if (!is_object($quiz) || !method_exists($quiz, 'id')) {
      return;
    }
    $result = $this->quizSession->getResult($quiz);
    if (!$result) {
      return;
    }

    $next = NULL;
    foreach ($result->getLayout() as $number => $answer) {
      if (!$answer->isAnswered()) {
        $next = (int) $number;
        break;
      }
    }
    // Nothing left to answer, or the open question is the one just refused:
    // redirecting would not help and could loop.
    $requested = (int) $request->attributes->get('question_number');
    if ($next === NULL || $next === $requested) {
      return;
    }

    $this->quizSession->setCurrentQuestion($quiz, $next);
    $url = Url::fromRoute('quiz.question.take', [
      'quiz' => $quiz->id(),
      'question_number' => $next,
    ])->toString();
    $event->setResponse(new LocalRedirectResponse($url));
  }

}
