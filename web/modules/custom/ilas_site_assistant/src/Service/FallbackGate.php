<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for determining answer, clarify, and hard-route outcomes.
 *
 * This service implements a measurable, tunable confidence model that combines:
 * - Intent confidence (rule-based pattern match strength)
 * - Retrieval confidence (FAQ/resource search result quality)
 * - Authoritative pre-routing overrides (urgent situations that override
 *   normal routing)
 *
 * The gate decides one of four outcomes:
 * 1. ANSWER - Built-in response with high confidence
 * 2. CLARIFY - Ask for clarification before proceeding
 * 3. FALLBACK_LLM - Use bounded request-time LLM classification, then reroute
 * 4. HARD_ROUTE - Safety/policy override to specific resource
 *
 * Reason codes explain WHY a decision was made for debugging and tuning.
 */
class FallbackGate {

  /**
   * Decision types.
   */
  const DECISION_ANSWER = 'answer';
  const DECISION_CLARIFY = 'clarify';
  const DECISION_FALLBACK_LLM = 'fallback_llm';
  const DECISION_HARD_ROUTE = 'hard_route';

  /**
   * Reason codes - explain why a decision was made.
   */
  const REASON_HIGH_CONF_INTENT = 'HIGH_CONF_INTENT';
  const REASON_HIGH_CONF_RETRIEVAL = 'HIGH_CONF_RETRIEVAL';
  const REASON_LOW_INTENT_CONF = 'LOW_INTENT_CONF';
  const REASON_LOW_RETRIEVAL_SCORE = 'LOW_RETRIEVAL_SCORE';
  const REASON_AMBIGUOUS_MULTI_INTENT = 'AMBIGUOUS_MULTI_INTENT';
  const REASON_SAFETY_URGENT = 'SAFETY_URGENT';
  const REASON_OUT_OF_SCOPE = 'OUT_OF_SCOPE';
  const REASON_POLICY_VIOLATION = 'POLICY_VIOLATION';
  const REASON_NO_RESULTS = 'NO_RESULTS';
  const REASON_LARGE_SCORE_GAP = 'LARGE_SCORE_GAP';
  const REASON_BORDERLINE_CONF = 'BORDERLINE_CONF';
  const REASON_GREETING = 'GREETING';
  const REASON_LLM_DISABLED = 'LLM_DISABLED';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The shared environment detector.
   *
   * @var \Drupal\ilas_site_assistant\Service\EnvironmentDetector
   */
  protected EnvironmentDetector $environmentDetector;

  /**
   * Optional request-time LLM coordinator.
   *
   * @var \Drupal\ilas_site_assistant\Service\LlmEnhancer|null
   */
  protected ?LlmEnhancer $llmEnhancer;

  /**
   * Default thresholds.
   *
   * These can be overridden in config.
   *
   * @var array
   */
  protected $defaultThresholds = [
    // Intent confidence thresholds.
    'intent_high_conf' => 0.85,
    'intent_medium_conf' => 0.65,
    'intent_low_conf' => 0.40,

    // Retrieval score thresholds.
    'retrieval_high_score' => 0.75,
    'retrieval_medium_score' => 0.50,
    'retrieval_low_score' => 0.30,

    // Score gap thresholds (difference between top result and others).
    'retrieval_score_gap_high' => 0.25,
    'retrieval_score_gap_low' => 0.10,

    // Minimum results to consider retrieval confident.
    'retrieval_min_results' => 1,

    // Combined confidence thresholds.
    'combined_high_conf' => 0.80,
    'combined_fallback_threshold' => 0.50,

    // Message characteristics.
    'short_message_words' => 3,
    'ambiguous_message_words' => 2,
  ];

  /**
   * Intent types that don't need retrieval to confirm.
   *
   * @var array
   */
  protected $directRouteIntents = [
    'greeting',
    'thanks',
    'apply',
    'apply_for_help',
    'hotline',
    'legal_advice_line',
    'offices',
    'offices_contact',
    'donate',
    'donations',
    'feedback',
    'services',
    'services_overview',
    'eligibility',
    'risk_detector',
    'high_risk',
    'out_of_scope',
    'clarify',
    'disambiguation',
    'forms_inventory',
    'guides_inventory',
    'services_inventory',
    'service_area',
    'navigation',
  ];

