<?php

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Support\MemberSuccessLifecycle;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists members suppressed from outreach queues, grouped by reason.
 */
class SuppressedQueueController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Builds the suppressed queue page.
   */
  public function build(): array {
    $status_info = [
      MemberSuccessLifecycle::FOLLOWUP_CONFIRMED_CANCELLATION => [
        'label' => 'Confirmed Cancellation',
        'desc' => 'Member confirmed they are cancelling — no further outreach expected.',
        'badge' => 'bg-danger',
      ],
      MemberSuccessLifecycle::FOLLOWUP_OUTREACH_EXHAUSTED => [
        'label' => 'Outreach Exhausted',
        'desc' => 'Staff marked outreach as exhausted after repeated attempts with no response.',
        'badge' => 'bg-secondary',
      ],
      MemberSuccessLifecycle::FOLLOWUP_NO_ACTION_NEEDED => [
        'label' => 'No Action Needed',
        'desc' => 'Stage closed without contact — situation resolved on its own or was marked as not requiring follow-up.',
        'badge' => 'bg-info text-dark',
      ],
      MemberSuccessLifecycle::FOLLOWUP_NEEDS_REVIEW => [
        'label' => 'Needs Review',
        'desc' => 'Saved for later staff review. Also available on the dedicated Needs Review queue.',
        'badge' => 'bg-warning text-dark',
      ],
    ];
    $statuses = array_keys($status_info);

    $records = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms', [
        'uid', 'stage', 'risk_score', 'member_followup_status',
        'last_contact_date', 'contact_count', 'last_visit_ts',
      ])
      ->condition('ms.is_latest', 1)
      ->condition('ms.snapshot_type', 'daily')
      ->condition('ms.member_followup_status', $statuses, 'IN')
      ->orderBy('ms.last_contact_date', 'DESC')
      ->execute()
      ->fetchAll();

    $grouped = [];
    foreach ($records as $record) {
      $grouped[$record->member_followup_status][] = $record;
    }

    $total = count($records);
    $build = [
      '#attached' => ['library' => ['makerspace_member_success/dashboard']],
    ];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="alert alert-secondary mb-3">'
      . '<strong>Suppressed queue:</strong> ' . $total . ' '
      . ($total === 1 ? 'member is' : 'members are')
      . ' currently hidden from the main action queues because they have a resolved follow-up status. '
      . 'Expand a section to see who and why; expand a row to see recent outreach history.'
      . '</div>',
    ];

    foreach ($status_info as $status => $info) {
      $rows_data = $grouped[$status] ?? [];
      $count = count($rows_data);

      $build['section_' . $status] = [
        '#type' => 'details',
        '#title' => $info['label'] . ' (' . $count . ')',
        '#open' => $count > 0 && $count <= 15,
        '#attributes' => ['class' => ['ms-suppressed-section']],
      ];

      $build['section_' . $status]['desc'] = [
        '#type' => 'markup',
        '#markup' => '<p class="text-muted small">' . Html::escape($info['desc']) . '</p>',
      ];

      if ($count === 0) {
        $build['section_' . $status]['empty'] = [
          '#type' => 'markup',
          '#markup' => '<p class="text-muted"><em>No members currently in this bucket.</em></p>',
        ];
        continue;
      }

      $table_rows = [];
      foreach ($rows_data as $record) {
        $user = User::load((int) $record->uid);
        if (!$user) {
          continue;
        }

        $member_markup = '<strong>' . Link::fromTextAndUrl(
          $user->getDisplayName() . ' (#' . $user->id() . ')',
          Url::fromRoute('entity.user.canonical', ['user' => $user->id()])
        )->toString() . '</strong><br><small class="text-muted">'
          . Html::escape((string) $user->getEmail()) . '</small>';

        $log_entries = $this->database->select('ms_member_outreach_log', 'l')
          ->fields('l', ['contact_date', 'contact_method', 'outcome', 'notes', 'staff_uid'])
          ->condition('l.uid', $record->uid)
          ->orderBy('l.created_at', 'DESC')
          ->range(0, 5)
          ->execute()
          ->fetchAll();

        $history_markup = $this->renderHistory($log_entries);

        $actions = [];
        $actions[] = Link::fromTextAndUrl(
          $this->t('Log Interaction'),
          Url::fromRoute('makerspace_member_success.log_contact', ['user' => $user->id()], [
            'query' => ['destination' => '/admin/makerspace/member-success/suppressed'],
          ])
        )->toString();

        $last_contact = $record->last_contact_date ?: '—';
        $last_visit = !empty($record->last_visit_ts)
          ? date('Y-m-d', (int) $record->last_visit_ts)
          : '—';

        $table_rows[] = [
          ['data' => ['#markup' => $member_markup]],
          Html::escape($record->stage),
          (int) $record->risk_score,
          Html::escape($last_contact),
          (int) $record->contact_count,
          Html::escape($last_visit),
          ['data' => ['#markup' => $history_markup]],
          ['data' => ['#markup' => implode(' | ', $actions)]],
        ];
      }

      $build['section_' . $status]['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Member'),
          $this->t('Stage'),
          $this->t('Risk'),
          $this->t('Last Contact'),
          $this->t('Attempts'),
          $this->t('Last Visit'),
          $this->t('Reason / History'),
          $this->t('Actions'),
        ],
        '#rows' => $table_rows,
        '#attributes' => ['class' => ['ms-suppressed-table']],
      ];
    }

    return $build;
  }

  /**
   * Renders a collapsible block of recent outreach log entries.
   */
  protected function renderHistory(array $log_entries): string {
    if (empty($log_entries)) {
      return '<em class="text-muted small">No outreach log entries yet.</em>';
    }

    $items = [];
    foreach ($log_entries as $entry) {
      $staff_label = '';
      if (!empty($entry->staff_uid)) {
        $staff = User::load((int) $entry->staff_uid);
        if ($staff) {
          $staff_label = ' by ' . Html::escape($staff->getDisplayName());
        }
      }

      $method = ucwords(str_replace('_', ' ', (string) $entry->contact_method));
      $outcome = ucwords(str_replace('_', ' ', (string) $entry->outcome));
      $note_text = trim((string) $entry->notes);

      $items[] = '<li><strong>' . Html::escape((string) $entry->contact_date) . '</strong> — '
        . Html::escape($method) . ' / ' . Html::escape($outcome) . $staff_label
        . ($note_text !== ''
          ? '<br><em>' . nl2br(Html::escape($note_text)) . '</em>'
          : '')
        . '</li>';
    }

    return '<details class="ms-suppressed-history">'
      . '<summary>View history (' . count($log_entries) . ')</summary>'
      . '<ul class="mb-0 small mt-2">' . implode('', $items) . '</ul>'
      . '</details>';
  }

}
