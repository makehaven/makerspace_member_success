<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\DuplicateRecordFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Staff page: people who probably have two contacts or two accounts.
 */
class DuplicateRecordsController extends ControllerBase {

  public function __construct(
    protected readonly DuplicateRecordFinder $finder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.duplicate_finder'),
    );
  }

  /**
   * Lists the open rows from the last weekly check.
   */
  public function build(): array {
    $run = $this->finder->lastRun();
    $open = $this->finder->openRows($run);
    $ran = (int) ($run['ran'] ?? 0);

    $build = [];
    $build['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Records that probably belong to one person. A second contact splits a person\'s history: classes they took under one contact never count toward the membership on the other. Nothing here is merged automatically. Check each pair, merge it in CiviCRM or block the extra account, or mark it as two different people.'),
    ];
    $build['last'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $ran === 0
        ? $this->t('The check has not run yet. It runs weekly from cron; staff can run it now with <code>drush ms:duplicates --save</code>.')
        : $this->t('Last checked @when. Checked weekly. A merged or blocked record drops off at the next check.', ['@when' => date('Y-m-d H:i', $ran)]),
    ];

    $sections = [
      'contact' => $this->t('CiviCRM contacts'),
      'account' => $this->t('Website accounts'),
    ];
    foreach ($sections as $type => $title) {
      $rows = [];
      foreach ($open as $row) {
        if ($row['type'] !== $type) {
          continue;
        }
        $rows[] = $this->tableRow($row, $ran);
      }
      $build[$type . '_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('@title (@n)', ['@title' => $title, '@n' => count($rows)]),
      ];
      $build[$type] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Confidence'),
          $this->t('Name'),
          $this->t('Keep'),
          $type === 'contact' ? $this->t('Merge into it') : $this->t('Retire'),
          $this->t('Why'),
          $this->t('What to do'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No likely duplicates.'),
      ];
    }
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * Marks a pair as two different people and returns to the page.
   */
  public function dismiss(string $pair): RedirectResponse {
    $this->finder->dismiss($pair, (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Marked as not a duplicate. This pair will not be listed again.'));
    return new RedirectResponse(Url::fromRoute('makerspace_member_success.duplicates')->toString());
  }

  /**
   * One table row.
   */
  protected function tableRow(array $row, int $ran): array {
    $contact = $row['type'] === 'contact';
    $record = function (int $id, string $label) use ($contact): array {
      return [
        'data' => [
          'link' => [
            '#type' => 'link',
            '#title' => $contact ? $this->t('Contact @id', ['@id' => $id]) : $this->t('Account @id', ['@id' => $id]),
            '#url' => $contact
              ? Url::fromUri('internal:/civicrm/contact/view', ['query' => ['reset' => 1, 'cid' => $id]])
              : Url::fromRoute('entity.user.canonical', ['user' => $id]),
          ],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $label,
            '#attributes' => ['class' => ['description']],
          ],
        ],
      ];
    };

    $actions = [];
    // CiviCRM keeps one account link per contact, so two linked contacts wait
    // until the duplicate account is resolved.
    if ($contact && ($row['keep_uid'] === NULL || $row['other_uid'] === NULL)) {
      $actions['merge'] = [
        '#type' => 'link',
        '#title' => $this->t('Compare and merge'),
        '#url' => Url::fromUri('internal:/civicrm/contact/merge', [
          'query' => ['reset' => 1, 'cid' => $row['keep_id'], 'oid' => $row['other_id'], 'action' => 'update'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small'], 'target' => '_blank'],
      ];
    }
    $actions['dismiss'] = [
      '#type' => 'link',
      '#title' => $this->t('Not a duplicate'),
      '#url' => Url::fromRoute('makerspace_member_success.duplicates_dismiss', ['pair' => $row['key']]),
      '#attributes' => ['class' => ['button', 'button--small']],
    ];

    $confidence = (string) $row['confidence'];
    if (($row['first_seen'] ?? 0) >= $ran - 3600) {
      $confidence .= ' · new';
    }

    return [
      $confidence,
      $row['name'],
      $record((int) $row['keep_id'], (string) $row['keep_label']),
      $record((int) $row['other_id'], (string) $row['other_label']),
      implode(', ', $row['reasons']),
      $row['recommendation'],
      ['data' => $actions],
    ];
  }

}
