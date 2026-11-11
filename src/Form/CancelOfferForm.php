<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\chargebee_portal\Service\MembershipStatusResolver;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Before you cancel": offer a pause, then the member's choice.
 *
 * About half of pausers resume against about a quarter of cancellers who
 * come back (pause-beats-cancel analysis), so the site's cancel link offers a
 * pause first. No reason question here: the Chargebee portal already asks a
 * cancel reason (field_chargebee_cancel_reason). Each choice is stored in
 * ms_cancel_offer so the share who pause instead can be measured.
 *
 * A member who already paused in the last 12 months is not offered another
 * pause (Kate 11-10: repeat pausers who really meant to cancel). They go
 * straight to the portal, logged as 'repeat' so the skip can be counted.
 */
class CancelOfferForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_cancel_offer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser()->isAnonymous()) {
      $login = Url::fromRoute('user.login', [], ['query' => ['destination' => '/membership/cancel']]);
      throw new EnforcedResponseException(new RedirectResponse($login->toString()));
    }
    // Only an active member can switch a cancel to a pause.
    $status = \Drupal::service('chargebee_portal.membership_status_resolver')->resolve($this->currentUser());
    if ($status !== MembershipStatusResolver::STATUS_ACTIVE) {
      throw new EnforcedResponseException(new RedirectResponse(Url::fromRoute('chargebee_portal.payment_portal')->toString()));
    }
    $uid = (int) $this->currentUser()->id();
    if (static::pausedWithinYear($uid)) {
      static::logChoice($uid, 'repeat');
      throw new EnforcedResponseException(new RedirectResponse(Url::fromRoute('chargebee_portal.payment_portal')->toString()));
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Would a pause work instead? A pause stops your membership billing for 30, 60 or 90 days. When it ends, your membership and billing restart automatically; to stop for good, cancel instead. Your profile, badges and training records stay as they are either way.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['pause'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pause instead'),
      '#name' => 'pause',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to cancel'),
      '#name' => 'cancel',
      '#attributes' => ['class' => ['ms-choice-quiet']],
    ];
    $form['#attributes']['class'][] = 'ms-membership-choice';
    $form['#attached']['library'][] = 'makerspace_member_success/membership_choice';
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $choice = ($form_state->getTriggeringElement()['#name'] ?? '') === 'pause' ? 'pause' : 'cancel';
    static::logChoice((int) $this->currentUser()->id(), $choice);
    if ($choice === 'pause') {
      $form_state->setRedirect('makerspace_member_success.pause_before', [], ['query' => ['from' => 'cancel']]);
      return;
    }
    $form_state->setRedirect('chargebee_portal.payment_portal');
  }

  /**
   * Whether the member was paused on any snapshot in the last 12 months.
   */
  protected static function pausedWithinYear(int $uid): bool {
    $since = date('Y-m-d', strtotime('-12 months', \Drupal::time()->getRequestTime()));
    return (bool) \Drupal::database()->select('ms_member_success_snapshot', 's')
      ->condition('s.uid', $uid)
      ->condition('s.stage', MemberSuccessLifecycle::STAGE_PAUSED)
      ->condition('s.snapshot_date', $since, '>=')
      ->range(0, 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Records one visit's outcome: pause, cancel, or repeat (offer skipped).
   */
  protected static function logChoice(int $uid, string $choice): void {
    \Drupal::database()->insert('ms_cancel_offer')
      ->fields([
        'uid' => $uid,
        'choice' => $choice,
        'created' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();
  }

}