  /**
   * Intent types that benefit from retrieval confirmation.
   *
   * @var array
   */
  protected $retrievalIntents = [
    'faq',
    'forms',
    'forms_finder',
    'guides',
    'guides_finder',
    'resources',
    'topic',
    'service_area',
    'topic_employment',
    'topic_housing',
    'topic_family',
    'topic_seniors',
    'topic_benefits',
    'topic_health',
    'topic_consumer',
    'topic_civil_rights',
    // Sub-topic intents benefit from retrieval confirmation.
    'topic_family_custody',
    'topic_family_divorce',
    'topic_family_child_support',
    'topic_family_protection_order',
    'topic_housing_eviction',
    'topic_housing_foreclosure',
    'topic_consumer_debt_collection',
    'topic_consumer_bankruptcy',
  ];

  /**
   * Constructs a FallbackGate object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ?EnvironmentDetector $environment_detector = NULL,
    ?LlmEnhancer $llm_enhancer = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->environmentDetector = $environment_detector ?? new EnvironmentDetector();
    $this->llmEnhancer = $llm_enhancer;
  }

  /**
   * Returns TRUE when running in Pantheon live environment.
   */
  protected function isLiveEnvironment(): bool {
    return $this->environmentDetector->isLiveEnvironment();
  }

  /**
   * Returns whether LLM is effectively enabled for gate decisions.
   */
  protected function isLlmEffectivelyEnabled(): bool {
    if ($this->llmEnhancer !== NULL) {
      return $this->llmEnhancer->isEnabled();
    }

    return (bool) $this->configFactory->get('ilas_site_assistant.settings')->get('llm.enabled');
  }

  /**
   * Evaluates whether to answer, clarify, or hard-route.
   *
   * @param array $intent
   *   The detected intent from IntentRouter.
   * @param array $retrieval_results
   *   Search results from FAQ/resource search (with scores).
   * @param array|null $routing_override_intent
   *   Authoritative route override from PreRoutingDecisionEngine.
   * @param array $context
   *   Additional context (message, policy check, etc.).
   *
   * @return array
   *   Decision array with keys:
   *   - 'decision': One of DECISION_* constants
   *   - 'reason_code': One of REASON_* constants
   *   - 'confidence': Overall confidence score (0-1)
   *   - 'details': Debug details about the decision
   */
  public function evaluate(array $intent, array $retrieval_results, ?array $routing_override_intent, array $context = []): array {
    $routing_override_intent = !empty($routing_override_intent) ? $routing_override_intent : NULL;
    $thresholds = $this->getThresholds();
    $message = $context['message'] ?? '';
    $policy_violation = $context['policy_violation'] ?? FALSE;

    // Initialize decision details for debugging.
    $details = [
      'intent_type' => $intent['type'] ?? 'unknown',
      'intent_confidence' => $this->calculateIntentConfidence($intent, $message),
      'retrieval_confidence' => $this->calculateRetrievalConfidence($retrieval_results, $thresholds),
      'routing_override_intent' => $routing_override_intent,
      'thresholds_used' => $thresholds,
    ];

    // Check 1: Policy violation - hard route to escalation.
    if ($policy_violation) {
      return $this->buildDecision(
        self::DECISION_HARD_ROUTE,
        self::REASON_POLICY_VIOLATION,
        1.0,
        $details
      );
    }

    // Check 2: Authoritative pre-routing override - hard route.
    if ($routing_override_intent !== NULL) {
      return $this->buildDecision(
        self::DECISION_HARD_ROUTE,
        self::REASON_SAFETY_URGENT,
        1.0,
        array_merge($details, [
          'override_intent_type' => $routing_override_intent['type'] ?? 'unknown',
          'override_risk_category' => $routing_override_intent['risk_category'] ?? 'unknown',
        ])
      );
    }

    // Check 3: High-risk intent detected - hard route.
    if (($intent['type'] ?? '') === 'high_risk') {
      return $this->buildDecision(
        self::DECISION_HARD_ROUTE,
        self::REASON_SAFETY_URGENT,
        0.95,
        array_merge($details, ['risk_category' => $intent['risk_category'] ?? 'unknown'])
      );
    }

    // Check 4: Out-of-scope - hard route with explanation.
    if (($intent['type'] ?? '') === 'out_of_scope') {
      return $this->buildDecision(
        self::DECISION_HARD_ROUTE,
        self::REASON_OUT_OF_SCOPE,
        0.90,
        $details
      );
    }

    // Check 5: Greeting - always answer.
    if (($intent['type'] ?? '') === 'greeting') {
      return $this->buildDecision(
        self::DECISION_ANSWER,
        self::REASON_GREETING,
        1.0,
        $details
      );
    }

    // Check 6: Unknown intent - evaluate for fallback.
    if (($intent['type'] ?? '') === 'unknown') {
      return $this->handleUnknownIntent($details, $thresholds, $retrieval_results, $message);
    }

    // Check 7: Direct route intents (high confidence without retrieval).
    if (in_array($intent['type'], $this->directRouteIntents)) {
      $intent_conf = $details['intent_confidence'];

      if ($intent_conf >= $thresholds['intent_high_conf']) {
        return $this->buildDecision(
          self::DECISION_ANSWER,
          self::REASON_HIGH_CONF_INTENT,
          $intent_conf,
          $details
        );
      }

      // Medium confidence - still answer but note it.
      if ($intent_conf >= $thresholds['intent_medium_conf']) {
        return $this->buildDecision(
          self::DECISION_ANSWER,
          self::REASON_HIGH_CONF_INTENT,
          $intent_conf,
          array_merge($details, ['confidence_level' => 'medium'])
        );
      }

      // Direct route intents should still route at low confidence (e.g. short
      // messages like "office locations" that trigger a word-count penalty).
      // These intents don't depend on retrieval, so routing is safe.
      if ($intent_conf >= $thresholds['intent_low_conf']) {
        return $this->buildDecision(
          self::DECISION_ANSWER,
          self::REASON_HIGH_CONF_INTENT,
          $intent_conf,
          array_merge($details, ['confidence_level' => 'low'])
        );
      }
    }

    // Check 8: Retrieval-based intents - combine intent + retrieval confidence.
    if (in_array($intent['type'], $this->retrievalIntents) || !empty($retrieval_results)) {
      return $this->handleRetrievalIntent($details, $thresholds, $retrieval_results);
    }

    // Check 9: Borderline cases - evaluate combined confidence.
    return $this->handleBorderlineCase($details, $thresholds, $retrieval_results, $message);
  }

