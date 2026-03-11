<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * 4-level no-dead-end fallback tree evaluator.
 *
 * Pure-PHP static utility with no Drupal dependencies. Given the current
 * intent, retrieval results, conversation history, and optional TopIntentsPack,
 * determines the appropriate fallback level and returns an actionable response
 * structure with at least 2 links.
 *
 * Levels:
 *   1: Known intent, no results, first attempt → clarifier chips + contact
 *   2: Repeated same-area failure → parent service area page + sub-topic chips
 *   3: 2+ failures → prominent contact info + nearby intents
 *   4: Terminal → direct human connection + all contact channels
 *
 * Invariant: every level includes >= 2 actionable links.
 */
class FallbackTreeEvaluator {

  /**
   * Maps intent prefixes to service areas.
   */
  const INTENT_AREA_MAP = [
    'topic_housing' => 'housing',
    'topic_family' => 'family',
    'topic_seniors' => 'seniors',
    'topic_health' => 'health',
    'topic_consumer' => 'consumer',
    'topic_civil_rights' => 'civil_rights',
    'topic_employment' => 'civil_rights',
    'topic_benefits' => 'health',
  ];

  /**
   * Determines the fallback level based on context.
   *
   * @param string $intent
   *   The current intent key.
   * @param array $retrieval_results
   *   Search results (empty means no results).
   * @param array $server_history
   *   Conversation history entries.
   *
   * @return int
   *   Fallback level (1-4).
   */
  public static function determineLevel(string $intent, array $retrieval_results, array $server_history): int {
    // Count consecutive fallback turns in recent history.
    $fallback_count = self::countRecentFallbacks($server_history);

    // Level 4: 3+ consecutive failures.
    if ($fallback_count >= 3) {
      return 4;
    }

    // Level 3: 2 failures.
    if ($fallback_count >= 2) {
      return 3;
    }

    // Level 2: repeated failure in same service area.
    $area = self::resolveArea($intent);
    if ($area && $fallback_count >= 1 && self::hasSameAreaInHistory($area, $server_history)) {
      return 2;
    }

    // Level 1: first attempt with a known intent.
    return 1;
  }

  /**
   * Evaluates a fallback level and returns actionable response data.
   *
   * @param string $intent
   *   The current intent key.
   * @param array $retrieval_results
   *   Search results.
   * @param array $server_history
   *   Conversation history.
   * @param \Drupal\ilas_site_assistant\Service\TopIntentsPack|null $pack
   *   Optional Top Intents Pack.
   * @param array $canonical_urls
   *   Runtime canonical URL map.
   *
   * @return array
   *   Response data with keys:
   *   - 'level' (int): The fallback level (1-4)
   *   - 'message' (string): The fallback message
   *   - 'primary_action' (array): {label, url}
   *   - 'links' (array): Additional actionable links (>= 2 total)
   *   - 'suggestions' (array): Chip suggestions (optional)
   */
  public static function evaluateLevel(string $intent, array $retrieval_results, array $server_history, ?TopIntentsPack $pack = NULL, array $canonical_urls = []): array {
    $level = self::determineLevel($intent, $retrieval_results, $server_history);
    $area = self::resolveArea($intent);
    $urls = $canonical_urls;
    $urls['service_areas'] = isset($urls['service_areas']) && is_array($urls['service_areas']) ? $urls['service_areas'] : [];

    switch ($level) {
      case 1:
        return self::buildLevel1($intent, $area, $pack, $urls);

      case 2:
        return self::buildLevel2($intent, $area, $pack, $urls);

      case 3:
        return self::buildLevel3($area, $pack, $urls);

      case 4:
      default:
        return self::buildLevel4($urls);
    }
  }

  /**
   * Level 1: clarifier chips + contact secondary actions.
   */
  protected static function buildLevel1(string $intent, ?string $area, ?TopIntentsPack $pack, array $urls): array {
    $suggestions = [];
    $message = "I wasn't able to find a match for that. Let me help you narrow it down.";

    // Try pack clarifier first.
    if ($pack) {
      $clarifier = $pack->getClarifier($intent);
      if ($clarifier) {
        $message = $clarifier['question'];
        $suggestions = array_map(function ($opt) {
          return ['label' => $opt['label'], 'action' => $opt['intent']];
        }, $clarifier['options'] ?? []);
      }
      // Try chips as fallback.
      elseif ($chips = $pack->getChips($intent)) {
        $suggestions = array_map(function ($chip) {
          return ['label' => $chip['label'], 'action' => $chip['intent']];
        }, $chips);
      }
    }

    $links = [
      ['label' => 'Apply for Help', 'url' => $urls['apply'], 'type' => 'apply'],
      ['label' => 'Call Legal Advice Line', 'url' => $urls['hotline'], 'type' => 'hotline'],
    ];

    // Add area-specific link if available.
    if ($area && isset($urls['service_areas'][$area])) {
      array_unshift($links, [
        'label' => ucfirst(str_replace('_', ' ', $area)) . ' Legal Help',
        'url' => $urls['service_areas'][$area],
        'type' => 'services',
      ]);
    }

    return [
      'level' => 1,
      'message' => $message,
      'primary_action' => ['label' => 'Apply for Help', 'url' => $urls['apply']],
      'links' => $links,
      'suggestions' => $suggestions,
    ];
  }

