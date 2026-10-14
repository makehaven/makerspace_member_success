<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms linking a suggested account to a paying Chargebee customer.
 *
 * Sets the account's Chargebee ID — the key the webhook uses to find it — and
 * then, when chargebee_fetch_data is installed, runs its one-account plan
 * repair (plan, monthly amount, membership type). It never grants roles: if
 * the account is not yet a member, the next daily check lists it as missing
 * the member role.
 */
class BillingLinkAccountForm extends ConfirmFormBase {

  /**
   * The open mismatch row.
   */
  protected array $row = [];

  /**
   * The account to link.
   */
  protected ?UserInterface $account = NULL;

  /**
   * Optional one-account plan repair (chargebee_fetch_data.plan_reconciler).
   */
  protected ?object $planReconciler = NULL;

  public function __construct(
    protected readonly ChargebeeAccountReconciler $reconciler,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('makerspace_member_success.chargebee_account_reconciler'),
      $container->get('entity_type.manager'),
    );
    if ($container->has('chargebee_fetch_data.plan_reconciler')) {
      $form->planReconciler = $container->get('chargebee_fetch_data.plan_reconciler');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_member_success_billing_link_account';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $subscription = NULL, ?UserInterface $user = NULL): array {
    foreach ($this->reconciler->getOpen(TRUE) as $row) {
      if ($row['subscription_id'] === $subscription) {
        $this->row = $row;
        break;
      }
    }
    if (!$this->row || !$user) {
      throw new NotFoundHttpException();
    }
    $this->account = $user;
    $form_state->set('subscription', $subscription);
    $form_state->set('uid', (int) $user->id());
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Link account @uid to the Chargebee customer paying as @name?', [
      '@uid' => $this->account?->id(),
      '@name' => $this->row['name'] ?? $this->row['email'] ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $items = [
      $this->t('Chargebee: @name, @email, plan @plan, paying since @since (customer @cid).', [
        '@name' => $this->row['name'] ?? '—',
        '@email' => $this->row['email'] ?? '—',
        '@plan' => $this->row['plan_id'] ?? '—',
        '@since' => !empty($this->row['sub_created']) ? date('Y-m-d', (int) $this->row['sub_created']) : '—',
        '@cid' => $this->row['customer_id'] ?? '',
      ]),
      $this->t('Website account @uid: @mail, created @created.', [
        '@uid' => $this->account?->id(),
        '@mail' => $this->account?->getEmail(),
        '@created' => $this->account ? date('Y-m-d', (int) $this->account->getCreatedTime()) : '',
      ]),
      $this->t('Only confirm if you are sure this is the same person — billing changes for this customer will apply to this account from now on.'),
    ];
    return implode('<br>', array_map('strval', $items));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Link to this account');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('makerspace_member_success.billing_queue');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->get('uid');
    $error = $this->reconciler->linkAccount((string) $form_state->get('subscription'), $uid, (int) $this->currentUser()->id());
    $form_state->setRedirectUrl($this->getCancelUrl());
    if ($error !== NULL) {
      $this->messenger()->addError($error);
      return;
    }
    $this->messenger()->addStatus($this->t('Linked account @uid to the Chargebee customer.', ['@uid' => $uid]));

    $synced = FALSE;
    if ($this->planReconciler && method_exists($this->planReconciler, 'reconcileUser')) {
      try {
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user instanceof UserInterface) {
          $result = $this->planReconciler->reconcileUser($user);
          $synced = in_array($result['status'] ?? '', ['repaired', 'unchanged'], TRUE);
        }
      }
      catch (\Throwable $e) {
        $this->getLogger('makerspace_member_success')->warning('Plan sync after billing link failed for uid @uid: @msg', [
          '@uid' => $uid,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    $this->messenger()->addStatus($synced
      ? $this->t('Plan and membership type copied from Chargebee. If the account is not a member yet, it shows here tomorrow as missing the member role.')
      : $this->t('Plan not copied yet — run <code>drush chargebee:reconcile-plans --uid=@uid</code>. If the account is not a member yet, it shows here tomorrow as missing the member role.', ['@uid' => $uid]));
  }

}
