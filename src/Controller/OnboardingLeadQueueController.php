<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\OnboardingLeadTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Staff queue of unpaid join-form leads.
 */
class OnboardingLeadQueueController extends ControllerBase {

  public function __construct(
    protected readonly OnboardingLeadTracker $leadTracker,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.onboarding_lead_tracker'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Lists unpaid leads (join form submitted, no paid account yet).
   */
  public function build(): array {
    $now = (int) $this->time->getRequestTime();
    $enabled = (bool) $this->config('makerspace_member_success.settings')->get('lead_followup_enabled');

    $header = [
      $this->t('Email'),
      $this->t('Name'),
      $this->t('Submitted'),
      $this->t('Submissions'),
      $this->t('Follow-up'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($this->leadTracker->getLeads('unpaid') as $lead) {
      $age_hours = !empty($lead['submitted_ts']) ? (int) floor(($now - (int) $lead['submitted_ts']) / 3600) : 0;
      $age = $age_hours >= 48 ? floor($age_hours / 24) . 'd' : $age_hours . 'h';

      if (!empty($lead['followup_sent_ts'])) {
        $followup = $this->t('Sent @when', ['@when' => $this->formatAge($now - (int) $lead['followup_sent_ts'])]);
      }
      elseif ($enabled) {
        $followup = $this->t('Queued (auto)');
      }
      else {
        $followup = $this->t('Auto-send off');
      }

      $dismiss = Url::fromRoute('makerspace_member_success.lead_dismiss', ['lead' => $lead['id']]);
      $rows[] = [
        $lead['email'],
        $lead['name'] ?: '—',
        $this->t('@age ago', ['@age' => $age]),
        $lead['submission_count'],
        $followup,
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Dismiss'),
            '#url' => $dismiss,
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ],
      ];
    }

    $build = [];
    $build['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $enabled
        ? $this->t('Auto follow-up is ON: each lead gets one email after the configured wait. Dismiss anyone who should not be contacted.')
        : $this->t('Auto follow-up is OFF: these leads are tracked for manual outreach only. Enable auto-send in <a href=":url">settings</a>.', [
          ':url' => Url::fromRoute('makerspace_member_success.settings')->toString(),
        ]),
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No unpaid leads right now — everyone who started the join form has an account.'),
    ];

    return $build;
  }

  /**
   * Dismiss a lead (no follow-up needed) and return to the queue.
   */
  public function dismiss(int $lead): RedirectResponse {
    $this->leadTracker->dismiss($lead);
    $this->messenger()->addStatus($this->t('Lead dismissed.'));
    return new RedirectResponse(Url::fromRoute('makerspace_member_success.lead_queue')->toString());
  }

  /**
   * Human "N ago" from a second delta.
   */
  protected function formatAge(int $seconds): string {
    if ($seconds >= 86400) {
      return (string) $this->t('@d d ago', ['@d' => floor($seconds / 86400)]);
    }
    return (string) $this->t('@h h ago', ['@h' => max(0, (int) floor($seconds / 3600))]);
  }

}
