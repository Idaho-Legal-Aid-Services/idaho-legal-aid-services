<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Single source of truth for hard-route intent -> canonical URL mappings.
 *
 * Hard-route intents are deterministic: when the intent is selected, the
 * canonical URL MUST be emitted. This prevents URL drift where the intent
 * is correct but the response URL is wrong.
 *
 * Usage:
 *   $registry = new HardRouteRegistry($canonical_urls);
 *   if ($registry->isHardRoute($intent_type)) {
 *     $url = $registry->getCanonicalUrl($intent_type);
 *   }
 *   // Or enforce after building response:
 *   $response = $registry->enforceCanonicalUrl($response, $intent);
 */
class HardRouteRegistry {

  /**
   * Canonical URL map.
   *
   * @var array
   */
  protected $canonicalUrls;

  /**
   * Hard-route intent to URL key mapping.
   *
   * Maps intent types to their canonical URL key. If an intent is in this map,
   * it is a hard-route and MUST emit the corresponding canonical URL.
   *
   * Format: 'intent_type' => 'canonical_url_key' or ['key', 'subkey']
   *
   * @var array
   */
  protected const HARD_ROUTE_MAP = [
    // Navigation intents - direct page targets.
    'apply' => 'apply',
    'apply_for_help' => 'apply',
    'hotline' => 'hotline',
    'legal_advice_line' => 'hotline',
    'offices' => 'offices',
    'offices_contact' => 'offices',
    'donate' => 'donate',
    'donations' => 'donate',
    'feedback' => 'feedback',
    'feedback_complaints' => 'feedback',
    'forms' => 'forms',
    'forms_finder' => 'forms',
    'guides' => 'guides',
    'guides_finder' => 'guides',
    'faq' => 'faq',
    'services' => 'services',
    'services_overview' => 'services',
    'eligibility' => 'apply',
    'risk_detector' => 'senior_risk_detector',
    'senior_risk_detector' => 'senior_risk_detector',
    'resources' => 'resources',

    // Service area intents - map to service_areas subkey.
    'topic_housing' => ['service_areas', 'housing'],
    'topic_family' => ['service_areas', 'family'],
    'topic_seniors' => ['service_areas', 'seniors'],
    'topic_health' => ['service_areas', 'health'],
    'topic_consumer' => ['service_areas', 'consumer'],
    'topic_civil_rights' => ['service_areas', 'civil_rights'],
    'service_area_housing' => ['service_areas', 'housing'],
    'service_area_family' => ['service_areas', 'family'],
    'service_area_seniors' => ['service_areas', 'seniors'],
    'service_area_health' => ['service_areas', 'health'],
    'service_area_consumer' => ['service_areas', 'consumer'],
    'service_area_civil_rights' => ['service_areas', 'civil_rights'],

    // High-risk intents - default to apply, except deadline which goes to hotline.
    'high_risk' => 'apply',
    'high_risk_dv' => 'apply',
    'high_risk_eviction' => 'apply',
    'high_risk_scam' => 'apply',
    'high_risk_utility' => 'apply',
    'high_risk_deadline' => 'hotline',

    // Out of scope - default to services.
    'out_of_scope' => 'services',
  ];

  /**
   * Intent types that should NOT have their URL enforced.
   *
   * These intents have dynamic URLs based on context (e.g., navigation to
   * a page the user asked about, or topic resolution).
   *
   * @var array
   */
  protected const SOFT_ROUTE_INTENTS = [
    'navigation',      // URL comes from NavigationIntent detection.
    'topic',           // URL comes from topic resolution.
    'service_area',    // URL comes from intent['area'] parameter.
    'disambiguation',  // URL depends on competing intents.
    'clarify',         // Fallback URL is ok.
    'greeting',        // No URL required.
    'unknown',         // Fallback URL is ok.
    'fallback',        // Fallback URL is ok.
    'multi_intent',    // Handled specially.
  ];