  /**
   * Handles unknown intent cases.
   *
   * @param array $details
   *   Decision details.
   * @param array $thresholds
   *   Threshold values.
   * @param array $retrieval_results
   *   Retrieval results.
   * @param string $message
   *   Original message.
   *
   * @return array
   *   Decision array.
   */
  protected function handleUnknownIntent(array $details, array $thresholds, array $retrieval_results, string $message): array {
    $retrieval_conf = $details['retrieval_confidence'];

    // If we have good retrieval results, answer with them.
    if ($retrieval_conf >= $thresholds['retrieval_high_score'] && !empty($retrieval_results)) {
      return $this->buildDecision(
        self::DECISION_ANSWER,
        self::REASON_HIGH_CONF_RETRIEVAL,
        $retrieval_conf,
        $details
      );
    }

    // Check if message is too short/ambiguous for clarification.
    $word_count = str_word_count($message);
    if ($word_count <= $thresholds['ambiguous_message_words']) {
      return $this->buildDecision(
        self::DECISION_CLARIFY,
        self::REASON_LOW_INTENT_CONF,
        0.3,
        array_merge($details, ['message_words' => $word_count])
      );
    }

    // Check if LLM is effectively enabled.
    if (!$this->isLlmEffectivelyEnabled()) {
      // Fallback to clarification since LLM is disabled.
      return $this->buildDecision(
        self::DECISION_CLARIFY,
        self::REASON_LLM_DISABLED,
        0.2,
        $details
      );
    }

    // Unknown intent with sufficient message length can take a bounded
    // request-time classification detour before falling back to clarify.
    return $this->buildDecision(
      self::DECISION_FALLBACK_LLM,
      self::REASON_BORDERLINE_CONF,
      0.45,
      $details
    );
  }

