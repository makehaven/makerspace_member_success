<?php

namespace Drupal\makerspace_member_success\Support;

/**
 * Validated inclusive calendar dates shared by the page and CSV downloads.
 */
final class ReportRange {

  /**
   * Validate dates and apply the same default period to pages and exports.
   */
  public static function resolve(?string $start, ?string $end, string $today): array {
    $end = $end ?: $today;
    foreach (array_filter([$start, $end]) as $value) {
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
      if (!$date || $date->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException('Use valid YYYY-MM-DD report dates.');
      }
    }
    $start = $start ?: (new \DateTimeImmutable($end))->modify('-90 days')->format('Y-m-d');
    if ($start > $end) {
      throw new \InvalidArgumentException('The start date must not follow the end date.');
    }
    return [$start, $end];
  }

}
