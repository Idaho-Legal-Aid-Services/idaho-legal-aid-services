<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Authoritative policy layer for pre-routing decisions.
 *
 * This service evaluates all deterministic pre-routing detectors, records the
 * overlapping results, and applies a single precedence contract before the
 * controller proceeds to intent routing.
 */
class PreRoutingDecisionEngine {

  /**
   * Decision type: Safety classifier wins.
   */
  public const DECISION_SAFETY_EXIT = 'safety_exit';

  /**
   * Decision type: Out-of-scope classifier wins.
   */
  public const DECISION_OOS_EXIT = 'oos_exit';

  /**
   * Decision type: Policy filter wins.
   */
  public const DECISION_POLICY_EXIT = 'policy_exit';

  /**
   * Decision type: Continue to routing/gate path.
   */
  public const DECISION_CONTINUE = 'continue';

  /**
   * Urgency signals that may force an authoritative route override.
   */
  private const URGENCY_SIGNAL_PRIORITY = [
    'dv_indicator' => 'high_risk_dv',
    'eviction_imminent' => 'high_risk_eviction',
    'identity_theft' => 'high_risk_scam',
    'deadline_pressure' => 'high_risk_deadline',
    'crisis_emergency' => 'high_risk_deadline',
  ];

  /**
   * Deadline/urgency patterns handled outside SafetyClassifier.
   */
  private const DEADLINE_PATTERNS = [
    '/\b(deadline\s*(is\s*)?(today|tomorrow|friday|monday|this\s*week|tonight|next\s*monday)|due\s*(today|tomorrow|friday|monday|this\s*week|tonight))\b/i',
    '/\b(court\s*(date|hearing)\s*(is\s*)?(today|tomorrow|friday|monday|this\s*week|tonight))\b/i',
    '/\b(must\s*respond|have\s*to\s*respond|need\s*to\s*respond|respond\s*by|respond\s*in\s*(24|48|72)\s*hours?|one\s*day\s*to\s*(answer|respond))\b/i',
    '/\b(file\s*by\s*(today|tomorrow|friday|monday)|have\s*to\s*file\s*by|need\s*to\s*file\s*by|paperwork\s*by\s*(today|tomorrow|friday|monday))\b/i',
    '/\b(served\s*(with\s*)?(papers|summons)|got\s*served|answer\s*(the\s*)?(lawsuit|complaint)|respond\s*to\s*(the\s*)?(lawsuit|summons|complaint))\b/i',
    '/\b(fecha\s*limite\s*(hoy|manana|mañana|viernes|lunes)|vence\s*(hoy|manana|mañana|viernes|lunes)|tengo\s*que\s*responder)\b/i',
    '/\b(fecha\s*de\s*corte\s*(hoy|manana|mañana)|tengo\s*(una\s*)?corte\s*(hoy|manana|mañana)|audiencia\s*(hoy|manana|mañana))\b/i',
    '/\b(corte\s*date\s*(manana|mañana|hoy)?|court\s*date\s*(manana|mañana)|court\s*manana)\b/i',
  ];

  /**
   * Informational deadline queries that should not trigger overrides.
   */
  private const DEADLINE_DAMPENERS = [
    '/\b(how\s*long\s*do\s*i\s*have|what\s*is\s*the\s*deadline|when\s*is\s*the\s*deadline|typical\s*deadline|general\s*deadline|deadline\s*information)\b/i',
    '/\b(deadline\s*for\s*(eviction|filing)|how\s*many\s*days|how\s*much\s*time\s*do\s*i\s*have)\b/i',
    '/\b(cuanto\s*tiempo\s*tengo|cual\s*es\s*la\s*fecha\s*limite)\b/i',
    '/\b(general\s*information\s*about\s*court\s*dates|learn\s*about\s*deadlines)\b/i',
  ];

  /**
   * The safety classifier.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyClassifier|null
   */
  protected ?SafetyClassifier $safetyClassifier;