  /**
   * Handles retrieval-based intent cases.
   *
   * @param array $details
   *   Decision details.
   * @param array $thresholds
   *   Threshold values.
   * @param array $retrieval_results
   *   Retrieval results.
   *
   * @return array
   *   Decision array.
   */
  protected function handleRetrievalIntent(array $details, array $thresholds, array $retrieval_results): array {
    $intent_conf = $details['intent_confidence'];
    $retrieval_conf = $details['retrieval_confidence'];

    // No results found.
    if (empty($retrieval_results)) {
      // If high intent confidence, still route to the resource page.
      if ($intent_conf >= $thresholds['intent_high_conf']) {
        $uncapped_confidence = $intent_conf * 0.7;
        $capped_confidence = min(0.49, $uncapped_confidence);
        return $this->buildDecision(
          self::DECISION_ANSWER,
          self::REASON_NO_RESULTS,
          $capped_confidence,
          array_merge($details, [
            'note' => 'No results but high intent confidence',
            'no_results_confidence_capped' => TRUE,
            'no_results_uncapped_confidence' => round($uncapped_confidence, 4),
          ])
        );
      }

      // Low confidence with no results - clarify.
      return $this->buildDecision(
        self::DECISION_CLARIFY,
        self::REASON_NO_RESULTS,
        0.3,
        $details
      );
    }

    // Calculate combined confidence.
    $combined_conf = $this->calculateCombinedConfidence($intent_conf, $retrieval_conf);
    $details['combined_confidence'] = $combined_conf;

    // Check for score gap (indicates clear winner).
    $score_gap = $this->calculateScoreGap($retrieval_results);
    $details['retrieval_score_gap'] = $score_gap;

    // High combined confidence with good score gap.
    if ($combined_conf >= $thresholds['combined_high_conf'] &&
        $score_gap >= $thresholds['retrieval_score_gap_low']) {
      return $this->buildDecision(
        self::DECISION_ANSWER,
        self::REASON_HIGH_CONF_RETRIEVAL,
        $combined_conf,
        $details
      );
    }

    // Large score gap indicates clear best result.
    if ($score_gap >= $thresholds['retrieval_score_gap_high']) {
      return $this->buildDecision(
        self::DECISION_ANSWER,
        self::REASON_LARGE_SCORE_GAP,
        $combined_conf,
        $details
      );
    }

    // Medium confidence - still answer, but mark the result as borderline.
    if ($combined_conf >= $thresholds['combined_fallback_threshold']) {
      return $this->buildDecision(
        self::DECISION_ANSWER,
        self::REASON_BORDERLINE_CONF,
        $combined_conf,
        array_merge($details, ['borderline_retrieval_confidence' => TRUE])
      );
    }

    // Low confidence - clarify instead.
    return $this->buildDecision(
      self::DECISION_CLARIFY,
      self::REASON_LOW_RETRIEVAL_SCORE,
      $combined_conf,
      $details
    );
  }

  /**
   * Handles borderline/edge cases.
   *
   * @param array $details
   *   Decision details.
   * @param array $thresholds
   *   Threshold values.
   * @param array $retrieval_results
   *   Retrieval results.
   * @param string $message
   *   Original message.
   *
   * @return array
   *   Decision array.
   */
  protected function handleBorderlineCase(array $details, array $thresholds, array $retrieval_results, string $message): array {
    $intent_conf = $details['intent_confidence'];
    $retrieval_conf = $details['retrieval_confidence'];

    // Check for multi-intent ambiguity.
    $word_count = str_word_count($message);
    $has_conjunctions = preg_match('/\b(and|also|plus|or)\b/i', $message);

    if ($word_count > 10 && $has_conjunctions && $intent_conf < $thresholds['intent_high_conf']) {
      return $this->buildDecision(
        self::DECISION_CLARIFY,
        self::REASON_AMBIGUOUS_MULTI_INTENT,
        $intent_conf,
        array_merge($details, ['multi_intent_indicators' => TRUE])
      );
    }

    // Default: answer with current intent confidence.
    return $this->buildDecision(
      self::DECISION_ANSWER,
      self::REASON_HIGH_CONF_INTENT,
      $intent_conf,
      $details
    );
  }

