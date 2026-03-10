<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Shared heuristics for separating benign informational phrasing from risk.
 */
final class InformationalRiskHeuristics {

  /**
   * Informational phrasing used for safety-category dampening.
   */
  private const SAFETY_INFORMATIONAL_PATTERNS = [
    '/\b(form\s*to|how\s*to\s*(file|dismiss|respond|answer))\b/i',
    '/\b(information\s*(on|about)|learn\s*about|tell\s*me\s*about)\b/i',
    '/\b(what\s*(is|are)\s*the\s*(process|steps|law|rule))\b/i',
    '/\b(where\s*can\s*i\s*find|legal\s*process\s*of)\b/i',
    '/\b(how\s*to\s*evict\s*a\s*tenant|evict\s*a\s*tenant\s*who)\b/i',
    '/\b(dismiss\s*an?\s*eviction|respond\s*to\s*an?\s*eviction)\b/i',
    '/\b(what\s*form|what\s*do\s*i\s*need)\b/i',
    '/\b(can\s*(a|my)\s*landlord|rights\s*(as|of))\b/i',
    '/\b(how\s*do\s*i\s*report|who\s*do\s*i\s*contact)\b/i',
  ];

  /**
   * Informational phrasing used for deadline dampening.
   */
  private const DEADLINE_INFORMATIONAL_PATTERNS = [
    '/\b(how\s*long\s*do\s*i\s*have|what\s*is\s*the\s*deadline|when\s*is\s*the\s*deadline|typical\s*deadline|general\s*deadline|deadline\s*information)\b/i',
    '/\b(deadline\s*for\s*(eviction|filing)|how\s*many\s*days|how\s*much\s*time\s*do\s*i\s*have)\b/i',
    '/\b(cuanto\s*tiempo\s*tengo|cual\s*es\s*la\s*fecha\s*limite)\b/i',
    '/\b(general\s*information\s*about\s*court\s*dates|learn\s*about\s*deadlines)\b/i',
  ];

  /**
   * Context markers that indicate an active personal or relational case.
   */
  private const ACTIVE_RISK_CONTEXT_PATTERNS = [
    '/\bmy\s+\d[-\s]*day\b/i',
    '/\bmy\s+(eviction|3[-\s]*day|five[-\s]*day|three[-\s]*day|answer|lawsuit|summons|complaint|deadline)\b/i',
    '/\b(i\s+(got|received|have)|just\s+got|just\s+received)\b/i',
    '/\b(someone\s+stole\s+my|they\s+took\s+my|stole\s+my)\b/i',
    '/\b(today|tonight|right\s+now|this\s+(morning|afternoon|evening))\b/i',
    '/\b(was\s+(served|given)|handed\s+me|gave\s+me|served\s+with\s*(papers|summons))\b/i',
    '/\b(this\s+(lawsuit|notice|summons|complaint)|my\s+(answer|lawsuit|summons|complaint|deadline))\b/i',
    '/\bmy\s+(mom|mother|dad|father|friend|brother|sister|husband|wife|partner|spouse|son|daughter|child(ren)?|kid(s)?|parent|grandparent|grandmother|grandfather|boyfriend|girlfriend)(?:\'s|\s+who|\s+is|\s+got|\s+received|\s+was|\s+has|\s+keeps|\s+says)\b/i',
  ];

  /**
   * Deadline-specific context markers that indicate a live personal deadline.
   */
  private const ACTIVE_DEADLINE_CONTEXT_PATTERNS = [
    '/\b(this\s+(lawsuit|case|notice|summons|complaint)|my\s+(answer|paperwork|lawsuit|case|deadline))\b/i',
    '/\b(served\s+with\s*(papers|summons)|got\s+served)\b/i',
    '/\b(file|respond)\s+by\s+(today|tomorrow|friday|monday|this\s*week|tonight|next\s*monday)\b/i',
    '/\b(have\s+to|need\s+to|must)\s+(file|respond)\s+by\s+(today|tomorrow|friday|monday|this\s*week|tonight|next\s*monday)\b/i',
  ];

  /**
   * Returns TRUE when safety-topic phrasing is informational with no urgency.
   */
  public static function isPurelyInformationalSafetyQuery(string $message): bool {
    return self::matchesAny($message, self::SAFETY_INFORMATIONAL_PATTERNS)
      && !self::hasActiveRiskContext($message);
  }

  /**
   * Returns TRUE when deadline phrasing is informational with no urgency.
   */
  public static function isPurelyInformationalDeadlineQuery(string $message): bool {
    return self::matchesAny($message, self::DEADLINE_INFORMATIONAL_PATTERNS)
      && !self::hasActiveDeadlineContext($message);
  }

  /**
   * Returns TRUE when the message describes an active personal or family case.
   */
  public static function hasActiveRiskContext(string $message): bool {
    return self::matchesAny($message, self::ACTIVE_RISK_CONTEXT_PATTERNS);
  }

  /**
   * Returns TRUE when the message describes a live personal deadline.
   */
  public static function hasActiveDeadlineContext(string $message): bool {
    return self::matchesAny($message, self::ACTIVE_DEADLINE_CONTEXT_PATTERNS);
  }

  /**
   * Returns TRUE when any pattern matches.
   */
  private static function matchesAny(string $message, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
