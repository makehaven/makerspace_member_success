<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\makerspace_member_success\Service\DuplicateRecordFinder;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the weekly duplicate-record check.
 */
class DuplicateCheckCommands extends DrushCommands {

  public function __construct(
    protected readonly DuplicateRecordFinder $finder,
  ) {
    parent::__construct();
  }

  /**
   * List likely duplicate CiviCRM contacts and website accounts.
   *
   * Read-only: never merges, blocks or edits a record. Each row names the
   * record to keep and why. Cron runs this weekly and saves the result for
   * the "Possible Duplicates" staff page.
   *
   * @param array $options
   *   Command options.
   *
   * @option type
   *   contacts, accounts, or all.
   * @option save
   *   Also save the result for the staff page (what cron does).
   * @option site-base
   *   Base URL for the links in the output.
   *
   * @command ms:duplicates
   * @aliases ms-dupes
   * @usage drush ms:duplicates
   *   Print every likely duplicate pair.
   * @usage drush ms:duplicates --type=accounts --format=csv --fields=*
   *   Website accounts only, as CSV, with each record's detail.
   * @usage drush ms:duplicates --save
   *   Refresh the staff page now instead of waiting for the weekly run.
   *
   * @field-labels
   *   type: Type
   *   confidence: Confidence
   *   name: Name
   *   keep: Keep
   *   retire: Merge/retire
   *   keep_detail: Keep detail
   *   retire_detail: Other detail
   *   why: Why
   *   recommendation: Recommendation
   *   keep_link: Keep link
   *   retire_link: Other link
   * @default-fields type,confidence,name,keep,retire,why,recommendation,keep_link,retire_link
   */
  public function duplicates(array $options = [
    'type' => 'all',
    'save' => FALSE,
    'site-base' => 'https://www.makehaven.org',
    'format' => 'table',
  ]): RowsOfFields {
    $type = (string) $options['type'];
    if (!empty($options['save'])) {
      $run = $this->finder->runAndSave();
      $rows = $this->finder->openRows($run);
      $this->logger()->success(dt('Saved for the staff page: @n open, @new new since the last saved run.', [
        '@n' => count($rows),
        '@new' => $run['new'],
      ]));
    }
    else {
      $rows = match ($type) {
        'contacts' => $this->finder->findContactDuplicates(),
        'accounts' => $this->finder->findAccountDuplicates(),
        default => array_merge($this->finder->findContactDuplicates(), $this->finder->findAccountDuplicates()),
      };
    }
    if ($type === 'contacts' || $type === 'accounts') {
      $want = rtrim($type, 's');
      $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['type'] === $want));
    }

    $base = rtrim((string) $options['site-base'], '/');
    $out = [];
    foreach ($rows as $row) {
      $contact = $row['type'] === 'contact';
      $out[] = [
        'type' => $row['type'],
        'confidence' => $row['confidence'],
        'name' => $row['name'],
        'keep' => sprintf('%s %d', $contact ? 'cid' : 'uid', $row['keep_id']),
        'retire' => sprintf('%s %d', $contact ? 'cid' : 'uid', $row['other_id']),
        'keep_detail' => $row['keep_label'],
        'retire_detail' => $row['other_label'],
        'why' => implode(', ', $row['reasons']),
        'recommendation' => $row['recommendation'],
        'keep_link' => $contact
          ? $base . '/civicrm/contact/view?reset=1&cid=' . $row['keep_id']
          : $base . '/user/' . $row['keep_id'],
        'retire_link' => $contact
          ? $base . '/civicrm/contact/view?reset=1&cid=' . $row['other_id']
          : $base . '/user/' . $row['other_id'],
      ];
    }
    $counts = array_count_values(array_column($rows, 'type'));
    $this->logger()->notice(dt('@c contact pair(s), @a account pair(s). Nothing was changed.', [
      '@c' => $counts['contact'] ?? 0,
      '@a' => $counts['account'] ?? 0,
    ]));
    return new RowsOfFields($out);
  }

}