  /**
   * Calculates intent confidence based on match characteristics.
   *
   * @param array $intent
   *   The detected intent.
   * @param string $message
   *   The original message.
   *
   * @return float
   *   Confidence score between 0 and 1.
   */
  public function calculateIntentConfidence(array $intent, string $message): float {
    $type = $intent['type'] ?? 'unknown';

    // Unknown intent has very low confidence.
    if ($type === 'unknown') {
      return 0.15;
    }

    // LLM-classified intents have medium confidence.
    if (isset($intent['source']) && $intent['source'] === 'llm') {
      return 0.55;
    }

    // Start with base confidence for rule-based matches.
    $base_conf = 0.70;

    // Adjust based on message characteristics.
    $word_count = str_word_count($message);

    // Very short messages get lower confidence.
    if ($word_count < 3) {
      $base_conf -= 0.15;
    }
    // Medium length messages get a boost.
    elseif ($word_count >= 3 && $word_count <= 15) {
      $base_conf += 0.15;
    }
    // Very long messages may be complex/multi-intent.
    elseif ($word_count > 20) {
      $base_conf -= 0.05;
    }

    // Check if extraction found relevant keywords.
    if (!empty($intent['extraction']['keywords'])) {
      $keyword_count = count($intent['extraction']['keywords']);
      if ($keyword_count >= 2) {
        $base_conf += 0.05;
      }
    }

    // Check for phrase matches (more specific = higher confidence).
    if (!empty($intent['extraction']['phrases_found'])) {
      $base_conf += 0.10;
    }

    // Check for synonym normalization (indicates good understanding).
    if (!empty($intent['extraction']['synonyms_applied'])) {
      $base_conf += 0.05;
    }

    // Cap at 1.0.
    return min(1.0, max(0.0, $base_conf));
  }

  /**
   * Calculates retrieval confidence from search results.
   *
   * @param array $results
   *   Search results with scores.
   * @param array $thresholds
   *   Threshold values.
   *
   * @return float
   *   Confidence score between 0 and 1.
   */
  public function calculateRetrievalConfidence(array $results, array $thresholds): float {
    if (empty($results)) {
      return 0.0;
    }

    // Get the top score.
    $top_score = $this->getTopScore($results);

    // If we have explicit scores, use them.
    if ($top_score > 0) {
      // Normalize score to 0-1 range if needed.
      if ($top_score > 1) {
        // Assume Search API scores (often 0-100 or higher).
        $top_score = min(1.0, $top_score / 100);
      }

      return $top_score;
    }

    // Without explicit scores, estimate based on result count and position.
    $result_count = count($results);

    if ($result_count >= 3) {
      return 0.70; // Multiple results suggest good match.
    }
    elseif ($result_count >= 1) {
      return 0.50; // At least one result.
    }

    return 0.0;
  }

  /**
   * Calculates combined confidence from intent and retrieval.
   *
   * @param float $intent_conf
   *   Intent confidence.
   * @param float $retrieval_conf
   *   Retrieval confidence.
   *
   * @return float
   *   Combined confidence.
   */
  protected function calculateCombinedConfidence(float $intent_conf, float $retrieval_conf): float {
    // Weighted average favoring intent slightly.
    return ($intent_conf * 0.55) + ($retrieval_conf * 0.45);
  }

  /**
   * Gets the top score from results.
   *
   * @param array $results
   *   Search results.
   *
   * @return float
   *   Top score.
   */
  protected function getTopScore(array $results): float {
    if (empty($results)) {
      return 0.0;
    }

    $top = $results[0];

    // Check various score field names.
    if (isset($top['score'])) {
      return (float) $top['score'];
    }
    if (isset($top['relevance'])) {
      return (float) $top['relevance'];
    }
    if (isset($top['_score'])) {
      return (float) $top['_score'];
    }

    return 0.0;
  }

  /**
   * Calculates score gap between top result and second result.
   *
   * Large gap indicates a clear "winner" among results.
   *
   * @param array $results
   *   Search results with scores.
   *
   * @return float
   *   Score gap (0-1).
   */
  protected function calculateScoreGap(array $results): float {
    if (count($results) < 2) {
      // Single result = maximum gap (clear winner).
      return 1.0;
    }

    $top_score = $this->getTopScore($results);

    // Get second score.
    $second_score = 0.0;
    if (isset($results[1])) {
      $second = $results[1];
      if (isset($second['score'])) {
        $second_score = (float) $second['score'];
      }
      elseif (isset($second['relevance'])) {
        $second_score = (float) $second['relevance'];
      }
    }

    // Avoid division by zero.
    if ($top_score == 0) {
      return 0.0;
    }

    return ($top_score - $second_score) / $top_score;
  }

