<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Drupal\makerspace_member_success\Service\CiviCrmActivityLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Marks a paying-not-set-up row as needing no action, with a note.
 *
 * The note goes on the person's CiviCRM contact as an activity, found by the
 * matched account or else by a Chargebee email only one contact has, and is
 * also kept on the queue row. Kate asked (2026-10-16) for a record of how each
 * row was dealt with; the old one-click dismiss kept only who and when.
 */
class BillingDismissForm extends FormBase {

  /**
   * The open mismatch row.
   */
  protected array $row = [];

  public function __construct(
    protected readonly ChargebeeAccountReconciler $reconciler,
    protected readonly CiviCrmActivityLogger $activityLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.chargebee_account_reconciler'),
      $container->get('makerspace_member_success.activity_logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_billing_dismiss';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $subscription = NULL): array {
    foreach ($this->reconciler->getOpen() as $row) {
      if ($row['subscription_id'] === $subscription) {
        $this->row = $row;
        break;
      }
    }
    if (!$this->row) {
      throw new NotFoundHttpException();
    }
    $form_state->set('row', $this->row);

    $form['#title'] = $this->t('No action needed: @name', [
      '@name' => $this->row['name'] ?? $this->row['email'] ?? $subscription,
    ]);
    $form['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Chargebee: @email, plan @plan, paying since @since. @what', [
        '@email' => $this->row['email'] ?? '—',
        '@plan' => $this->row['plan_id'] ?? '—',
        '@since' => !empty($this->row['sub_created']) ? date('Y-m-d', (int) $this->row['sub_created']) : '—',
        '@what' => ChargebeeAccountReconciler::describe($this->row['kind'], $this->row['plan_id']),
      ]),
    ];
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Why no action is needed'),
      '#description' => $this->t('For example: refunded; gift membership set up by hand; duplicate Chargebee customer, real one is linked. Saved to their CiviCRM contact when we can find one, and kept with this queue row either way.'),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save note and remove from queue'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('makerspace_member_success.billing_queue'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $form_state->get('row');
    $note = trim((string) $form_state->getValue('note'));
    $form_state->setRedirect('makerspace_member_success.billing_queue');

    $contact_id = NULL;
    if (!empty($row['uid'])) {
      $contact_id = $this->activityLogger->getContactId((int) $row['uid']);
    }
    if ($contact_id === NULL && !empty($row['email'])) {
      $contact_id = $this->activityLogger->getContactIdByEmail((string) $row['email']);
    }

    $activity_id = NULL;
    if ($contact_id !== NULL) {
      $details = implode("\n", [
        $note,
        '',
        sprintf('Paying, Not Set Up queue: %s', ChargebeeAccountReconciler::describe($row['kind'], $row['plan_id'])),
        sprintf('Chargebee customer %s, subscription %s, plan %s.', $row['customer_id'], $row['subscription_id'], $row['plan_id'] ?? '?'),
      ]);
      $activity_id = $this->activityLogger->logNote($contact_id, 'Billing check: no action needed', $details);
    }

    $this->reconciler->dismiss((string) $row['subscription_id'], (int) $this->currentUser()->id(), $note);

    if ($activity_id !== NULL) {
      $this->messenger()->addStatus($this->t('Removed from the queue. Your note is on <a href=":url">their CiviCRM contact</a>.', [
        ':url' => Url::fromUri('internal:/civicrm/contact/view', ['query' => ['reset' => 1, 'cid' => $contact_id]])->toString(),
      ]));
    }
    elseif ($contact_id !== NULL) {
      $this->messenger()->addWarning($this->t('Removed from the queue, but CiviCRM did not accept the note. It is saved with the queue row.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Removed from the queue. No single CiviCRM contact matches this person, so the note is saved with the queue row.'));
    }
  }

}
