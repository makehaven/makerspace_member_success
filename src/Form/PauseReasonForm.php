<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\makerspace_member_success\Support\PauseReason;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The signed "why are you pausing?" page from the pause-reason email.
 *
 * No login needed. The email's links open this page with the clicked answer
 * already selected; nothing is saved until the member presses the button.
 * About half of email clicks come from mail scanners following every link,
 * and a one-click GET would record all seven answers for each scanned email.
 */
class PauseReasonForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_pause_reason';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $uid = 0, string $pause = '', string $token = ''): array {
    if (!$uid || !PauseReason::validToken($uid, $pause, $token, Settings::getHashSalt())) {
      throw new AccessDeniedHttpException();
    }
    $form_state->set('uid', $uid);
    $form_state->set('pause', $pause);

    $existing = \Drupal::service('makerspace_member_success.pause_reason_store')->get($uid, $pause);
    $picked = (string) $this->getRequest()->query->get('r', '');
    $default = PauseReason::label($picked) !== '' ? $picked : ($existing['reason'] ?? NULL);

    $form['intro'] = [
      '#markup' => '<p>' . ($existing
        ? $this->t('Thanks, we have your answer. You can change it here.')
        : $this->t('What is the main reason for your pause?')) . '</p>',
    ];
    $form['reason'] = [
      '#type' => 'radios',
      '#title' => $this->t('Main reason'),
      '#title_display' => 'invisible',
      '#options' => PauseReason::REASONS,
      '#default_value' => $default,
      '#required' => TRUE,
    ];
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Anything we could do that would help? (optional)'),
      '#rows' => 3,
      '#maxlength' => 2000,
      '#default_value' => $existing['note'] ?? '',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $existing ? $this->t('Update my answer') : $this->t('Send'),
    ];
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('makerspace_member_success.pause_reason_store')->record(
      (int) $form_state->get('uid'),
      (string) $form_state->get('pause'),
      (string) $form_state->getValue('reason'),
      (string) $form_state->getValue('note'),
    );
    $this->messenger()->addStatus($this->t('Thank you. That helps us plan, and you do not need to do anything else.'));
  }

}
