<?php

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\FollowupStatusManager;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms no-contact "No Action Needed" status updates from stage queues.
 */
class MarkNoActionNeededForm extends ConfirmFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected FollowupStatusManager $followupStatusManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_member_success.followup_status_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_mark_no_action_needed_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $account = $this->getRouteMatch()->getParameter('user');
    $stage = (string) $this->getRouteMatch()->getParameter('stage');
    $label = ($account instanceof UserInterface) ? $account->getDisplayName() : (string) $this->t('this member');
    return (string) $this->t('Mark @member as "No Action Needed" for @stage stage without logging an interaction?', [
      '@member' => $label,
      '@stage' => $stage,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('This suppresses queued outreach for this stage and syncs followup status to Chargebee/CiviCRM.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $destination = $this->getRequest()->query->get('destination');
    if (is_string($destination) && $destination !== '' && str_starts_with($destination, '/')) {
      return Url::fromUserInput($destination);
    }
    return Url::fromRoute('makerspace_member_success.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Mark No Action Needed');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->getRouteMatch()->getParameter('user');
    $stage = (string) $this->getRouteMatch()->getParameter('stage');
    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to load member account.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $updated = $this->followupStatusManager->applyNoContactStatusByMemberStage(
      (int) $account->id(),
      $stage,
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED,
      'manual_no_action_needed_no_contact'
    );

    if ($updated) {
      $this->messenger()->addStatus($this->t('Follow-up status updated to No Action Needed for @member.', [
        '@member' => $account->getDisplayName(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('No update was applied.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