  /**
   * Constructs a HardRouteRegistry.
   *
   * @param array $canonical_urls
   *   The canonical URL map (from config or ResponseBuilder::getDefaultCanonicalUrls()).
   */
  public function __construct(array $canonical_urls = []) {
    $this->canonicalUrls = $canonical_urls ?: ResponseBuilder::getDefaultCanonicalUrls();
  }

  /**
   * Checks if an intent type is a hard-route.
   *
   * Hard-route intents MUST emit their canonical URL without substitution.
   *
   * @param string $intent_type
   *   The intent type.
   *
   * @return bool
   *   TRUE if this is a hard-route intent.
   */
  public function isHardRoute(string $intent_type): bool {
    // Normalize intent type.
    $normalized = ResponseBuilder::normalizeIntentType($intent_type);

    // Check if it's in the hard-route map.
    if (isset(self::HARD_ROUTE_MAP[$intent_type]) || isset(self::HARD_ROUTE_MAP[$normalized])) {
      return TRUE;
    }

    // Check for service_area with specific area.
    if ($intent_type === 'service_area') {
      return FALSE; // service_area without specific area is soft-route.
    }

    return FALSE;
  }

  /**
   * Checks if an intent type is a soft-route.
   *
   * @param string $intent_type
   *   The intent type.
   *
   * @return bool
   *   TRUE if this is a soft-route intent.
   */
  public function isSoftRoute(string $intent_type): bool {
    return in_array($intent_type, self::SOFT_ROUTE_INTENTS) ||
           in_array(ResponseBuilder::normalizeIntentType($intent_type), self::SOFT_ROUTE_INTENTS);
  }

  /**
   * Gets the canonical URL for a hard-route intent.
   *
   * @param string $intent_type
   *   The intent type.
   * @param array $intent
   *   Full intent array (optional, used for service_area).
   *
   * @return string|null
   *   The canonical URL, or NULL if not a hard-route.
   */
  public function getCanonicalUrl(string $intent_type, array $intent = []): ?string {
    // Normalize intent type.
    $normalized = ResponseBuilder::normalizeIntentType($intent_type);

    if (($intent_type === 'high_risk' || $normalized === 'high_risk') && !empty($intent['risk_category'])) {
      $risk_category = (string) $intent['risk_category'];
      if (isset(self::HARD_ROUTE_MAP[$risk_category])) {
        $intent_type = $risk_category;
        $normalized = $risk_category;
      }
    }

    // Check hard-route map.
    $url_key = self::HARD_ROUTE_MAP[$intent_type] ?? self::HARD_ROUTE_MAP[$normalized] ?? NULL;

    if ($url_key === NULL) {
      // Check for service_area with specific area.
      if (($intent_type === 'service_area' || $normalized === 'service_area') && !empty($intent['area'])) {
        $area = $intent['area'];
        if (isset($this->canonicalUrls['service_areas'][$area])) {
          return $this->canonicalUrls['service_areas'][$area];
        }
      }
      return NULL;
    }

    // Handle nested keys (e.g., ['service_areas', 'housing']).
    if (is_array($url_key)) {
      $value = $this->canonicalUrls;
      foreach ($url_key as $key) {
        if (!isset($value[$key])) {
          return NULL;
        }
        $value = $value[$key];
      }
      return $value;
    }

    // Simple key lookup.
    return $this->canonicalUrls[$url_key] ?? NULL;
  }

