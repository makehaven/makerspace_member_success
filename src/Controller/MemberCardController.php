<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_member_success\Service\MemberCardPdf;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable member ID card PDFs for the /onboard staff list.
 *
 * /onboard/card/{uid} is one card; /onboard/cards?uids=1,2,3 is one PDF with
 * a page per member, for printing a stack in one go.
 */
final class MemberCardController extends ControllerBase {

  private const MAX_BATCH = 50;

  public function __construct(
    private readonly MemberCardPdf $cardPdf,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.member_card_pdf'),
    );
  }

  /**
   * One member's card.
   */
  public function single(UserInterface $user, Request $request): Response {
    $card = $this->cardFor($user);
    if ($card['photo'] === NULL && !$request->query->getBoolean('nophoto')) {
      $this->messenger()->addWarning($this->t('@name has no member photo yet, so there is no card to print. Add the photo to their profile first.', [
        '@name' => $user->getDisplayName(),
      ]));
      return new RedirectResponse('/onboard');
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($card['first'] . ' ' . $card['last'])) ?: 'member';
    return $this->pdfResponse([$card], 'makehaven-card-' . trim($slug, '-') . '.pdf');
  }

  /**
   * Several cards in one PDF: ?uids=12,34,56.
   */
  public function batch(Request $request): Response {
    $uids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query->get('uids', ''))))), 0, self::MAX_BATCH);
    $users = $uids ? $this->entityTypeManager()->getStorage('user')->loadMultiple($uids) : [];

    $cards = [];
    $skipped = [];
    foreach ($uids as $uid) {
      if (!isset($users[$uid])) {
        continue;
      }
      $card = $this->cardFor($users[$uid]);
      if ($card['photo'] === NULL) {
        $skipped[] = $users[$uid]->getDisplayName();
        continue;
      }
      $cards[] = $card;
    }

    if ($cards === []) {
      $this->messenger()->addWarning($this->t('No cards to print: none of the selected members has a photo.'));
      return new RedirectResponse('/onboard');
    }
    $response = $this->pdfResponse($cards, 'makehaven-cards-' . date('Y-m-d') . '.pdf');
    if ($skipped) {
      $response->headers->set('X-Cards-Skipped', implode(', ', $skipped));
    }
    return $response;
  }

  /**
   * Name + photo for one member's card.
   *
   * @return array{first: string, last: string, photo: ?string}
   */
  private function cardFor(UserInterface $user): array {
    $first = $user->hasField('field_first_name') ? trim((string) $user->get('field_first_name')->value) : '';
    $last = $user->hasField('field_last_name') ? trim((string) $user->get('field_last_name')->value) : '';
    if ($first === '' && $last === '') {
      $parts = preg_split('/\s+/', trim($user->getDisplayName()), 2);
      $first = $parts[0] ?? '';
      $last = $parts[1] ?? '';
    }

    $photo = NULL;
    $profiles = $this->entityTypeManager()->getStorage('profile')->loadByProperties([
      'uid' => $user->id(),
      'type' => 'main',
      'status' => 1,
    ]);
    foreach ($profiles as $profile) {
      if ($profile->hasField('field_member_photo') && !$profile->get('field_member_photo')->isEmpty()) {
        $file = $profile->get('field_member_photo')->entity;
        // A file row whose bytes are gone (e.g. a local copy of live's DB)
        // counts as no photo rather than printing a blank card.
        if ($file && is_readable($file->getFileUri())) {
          $photo = $file->getFileUri();
          break;
        }
      }
    }

    return ['first' => $first, 'last' => $last, 'photo' => $photo];
  }

  /**
   * Inline PDF so it opens in the browser's viewer, ready to print.
   */
  private function pdfResponse(array $cards, string $filename): Response {
    $response = new Response($this->cardPdf->render($cards));
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
