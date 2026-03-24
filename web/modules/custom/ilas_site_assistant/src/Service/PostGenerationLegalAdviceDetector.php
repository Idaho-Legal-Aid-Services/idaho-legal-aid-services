<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Shared legal-advice detector for post-generation output checks.
 */
final class PostGenerationLegalAdviceDetector {

  /**
   * Deterministic patterns for legal-advice output detection.
   */
  private const LEGAL_ADVICE_PATTERNS = [
    '/idaho\s+code\s*(§|section)/i',
    '/i\.c\.\s*§/i',
    '/under\s+(the\s+)?(law|statute|code)/i',
    '/according\s+to\s+(the\s+)?(law|statute)/i',
    '/(statute|code)\s+(says|states|requires)/i',
    '/you\s+(will|would)\s+(likely|probably)\s+(win|lose|succeed|fail)/i',
    '/your\s+chances\s+(of|are)/i',
    '/the\s+court\s+will\s+(likely|probably)/i',
    '/you\s+(are|\'re)\s+(likely|probably)\s+to\s+(win|lose)/i',
    '/you\s+should\s+(file|sue|appeal|claim|motion)/i',
    '/i\s+(would\s+)?(advise|recommend|suggest)\s+(you|that\s+you)/i',
    '/my\s+(legal\s+)?advice\s+is/i',
    '/the\s+best\s+(legal\s+)?(strategy|approach)\s+is/i',
    '/you\s+need\s+to\s+(file|submit|send)/i',
    '/you\s+should\s+(stop\s+paying|withhold|break\s+your)/i',
    '/don\'t\s+(pay|respond|go\s+to\s+court)/i',
    '/ignore\s+the\s+(notice|summons|order)/i',
    '/you\s+have\s+the\s+right\s+to/i',
    '/you\s+(definitely|clearly|certainly)\s+qualify/i',
    '/you\s+(do|don\'t)\s+qualify\s+for/i',
    // Paraphrase patterns for LLM output (AFRP-06 G-3).
    '/it\s+would\s+be\s+(in\s+your\s+interest|advisable|wise|prudent)\s+to\s+(file|sue|appeal|pursue|take)/i',
    '/the\s+prudent\s+(course|step|action|approach)\s+is\s+to/i',
    '/filing\s+(a\s+)?(motion|complaint|suit|claim)\s+would\s+(strengthen|improve|help|benefit)/i',
    '/given\s+the\s+circumstances.{0,40}(appropriate|advisable|recommended)\b/i',
    '/your\s+(best|strongest|most\s+effective)\s+(option|course|strategy|approach)\s+is/i',
    '/you\s+(could|might|may)\s+(want\s+to\s+)?(consider\s+)?(fil(e|ing)|su(e|ing)|appeal(ing)?)/i',
  ];

  /**
   * Returns TRUE when the provided text looks like legal advice.
   */
  public static function containsLegalAdvice(string $text): bool {
    $normalized = InputNormalizer::normalize($text);

    foreach (self::LEGAL_ADVICE_PATTERNS as $pattern) {
      if (preg_match($pattern, $normalized)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