  /**
   * Gets the canonical URL label for a hard-route intent.
   *
   * @param string $intent_type
   *   The intent type.
   *
   * @return string|null
   *   The label, or NULL if not a hard-route.
   */
  public function getCanonicalLabel(string $intent_type): ?string {
    $labels = [
      'apply' => 'Apply for Help',
      'apply_for_help' => 'Apply for Help',
      'hotline' => 'Contact Hotline',
      'legal_advice_line' => 'Contact Hotline',
      'offices' => 'Find Offices',
      'offices_contact' => 'Find Offices',
      'donate' => 'Donate',
      'donations' => 'Donate',
      'feedback' => 'Give Feedback',
      'feedback_complaints' => 'Give Feedback',
      'forms' => 'Find Forms',
      'forms_finder' => 'Find Forms',
      'guides' => 'Find Guides',
      'guides_finder' => 'Find Guides',
      'faq' => 'Browse FAQs',
      'services' => 'Our Services',
      'services_overview' => 'Apply for Help',
      'eligibility' => 'Apply for Help',
      'risk_detector' => 'Take the Assessment',
      'senior_risk_detector' => 'Take the Assessment',
      'resources' => 'Browse Resources',
      'high_risk' => 'Apply for Help',
      'high_risk_dv' => 'Apply for Help',
      'high_risk_eviction' => 'Apply for Help',
      'high_risk_scam' => 'Apply for Help',
      'high_risk_utility' => 'Apply for Help',
      'high_risk_deadline' => 'Call Hotline',
      'out_of_scope' => 'Our Services',
    ];

    return $labels[$intent_type] ?? $labels[ResponseBuilder::normalizeIntentType($intent_type)] ?? NULL;
  }

  /**
   * Enforces canonical URL for hard-route intents in a response.
   *
   * This is the critical enforcement method. Call this AFTER all enrichment
   * to ensure hard-route intents always emit their canonical URL.
   *
   * @param array $response
   *   The response array (must have 'type' and 'primary_action').
   * @param array $intent
   *   The full intent array.
   *
   * @return array
   *   The response with canonical URL enforced (if hard-route).
   */
  public function enforceCanonicalUrl(array $response, array $intent): array {
    $intent_type = $intent['type'] ?? 'unknown';

    // Skip enforcement for soft-route intents.
    if ($this->isSoftRoute($intent_type)) {
      return $response;
    }

    // Get canonical URL for this intent.
    $canonical_url = $this->getCanonicalUrl($intent_type, $intent);

    if ($canonical_url === NULL) {
      // Not a hard-route, or no canonical URL defined.
      return $response;
    }

    // Enforce the canonical URL.
    $canonical_label = $this->getCanonicalLabel($intent_type) ?? 'View Page';

    // Only enforce if primary_action exists.
    if (isset($response['primary_action'])) {
      $response['primary_action']['url'] = $canonical_url;
      // Preserve label if already set, otherwise use canonical label.
      if (empty($response['primary_action']['label'])) {
        $response['primary_action']['label'] = $canonical_label;
      }
    }
    else {
      $response['primary_action'] = [
        'label' => $canonical_label,
        'url' => $canonical_url,
      ];
    }

    // Also enforce the legacy 'url' field for backwards compat.
    $response['url'] = $canonical_url;

    // Add debug marker if enforcement changed the URL.
    if (!isset($response['_hard_route_enforced'])) {
      $response['_hard_route_enforced'] = TRUE;
      $response['_hard_route_intent'] = $intent_type;
    }

    return $response;
  }

