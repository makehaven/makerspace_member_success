<?php

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays members marked for later review.
 */
class NeedsReviewQueueController extends ControllerBase {

  /**
   * Constructs the controller.
   */
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
   * Builds the queue page.
   */
  public function build(): array {
    $build = [
      '#attached' => [
        'library' => [
          'makerspace_member_success/dashboard',
        ],
      ],
    ];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="alert alert-warning mb-3">'
      . '<strong>Needs Review queue:</strong> members here were intentionally removed from the main action queues so someone can revisit them later with more context.'
      . '</div>',
    ];

    $header = [
      $this->t('Member'),
      $this->t('Stage'),
      $this->t('Risk'),
      $this->t('Last Door Entry'),
      $this->t('Visit Count (30d)'),
      $this->t('Actions'),
    ];

    $rows = [];
    $query = $this->database->select('ms_member_success_snapshot', 'ms');
    $query->fields('ms', ['uid', 'stage', 'risk_score', 'last_visit_ts', 'visit_count_30d']);
    $query->condition('ms.is_latest', 1);
    $query->condition('ms.snapshot_type', 'daily');
    $query->condition('ms.member_followup_status', 'needs_review');
    $query->orderBy('ms.risk_score', 'DESC');
    $query->orderBy('ms.last_visit_ts', 'ASC');
    $records = $query->execute()->fetchAll();

    foreach ($records as $record) {
      $user = User::load((int) $record->uid);
      if (!$user) {
        continue;
      }

      $member = Link::fromTextAndUrl(
        $user->getDisplayName() . ' (#' . $user->id() . ')',
        Url::fromRoute('entity.user.canonical', ['user' => $user->id()])
      )->toString();

      $actions = [];
      $actions[] = Link::fromTextAndUrl(
        $this->t('Log Interaction'),
        Url::fromRoute('makerspace_member_success.log_contact', ['user' => $user->id()], [
          'query' => ['destination' => '/admin/makerspace/member-success/needs-review'],
        ])
      )->toString();
      $actions[] = Link::fromTextAndUrl(
        $this->t('Mark No Follow-Up'),
        Url::fromRoute('makerspace_member_success.mark_no_action_needed', [
          'user' => $user->id(),
          'stage' => $record->stage,
        ], [
          'query' => ['destination' => '/admin/makerspace/member-success/needs-review'],
        ])
      )->toString();

      $last_visit = !empty($record->last_visit_ts) ? date('Y-m-d H:i', (int) $record->last_visit_ts) : 'Never';
      $rows[] = [
        ['data' => ['#markup' => $member . '<br><small>' . $user->getEmail() . '</small>']],
        $record->stage,
        (int) $record->risk_score,
        $last_visit,
        (int) $record->visit_count_30d,
        ['data' => ['#markup' => implode(' | ', $actions)]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No members are currently marked for later review.'),
      '#attributes' => ['class' => ['ms-review-queue-table']],
    ];

    return $build;
  }

}
