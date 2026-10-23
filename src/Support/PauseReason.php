<?php

namespace Drupal\makerspace_member_success\Support;

use Drupal\Component\Utility\Crypt;

/**
 * The "why are you pausing?" answers and the signed link that carries them.
 *
 * A pause is a decision point we know nothing about: the member clicks pause
 * in the Chargebee portal and no one asks why. 46% of pausers had already
 * stopped coming in before they paused (retention brief, 2026-09-10), so
 * "not using it enough" is a choice of its own rather than folded into
 * "busy" — it is the answer that most changes what we say at pause end.
 */
final class PauseReason {

  /**
   * Answer code => label, in the order the email lists them.
   */
  public const REASONS = [
    'busy' => 'Too busy right now',
    'away' => 'Travelling or away',
    'school' => 'School or semester schedule',
    'not_using' => 'Not using the space enough',
    'cost' => 'Cost',
    'health_family' => 'Health or family',
    'other' => 'Something else',
  ];

  /**
   * Signed token for one member's one pause.
   *
   * The answer is not part of the signature, so the page can let the member
   * change their pick before submitting.
   */
  public static function token(int $uid, string $pauseStart, string $salt): string {
    return substr(Crypt::hmacBase64('pause-reason:' . $uid . ':' . $pauseStart, $salt), 0, 24);
  }

  /**
   * Whether a token was issued for this member and pause.
   */
  public static function validToken(int $uid, string $pauseStart, string $token, string $salt): bool {
    return $token !== '' && hash_equals(self::token($uid, $pauseStart, $salt), $token);
  }

  /**
   * Label for an answer code, or '' when the code is unknown.
   */
  public static function label(string $reason): string {
    return self::REASONS[$reason] ?? '';
  }

  /**
   * Plain-text list of one link per answer, for the email body.
   *
   * Label and link on separate lines: the outreach mail is plain text and the
   * mailer wraps long lines, which would otherwise split label from link.
   */
  public static function linkList(string $baseUrl, int $uid, string $pauseStart, string $salt): string {
    $blocks = [];
    foreach (self::REASONS as $code => $label) {
      $blocks[] = $label . "\n" . self::url($baseUrl, $uid, $pauseStart, $salt, $code);
    }
    return implode("\n\n", $blocks);
  }

  /**
   * The answer page link, optionally with one answer preselected.
   */
  public static function url(string $baseUrl, int $uid, string $pauseStart, string $salt, string $reason = ''): string {
    $url = $baseUrl . '/membership/pause-reason/' . $uid . '/' . $pauseStart . '/' . self::token($uid, $pauseStart, $salt);
    return $reason !== '' ? $url . '?r=' . $reason : $url;
  }

}
