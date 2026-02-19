<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Resolves intent from conversation history when direct routing returns unknown.
 *
 * Pure-PHP static utility with no Drupal dependencies. Activated ONLY when
 * IntentRouter::route() returns 'unknown'. Analyzes recent server_history
 * entries to find a dominant topic intent for multi-turn continuity.
 */
class HistoryIntentResolver {

  /**
   * Default max history turns to analyze.
   */
  const DEFAULT_MAX_TURNS = 6;

  /**
   * Default time window in seconds (10 minutes).
   */
  const DEFAULT_TIME_WINDOW_SEC = 600;

  /**
   * Default dominance threshold (50%).
   */
  const DEFAULT_DOMINANCE_THRESHOLD = 0.5;

  /**
   * Default minimum eligible turns needed.
   */
  const DEFAULT_MIN_ELIGIBLE_TURNS = 1;

  /**
   * Intents that should never propagate as topic context.
   *
   * Safety, meta, and non-topical intents are excluded from history-based
   * fallback because they don't represent a user's ongoing topic of interest.
   */
  const EXCLUDED_INTENTS = [
    'unknown',
    'greeting',
    'disambiguation',
    'office_location_followup',
    'office_location_followup_miss',
    'out_of_scope',
    'urgent_safety',
    'high_risk',
    'escalation',
    'clarify',
  ];

  /**
   * Phrases that signal a topic shift — suppresses history fallback.
   *
   * Covers English and Spanish. Matched case-insensitively against the
   * beginning or interior of the current message.
   */
  const RESET_SIGNALS = [
    'new question',
    'different issue',
    'switching gears',
    'actually,',
    'instead,',
    'something else',
    'different topic',
    'otra pregunta',
    'cambiar de tema',
    'otra cosa',
  ];

  /**
   * Attempts to resolve intent from conversation history.
   *
   * @param array $server_history
   *   Array of history entries, each with 'intent', 'timestamp', 'role'.
   * @param string $current_message
   *   The current user message (checked for reset signals).
   * @param int $now
   *   Current timestamp (injectable for testing).
   * @param array $config
   *   Optional config overrides with keys:
   *   - history_max_turns (int)
   *   - history_time_window_sec (int)
   *   - history_dominance_threshold (float)
   *   - history_min_eligible_turns (int)
   *
   * @return array|null
   *   NULL if no fallback should apply. Otherwise array with:
   *   - 'intent' (string): The dominant intent from history.
   *   - 'confidence' (float): Dominance ratio (0-1).
   *   - 'turns_analyzed' (int): Number of eligible turns considered.
   *   - 'reason' (string): Human-readable reason for the fallback.
   */
  public static function resolveFromHistory(array $server_history, string $current_message, int $now, array $config = []): ?array {
    // Step 1: Check for topic-shift reset signals.
    if (self::detectResetSignal($current_message)) {
      return NULL;
    }

    // Step 2: Extract config with defaults.
    $max_turns = (int) ($config['history_max_turns'] ?? self::DEFAULT_MAX_TURNS);
    $time_window = (int) ($config['history_time_window_sec'] ?? self::DEFAULT_TIME_WINDOW_SEC);
    $dominance_threshold = (float) ($config['history_dominance_threshold'] ?? self::DEFAULT_DOMINANCE_THRESHOLD);
    $min_eligible = (int) ($config['history_min_eligible_turns'] ?? self::DEFAULT_MIN_ELIGIBLE_TURNS);

    // Step 3: Filter eligible turns from recent history.
    // Only look at the last $max_turns entries.
    $recent = array_slice($server_history, -$max_turns);
    $eligible = [];

    foreach ($recent as $entry) {
      // Must have an intent.
      $intent = $entry['intent'] ?? 'unknown';

      // Skip excluded intents.
      if (in_array($intent, self::EXCLUDED_INTENTS, TRUE)) {
        continue;
      }

      // Skip entries outside the time window.
      $timestamp = $entry['timestamp'] ?? 0;
      if (($now - $timestamp) > $time_window) {
        continue;
      }

      $eligible[] = $intent;
    }

    // Step 4: Check minimum eligible turns.
    if (count($eligible) < $min_eligible) {
      return NULL;
    }

    // Step 5: Build frequency map and find dominant intent.
    $frequencies = array_count_values($eligible);
    arsort($frequencies);

    $top_intents = array_keys($frequencies);
    $top_count = $frequencies[$top_intents[0]];
    $total = count($eligible);
    $dominance = $top_count / $total;

    // Check for tie: if second-place has the same count, no clear dominant.
    if (count($top_intents) > 1 && $frequencies[$top_intents[1]] === $top_count) {
      return NULL;
    }

    // Check dominance threshold.
    if ($dominance < $dominance_threshold) {
      return NULL;
    }

    return [
      'intent' => $top_intents[0],
      'confidence' => round($dominance, 2),
      'turns_analyzed' => $total,
      'reason' => 'direct_unknown + dominant_recent_history',
    ];
  }

  /**
   * Detects if the current message contains a topic-shift reset signal.
   *
   * @param string $message
   *   The user's current message.
   *
   * @return bool
   *   TRUE if a reset signal is detected.
   */
  public static function detectResetSignal(string $message): bool {
    $lower = mb_strtolower(trim($message));

    foreach (self::RESET_SIGNALS as $signal) {
      if (str_contains($lower, $signal)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