  /**
   * Validates that a response has the correct canonical URL.
   *
   * Returns validation result without modifying the response.
   *
   * @param array $response
   *   The response array.
   * @param array $intent
   *   The full intent array.
   *
   * @return array
   *   Validation result: ['valid' => bool, 'expected' => url, 'actual' => url, 'message' => string]
   */
  public function validateCanonicalUrl(array $response, array $intent): array {
    $intent_type = $intent['type'] ?? 'unknown';

    // Soft-route intents are always valid.
    if ($this->isSoftRoute($intent_type)) {
      return [
        'valid' => TRUE,
        'expected' => NULL,
        'actual' => $response['primary_action']['url'] ?? NULL,
        'message' => 'Soft-route intent - no canonical URL required',
        'is_hard_route' => FALSE,
      ];
    }

    $canonical_url = $this->getCanonicalUrl($intent_type, $intent);

    if ($canonical_url === NULL) {
      return [
        'valid' => TRUE,
        'expected' => NULL,
        'actual' => $response['primary_action']['url'] ?? NULL,
        'message' => 'No canonical URL defined for this intent',
        'is_hard_route' => FALSE,
      ];
    }

    $actual_url = $response['primary_action']['url'] ?? $response['url'] ?? NULL;

    // Normalize for comparison.
    $expected_normalized = strtolower(trim($canonical_url, '/'));
    $actual_normalized = $actual_url ? strtolower(trim($actual_url, '/')) : '';

    $valid = $expected_normalized === $actual_normalized;

    return [
      'valid' => $valid,
      'expected' => $canonical_url,
      'actual' => $actual_url,
      'message' => $valid
        ? 'Canonical URL matches'
        : "URL drift detected: expected '$canonical_url', got '$actual_url'",
      'is_hard_route' => TRUE,
      'intent_type' => $intent_type,
    ];
  }

  /**
   * Gets the full hard-route map (for debugging/testing).
   *
   * @return array
   *   The hard-route map.
   */
  public static function getHardRouteMap(): array {
    return self::HARD_ROUTE_MAP;
  }

  /**
   * Gets the soft-route intent list (for debugging/testing).
   *
   * @return array
   *   The soft-route intents.
   */
  public static function getSoftRouteIntents(): array {
    return self::SOFT_ROUTE_INTENTS;
  }

  /**
   * Gets all registered canonical URLs for testing.
   *
   * @return array
   *   All canonical URLs.
   */
  public function getAllCanonicalUrls(): array {
    $urls = [];
    foreach (self::HARD_ROUTE_MAP as $intent => $key) {
      $url = $this->getCanonicalUrl($intent);
      if ($url) {
        $urls[$intent] = $url;
      }
    }
    return $urls;
  }

  /**
   * Enforces canonical URL with authoritative override intent awareness.
   *
   * @param array $response
   *   The response array.
   * @param array $intent
   *   The full routed intent array.
   * @param array|null $override_intent
   *   Optional authoritative override intent from PreRoutingDecisionEngine.
   *
   * @return array
   *   The response with canonical URL enforced.
   */
  public function enforceCanonicalUrlWithOverrideIntent(array $response, array $intent, ?array $override_intent = NULL): array {
    $effective_intent = $override_intent ?? $intent;
    $effective_type = $effective_intent['type'] ?? 'unknown';
    $effective_route_type = ($effective_type === 'high_risk' && !empty($effective_intent['risk_category']))
      ? (string) $effective_intent['risk_category']
      : $effective_type;
    $original_type = $intent['type'] ?? 'unknown';

    if (!$this->isHardRoute($effective_type)) {
      return $response;
    }

    $response = $this->enforceCanonicalUrl($response, $effective_intent);

    if ($override_intent !== NULL && $effective_route_type !== $original_type) {
      $response['_hard_route_enforced_by_override_intent'] = TRUE;
      $response['_original_intent'] = $original_type;
      $response['_routing_override_intent'] = $effective_route_type;
    }

    return $response;
  }

  /**
   * Gets the URL and label for a hard-route intent as a single struct.
   *
   * Convenience method for testing and direct lookups.
   *
   * @param string $intent_type
   *   The intent type.
   * @param array $intent
   *   Full intent array (optional).
   *
   * @return array|null
   *   ['url' => string, 'label' => string] or NULL if not hard-route.
   */
  public function getCanonicalAction(string $intent_type, array $intent = []): ?array {
    $url = $this->getCanonicalUrl($intent_type, $intent);
    if ($url === NULL) {
      return NULL;
    }

    return [
      'url' => $url,
      'label' => $this->getCanonicalLabel($intent_type) ?? 'View Page',
    ];
  }

}
