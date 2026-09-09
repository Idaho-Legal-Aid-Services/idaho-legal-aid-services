<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Decides whether an active eviction/housing thread should override an
 * incoming `offices_contact` (or other generic follow-up) intent.
 *
 * Encapsulates the predicate logic that the controller and AssistantFlowRunner
 * both consult. Keeping this in a stateless service avoids the controller
 * being called by services (a backwards coupling) and lets the predicate be
 * regression-tested in isolation.
 */
class HousingEvictionContinuityDecider {

  /**
   * Topic types that represent a clear topic switch and must NEVER be
   * overridden, even if the message also carries a county phrase.
   */
  private const TOPIC_SWITCH_PREFIXES = [
    'topic_family',
    'topic_consumer',
    'topic_benefits',
    'topic_civil_rights',
    'topic_seniors',
    'topic_health',
  ];

  /**
   * Pattern matching eviction-related language in the active message or
   * recent history text.
   */
  private const EVICTION_PATTERN = '/\b(eviction|evict(?:ed|ing)?|notice\s+to\s+(?:vacate|quit)|lockout|unlawful\s+detainer|3[\s-]?day\s+notice)\b/i';

  /**
   * Returns TRUE when the housing-eviction continuity guard should fire and
   * override the supplied intent (typically `offices_contact` from a
   * bare-city follow-up).
   */
  public function shouldOverrideOfficesContact(
    array $intent,
    array $generic_followup_intents,
    array $server_history,
    array $conversation_context_summary,
    string $current_message,
    bool $is_location_like_reply,
    bool $is_next_step_followup,
  ): bool {
    if ($server_history === []) {
      return FALSE;
    }

    $intent_type = (string) ($intent['type'] ?? 'unknown');

    foreach (self::TOPIC_SWITCH_PREFIXES as $prefix) {
      if (str_starts_with($intent_type, $prefix)) {
        return FALSE;
      }
    }

    // An explicit office question ("where is the Boise office", "donde
    // estan sus oficinas") is a new request, not a location refinement of
    // the eviction thread, even when it names a city. Only bare-city or
    // anaphoric replies remain override-eligible for offices_contact.
    if ($intent_type === 'offices_contact' && self::hasExplicitOfficePhrasing($current_message)) {
      return FALSE;
    }

    $is_override_eligible = in_array($intent_type, $generic_followup_intents, TRUE)
      || str_starts_with($intent_type, 'intent_pack_meta_')
      || $intent_type === 'offices_contact';
    if (!$is_override_eligible) {
      return FALSE;
    }

    if (!$is_location_like_reply && !$is_next_step_followup) {
      return FALSE;
    }

    if (HistoryIntentResolver::detectResetSignal($current_message)) {
      return FALSE;
    }

    return $this->isHousingEvictionFollowup(
      $conversation_context_summary,
      $server_history,
      $current_message
    );
  }

  /**
   * Returns TRUE when the active conversation is in an eviction/housing thread.
   *
   * Checks the conversation context summary first (cheap & deterministic),
   * then falls back to scanning recent history texts for eviction keywords.
   */
  public function isHousingEvictionFollowup(array $conversation_context_summary, array $server_history, string $current_message): bool {
    $current_topic = (string) ($conversation_context_summary['current_topic'] ?? '');
    if ($current_topic === 'housing_eviction') {
      return TRUE;
    }

    $deadline = (string) ($conversation_context_summary['deadline_or_notice'] ?? '');
    if (in_array($deadline, ['3_day_notice', 'lockout', 'eviction_notice'], TRUE)) {
      return TRUE;
    }

    if (preg_match(self::EVICTION_PATTERN, $current_message) === 1) {
      return TRUE;
    }

    $recent = array_slice($server_history, -6);
    foreach ($recent as $entry) {
      $text = (string) ($entry['text'] ?? '');
      if ($text !== '' && preg_match(self::EVICTION_PATTERN, $text) === 1) {
        return TRUE;
      }
      $entry_topic = (string) ($entry['topic'] ?? '');
      if ($entry_topic === 'eviction') {
        return TRUE;
      }
      $entry_intent = (string) ($entry['intent'] ?? '');
      if (str_starts_with($entry_intent, 'topic_housing_eviction')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when the message explicitly asks about an office.
   */
  public static function hasExplicitOfficePhrasing(string $message): bool {
    return (bool) preg_match('/\b(office|offices|oficina[s]?|ubicaci[oó]n(?:es)?|direcci[oó]n(?:es)?|horario[s]?)\b/iu', $message);
  }

}