  /**
   * The out-of-scope classifier.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeClassifier|null
   */
  protected ?OutOfScopeClassifier $outOfScopeClassifier;

  /**
   * The policy filter.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected PolicyFilter $policyFilter;

  /**
   * Constructs a pre-routing decision engine.
   */
  public function __construct(
    PolicyFilter $policy_filter,
    ?SafetyClassifier $safety_classifier = NULL,
    ?OutOfScopeClassifier $out_of_scope_classifier = NULL,
  ) {
    $this->policyFilter = $policy_filter;
    $this->safetyClassifier = $safety_classifier;
    $this->outOfScopeClassifier = $out_of_scope_classifier;
  }

  /**
   * Evaluates the normalized message and returns the authoritative decision.
   */
  public function evaluate(string $normalized_message): array {
    $safety = $this->safetyClassifier
      ? $this->safetyClassifier->classify($normalized_message)
      : $this->buildSafeFallback();
    $oos = $this->outOfScopeClassifier
      ? $this->outOfScopeClassifier->classify($normalized_message)
      : $this->buildInScopeFallback();
    $policy = $this->policyFilter->check($normalized_message);

    $urgency_signals = $this->extractUrgencySignals($normalized_message, $safety, $oos, $policy);

    $decision_type = self::DECISION_CONTINUE;
    $winner_source = 'none';
    $reason_code = 'pre_routing_continue';
    $routing_override_intent = NULL;

    if (!$safety['is_safe'] && !$this->shouldDeferSafetyExit($safety)) {
      $decision_type = self::DECISION_SAFETY_EXIT;
      $winner_source = 'safety';
      $reason_code = (string) ($safety['reason_code'] ?? 'safety_exit');
    }
    elseif ($oos['is_out_of_scope']) {
      $decision_type = self::DECISION_OOS_EXIT;
      $winner_source = 'out_of_scope';
      $reason_code = (string) ($oos['reason_code'] ?? 'oos_exit');
    }
    else {
      $routing_override_intent = $this->buildRoutingOverrideIntent($urgency_signals);

      if ($routing_override_intent && $this->shouldPreferUrgencyOverride($routing_override_intent, $policy)) {
        $winner_source = 'urgency';
        $reason_code = 'route_override_' . ($routing_override_intent['risk_category'] ?? $routing_override_intent['type']);
      }
      elseif ($policy['violation']) {
        $decision_type = self::DECISION_POLICY_EXIT;
        $winner_source = 'policy';
        $reason_code = 'policy_' . $policy['type'];
        $routing_override_intent = NULL;
      }
    }

    return [
      'decision_type' => $decision_type,
      'winner_source' => $winner_source,
      'reason_code' => $reason_code,
      'safety' => $safety,
      'oos' => $oos,
      'policy' => $policy,
      'urgency_signals' => $urgency_signals,
      'routing_override_intent' => $routing_override_intent,
    ];
  }

  /**
   * Extracts stable urgency signals from the classifier/policy results.
   */
  protected function extractUrgencySignals(string $message, array $safety, array $oos, array $policy): array {
    $signals = [];

    switch ($safety['class'] ?? SafetyClassifier::CLASS_SAFE) {
      case SafetyClassifier::CLASS_DV_EMERGENCY:
        $signals[] = 'dv_indicator';
        break;

      case SafetyClassifier::CLASS_EVICTION_EMERGENCY:
        $signals[] = 'eviction_imminent';
        break;

      case SafetyClassifier::CLASS_SCAM_ACTIVE:
        $signals[] = 'identity_theft';
        break;

      case SafetyClassifier::CLASS_CRISIS:
      case SafetyClassifier::CLASS_IMMEDIATE_DANGER:
      case SafetyClassifier::CLASS_CHILD_SAFETY:
        $signals[] = 'crisis_emergency';
        break;
    }

    if (($oos['category'] ?? '') === OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES) {
      $signals[] = 'crisis_emergency';
    }

    if (($oos['category'] ?? '') === OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE || ($policy['type'] ?? NULL) === PolicyFilter::VIOLATION_CRIMINAL) {
      $signals[] = 'criminal_matter';
    }

    if (($policy['type'] ?? NULL) === PolicyFilter::VIOLATION_EMERGENCY) {
      $signals[] = 'crisis_emergency';
    }

    if ($this->hasDeadlinePressure($message, $safety)) {
      $signals[] = 'deadline_pressure';
    }

    return array_values(array_unique($signals));
  }

