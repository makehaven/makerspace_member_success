<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_member_success\Service\DuplicateEvidence;
use Drupal\makerspace_member_success\Service\DuplicateRecordFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Staff page: people who probably have two contacts or two accounts.
 */
class DuplicateRecordsController extends ControllerBase {

  /**
   * CiviCRM rule "Name and Email (reserved)", used for the Find Duplicates link.
   */
  protected const CIVICRM_NAME_EMAIL_RULE = 1;

  public function __construct(
    protected readonly DuplicateRecordFinder $finder,
    protected readonly DuplicateEvidence $evidence,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makerspace_member_success.duplicate_finder'),
      $container->get('makerspace_member_success.duplicate_evidence'),
    );
  }

  /**
   * Lists the open rows from the last weekly check.
   */
  public function build(): array {
    $run = $this->finder->lastRun();
    $open = $this->finder->openRows($run);
    $ran = (int) ($run['ran'] ?? 0);
    $evidence = $this->evidence->load($open);

    $build = [];
    $build['#attached']['library'][] = 'makerspace_member_success/duplicates';
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ms-dup-intro']],
      'what' => [
        '#markup' => '<p>' . $this->t('Records that probably belong to one person. A second contact splits a person\'s history: classes taken under one contact never count toward the membership on the other. Each pair shows both records side by side; values the two share are <span class="ms-dup-match">marked</span>. Nothing here is merged automatically.') . '</p>',
      ],
      'how' => [
        '#markup' => '<p>' . $this->t('<strong>Compare and merge</strong> opens CiviCRM\'s own merge screen in a new tab, where you choose field by field which value to keep. <strong>Not a duplicate</strong> is shared with CiviCRM: the pair also drops off CiviCRM\'s Find Duplicates, and pairs marked there do not appear here.') . '</p>',
      ],
      'civicrm' => [
        '#markup' => '<p>' . $this->t('Why a separate list: CiviCRM only compares contacts, one rule at a time, when someone runs it, and its rules here match on name alone (every same-name stranger) or email alone (a family sharing one email). This list runs weekly, also catches duplicate website accounts, and pairs a class-only contact with the same person\'s member record. You can still use <a href=":find" target="_blank">CiviCRM Find Duplicates</a> or edit its <a href=":rules" target="_blank">duplicate rules</a>.', [
          ':find' => Url::fromUri('internal:/civicrm/contact/dedupefind', ['query' => ['reset' => 1, 'action' => 'update', 'rgid' => self::CIVICRM_NAME_EMAIL_RULE]])->toString(),
          ':rules' => Url::fromUri('internal:/civicrm/contact/deduperules', ['query' => ['reset' => 1]])->toString(),
        ]) . '</p>',
      ],
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
        $rows[] = $this->tableRow($row, $ran, $evidence);
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
          $this->t('Keep'),
          $type === 'contact' ? $this->t('Merge into it') : $this->t('Retire'),
          $this->t('Why it was listed'),
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
    $this->messenger()->addStatus($this->t('Marked as not a duplicate, here and in CiviCRM. This pair will not be listed again.'));
    return new RedirectResponse(Url::fromRoute('makerspace_member_success.duplicates')->toString());
  }

  /**
   * One table row.
   */
  protected function tableRow(array $row, int $ran, array $evidence): array {
    $contact = $row['type'] === 'contact';
    $keep_id = (int) $row['keep_id'];
    $other_id = (int) $row['other_id'];

    if ($contact) {
      $a = $evidence['contacts'][$keep_id] ?? NULL;
      $b = $evidence['contacts'][$other_id] ?? NULL;
      $keep = $this->contactCard($keep_id, $a, $b, $evidence['accounts']);
      $other = $this->contactCard($other_id, $b, $a, $evidence['accounts']);
    }
    else {
      $a = $evidence['accounts'][$keep_id] ?? NULL;
      $b = $evidence['accounts'][$other_id] ?? NULL;
      $keep = $this->accountCard($keep_id, $a, $b, $evidence['contacts']);
      $other = $this->accountCard($other_id, $b, $a, $evidence['contacts']);
    }

    $actions = [];
    // CiviCRM keeps one account link per contact, so two linked contacts wait
    // until the duplicate account is resolved.
    if ($contact && ($row['keep_uid'] === NULL || $row['other_uid'] === NULL)) {
      $actions['merge'] = [
        '#type' => 'link',
        '#title' => $this->t('Compare and merge'),
        '#url' => Url::fromUri('internal:/civicrm/contact/merge', [
          'query' => ['reset' => 1, 'cid' => $keep_id, 'oid' => $other_id, 'action' => 'update'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small', 'button--primary'], 'target' => '_blank'],
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
      ['data' => $keep],
      ['data' => $other],
      ['data' => ['#markup' => '<p>' . Html::escape(ucfirst(implode(', ', $row['reasons']))) . '.</p><p class="ms-dup-record__label">' . Html::escape((string) $row['recommendation']) . '</p>']],
      ['data' => $actions],
    ];
  }

  /**
   * One contact, with the values it shares with the other marked.
   */
  protected function contactCard(int $id, ?array $c, ?array $other, array $accounts): array {
    $link = $this->recordLink(Url::fromUri('internal:/civicrm/contact/view', ['query' => ['reset' => 1, 'cid' => $id]]), $this->t('Contact @id', ['@id' => $id]), Html::decodeEntities($c['name'] ?? ''));
    if ($c === NULL) {
      return $this->card($link, ['<span class="ms-dup-warn">' . $this->t('No longer exists.') . '</span>']);
    }
    $lines = [];
    if ($c['deleted']) {
      $lines[] = '<span class="ms-dup-warn">' . $this->t('In CiviCRM trash.') . '</span>';
    }
    $lines[] = $this->field((string) $this->t('Email'), $c['emails'], $other['emails'] ?? []);
    $lines[] = $this->field((string) $this->t('Phone'), $c['phones'], $other['phones'] ?? []);
    $lines[] = $this->field((string) $this->t('Street'), $c['streets'], $other['streets'] ?? []);
    $lines[] = $this->field((string) $this->t('ZIP'), $c['zips'], $other['zips'] ?? []);
    $lines[] = $this->field((string) $this->t('Born'), array_filter([$c['birth_date']]), array_filter([$other['birth_date'] ?? '']));
    $lines[] = $this->loginLine($c['uid'] !== NULL ? ($accounts[$c['uid']] ?? NULL) : NULL, $c['uid']);
    $lines[] = $this->historyLine($c);
    $lines[] = $this->label((string) $this->t('Created')) . Html::escape($c['created'] ?: '—');
    return $this->card($link, $lines);
  }

  /**
   * One website account, with the values it shares with the other marked.
   */
  protected function accountCard(int $uid, ?array $u, ?array $other, array $contacts): array {
    $link = $this->recordLink(Url::fromRoute('entity.user.canonical', ['user' => $uid]), $this->t('Account @id', ['@id' => $uid]), $u['name'] ?? '');
    if ($u === NULL) {
      return $this->card($link, ['<span class="ms-dup-warn">' . $this->t('No longer exists.') . '</span>']);
    }
    $lines = [];
    if ($u['blocked']) {
      $lines[] = '<span class="ms-dup-warn">' . $this->t('Blocked.') . '</span>';
    }
    $lines[] = $this->field((string) $this->t('Username'), [$u['username']], []);
    $lines[] = $this->field((string) $this->t('Email'), array_filter([$u['mail']]), array_filter([$other['mail'] ?? '']));
    $roles = array_values(array_diff($u['roles'], ['authenticated']));
    $lines[] = $this->label((string) $this->t('Roles')) . Html::escape($roles ? implode(', ', $roles) : '—');
    $lines[] = $this->label((string) $this->t('Last login')) . Html::escape($u['access'] > 0 ? date('Y-m-d', $u['access']) : (string) $this->t('never'));
    $lines[] = $this->field((string) $this->t('Chargebee'), array_filter([$u['chargebee']]), array_filter([$other['chargebee'] ?? '']));
    $lines[] = $this->label((string) $this->t('Created')) . date('Y-m-d', $u['created']);
    $c = $u['contact_id'] !== NULL ? ($contacts[$u['contact_id']] ?? NULL) : NULL;
    if ($c !== NULL) {
      $contact_link = Url::fromUri('internal:/civicrm/contact/view', ['query' => ['reset' => 1, 'cid' => $c['id']]])->toString();
      $lines[] = $this->label((string) $this->t('Contact')) . '<a href="' . Html::escape($contact_link) . '">' . $c['id'] . '</a>';
      $lines[] = $this->historyLine($c);
    }
    else {
      $lines[] = $this->label((string) $this->t('Contact')) . $this->t('none linked');
    }
    return $this->card($link, $lines);
  }

  /**
   * "Label: a, b" with values the other record also has marked.
   */
  protected function field(string $label, array $values, array $other): string {
    if ($values === []) {
      return $this->label($label) . '—';
    }
    $shared = array_flip(DuplicateEvidence::shared($values, $other));
    $out = [];
    foreach ($values as $v) {
      $out[] = isset($shared[$v]) ? '<span class="ms-dup-match">' . Html::escape($v) . '</span>' : Html::escape($v);
    }
    return $this->label($label) . implode(', ', $out);
  }

  /**
   * The website login behind a contact.
   */
  protected function loginLine(?array $u, ?int $uid): string {
    if ($uid === NULL) {
      return $this->label((string) $this->t('Login')) . $this->t('none');
    }
    $link = '<a href="' . Html::escape(Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString()) . '">' . Html::escape($u['username'] ?? (string) $uid) . '</a>';
    if ($u === NULL) {
      return $this->label((string) $this->t('Login')) . $link;
    }
    $bits = [in_array('member', $u['roles'], TRUE) ? (string) $this->t('member') : (string) $this->t('not a member')];
    if ($u['blocked']) {
      $bits[] = (string) $this->t('blocked');
    }
    $bits[] = $u['access'] > 0 ? (string) $this->t('last login @d', ['@d' => date('Y-m-d', $u['access'])]) : (string) $this->t('never logged in');
    return $this->label((string) $this->t('Login')) . $link . ' · ' . Html::escape(implode(', ', $bits));
  }

  /**
   * Events, payments and memberships on a contact.
   */
  protected function historyLine(array $c): string {
    $parts = [];
    $parts[] = $c['events'] > 0
      ? $this->formatPlural($c['events'], '1 event', '@count events') . ' (' . $this->t('last: @e', ['@e' => $c['last_event']]) . ')'
      : (string) $this->t('no events');
    $parts[] = $c['payments'] > 0
      ? $this->formatPlural($c['payments'], '1 payment', '@count payments') . ' ($' . number_format($c['paid']) . ', ' . $this->t('last @d', ['@d' => $c['last_payment']]) . ')'
      : (string) $this->t('no payments');
    if ($c['memberships'] > 0) {
      $parts[] = (string) $this->formatPlural($c['memberships'], '1 membership', '@count memberships');
    }
    // Each part is already escaped: plural counts, dates, and the event title
    // through its @placeholder.
    return $this->label((string) $this->t('History')) . implode('; ', array_map('strval', $parts));
  }

  /**
   * Bold record link with the record's name under it.
   */
  protected function recordLink(Url $url, $title, string $name): string {
    $html = '<a href="' . Html::escape($url->toString()) . '">' . Html::escape((string) $title) . '</a>';
    return $name !== '' ? $html . ' — ' . Html::escape($name) : $html;
  }

  /**
   * "Label: " prefix.
   */
  protected function label(string $label): string {
    return '<span class="ms-dup-record__label">' . Html::escape($label) . ':</span> ';
  }

  /**
   * A record card: heading line then one line per fact.
   */
  protected function card(string $head, array $lines): array {
    $items = '';
    foreach ($lines as $line) {
      $items .= '<li>' . $line . '</li>';
    }
    return ['#markup' => '<div class="ms-dup-record__head">' . $head . '</div><ul class="ms-dup-record">' . $items . '</ul>'];
  }

}
