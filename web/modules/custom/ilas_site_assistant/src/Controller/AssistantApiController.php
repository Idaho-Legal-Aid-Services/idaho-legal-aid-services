<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\ilas_site_assistant\Service\SafetyResponseTemplates;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Site Assistant API endpoints.
 */
class AssistantApiController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The intent router service.
   *
   * @var \Drupal\ilas_site_assistant\Service\IntentRouter
   */
  protected $intentRouter;

  /**
   * The FAQ index service.
   *
   * @var \Drupal\ilas_site_assistant\Service\FaqIndex
   */
  protected $faqIndex;

  /**
   * The resource finder service.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResourceFinder
   */
  protected $resourceFinder;

  /**
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected $policyFilter;

  /**
   * The analytics logger service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AnalyticsLogger
   */
  protected $analyticsLogger;

  /**
   * The LLM enhancer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\LlmEnhancer
   */
  protected $llmEnhancer;

  /**
   * The fallback gate service.
   *
   * @var \Drupal\ilas_site_assistant\Service\FallbackGate
   */
  protected $fallbackGate;

  /**
   * The response grounder service.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResponseGrounder
   */
  protected $responseGrounder;

  /**
   * The safety classifier service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyClassifier
   */
  protected $safetyClassifier;

  /**
   * The safety response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyResponseTemplates
   */
  protected $safetyResponseTemplates;

  /**
   * The out-of-scope classifier service.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeClassifier
   */
  protected $outOfScopeClassifier;

  /**
   * The out-of-scope response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates
   */
  protected $outOfScopeResponseTemplates;

  /**
   * The performance monitor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PerformanceMonitor
   */
  protected $performanceMonitor;

  /**
   * The flood service for rate limiting.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The cache backend for conversation state.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $conversationCache;

  /**
   * Constructs an AssistantApiController object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    IntentRouter $intent_router,
    FaqIndex $faq_index,
    ResourceFinder $resource_finder,
    PolicyFilter $policy_filter,
    AnalyticsLogger $analytics_logger,
    LlmEnhancer $llm_enhancer,
    FallbackGate $fallback_gate,
    ResponseGrounder $response_grounder = NULL,
    SafetyClassifier $safety_classifier = NULL,
    SafetyResponseTemplates $safety_response_templates = NULL,
    OutOfScopeClassifier $out_of_scope_classifier = NULL,
    OutOfScopeResponseTemplates $out_of_scope_response_templates = NULL,
    PerformanceMonitor $performance_monitor = NULL
  ) {
    $this->configFactory = $config_factory;
    $this->intentRouter = $intent_router;
    $this->faqIndex = $faq_index;
    $this->resourceFinder = $resource_finder;
    $this->policyFilter = $policy_filter;
    $this->analyticsLogger = $analytics_logger;
    $this->llmEnhancer = $llm_enhancer;
    $this->fallbackGate = $fallback_gate;
    $this->responseGrounder = $response_grounder;
    $this->safetyClassifier = $safety_classifier;
    $this->safetyResponseTemplates = $safety_response_templates;
    $this->outOfScopeClassifier = $out_of_scope_classifier;
    $this->outOfScopeResponseTemplates = $out_of_scope_response_templates;
    $this->performanceMonitor = $performance_monitor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static(
      $container->get('config.factory'),
      $container->get('ilas_site_assistant.intent_router'),
      $container->get('ilas_site_assistant.faq_index'),
      $container->get('ilas_site_assistant.resource_finder'),
      $container->get('ilas_site_assistant.policy_filter'),
      $container->get('ilas_site_assistant.analytics_logger'),
      $container->get('ilas_site_assistant.llm_enhancer'),
      $container->get('ilas_site_assistant.fallback_gate'),
      $container->has('ilas_site_assistant.response_grounder') ? $container->get('ilas_site_assistant.response_grounder') : NULL,
      $container->has('ilas_site_assistant.safety_classifier') ? $container->get('ilas_site_assistant.safety_classifier') : NULL,
      $container->has('ilas_site_assistant.safety_response_templates') ? $container->get('ilas_site_assistant.safety_response_templates') : NULL,
      $container->has('ilas_site_assistant.out_of_scope_classifier') ? $container->get('ilas_site_assistant.out_of_scope_classifier') : NULL,
      $container->has('ilas_site_assistant.out_of_scope_response_templates') ? $container->get('ilas_site_assistant.out_of_scope_response_templates') : NULL,
      $container->has('ilas_site_assistant.performance_monitor') ? $container->get('ilas_site_assistant.performance_monitor') : NULL
    );
    $instance->flood = $container->get('flood');
    $instance->conversationCache = $container->get('cache.default');
    return $instance;
  }

  /**
   * Handles incoming chat messages.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with assistant reply.
   */
  public function message(Request $request) {
    // Rate limiting — keyed by client IP.
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $ip = $request->getClientIp();
    $flood_id = 'ilas_assistant:' . $ip;
    $per_min = (int) ($config->get('rate_limit_per_minute') ?? 15);
    $per_hr = (int) ($config->get('rate_limit_per_hour') ?? 120);

    if (!$this->flood->isAllowed('ilas_assistant_min', $per_min, 60, $flood_id)) {
      return new JsonResponse([
        'error' => 'Too many requests. Please wait a moment before trying again.',
        'type' => 'rate_limit',
      ], 429, ['Retry-After' => '60']);
    }
    if (!$this->flood->isAllowed('ilas_assistant_hr', $per_hr, 3600, $flood_id)) {
      return new JsonResponse([
        'error' => 'You have reached the hourly limit. Please try again later.',
        'type' => 'rate_limit',
      ], 429, ['Retry-After' => '3600']);
    }
    $this->flood->register('ilas_assistant_min', 60, $flood_id);
    $this->flood->register('ilas_assistant_hr', 3600, $flood_id);

    // Start performance tracking.
    $start_time = microtime(TRUE);

    // Check for DEBUG mode (server-side env var only).
    $debug_mode = $this->isDebugMode($request);

    // Initialize debug metadata.
    $debug_meta = $debug_mode ? [
      'timestamp' => date('c'),
      'intent_selected' => NULL,
      'intent_confidence' => NULL,
      'intent_source' => 'rule_based',
      'extracted_keywords' => [],
      'retrieval_results' => [],
      'retrieval_confidence' => NULL,
      'final_action' => NULL,
      'reason_code' => NULL,
      'gate_decision' => NULL,
      'gate_reason_code' => NULL,
      'gate_confidence' => NULL,
      'safety_flags' => [],
      'policy_check' => ['passed' => TRUE, 'violation_type' => NULL],
      'llm_used' => FALSE,
      'processing_stages' => [],
    ] : NULL;

    // Validate content type.
    $content_type = $request->headers->get('Content-Type');
    if (strpos($content_type, 'application/json') === FALSE) {
      return new JsonResponse(['error' => 'Invalid content type'], 400);
    }

    // Parse request body.
    $content = $request->getContent();
    if (strlen($content) > 2000) {
      return new JsonResponse(['error' => 'Request too large'], 413);
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['message'])) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }

    $user_message = $this->sanitizeInput($data['message']);
    $context = $data['context'] ?? [];

    // Parse ephemeral conversation ID (client-generated UUID).
    $conversation_id = NULL;
    $server_history = [];
    if (!empty($data['conversation_id']) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $data['conversation_id'])) {
      $conversation_id = $data['conversation_id'];
      $cache_key = 'ilas_conv:' . $conversation_id;
      $cached = $this->conversationCache->get($cache_key);
      if ($cached) {
        $server_history = $cached->data;
      }

      // Abuse detection: repeated identical messages.
      if (count($server_history) >= 3) {
        $recent_messages = array_column(array_slice($server_history, -3), 'text');
        if (count(array_unique($recent_messages)) === 1 && $recent_messages[0] === mb_substr($user_message, 0, 200)) {
          return new JsonResponse([
            'type' => 'escalation',
            'escalation_type' => 'repeated',
            'message' => (string) $this->t('It looks like you may be having trouble. Please call our Legal Advice Line for direct assistance.'),
            'actions' => $this->getEscalationActions(),
          ]);
        }
      }
    }

    // Extract keywords for debug (avoid storing raw user text).
    if ($debug_mode) {
      $debug_meta['extracted_keywords'] = $this->extractKeywords($user_message);
      $debug_meta['processing_stages'][] = 'input_sanitized';
    }

    // Run SafetyClassifier first (if available) for enhanced classification.
    $safety_classification = NULL;
    if ($this->safetyClassifier) {
      $safety_classification = $this->safetyClassifier->classify($user_message);

      if ($debug_mode) {
        $debug_meta['safety_classification'] = [
          'class' => $safety_classification['class'],
          'reason_code' => $safety_classification['reason_code'],
          'escalation_level' => $safety_classification['escalation_level'],
          'is_safe' => $safety_classification['is_safe'],
        ];
        $debug_meta['processing_stages'][] = 'safety_classified';
      }

      // If SafetyClassifier detected a non-safe message, use SafetyResponseTemplates.
      if (!$safety_classification['is_safe'] && $this->safetyResponseTemplates) {
        $safety_response = $this->safetyResponseTemplates->getResponse($safety_classification);

        // Log the safety violation with reason code.
        $this->analyticsLogger->log('safety_violation', $safety_classification['reason_code']);

        if ($debug_mode) {
          $debug_meta['policy_check'] = [
            'passed' => FALSE,
            'violation_type' => $safety_classification['class'],
            'reason_code' => $safety_classification['reason_code'],
          ];
          $debug_meta['final_action'] = $safety_classification['requires_refusal'] ? 'refusal' : 'escalation';
          $debug_meta['reason_code'] = $safety_classification['reason_code'];
          $debug_meta['intent_selected'] = 'safety_' . $safety_classification['class'];
          $debug_meta['safety_flags'] = $this->detectSafetyFlags($user_message);
        }

        $response_data = [
          'type' => $safety_response['type'],
          'escalation_type' => $safety_response['escalation_type'],
          'escalation_level' => $safety_response['escalation_level'],
          'message' => $safety_response['message'],
          'links' => $safety_response['links'] ?? [],
          'actions' => $this->getEscalationActions(),
          'reason_code' => $safety_classification['reason_code'],
        ];

        if (!empty($safety_response['disclaimer'])) {
          $response_data['disclaimer'] = $safety_response['disclaimer'];
        }

        if ($debug_mode) {
          $response_data['_debug'] = $debug_meta;
        }

        return new JsonResponse($response_data);
      }
    }

    // Run OutOfScopeClassifier as second-pass check (after safety, before intent).
    // This catches: non-Idaho, business/commercial, federal matters, emergency services, high-value civil.
    $oos_classification = NULL;
    if ($this->outOfScopeClassifier) {
      $oos_classification = $this->outOfScopeClassifier->classify($user_message);

      if ($debug_mode) {
        $debug_meta['oos_classification'] = [
          'is_out_of_scope' => $oos_classification['is_out_of_scope'],
          'category' => $oos_classification['category'],
          'reason_code' => $oos_classification['reason_code'],
          'response_type' => $oos_classification['response_type'],
        ];
        $debug_meta['processing_stages'][] = 'oos_classified';
      }

      // If out-of-scope and we have templates, return OOS response.
      if ($oos_classification['is_out_of_scope'] && $this->outOfScopeResponseTemplates) {
        $oos_response = $this->outOfScopeResponseTemplates->getResponse($oos_classification);

        // Log the out-of-scope query.
        $this->analyticsLogger->log('out_of_scope', $oos_classification['reason_code']);

        if ($debug_mode) {
          $debug_meta['final_action'] = 'out_of_scope';
          $debug_meta['reason_code'] = $oos_classification['reason_code'];
          $debug_meta['intent_selected'] = 'oos_' . $oos_classification['category'];
        }

        $response_data = [
          'type' => $oos_response['type'],
          'response_mode' => $oos_response['response_mode'],
          'escalation_type' => $oos_response['escalation_type'],
          'message' => $oos_response['message'],
          'links' => $oos_response['links'] ?? [],
          'suggestions' => $oos_response['suggestions'] ?? [],
          'actions' => $this->getEscalationActions(),
          'reason_code' => $oos_classification['reason_code'],
          'can_still_help' => $oos_response['can_still_help'] ?? FALSE,
        ];

        if (!empty($oos_response['disclaimer'])) {
          $response_data['disclaimer'] = $oos_response['disclaimer'];
        }

        if ($debug_mode) {
          $response_data['_debug'] = $debug_meta;
        }

        return new JsonResponse($response_data);
      }
    }

    // Fallback to PolicyFilter if SafetyClassifier not available or marked safe.
    $policy_result = $this->policyFilter->check($user_message);

    if ($debug_mode) {
      // Detect safety flags from policy patterns.
      $debug_meta['safety_flags'] = $this->detectSafetyFlags($user_message);
      $debug_meta['processing_stages'][] = 'policy_checked';
    }

    if ($policy_result['violation']) {
      $this->analyticsLogger->log('policy_violation', $policy_result['type']);

      if ($debug_mode) {
        $debug_meta['policy_check'] = [
          'passed' => FALSE,
          'violation_type' => $policy_result['type'],
        ];
        $debug_meta['final_action'] = 'escalation';
        $debug_meta['reason_code'] = 'policy_' . $policy_result['type'];
        $debug_meta['intent_selected'] = 'escalation';
      }

      $response_data = [
        'type' => 'escalation',
        'escalation_type' => $policy_result['type'],
        'escalation_level' => $policy_result['escalation_level'],
        'message' => $policy_result['response'],
        'links' => $policy_result['links'] ?? [],
        'actions' => $this->getEscalationActions(),
        'reason_code' => 'policy_' . $policy_result['type'],
      ];

      if ($debug_mode) {
        $response_data['_debug'] = $debug_meta;
      }

      return new JsonResponse($response_data);
    }

    // Quick-action short-circuit: when the request comes from a suggestion
    // button click, bypass all classifiers/routers and use the action directly.
    // This prevents NavigationIntent, Disambiguator, or TopicRouter from
    // hijacking deterministic button clicks (e.g. "Apply" returning FAQ).
    $quick_action_intents = [
      'apply' => 'apply_for_help',
      'hotline' => 'legal_advice_line',
      'forms' => 'forms_finder',
      'guides' => 'guides_finder',
      'faq' => 'faq',
      'topics' => 'services_overview',
    ];
    $quick_action = $context['quickAction'] ?? NULL;
    if ($quick_action && isset($quick_action_intents[$quick_action])) {
      $intent = [
        'type' => $quick_action_intents[$quick_action],
        'confidence' => 1.0,
        'source' => 'quick_action',
        'extraction' => [],
      ];

      if ($debug_mode) {
        $debug_meta['intent_selected'] = $intent['type'];
        $debug_meta['intent_source'] = 'quick_action';
        $debug_meta['intent_confidence'] = 1.0;
        $debug_meta['processing_stages'][] = 'quick_action_shortcircuit';
      }
    }
    else {
      // Route the intent via normal classification pipeline.
      $intent = $this->intentRouter->route($user_message, $context);
    }

    if ($debug_mode) {
      $debug_meta['intent_selected'] = $intent['type'];
      $debug_meta['intent_confidence'] = $this->fallbackGate->calculateIntentConfidence($intent, $user_message);
      $debug_meta['processing_stages'][] = 'intent_routed';
    }

    // Early retrieval for gate evaluation (search FAQ/resources for context).
    // Skip for deterministic intents (greeting, apply) that never need retrieval.
    $early_retrieval = [];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $skip_retrieval_intents = ['greeting', 'apply_for_help', 'apply'];
    if ($config->get('enable_faq') && !in_array($intent['type'], $skip_retrieval_intents)) {
      $early_retrieval = $this->faqIndex->search($user_message, 3);
    }

    // Evaluate fallback gate to decide: answer, clarify, or use LLM.
    $gate_context = [
      'message' => $user_message,
      'policy_violation' => FALSE,
    ];
    $gate_decision = $this->fallbackGate->evaluate(
      $intent,
      $early_retrieval,
      $debug_meta['safety_flags'] ?? [],
      $gate_context
    );

    if ($debug_mode) {
      $debug_meta['gate_decision'] = $gate_decision['decision'];
      $debug_meta['gate_reason_code'] = $gate_decision['reason_code'];
      $debug_meta['gate_confidence'] = $gate_decision['confidence'];
      $debug_meta['processing_stages'][] = 'gate_evaluated';
    }

    // Handle gate decision.
    // Never override quick-action intents — they are deterministic button clicks.
    $is_quick_action = ($intent['source'] ?? '') === 'quick_action';
    if (!$is_quick_action && $gate_decision['decision'] === FallbackGate::DECISION_FALLBACK_LLM && $this->llmEnhancer->isEnabled()) {
      // Try LLM classification for low-confidence cases.
      $llm_intent = $this->llmEnhancer->classifyIntent($user_message, $intent['type']);
      if ($llm_intent !== 'unknown' && $llm_intent !== $intent['type']) {
        $intent = ['type' => $llm_intent, 'source' => 'llm', 'extraction' => $intent['extraction'] ?? []];

        if ($debug_mode) {
          $debug_meta['intent_selected'] = $llm_intent;
          $debug_meta['intent_source'] = 'llm_fallback';
          $debug_meta['llm_used'] = TRUE;
          $debug_meta['processing_stages'][] = 'llm_classification';
        }
      }
    }
    elseif (!$is_quick_action && $gate_decision['decision'] === FallbackGate::DECISION_CLARIFY) {
      // Force clarification response.
      $intent = ['type' => 'clarify', 'original_intent' => $intent['type'], 'extraction' => $intent['extraction'] ?? []];

      if ($debug_mode) {
        $debug_meta['intent_selected'] = 'clarify';
        $debug_meta['processing_stages'][] = 'clarification_forced';
      }
    }

    // Process based on intent.
    $response = $this->processIntent($intent, $user_message, $context);

    // CRITICAL: Enforce canonical URL for hard-route intents.
    // This prevents URL drift where intent is correct but URL is wrong.
    // We use the safety-flag-aware enforcement to catch cases where
    // the intent was misclassified as a soft-route (like 'service_area')
    // but safety flags indicate a high-risk situation.
    $canonical_urls = ilas_site_assistant_get_canonical_urls();
    $builder = new ResponseBuilder($canonical_urls);
    $safety_flags = $debug_meta['safety_flags'] ?? [];
    $response = $builder->enforceHardRouteUrlWithSafetyFlags($response, $intent, $safety_flags);

    // Apply response grounding (add citations, validate info).
    if ($this->responseGrounder && !empty($response['results'])) {
      $response = $this->responseGrounder->groundResponse($response, $response['results']);
      if ($debug_mode) {
        $debug_meta['processing_stages'][] = 'response_grounded';
      }
    }

    if ($debug_mode) {
      $debug_meta['final_action'] = $this->determineFinalAction($response['type']);
      $debug_meta['reason_code'] = $this->determineReasonCode($intent, $response);
      $debug_meta['processing_stages'][] = 'intent_processed';

      // Capture retrieval results (IDs/URLs only, no content).
      if (!empty($response['results'])) {
        $debug_meta['retrieval_results'] = $this->extractRetrievalMeta($response['results']);
      }
    }

    // Enhance response with LLM if enabled.
    $original_response = $response;
    $response = $this->llmEnhancer->enhanceResponse($response, $user_message);

    if ($debug_mode && ($response['llm_enhanced'] ?? FALSE)) {
      $debug_meta['llm_used'] = TRUE;
      $debug_meta['processing_stages'][] = 'llm_enhancement';
    }

    // Log the interaction.
    $this->analyticsLogger->log($intent['type'], $intent['value'] ?? '');

    // Check if we found any results.
    if (empty($response['results']) && $response['type'] !== 'navigation') {
      $this->analyticsLogger->logNoAnswer($user_message);

      if ($debug_mode) {
        $debug_meta['reason_code'] = 'no_results_found';
      }
    }

    // Attach debug metadata if enabled.
    if ($debug_mode) {
      $debug_meta['processing_stages'][] = 'response_complete';
      $response['_debug'] = $debug_meta;
    }

    // Store conversation turn in cache for multi-turn continuity.
    if ($conversation_id) {
      $server_history[] = [
        'role' => 'user',
        'text' => mb_substr($user_message, 0, 200),
        'intent' => $intent['type'] ?? 'unknown',
        'safety_flags' => $debug_meta['safety_flags'] ?? $this->detectSafetyFlags($user_message),
        'timestamp' => time(),
      ];
      // Keep only last 10 entries (5 exchanges).
      $server_history = array_slice($server_history, -10);
      // Store with 30-minute TTL.
      $this->conversationCache->set(
        'ilas_conv:' . $conversation_id,
        $server_history,
        time() + 1800
      );

      // Multi-turn safety pattern detection.
      if (count($server_history) >= 3) {
        $recent_flags = [];
        foreach (array_slice($server_history, -3) as $turn) {
          $recent_flags = array_merge($recent_flags, $turn['safety_flags'] ?? []);
        }
        if (count($recent_flags) >= 3) {
          \Drupal::logger('ilas_site_assistant')->warning(
            'Multi-turn safety pattern detected for conversation @id: @flags',
            ['@id' => $conversation_id, '@flags' => implode(', ', $recent_flags)]
          );
        }
      }
    }

    // Opt-in conversation logging (for QA/debugging).
    if ($conversation_id && \Drupal::hasService('ilas_site_assistant.conversation_logger')) {
      $conv_logger = \Drupal::service('ilas_site_assistant.conversation_logger');
      $conv_logger->logExchange(
        $conversation_id,
        $user_message,
        $response['message'] ?? '',
        $intent['type'] ?? 'unknown',
        $response['type'] ?? 'unknown'
      );
    }

    // Record performance metrics.
    if ($this->performanceMonitor) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $scenario = $this->classifyScenario($intent['type'] ?? 'unknown');
      $this->performanceMonitor->recordRequest($duration_ms, TRUE, $scenario);
    }

    return new JsonResponse($response);
  }

  /**
   * Classifies intent type into monitoring scenario.
   *
   * @param string $intent_type
   *   The intent type.
   *
   * @return string
   *   One of: short, navigation, retrieval.
   */
  protected function classifyScenario(string $intent_type): string {
    $short_types = ['greeting', 'thanks', 'help'];
    $navigation_types = ['apply', 'hotline', 'offices', 'donate', 'feedback', 'services'];
    // Everything else is retrieval (faq, forms, guides, resources, topic, etc.)

    if (in_array($intent_type, $short_types)) {
      return 'short';
    }
    if (in_array($intent_type, $navigation_types)) {
      return 'navigation';
    }
    return 'retrieval';
  }

  /**
   * Checks if debug mode is enabled.
   *
   * Debug mode can be enabled via:
   * - Environment variable: ILAS_CHATBOT_DEBUG=1
   * - Request header: X-Debug-Mode: 1
   * - Request body: { "debug": true }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if debug mode is enabled.
   */
  protected function isDebugMode(Request $request): bool {
    // SECURITY: Debug mode is ONLY enabled via server-side environment variable.
    // Client-supplied debug flags (headers, body) are never trusted on public
    // endpoints. Set ILAS_CHATBOT_DEBUG=1 in .ddev/.env (local) or Pantheon
    // environment variables (dev/multidev only — never live).
    return getenv('ILAS_CHATBOT_DEBUG') === '1';
  }

  /**
   * Extracts keywords from text for debug output.
   *
   * Avoids storing raw user text by extracting only keywords.
   *
   * @param string $text
   *   The text to extract keywords from.
   *
   * @return array
   *   Array of keywords.
   */
  protected function extractKeywords(string $text): array {
    $text = strtolower($text);
    $text = preg_replace('/[^\w\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text);

    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'you', 'we', 'they', 'my', 'your', 'me', 'can',
      'do', 'does', 'did', 'have', 'has', 'am', 'be', 'been', 'what',
      'how', 'where', 'when', 'why', 'this', 'that', 'it',
    ];

    $keywords = array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) >= 3 && !in_array($word, $stop_words);
    });

    return array_values(array_unique($keywords));
  }

  /**
   * Detects safety flags from the user message.
   *
   * @param string $message
   *   The user message.
   *
   * @return array
   *   Array of detected safety flags.
   */
  protected function detectSafetyFlags(string $message): array {
    $flags = [];
    $message_lower = strtolower($message);

    // Domestic violence indicators.
    if (preg_match('/\b(domestic\s*violence|dv|abus|hit.*me|beat.*me|threaten)/i', $message)) {
      $flags[] = 'dv_indicator';
    }

    // Eviction urgency.
    if (preg_match('/\b(evict|sheriff|lock.*out|homeless|thrown?\s*out)/i', $message)) {
      $flags[] = 'eviction_imminent';
    }

    // Identity theft / scam.
    if (preg_match('/\b(identity\s*theft|scam|fraud|stolen\s*identity)/i', $message)) {
      $flags[] = 'identity_theft';
    }

    // Crisis/emergency.
    if (preg_match('/\b(emergency|urgent|suicide|crisis|danger|911)/i', $message)) {
      $flags[] = 'crisis_emergency';
    }

    // Deadline pressure.
    if (preg_match('/\b(deadline|due\s*(today|tomorrow|friday|monday)|court\s*date)/i', $message)) {
      $flags[] = 'deadline_pressure';
    }

    // Criminal matter (out of scope).
    if (preg_match('/\b(arrest|criminal|felony|misdemeanor|jail|prison|dui|dwi)/i', $message)) {
      $flags[] = 'criminal_matter';
    }

    return $flags;
  }

  /**
   * Calculates a confidence score for the detected intent.
   *
   * Delegates to FallbackGate for centralized confidence calculation.
   *
   * @param array $intent
   *   The detected intent.
   * @param string $message
   *   The user message.
   *
   * @return float
   *   Confidence score between 0 and 1.
   */
  protected function calculateIntentConfidence(array $intent, string $message): float {
    return $this->fallbackGate->calculateIntentConfidence($intent, $message);
  }

  /**
   * Determines the final action category.
   *
   * @param string $response_type
   *   The response type.
   *
   * @return string
   *   One of: answer, clarify, fallback_llm, hard_route.
   */
  protected function determineFinalAction(string $response_type): string {
    $answer_types = ['faq', 'resources', 'topic', 'eligibility', 'services_overview'];
    $hard_route_types = ['navigation', 'escalation'];

    if (in_array($response_type, $answer_types)) {
      return 'answer';
    }

    if (in_array($response_type, $hard_route_types)) {
      return 'hard_route';
    }

    if ($response_type === 'fallback') {
      return 'clarify';
    }

    if ($response_type === 'greeting') {
      return 'answer';
    }

    return 'answer';
  }

  /**
   * Determines a reason code for the response.
   *
   * @param array $intent
   *   The detected intent.
   * @param array $response
   *   The response data.
   *
   * @return string
   *   A reason code describing why this response was given.
   */
  protected function determineReasonCode(array $intent, array $response): string {
    $type = $response['type'] ?? 'unknown';
    $intent_type = $intent['type'] ?? 'unknown';

    // Build reason code from intent and result.
    if ($type === 'faq' && !empty($response['results'])) {
      return 'faq_match_found';
    }

    if ($type === 'resources' && !empty($response['results'])) {
      return 'resource_match_found';
    }

    if ($type === 'navigation') {
      return 'direct_navigation_' . $intent_type;
    }

    if ($type === 'fallback') {
      return 'no_match_fallback';
    }

    if ($type === 'escalation') {
      return 'policy_escalation';
    }

    return 'intent_' . $intent_type;
  }

  /**
   * Extracts retrieval metadata from results (IDs/URLs only).
   *
   * @param array $results
   *   The retrieval results.
   *
   * @return array
   *   Array of result metadata (id, url, score).
   */
  protected function extractRetrievalMeta(array $results): array {
    $meta = [];

    foreach (array_slice($results, 0, 10) as $i => $result) {
      $item = [
        'rank' => $i + 1,
        'id' => $result['id'] ?? $result['paragraph_id'] ?? NULL,
        'url' => $result['url'] ?? $result['source_url'] ?? NULL,
        'type' => $result['type'] ?? 'unknown',
      ];

      // Include score if available.
      if (isset($result['score'])) {
        $item['score'] = $result['score'];
      }

      $meta[] = $item;
    }

    return $meta;
  }

  /**
   * Returns quick suggestions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with suggestions.
   */
  public function suggest(Request $request) {
    $query = $request->query->get('q', '');
    $type = $request->query->get('type', 'all');

    $suggestions = [];

    if (strlen($query) >= 2) {
      $query = $this->sanitizeInput($query);

      if ($type === 'all' || $type === 'topics') {
        $topics = $this->intentRouter->suggestTopics($query);
        foreach ($topics as $topic) {
          $suggestions[] = [
            'type' => 'topic',
            'label' => $topic['name'],
            'id' => $topic['id'],
          ];
        }
      }

      if ($type === 'all' || $type === 'faq') {
        $faqs = $this->faqIndex->search($query, 3);
        foreach ($faqs as $faq) {
          $suggestions[] = [
            'type' => 'faq',
            'label' => $faq['question'],
            'id' => $faq['id'],
          ];
        }
      }
    }

    return new JsonResponse([
      'suggestions' => array_slice($suggestions, 0, 6),
    ]);
  }

  /**
   * Returns FAQ data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with FAQ data.
   */
  public function faq(Request $request) {
    $query = $request->query->get('q', '');
    $id = $request->query->get('id');

    if ($id) {
      // Get specific FAQ item.
      $faq = $this->faqIndex->getById($id);
      if ($faq) {
        return new JsonResponse(['faq' => $faq]);
      }
      return new JsonResponse(['error' => 'FAQ not found'], 404);
    }

    // Search FAQs.
    if (strlen($query) >= 2) {
      $query = $this->sanitizeInput($query);
      $results = $this->faqIndex->search($query, 5);
      return new JsonResponse([
        'results' => $results,
        'count' => count($results),
      ]);
    }

    // Return all FAQ categories.
    $categories = $this->faqIndex->getCategories();
    return new JsonResponse(['categories' => $categories]);
  }

  /**
   * Processes an intent and returns a response.
   *
   * @param array $intent
   *   The detected intent.
   * @param string $message
   *   The user's message.
   * @param array $context
   *   Conversation context.
   *
   * @return array
   *   Response data.
   */
  protected function processIntent(array $intent, string $message, array $context) {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $canonical_urls = ilas_site_assistant_get_canonical_urls();

    // Use the shared ResponseBuilder for the canonical response skeleton.
    $builder = new ResponseBuilder($canonical_urls);
    $contract = $builder->buildFromIntent($intent, $message);

    // Normalize intent type for enrichment logic.
    $intent_type = ResponseBuilder::normalizeIntentType($intent['type'] ?? 'unknown');

    // Build the API response from the contract, enriching with service data.
    // The contract's primary_action and secondary_actions are always present.
    $response = [
      'type' => $contract['type'],
      'message' => $contract['answer_text'],
      'response_mode' => $contract['response_mode'],
      'primary_action' => $contract['primary_action'],
      'secondary_actions' => $contract['secondary_actions'],
      'reason_code' => $contract['reason_code'],
    ];

    // Set legacy url field from primary_action for backwards compat.
    if (!empty($contract['primary_action']['url'])) {
      $response['url'] = $contract['primary_action']['url'];
    }

    // Enrich response based on intent type (FAQ results, resources, etc.).
    switch ($intent_type) {
      case 'faq':
        if (!$config->get('enable_faq')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can find frequently asked questions on our FAQ page.');
          break;
        }
        $results = $this->faqIndex->search($message, 3);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['faq'];
        $response['message'] = count($results) > 0
          ? $this->t('I found some FAQs that might help:')
          : $this->t('I couldn\'t find a matching FAQ. Try our FAQ page or contact us for help.');
        break;

      case 'forms':
        if (!$config->get('enable_resources')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can find forms on our Forms page.');
          break;
        }
        // Check if this is a bare/generic "find a form" request with no topic.
        // If so, ask the user what kind of form they need instead of returning
        // random results from a useless fulltext search on "Find a form".
        $form_query = $intent['topic'] ?? $message;
        $form_topic_keywords = $this->extractFormTopicKeywords($form_query);
        if (empty($form_topic_keywords)) {
          // No meaningful topic keywords — return clarification prompt.
          $response['type'] = 'form_finder_clarify';
          $response['response_mode'] = 'clarify';
          $response['message'] = $this->t('What kind of form are you looking for? You can type a keyword (e.g., eviction, divorce, debt), or pick a topic:');
          $response['topic_suggestions'] = [
            ['label' => $this->t('Housing'), 'action' => 'forms_housing'],
            ['label' => $this->t('Family'), 'action' => 'forms_family'],
            ['label' => $this->t('Consumer'), 'action' => 'forms_consumer'],
            ['label' => $this->t('Seniors'), 'action' => 'forms_seniors'],
            ['label' => $this->t('Safety'), 'action' => 'forms_safety'],
            ['label' => $this->t('Benefits'), 'action' => 'forms_benefits'],
          ];
          $response['primary_action'] = [
            'label' => $this->t('Browse All Forms'),
            'url' => $canonical_urls['forms'],
          ];
          $response['form_finder_mode'] = TRUE;
          // Log clarification trigger.
          \Drupal::logger('ilas_site_assistant')->info('Form Finder clarification triggered for bare query: @query', [
            '@query' => $message,
          ]);
          break;
        }
        // Has topic keywords — search with the topic-focused query.
        $results = $this->resourceFinder->findForms($form_topic_keywords, 6);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['forms'];
        $response['fallback_label'] = $this->t('Browse all forms');
        $response['message'] = count($results) > 0
          ? $this->t('Here are some forms that might help:')
          : $this->t('I couldn\'t find matching forms. Browse our Forms page or contact us.');
        // Add guardrail reminder about legal advice.
        if (count($results) > 0) {
          $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
        }
        // Log form finder search for debugging.
        \Drupal::logger('ilas_site_assistant')->info('Form Finder search: query=@query, topic_keywords=@keywords, results=@count', [
          '@query' => $message,
          '@keywords' => $form_topic_keywords,
          '@count' => count($results),
        ]);
        break;

      case 'guides':
        if (!$config->get('enable_resources')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can find guides on our Guides page.');
          break;
        }
        // Check if this is a bare/generic "find a guide" request with no topic.
        // If so, ask the user what kind of guide they need instead of returning
        // random results from a useless fulltext search on "Find a guide".
        $guide_query = $intent['topic'] ?? $message;
        $guide_topic_keywords = $this->extractFinderTopicKeywords($guide_query, 'guides');
        if (empty($guide_topic_keywords)) {
          // No meaningful topic keywords — return clarification prompt.
          $response['type'] = 'guide_finder_clarify';
          $response['response_mode'] = 'clarify';
          $response['message'] = $this->t('What kind of guide are you looking for? Type a keyword (e.g., eviction, divorce, debt), or pick a topic:');
          $response['topic_suggestions'] = [
            ['label' => $this->t('Housing'), 'action' => 'guides_housing'],
            ['label' => $this->t('Family'), 'action' => 'guides_family'],
            ['label' => $this->t('Consumer'), 'action' => 'guides_consumer'],
            ['label' => $this->t('Seniors'), 'action' => 'guides_seniors'],
            ['label' => $this->t('Employment'), 'action' => 'guides_employment'],
            ['label' => $this->t('Benefits'), 'action' => 'guides_benefits'],
            ['label' => $this->t('Safety'), 'action' => 'guides_safety'],
          ];
          $response['primary_action'] = [
            'label' => $this->t('Browse All Guides'),
            'url' => $canonical_urls['guides'],
          ];
          $response['guide_finder_mode'] = TRUE;
          // Log clarification trigger.
          \Drupal::logger('ilas_site_assistant')->info('Guide Finder clarification triggered for bare query: @query', [
            '@query' => $message,
          ]);
          break;
        }
        // Has topic keywords — search with the topic-focused query.
        $results = $this->resourceFinder->findGuides($guide_topic_keywords, 6);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['guides'];
        $response['fallback_label'] = $this->t('Browse all guides');
        $response['message'] = count($results) > 0
          ? $this->t('Here are some guides that might help:')
          : $this->t('I couldn\'t find matching guides for "@query". Browse our Guides page or contact us.', ['@query' => $guide_topic_keywords]);
        // Add guardrail reminder about legal advice.
        if (count($results) > 0) {
          $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
        }
        // Log guide finder search for debugging.
        \Drupal::logger('ilas_site_assistant')->info('Guide Finder search: query=@query, topic_keywords=@keywords, results=@count', [
          '@query' => $message,
          '@keywords' => $guide_topic_keywords,
          '@count' => count($results),
        ]);
        break;

      case 'resources':
        if (!$config->get('enable_resources')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can browse resources on our Resources page.');
          break;
        }
        $results = $this->resourceFinder->findResources($intent['topic'] ?? $message, 3);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['resources'];
        $response['message'] = count($results) > 0
          ? $this->t('Here are some resources that might help:')
          : $this->t('I couldn\'t find matching resources. Browse our Resources page or contact us.');
        break;

      case 'topic':
        $topic_info = $this->intentRouter->getTopicInfo($intent['topic_id']);
        if ($topic_info) {
          $response['message'] = $this->t('Here\'s information about @topic:', ['@topic' => $topic_info['name']]);
          $response['topic'] = $topic_info;
          $response['service_area_url'] = $topic_info['service_area_url'] ?? NULL;
          if (!empty($topic_info['service_area_url'])) {
            $response['primary_action']['url'] = $topic_info['service_area_url'];
            $response['url'] = $topic_info['service_area_url'];
          }
        }
        else {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('Browse our service areas to find information on your topic.');
        }
        break;

      case 'apply':
        $online_app_url = $canonical_urls['online_application'] ?? $canonical_urls['apply'];
        $response['type'] = 'apply_cta';
        $response['response_mode'] = 'navigate';
        $response['message'] = $this->t('Idaho Legal Aid Services offers three ways to apply—choose what works best for you.');
        $response['apply_methods'] = [
          [
            'method' => 'online',
            'heading' => $this->t('Apply online (fastest)'),
            'description' => $this->t('Start our secure online application (about 15 minutes).'),
            'cta_label' => $this->t('Start online application'),
            'cta_url' => $online_app_url,
            'secondary_label' => $this->t('Apply for Help page'),
            'secondary_url' => $canonical_urls['apply'],
            'icon' => 'laptop',
          ],
          [
            'method' => 'phone',
            'heading' => $this->t('Apply by phone'),
            'description' => $this->t('Call (208) 746-7541 Monday–Wednesday, 10:00 a.m.–1:30 p.m. Mountain (9:00 a.m.–12:30 p.m. Pacific). Phone intakes are closed Thursday/Friday.'),
            'cta_label' => $this->t('Call (208) 746-7541'),
            'cta_url' => 'tel:208-746-7541',
            'secondary_label' => $this->t('Legal Advice Line'),
            'secondary_url' => $canonical_urls['hotline'],
            'icon' => 'phone-alt',
          ],
          [
            'method' => 'in_person',
            'heading' => $this->t('Apply in person'),
            'description' => $this->t('Visit your local ILAS office between 8:30 a.m. and 4:30 p.m. (in-person intakes aren\'t available after 4:30).'),
            'cta_label' => $this->t('Find an office'),
            'cta_url' => $canonical_urls['offices'],
            'icon' => 'building',
          ],
        ];
        $response['followup'] = $this->t('If you tell me your city or county, I can point you to the nearest office.');
        $response['primary_action'] = [
          'label' => $this->t('Start online application'),
          'url' => $online_app_url,
        ];
        $response['url'] = $canonical_urls['apply'];
        break;

      case 'services':
        $response['message'] = $this->t('Idaho Legal Aid Services provides free civil legal help in areas including housing, family law, consumer issues, public benefits, and more. Here\'s an overview of our services:');
        $response['service_areas'] = [
          ['label' => $this->t('Housing'), 'url' => $canonical_urls['service_areas']['housing']],
          ['label' => $this->t('Family'), 'url' => $canonical_urls['service_areas']['family']],
          ['label' => $this->t('Seniors'), 'url' => $canonical_urls['service_areas']['seniors']],
          ['label' => $this->t('Health & Benefits'), 'url' => $canonical_urls['service_areas']['health']],
          ['label' => $this->t('Consumer'), 'url' => $canonical_urls['service_areas']['consumer']],
          ['label' => $this->t('Civil Rights'), 'url' => $canonical_urls['service_areas']['civil_rights']],
        ];
        break;

      case 'hotline':
        $response['cta'] = $this->t('Contact Hotline');
        $response['message'] = $this->t('Our Legal Advice Line can help. Here\'s the information:');
        break;

      case 'donate':
        $response['cta'] = $this->t('Donate');
        $response['message'] = $this->t('Thank you for considering a donation! Here\'s how you can help:');
        break;

      case 'offices':
        $response['cta'] = $this->t('Find Offices');
        $response['message'] = $this->t('Find an office near you:');
        break;

      case 'feedback':
        $response['cta'] = $this->t('Give Feedback');
        $response['message'] = $this->t('We value your feedback:');
        break;

      case 'risk_detector':
        $response['cta'] = $this->t('Take the Assessment');
        $response['message'] = $this->t('Our Legal Risk Detector can help identify potential legal issues you may be facing:');
        break;

      case 'greeting':
        $response['message'] = $config->get('welcome_message');
        $response['suggestions'] = $this->getQuickSuggestions();
        break;

      case 'eligibility':
        $response['message'] = $this->t('ILAS provides free legal help to low-income Idahoans. Eligibility is generally based on income and the type of legal issue. To find out if you qualify, you can apply online or call our Legal Advice Line.');
        $response['caveat'] = $this->t('Note: Eligibility depends on your specific situation. Applying is the best way to find out if we can help.');
        $response['links'] = [
          ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
          ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'services'],
        ];
        break;

      case 'service_area':
        $area = $intent['area'] ?? '';
        $response['message'] = $this->t('Here\'s our @area legal help page:', ['@area' => ucfirst(str_replace('_', ' ', $area))]);
        break;

      case 'disambiguation':
        // IntentRouter detected competing intents - present options.
        $options = $intent['options'] ?? [];
        $question = $intent['question'] ?? $this->t('What are you looking for?');

        $option_links = [];
        foreach ($options as $option) {
          $opt_intent = $option['intent'] ?? '';
          $opt_url = $builder->resolveIntentUrl($opt_intent);
          $option_links[] = [
            'label' => $option['label'] ?? $opt_intent,
            'action' => $opt_intent,
            'url' => $opt_url,
          ];
        }

        if (!empty($intent['competing_intents'])) {
          $best_intent = $intent['competing_intents'][0]['intent'] ?? '';
          $best_url = $builder->resolveIntentUrl($best_intent);
          if ($best_url) {
            $response['type'] = 'navigation';
            $response['url'] = $best_url;
            $response['primary_action'] = ['label' => 'Best Match', 'url' => $best_url];
          }
        }

        $response['message'] = $question;
        $response['options'] = $option_links;
        $response['topic_suggestions'] = $option_links;
        $response['suggestions'] = $this->getQuickSuggestions();
        break;

      case 'clarify':
        $response['message'] = $this->t('I want to make sure I understand. Could you tell me more about what you\'re looking for?');
        $response['topic_suggestions'] = [
          ['label' => $this->t('Apply for Legal Help'), 'action' => 'apply', 'url' => $canonical_urls['apply']],
          ['label' => $this->t('Find Forms'), 'action' => 'forms', 'url' => $canonical_urls['forms']],
          ['label' => $this->t('Search FAQs'), 'action' => 'faq', 'url' => $canonical_urls['faq']],
          ['label' => $this->t('Call Hotline'), 'action' => 'hotline', 'url' => $canonical_urls['hotline']],
        ];
        $response['suggestions'] = $this->getQuickSuggestions();
        $response['clarification_requested'] = TRUE;
        break;

      case 'high_risk':
        $risk_category = $intent['risk_category'] ?? 'unknown';
        $response['escalation_type'] = $risk_category;
        $response['escalation_level'] = 'high';
        $response['message'] = $this->getHighRiskMessage($risk_category);
        $response['links'] = [
          ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
          ['label' => $this->t('Call Hotline'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
        ];
        $response['actions'] = $this->getEscalationActions();

        if ($risk_category === 'high_risk_dv') {
          $response['links'][] = ['label' => $this->t('National DV Hotline: 1-800-799-7233'), 'url' => 'tel:18007997233', 'type' => 'crisis'];
          $response['links'][] = ['label' => $this->t('Protection Order Forms'), 'url' => $canonical_urls['forms'], 'type' => 'forms'];
        }
        elseif ($risk_category === 'high_risk_eviction') {
          $response['links'][] = ['label' => $this->t('Eviction Response Forms'), 'url' => $canonical_urls['forms'], 'type' => 'forms'];
          $response['links'][] = ['label' => $this->t('Housing Guides'), 'url' => $canonical_urls['guides'], 'type' => 'guides'];
        }
        elseif ($risk_category === 'high_risk_scam') {
          $response['links'][] = ['label' => $this->t('Report to FTC'), 'url' => 'https://reportfraud.ftc.gov', 'type' => 'external'];
        }
        elseif ($risk_category === 'high_risk_deadline') {
          $response['url'] = $canonical_urls['hotline'];
          $response['primary_action'] = ['label' => 'Call Hotline', 'url' => $canonical_urls['hotline']];
          $response['message'] = $this->t('With an urgent deadline, please call our Legal Advice Line right away for immediate assistance.');
        }
        break;

      case 'out_of_scope':
        $is_emergency = $this->isEmergencyMessage($message);
        $is_criminal = $this->isCriminalMatter($message);
        $is_non_idaho = $this->isNonIdaho($message);

        $response['escalation_type'] = 'out_of_scope';
        $response['escalation_level'] = 'medium';

        if ($is_emergency) {
          $response['escalation_type'] = 'emergency';
          $response['escalation_level'] = 'critical';
          $response['message'] = $this->t('If this is an emergency, please call 911 immediately. For non-emergency legal help, you can apply through our website.');
          $response['url'] = 'tel:911';
          $response['primary_action'] = ['label' => 'Call 911', 'url' => 'tel:911'];
          $response['links'] = [
            ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
          ];
        }
        elseif ($is_criminal) {
          $response['message'] = $this->t('Idaho Legal Aid Services handles civil (non-criminal) cases only. For criminal matters, please contact the Idaho State Bar Lawyer Referral Service at (208) 334-4500 or request a public defender through the court.');
          $response['links'] = [
            ['label' => $this->t('Idaho State Bar Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
          ];
        }
        elseif ($is_non_idaho) {
          $response['message'] = $this->t('Idaho Legal Aid Services only assists Idaho residents with legal matters in Idaho. For help in other states, please visit LawHelp.org to find legal aid in your area.');
          $response['links'] = [
            ['label' => $this->t('Find Legal Aid Nationwide'), 'url' => 'https://www.lawhelp.org', 'type' => 'external'],
          ];
        }
        else {
          $response['message'] = $this->t('This type of legal matter may be outside our service area. For more information about what we can help with, please visit our services page or call our Legal Advice Line.');
          $response['links'] = [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'services'],
            ['label' => $this->t('Call Hotline'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
            ['label' => $this->t('Idaho State Bar Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
          ];
        }
        break;

      default:
        // Before returning fallback, try searching FAQs as a last resort.
        if ($config->get('enable_faq')) {
          $faq_results = $this->faqIndex->search($message, 3);
          if (!empty($faq_results)) {
            $response['type'] = 'faq';
            $response['message'] = $this->t('I found some information that might help:');
            $response['results'] = $faq_results;
            $response['fallback_url'] = $canonical_urls['faq'];
            $response['primary_action'] = ['label' => 'Browse FAQs', 'url' => $canonical_urls['faq']];
            break;
          }
        }

        if ($config->get('enable_resources')) {
          $resource_results = $this->resourceFinder->findResources($message, 3);
          if (!empty($resource_results)) {
            $response['type'] = 'resources';
            $response['message'] = $this->t('I found some resources that might help:');
            $response['results'] = $resource_results;
            $response['fallback_url'] = $canonical_urls['resources'];
            $response['primary_action'] = ['label' => 'Browse Resources', 'url' => $canonical_urls['resources']];
            break;
          }
        }

        $response['message'] = $this->t('I\'m not sure I understood. Are you looking for help with one of these areas?');
        $response['topic_suggestions'] = [
          ['label' => $this->t('Housing'), 'action' => 'topic_housing', 'url' => $canonical_urls['service_areas']['housing']],
          ['label' => $this->t('Family'), 'action' => 'topic_family', 'url' => $canonical_urls['service_areas']['family']],
          ['label' => $this->t('Seniors'), 'action' => 'topic_seniors', 'url' => $canonical_urls['service_areas']['seniors']],
          ['label' => $this->t('Benefits'), 'action' => 'topic_benefits', 'url' => $canonical_urls['service_areas']['health']],
          ['label' => $this->t('Consumer'), 'action' => 'topic_consumer', 'url' => $canonical_urls['service_areas']['consumer']],
          ['label' => $this->t('Civil Rights'), 'action' => 'topic_civil_rights', 'url' => $canonical_urls['service_areas']['civil_rights']],
        ];
        $response['suggestions'] = $this->getQuickSuggestions();
        $response['actions'] = $this->getEscalationActions();
        break;
    }

    return $response;
  }

  /**
   * Returns quick suggestion buttons.
   *
   * @return array
   *   Array of suggestions.
   */
  protected function getQuickSuggestions() {
    return [
      ['label' => $this->t('Find a form'), 'action' => 'forms'],
      ['label' => $this->t('Find a guide'), 'action' => 'guides'],
      ['label' => $this->t('Search FAQs'), 'action' => 'faq'],
      ['label' => $this->t('Apply for help'), 'action' => 'apply'],
    ];
  }

  // resolveIntentUrl() has been moved to ResponseBuilder::resolveIntentUrl().

  /**
   * Returns escalation action buttons.
   *
   * @return array
   *   Array of escalation actions.
   */
  protected function getEscalationActions() {
    $canonical_urls = ilas_site_assistant_get_canonical_urls();
    return [
      [
        'label' => $this->t('Call Hotline'),
        'url' => $canonical_urls['hotline'],
        'type' => 'hotline',
      ],
      [
        'label' => $this->t('Apply for Help'),
        'url' => $canonical_urls['apply'],
        'type' => 'apply',
      ],
      [
        'label' => $this->t('Give Feedback'),
        'url' => $canonical_urls['feedback'],
        'type' => 'feedback',
      ],
    ];
  }

  /**
   * Extracts meaningful topic keywords from a finder query.
   *
   * Strips generic "find a form/guide" noise and returns the topic portion.
   * Returns empty string if the query is bare/generic (no topic).
   *
   * @param string $query
   *   The user's message (e.g., "Find a form", "Find housing guides").
   * @param string $finder_type
   *   The finder type: 'forms' or 'guides'. Determines which resource-
   *   specific noise words to strip.
   *
   * @return string
   *   Topic keywords for searching, or empty string if too generic.
   */
  protected function extractFinderTopicKeywords(string $query, string $finder_type = 'forms'): string {
    $lower = strtolower(trim($query));

    // Resource-type-specific noise words.
    $type_noise = [
      'forms' => '/\b(form|forms|froms|formulario|formularios|paperwork|papers|documents?|court\s*papers?)\b/i',
      'guides' => '/\b(guide|guides|giude|giudes|guia|guias|manual|manuals|handbook|handbooks|instructions?|how[\s-]*to|step[\s-]*by[\s-]*step|self[\s-]*help)\b/i',
    ];

    // Strip common finder preamble phrases + type-specific noise.
    $noise_patterns = [
      '/^(find|get|need|download|where|show|read|browse)\s*(me\s*)?(a|the|is|are|some|any)?\s*/i',
      $type_noise[$finder_type] ?? $type_noise['forms'],
      '/\b(for|to|about|on|regarding)\b/i',
      '/\b(legal|court|i\s*need|looking\s*for|where\s*can\s*i)\b/i',
      '/^\s*(a|an|the|my|some|any)\s+/i',
    ];

    $cleaned = $lower;
    foreach ($noise_patterns as $pattern) {
      $cleaned = preg_replace($pattern, ' ', $cleaned);
    }
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

    // If nothing meaningful remains (or only stop words), it's a bare request.
    $stop_words = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'are', 'i', 'me', 'my', 'can', 'do', 'how', 'what', 'where', 'please', 'find', 'get', 'need', 'show', 'download', 'looking', 'browse', 'read'];
    $words = array_filter(explode(' ', $cleaned), function ($w) use ($stop_words) {
      return strlen($w) >= 2 && !in_array($w, $stop_words);
    });

    if (empty($words)) {
      return '';
    }

    return implode(' ', $words);
  }

  /**
   * Backwards-compatible wrapper for form finder keyword extraction.
   */
  protected function extractFormTopicKeywords(string $query): string {
    return $this->extractFinderTopicKeywords($query, 'forms');
  }

  /**
   * Sanitizes user input.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   Sanitized string.
   */
  protected function sanitizeInput(string $input) {
    // Remove HTML tags.
    $input = strip_tags($input);
    // Limit length.
    $input = mb_substr($input, 0, 500);
    // Remove control characters.
    $input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
    // Normalize whitespace.
    $input = preg_replace('/\s+/', ' ', $input);
    return trim($input);
  }

  /**
   * Returns appropriate message for high-risk situations.
   *
   * @param string $risk_category
   *   The risk category (high_risk_dv, high_risk_eviction, etc.).
   *
   * @return string
   *   The appropriate safety message.
   */
  protected function getHighRiskMessage(string $risk_category): string {
    $messages = [
      'high_risk_dv' => $this->t('We understand you may be in a difficult situation. Your safety is important. Idaho Legal Aid can help with protection orders and safety planning. If you are in immediate danger, please call 911.'),
      'high_risk_eviction' => $this->t('If you\'ve received an eviction notice, it\'s important to act quickly. Idaho Legal Aid can help you understand your rights and respond to the notice. Please apply for help or call our hotline as soon as possible.'),
      'high_risk_scam' => $this->t('We\'re sorry to hear you may have been the victim of a scam or identity theft. Idaho Legal Aid can help you understand your options and take steps to protect yourself. Please apply for help right away.'),
      'high_risk_deadline' => $this->t('With an urgent legal deadline, please call our Legal Advice Line immediately for assistance. Time-sensitive legal matters require prompt attention.'),
      'high_risk_utility' => $this->t('If your utilities have been or are about to be shut off, Idaho Legal Aid may be able to help. Please apply for help or call our hotline immediately.'),
    ];

    return $messages[$risk_category] ?? $this->t('We see this may be an urgent situation. Please apply for help or call our Legal Advice Line for immediate assistance.');
  }

  /**
   * Checks if the message indicates an emergency requiring 911.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if message indicates emergency.
   */
  protected function isEmergencyMessage(string $message): bool {
    $emergency_patterns = [
      '/\b(call\s*911|dial\s*911)\b/i',
      '/\b(being\s+attacked|someone\s+is\s+dying)\b/i',
      '/\b(heart\s+attack|stroke|not\s+breathing)\b/i',
      '/\b(breaking\s+in\s+right\s+now)\b/i',
      '/\b(active\s+shooter|gun|weapon)\b/i',
    ];

    foreach ($emergency_patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the message indicates a criminal matter.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if message indicates criminal matter.
   */
  protected function isCriminalMatter(string $message): bool {
    $criminal_patterns = [
      '/\b(criminal\s+(defense|lawyer|case|charge))\b/i',
      '/\b(arrested|arrest\s+warrant)\b/i',
      '/\b(felony|misdemeanor)\b/i',
      '/\b(dui|dwi|drunk\s+driving)\b/i',
      '/\b(public\s+defender)\b/i',
      '/\b(jail|prison|incarcerat)\b/i',
      '/\b(probation\s+violation|parole)\b/i',
    ];

    // Don't classify as criminal if it's expungement-related.
    if (preg_match('/\b(expunge|seal|clear)\s+(my\s+)?record/i', $message)) {
      return FALSE;
    }

    foreach ($criminal_patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the message indicates a non-Idaho matter.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if message indicates non-Idaho matter.
   */
  protected function isNonIdaho(string $message): bool {
    // Don't classify as non-Idaho if Idaho is also mentioned
    // (e.g. "I moved from Texas to Idaho").
    if (preg_match('/\bidaho\b/i', $message)) {
      return FALSE;
    }

    $non_idaho_patterns = [
      // Generic out-of-state phrases.
      '/\b(out\s+of\s+state|different\s+state|another\s+state|not\s+in\s+idaho)\b/i',

      // Explicit "I live/reside in [state]" with all US states except Idaho.
      '/\b(live|living|reside|residing|located|based|am\s+in|i\'m\s+in)\s+(in\s+)?(alabama|alaska|arizona|arkansas|california|colorado|connecticut|delaware|florida|georgia|hawaii|illinois|indiana|iowa|kansas|kentucky|louisiana|maine|maryland|massachusetts|michigan|minnesota|mississippi|missouri|montana|nebraska|nevada|new\s+hampshire|new\s+jersey|new\s+mexico|new\s+york|north\s+carolina|north\s+dakota|ohio|oklahoma|oregon|pennsylvania|rhode\s+island|south\s+carolina|south\s+dakota|tennessee|texas|utah|vermont|virginia|washington\s+state|west\s+virginia|wisconsin|wyoming)\b/i',

      // Standalone neighboring state mentions (most common confusion).
      '/\b(oregon|washington\s+state|montana|nevada|utah|wyoming)\b/i',
    ];

    foreach ($non_idaho_patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Health check endpoint for monitoring.
   *
   * Returns basic health status. Use for uptime monitoring.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with health status.
   */
  public function health() {
    $status = 'healthy';
    $checks = [];

    // Check if performance monitor is available and get summary.
    if ($this->performanceMonitor) {
      $summary = $this->performanceMonitor->getSummary();
      $status = $summary['status'];
      $checks['latency_p95_ms'] = $summary['p95'];
      $checks['error_rate_pct'] = $summary['error_rate'];
      $checks['throughput_per_min'] = $summary['throughput_per_min'];
    }

    // Basic service checks.
    $checks['faq_index'] = $this->faqIndex ? 'ok' : 'unavailable';
    $checks['intent_router'] = $this->intentRouter ? 'ok' : 'unavailable';

    return new JsonResponse([
      'status' => $status,
      'timestamp' => date('c'),
      'checks' => $checks,
    ], $status === 'healthy' ? 200 : 503);
  }

  /**
   * Detailed metrics endpoint for monitoring dashboards.
   *
   * Returns performance metrics. Requires admin permission.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with detailed metrics.
   */
  public function metrics() {
    if (!$this->performanceMonitor) {
      return new JsonResponse([
        'error' => 'Performance monitoring not enabled',
      ], 503);
    }

    $summary = $this->performanceMonitor->getSummary();

    return new JsonResponse([
      'timestamp' => date('c'),
      'metrics' => $summary,
      'thresholds' => [
        'p95_latency_ms' => PerformanceMonitor::THRESHOLD_P95_MS,
        'error_rate_pct' => PerformanceMonitor::THRESHOLD_ERROR_RATE * 100,
      ],
    ]);
  }

}
