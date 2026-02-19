<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Static utility for normalizing user input before safety classification.
 *
 * Strips evasion techniques (interstitial punctuation, Unicode tricks,
 * spaced-out letters) while preserving legitimate text. Designed to run
 * BEFORE all classifier checks so that obfuscated text like "l.e.g.a.l"
 * or "s-h-o-u-l-d" is normalized to "legal" / "should".
 *
 * Key properties:
 * - Idempotent: normalize(normalize(x)) === normalize(x)
 * - Preserves legitimate hyphens: "self-help", "3-day", "U.S."
 * - No Drupal dependencies (static methods only, no DI)
 */
class InputNormalizer {

  /**
   * Applies the full normalization pipeline.
   *
   * Pipeline order:
   * 1. Unicode NFKC normalization
   * 2. Strip interstitial punctuation (l.e.g.a.l → legal)
   * 3. Collapse evasion spacing (l e g a l → legal)
   * 4. Normalize whitespace (collapse + trim)
   *
   * @param string $input
   *   Raw user input (already HTML-sanitized).
   *
   * @return string
   *   Normalized input safe for classifier pattern matching.
   */
  public static function normalize(string $input): string {
    $input = self::unicodeNfkc($input);
    $input = self::stripInterstitialPunctuation($input);
    $input = self::collapseEvasionSpacing($input);
    $input = self::normalizeWhitespace($input);

    return $input;
  }

  /**
   * Applies Unicode NFKC normalization.
   *
   * Converts compatibility characters to their canonical equivalents.
   * Falls back gracefully if the intl extension is not available.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   NFKC-normalized string.
   */
  public static function unicodeNfkc(string $input): string {
    if (class_exists('Normalizer')) {
      $normalized = \Normalizer::normalize($input, \Normalizer::FORM_KC);
      // Normalizer::normalize returns false on failure.
      return $normalized !== FALSE ? $normalized : $input;
    }
    return $input;
  }

  /**
   * Strips interstitial punctuation used to obfuscate words.
   *
   * Detects chains of 3+ single letters separated by dots, hyphens,
   * or underscores and joins them into words.
   *
   * Examples:
   *   - "l.e.g.a.l" → "legal"
   *   - "s-h-o-u-l-d" → "should"
   *   - "a_d_v_i_c_e" → "advice"
   *
   * Preserves:
   *   - "U.S." (only 2 segments — below threshold)
   *   - "self-help" (multi-char segments)
   *   - "3-day" (multi-char segments)
   *   - "A.M." / "P.M." (only 2 segments)
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   String with interstitial punctuation removed.
   */
  public static function stripInterstitialPunctuation(string $input): string {
    // Match chains of 3+ single letters separated by dots, hyphens, or
    // underscores. The pattern: single-letter, then (separator + single-letter)
    // repeated 2+ times (total 3+ letters).
    return preg_replace_callback(
      '/\b([a-zA-Z])[.\-_]([a-zA-Z])((?:[.\-_][a-zA-Z]){1,})\b/',
      function ($matches) {
        // $matches[0] is the full match like "l.e.g.a.l"
        // Strip all dots, hyphens, and underscores from the match.
        return preg_replace('/[.\-_]/', '', $matches[0]);
      },
      $input
    );
  }

  /**
   * Collapses evasion spacing (single letters separated by spaces).
   *
   * Detects chains of 3+ single letters separated by spaces and joins
   * them. Does not affect normal single-letter words like "I" or "a"
   * in sentences (they don't form chains of 3+).
   *
   * Examples:
   *   - "l e g a l" → "legal"
   *   - "s h o u l d" → "should"
   *
   * Preserves:
   *   - "I need a form" (no 3+ chain of single letters)
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   String with evasion spacing collapsed.
   */
  public static function collapseEvasionSpacing(string $input): string {
    // Match chains of 3+ single letters separated by single spaces.
    // Use word boundary at start and end to avoid matching mid-word.
    return preg_replace_callback(
      '/(?<![a-zA-Z])([a-zA-Z]) ([a-zA-Z])((?:\s[a-zA-Z]){1,})(?![a-zA-Z])/',
      function ($matches) {
        // Strip all spaces from the match.
        return preg_replace('/\s/', '', $matches[0]);
      },
      $input
    );
  }

  /**
   * Normalizes whitespace: collapse runs and trim.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   Whitespace-normalized string.
   */
  public static function normalizeWhitespace(string $input): string {
    return trim(preg_replace('/\s+/', ' ', $input));
  }

}