  /**
   * Builds a decision array.
   *
   * @param string $decision
   *   Decision type.
   * @param string $reason_code
   *   Reason code.
   * @param float $confidence
   *   Confidence score.
   * @param array $details
   *   Additional details.
   *
   * @return array
   *   Decision array.
   */
  protected function buildDecision(string $decision, string $reason_code, float $confidence, array $details): array {
    return [
      'decision' => $decision,
      'reason_code' => $reason_code,
      'confidence' => round($confidence, 4),
      'details' => $details,
    ];
  }

  /**
   * Gets threshold values from config with defaults.
   *
   * @return array
   *   Threshold values.
   */
  public function getThresholds(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $config_thresholds = $config->get('fallback_gate.thresholds') ?? [];

    return array_merge($this->defaultThresholds, $config_thresholds);
  }

  /**
   * Gets all reason codes with descriptions.
   *
   * @return array
   *   Reason codes mapped to descriptions.
   */
  public static function getReasonCodeDescriptions(): array {
    return [
      self::REASON_HIGH_CONF_INTENT => 'High confidence intent match from rule-based patterns',
      self::REASON_HIGH_CONF_RETRIEVAL => 'High quality retrieval results support the answer',
      self::REASON_LOW_INTENT_CONF => 'Intent detection confidence too low for reliable answer',
      self::REASON_LOW_RETRIEVAL_SCORE => 'Retrieval results not confident enough',
      self::REASON_AMBIGUOUS_MULTI_INTENT => 'Message appears to contain multiple intents',
      self::REASON_SAFETY_URGENT => 'Authoritative pre-routing override detected requiring urgent routing',
      self::REASON_OUT_OF_SCOPE => 'Request is outside scope of ILAS services',
      self::REASON_POLICY_VIOLATION => 'Message triggered policy filter',
      self::REASON_NO_RESULTS => 'No retrieval results found',
      self::REASON_LARGE_SCORE_GAP => 'Large score gap between top results indicates clear match',
      self::REASON_BORDERLINE_CONF => 'Borderline confidence - answer may benefit from enhancement',
      self::REASON_GREETING => 'Simple greeting detected',
      self::REASON_LLM_DISABLED => 'Request-time LLM fallback is unavailable - using clarification',
    ];
  }

  /**
   * Gets metrics about gate decisions for a batch of evaluations.
   *
   * @param array $decisions
   *   Array of decision arrays.
   *
   * @return array
   *   Metrics summary.
   */
  public static function calculateGateMetrics(array $decisions): array {
    $total = count($decisions);
    if ($total === 0) {
      return [
        'total' => 0,
        'answer_rate' => 0,
        'clarify_rate' => 0,
        'fallback_rate' => 0,
        'hard_route_rate' => 0,
        'avg_confidence' => 0,
        'by_reason_code' => [],
      ];
    }

    $counts = [
      self::DECISION_ANSWER => 0,
      self::DECISION_CLARIFY => 0,
      self::DECISION_FALLBACK_LLM => 0,
      self::DECISION_HARD_ROUTE => 0,
    ];

    $by_reason = [];
    $conf_sum = 0;

    foreach ($decisions as $decision) {
      $type = $decision['decision'] ?? '';
      if (isset($counts[$type])) {
        $counts[$type]++;
      }

      $reason = $decision['reason_code'] ?? 'UNKNOWN';
      $by_reason[$reason] = ($by_reason[$reason] ?? 0) + 1;

      $conf_sum += $decision['confidence'] ?? 0;
    }

    // Count dead ends: decisions where no actionable links would be present.
    // NO_RESULTS with low confidence and no LLM fallback = potential dead end.
    $dead_end_count = 0;
    foreach ($decisions as $decision) {
      $reason = $decision['reason_code'] ?? '';
      $conf = $decision['confidence'] ?? 0;
      // A dead end is a low-confidence fallback with no results.
      if ($reason === self::REASON_NO_RESULTS && $conf < 0.4) {
        $dead_end_count++;
      }
    }

    return [
      'total' => $total,
      'answer_rate' => round($counts[self::DECISION_ANSWER] / $total, 4),
      'clarify_rate' => round($counts[self::DECISION_CLARIFY] / $total, 4),
      'fallback_rate' => round($counts[self::DECISION_FALLBACK_LLM] / $total, 4),
      'hard_route_rate' => round($counts[self::DECISION_HARD_ROUTE] / $total, 4),
      'dead_end_rate' => round($dead_end_count / $total, 4),
      'avg_confidence' => round($conf_sum / $total, 4),
      'by_reason_code' => $by_reason,
    ];
  }

}