  /**
   * Returns TRUE when urgency override should win over policy exit.
   */
  protected function shouldPreferUrgencyOverride(array $routing_override_intent, array $policy): bool {
    if (empty($routing_override_intent)) {
      return FALSE;
    }

    if (!$policy['violation']) {
      return TRUE;
    }

    return in_array($policy['type'], [
      PolicyFilter::VIOLATION_LEGAL_ADVICE,
      PolicyFilter::VIOLATION_DOCUMENT_DRAFTING,
      PolicyFilter::VIOLATION_EXTERNAL,
      PolicyFilter::VIOLATION_FRUSTRATION,
    ], TRUE);
  }

  /**
   * Returns TRUE when a SafetyClassifier result should defer to fallback policy.
   */
  protected function shouldDeferSafetyExit(array $safety): bool {
    return in_array($safety['class'] ?? NULL, [
      SafetyClassifier::CLASS_CRIMINAL,
      SafetyClassifier::CLASS_IMMIGRATION,
      SafetyClassifier::CLASS_LEGAL_ADVICE,
      SafetyClassifier::CLASS_DOCUMENT_DRAFTING,
      SafetyClassifier::CLASS_EXTERNAL,
      SafetyClassifier::CLASS_FRUSTRATION,
    ], TRUE);
  }

  /**
   * Builds a deterministic high-risk override intent from urgency signals.
   */
  protected function buildRoutingOverrideIntent(array $urgency_signals): ?array {
    foreach (self::URGENCY_SIGNAL_PRIORITY as $signal => $risk_category) {
      if (in_array($signal, $urgency_signals, TRUE)) {
        return [
          'type' => 'high_risk',
          'risk_category' => $risk_category,
          'confidence' => 1.0,
          'source' => 'pre_routing_decision_engine',
          'extraction' => [],
        ];
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE when deadline pressure exists outside eviction safety exits.
   */
  protected function hasDeadlinePressure(string $message, array $safety): bool {
    if (($safety['class'] ?? SafetyClassifier::CLASS_SAFE) === SafetyClassifier::CLASS_EVICTION_EMERGENCY) {
      return FALSE;
    }

    foreach (self::DEADLINE_DAMPENERS as $pattern) {
      if (preg_match($pattern, $message)) {
        return FALSE;
      }
    }

    foreach (self::DEADLINE_PATTERNS as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Safe fallback result when SafetyClassifier is unavailable.
   */
  protected function buildSafeFallback(): array {
    return [
      'class' => SafetyClassifier::CLASS_SAFE,
      'reason_code' => 'safe_no_classifier',
      'escalation_level' => SafetyClassifier::ESCALATION_NONE,
      'is_safe' => TRUE,
      'requires_refusal' => FALSE,
      'requires_resources' => FALSE,
      'matched_pattern' => NULL,
      'category' => 'safe',
    ];
  }

  /**
   * In-scope fallback result when OutOfScopeClassifier is unavailable.
   */
  protected function buildInScopeFallback(): array {
    return [
      'is_out_of_scope' => FALSE,
      'category' => OutOfScopeClassifier::CATEGORY_IN_SCOPE,
      'reason_code' => 'in_scope_no_classifier',
      'response_type' => OutOfScopeClassifier::RESPONSE_IN_SCOPE,
      'matched_pattern' => NULL,
      'suggestions' => [],
    ];
  }

}