  /**
   * Level 2: parent service area page + nearby sub-topic chips.
   */
  protected static function buildLevel2(string $intent, ?string $area, ?TopIntentsPack $pack, array $urls): array {
    $area_url = $urls['service_areas'][$area] ?? ($urls['services'] ?? '');
    $area_label = $area ? ucfirst(str_replace('_', ' ', $area)) : 'Legal';

    $message = "I'm having trouble finding exactly what you need. Here are some options for $area_label legal help.";

    $suggestions = [];
    if ($pack && $area) {
      // Get chips from the parent area intent.
      $parent_intent = 'topic_' . $area;
      $chips = $pack->getChips($parent_intent);
      if (!empty($chips)) {
        $suggestions = array_map(function ($chip) {
          return ['label' => $chip['label'], 'action' => $chip['intent']];
        }, $chips);
      }
    }

    return [
      'level' => 2,
      'message' => $message,
      'primary_action' => ['label' => "$area_label Legal Help", 'url' => $area_url],
      'links' => [
        ['label' => "$area_label Legal Help", 'url' => $area_url, 'type' => 'services'],
        ['label' => 'Find Forms', 'url' => $urls['forms'], 'type' => 'forms'],
        ['label' => 'Apply for Help', 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => 'Call Legal Advice Line', 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'suggestions' => $suggestions,
    ];
  }

  /**
   * Level 3: prominent contact info + 3-4 nearby intents.
   */
  protected static function buildLevel3(?string $area, ?TopIntentsPack $pack, array $urls): array {
    $message = "I'm sorry I haven't been able to find what you need. Here are the best ways to get help directly.";

    $suggestions = [
      ['label' => 'Apply for Help', 'action' => 'apply_for_help'],
      ['label' => 'Call Hotline', 'action' => 'legal_advice_line'],
      ['label' => 'Find an Office', 'action' => 'offices_contact'],
    ];

    // Add area-specific chip if available.
    if ($area) {
      $suggestions[] = [
        'label' => ucfirst(str_replace('_', ' ', $area)) . ' Help',
        'action' => 'topic_' . $area,
      ];
    }

    return [
      'level' => 3,
      'message' => $message,
      'primary_action' => ['label' => 'Apply for Help', 'url' => $urls['apply']],
      'links' => [
        ['label' => 'Apply for Help', 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => 'Call Legal Advice Line', 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => 'Find an Office', 'url' => $urls['offices'], 'type' => 'offices'],
        ['label' => 'Our Services', 'url' => $urls['services'], 'type' => 'services'],
      ],
      'suggestions' => $suggestions,
    ];
  }

  /**
   * Level 4: terminal — direct human connection.
   */
  protected static function buildLevel4(array $urls): array {
    return [
      'level' => 4,
      'message' => "I can connect you with someone who can help directly. Please use one of the options below to reach us.",
      'primary_action' => ['label' => 'Apply for Help', 'url' => $urls['apply']],
      'links' => [
        ['label' => 'Apply for Help', 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => 'Call (208) 746-7541', 'url' => 'tel:208-746-7541', 'type' => 'hotline'],
        ['label' => 'Find an Office', 'url' => $urls['offices'], 'type' => 'offices'],
        ['label' => 'Our Services', 'url' => $urls['services'], 'type' => 'services'],
      ],
      'suggestions' => [
        ['label' => 'Apply for Help', 'action' => 'apply_for_help'],
        ['label' => 'Call Hotline', 'action' => 'legal_advice_line'],
        ['label' => 'Find an Office', 'action' => 'offices_contact'],
        ['label' => 'Our Services', 'action' => 'services_overview'],
      ],
    ];
  }

  /**
   * Resolves an intent key to its parent service area.
   *
   * @param string $intent
   *   The intent key.
   *
   * @return string|null
   *   The service area key, or NULL.
   */
  public static function resolveArea(string $intent): ?string {
    // Direct match.
    if (isset(self::INTENT_AREA_MAP[$intent])) {
      return self::INTENT_AREA_MAP[$intent];
    }

    // Check if intent starts with a known prefix.
    foreach (self::INTENT_AREA_MAP as $prefix => $area) {
      if (str_starts_with($intent, $prefix . '_')) {
        return $area;
      }
    }

    return NULL;
  }

  /**
   * Counts consecutive fallback/unknown responses in recent history.
   *
   * @param array $server_history
   *   Conversation history.
   *
   * @return int
   *   Number of consecutive fallback turns from the end.
   */
  protected static function countRecentFallbacks(array $server_history): int {
    $count = 0;
    $fallback_types = ['fallback', 'unknown'];

    for ($i = count($server_history) - 1; $i >= 0; $i--) {
      $entry = $server_history[$i];
      $response_type = $entry['response_type'] ?? '';
      $intent = $entry['intent'] ?? '';

      if (in_array($response_type, $fallback_types, TRUE) || $intent === 'unknown') {
        $count++;
      }
      else {
        break;
      }
    }

    return $count;
  }

  /**
   * Checks if any recent history entry shares the same service area.
   *
   * @param string $area
   *   The current service area.
   * @param array $server_history
   *   Conversation history.
   *
   * @return bool
   *   TRUE if a recent entry has the same area.
   */
  protected static function hasSameAreaInHistory(string $area, array $server_history): bool {
    $recent = array_slice($server_history, -3);
    foreach ($recent as $entry) {
      if (($entry['area'] ?? '') === $area) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
