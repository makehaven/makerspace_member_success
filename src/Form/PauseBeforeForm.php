<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\chargebee_portal\Service\MembershipStatusResolver;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Support\PauseReason;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Before you pause": the reason question, then on to the Chargebee portal.
 *
 * Chargebee's portal asks for a reason when a member cancels but has no
 * reason step for pause, and its pause API has no reason field. So the
 * site's pause links come here first. The answer is saved as pending
 * (PauseReasonStore::recordBeforePause()) and joins the pause once the
 * snapshot sees it, which also stops the after-pause email from asking again.
 * Skipping is allowed: this is a question, not a gate.
 */
class PauseBeforeForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_pause_before';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser()->isAnonymous()) {
      $login = Url::fromRoute('user.login', [], ['query' => ['destination' => '/membership/pause']]);
      throw new EnforcedResponseException(new RedirectResponse($login->toString()));
    }
    $portal = Url::fromRoute('chargebee_portal.payment_portal');
    // Only an active member has a pause to start. Anyone else (already
    // paused, payment failed, lapsed) needs the portal itself.
    $status = \Drupal::service('chargebee_portal.membership_status_resolver')->resolve($this->currentUser());
    if ($status !== MembershipStatusResolver::STATUS_ACTIVE) {
      throw new EnforcedResponseException(new RedirectResponse($portal->toString()));
    }

    $pending = \Drupal::service('makerspace_member_success.pause_reason_store')->getPending((int) $this->currentUser()->id());

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Before you pause: what is the main reason? It helps us plan, and it takes a few seconds. Next you will choose 30, 60 or 90 days in the payment portal. Your profile, badges and training records stay as they are.') . '</p>',
    ];
    $form['reason'] = [
      '#type' => 'radios',
      '#title' => $this->t('Main reason'),
      '#title_display' => 'invisible',
      '#options' => PauseReason::REASONS,
      '#default_value' => $pending['reason'] ?? NULL,
      '#required' => TRUE,
    ];
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Anything we could do that would help? (optional)'),
      '#rows' => 3,
      '#maxlength' => 2000,
      '#default_value' => $pending['note'] ?? '',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to the payment portal'),
    ];
    $form['actions']['skip'] = [
      '#type' => 'link',
      '#title' => $this->t('Skip this question'),
      '#url' => $portal,
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('makerspace_member_success.pause_reason_store')->recordBeforePause(
      (int) $this->currentUser()->id(),
      (string) $form_state->getValue('reason'),
      (string) $form_state->getValue('note'),
    );
    $form_state->setRedirect('chargebee_portal.payment_portal');
  }

}
