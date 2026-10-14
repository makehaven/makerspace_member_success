<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\ChargebeeAccountReconciler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Staff queue: people paying in Chargebee who are not set up as members.
 */
class BillingMismatchQueueController extends ControllerBase {

  public function __construct(
    protected readonly ChargebeeAccountReconciler $reconciler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.chargebee_account_reconciler'),
    );
  }

  /**
   * Lists open rows.
   */
  public function build(): array {
    $rows = [];
    foreach ($this->reconciler->getOpen() as $row) {
      $account = '—';
      if (!empty($row['uid'])) {
        $account = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Account @uid', ['@uid' => $row['uid']]),
            '#url' => Url::fromRoute('entity.user.edit_form', ['user' => $row['uid']]),
          ],
        ];
      }
      $suggested = '—';
      $suggestions = $this->reconciler->suggestAccounts($row);
      if ($suggestions !== []) {
        $suggested = ['data' => []];
        foreach ($suggestions as $i => $suggestion) {
          $suggested['data'][$i] = [
            '#type' => 'container',
            'account' => [
              '#type' => 'link',
              '#title' => $this->t('@name (@mail, account @uid) — @reason', [
                '@name' => $suggestion['name'] !== '' ? $suggestion['name'] : $this->t('no name'),
                '@mail' => $suggestion['mail'],
                '@uid' => $suggestion['uid'],
                '@reason' => $suggestion['reason'],
              ]),
              '#url' => Url::fromRoute('entity.user.canonical', ['user' => $suggestion['uid']]),
            ],
            'link' => [
              '#type' => 'link',
              '#title' => $this->t('Link to this account'),
              '#url' => Url::fromRoute('makerspace_member_success.billing_link', [
                'subscription' => $row['subscription_id'],
                'user' => $suggestion['uid'],
              ]),
              '#attributes' => ['class' => ['button', 'button--small']],
            ],
          ];
        }
      }
      $rows[] = [
        $row['name'] ?? '—',
        $row['email'] ?? '—',
        $row['plan_id'] ?? '—',
        $row['sub_created'] ? date('Y-m-d', (int) $row['sub_created']) : '—',
        ChargebeeAccountReconciler::describe($row['kind'], $row['plan_id']),
        $account,
        $suggested,
        [
          'data' => [
            'chargebee' => [
              '#type' => 'link',
              '#title' => $this->t('Chargebee'),
              '#url' => Url::fromUri('https://makehaven.chargebee.com/d/customers/' . rawurlencode($row['customer_id'])),
              '#attributes' => ['class' => ['button', 'button--small'], 'target' => '_blank'],
            ],
            'dismiss' => [
              '#type' => 'link',
              '#title' => $this->t('No action needed'),
              '#url' => Url::fromRoute('makerspace_member_success.billing_dismiss', ['subscription' => $row['subscription_id']]),
              '#attributes' => ['class' => ['button', 'button--small']],
            ],
          ],
        ],
      ];
    }

    $last = $this->reconciler->lastRun();
    $build = [];
    $build['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('People with a paying Chargebee subscription whom the website does not treat as members, so they get no door access and never appear in the other queues. Checked once a day. A row disappears on the next check after the account is fixed.'),
    ];
    $build['last'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => empty($last['ran'])
        ? $this->t('The check has not run yet. It runs daily on the live site; staff can run it now with <code>drush ms:billing-reconcile</code>.')
        : $this->t('Last checked @when: @scanned paying subscriptions.', [
          '@when' => date('Y-m-d H:i', (int) $last['ran']),
          '@scanned' => $last['scanned'] ?? 0,
        ]),
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Chargebee email'),
        $this->t('Plan'),
        $this->t('Paying since'),
        $this->t('What to do'),
        $this->t('Website account'),
        $this->t('Possible account'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Everyone paying in Chargebee is set up as a member.'),
    ];
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * Dismisses a row and returns to the queue.
   */
  public function dismiss(string $subscription): RedirectResponse {
    $this->reconciler->dismiss($subscription, (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Marked as needing no action. It will come back only if the problem changes.'));
    return new RedirectResponse(Url::fromRoute('makerspace_member_success.billing_queue')->toString());
  }

}
