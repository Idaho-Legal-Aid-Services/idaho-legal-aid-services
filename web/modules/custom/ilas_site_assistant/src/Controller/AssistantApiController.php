<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FallbackTreeEvaluator;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use Drupal\ilas_site_assistant\Service\AssistantReadEndpointGuard;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\PostGenerationLegalAdviceDetector;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\InputNormalizer;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\ilas_site_assistant\Service\TelemetrySchema;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\ilas_site_assistant\Service\TurnClassifier;
use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\SafetyResponseTemplates;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates;
use Drupal\ilas_site_assistant\Service\PerformanceMonitor;
use Drupal\ilas_site_assistant\Service\SafetyViolationTracker;
use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\AbTestingService;
use Drupal\ilas_site_assistant\Service\AssistantSessionBootstrapGuard;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drupal\ilas_site_assistant\Service\VoyageReranker;
use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Site Assistant API endpoints.
 */
class AssistantApiController extends ControllerBase {

  /**
   * Default security headers for all JSON responses.
   */
  const SECURITY_HEADERS = [
    'X-Content-Type-Options' => 'nosniff',
    'Cache-Control' => 'no-store',
  ];

  /**
   * Clarify-loop threshold before deterministic loop-break fallback.
   */
  const CLARIFY_LOOP_THRESHOLD = 3;

  /**
   * TTL for conversation cache entries (seconds).
   */
  const CONVERSATION_STATE_TTL = 1800;

  /**
   * Public API allowlist for FAQ search results.
   */
  private const FAQ_SEARCH_PUBLIC_FIELDS = ['id', 'question', 'answer', 'url', 'score', 'source'];

  /**
   * Public API allowlist for FAQ ID lookup results.
   */
  private const FAQ_ID_PUBLIC_FIELDS = ['id', 'question', 'answer', 'url'];

  /**
   * Safe fallback for post-generation safety replacement.
   */
  private const POST_GENERATION_SAFE_FALLBACK = 'I found some information that may help. For guidance specific to your situation, please contact our Legal Advice Line or apply for help.';

  /**
   * Authoritative request-context quick actions accepted by /message.
   */
  const REQUEST_CONTEXT_QUICK_ACTIONS = [
    'apply' => 'apply_for_help',
    'hotline' => 'legal_advice_line',
    'forms' => 'forms_finder',
    'guides' => 'guides_finder',
    'faq' => 'faq',
    'topics' => 'services_overview',
  ];

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
   * @var \Drupal\ilas_site_assistant\Service\ResponseGrounder|null
   */
  protected $responseGrounder;

  /**
   * The safety classifier service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyClassifier|null
   */
  protected $safetyClassifier;

  /**
   * The safety response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyResponseTemplates|null
   */
  protected $safetyResponseTemplates;

  /**
   * The out-of-scope classifier service.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeClassifier|null
   */
  protected $outOfScopeClassifier;

  /**
   * The out-of-scope response templates service.
   *
   * @var \Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates|null
   */
  protected $outOfScopeResponseTemplates;

  /**
   * The performance monitor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PerformanceMonitor|null
   */
  protected $performanceMonitor;

  /**
   * The conversation logger service.
   *
   * @var \Drupal\ilas_site_assistant\Service\ConversationLogger|null
   */
  protected $conversationLogger;

  /**
   * The A/B testing service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AbTestingService|null
   */
  protected $abTesting;

  /**
   * The authoritative pre-routing decision engine.
   *
   * @var \Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine
   */
  protected $preRoutingDecisionEngine;

  /**
   * The safety violation tracker.
   *
   * @var \Drupal\ilas_site_assistant\Service\SafetyViolationTracker|null
   */
  protected $violationTracker;

  /**
   * The Langfuse tracer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\LangfuseTracer|null
   */
  protected $langfuseTracer;

  /**
   * The source freshness/provenance governance service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SourceGovernanceService|null
   */
  protected $sourceGovernance;

  /**
   * The vector-index hygiene/refresh monitoring service.
   *
   * @var \Drupal\ilas_site_assistant\Service\VectorIndexHygieneService|null
   */
  protected $vectorIndexHygiene;

  /**
   * The retrieval/configuration governance service.
   *
   * @var \Drupal\ilas_site_assistant\Service\RetrievalConfigurationService|null
   */
  protected $retrievalConfiguration;

  /**
   * The Top Intents Pack service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack|null
   */
  protected $topIntentsPack;

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The request trust inspector for client-IP diagnostics.
   *
   * @var \Drupal\ilas_site_assistant\Service\RequestTrustInspector|null
   */
  protected $requestTrustInspector;

  /**
   * The CSRF token generator for recovery-only /track fallback validation.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator|null
   */
  protected $csrfTokenGenerator;

  /**
   * The shared environment detector.
   *
   * @var \Drupal\ilas_site_assistant\Service\EnvironmentDetector|null
   */
  protected ?EnvironmentDetector $environmentDetector;

  /**
   * The reusable assistant flow runner.
   *
   * @var \Drupal\ilas_site_assistant\Service\AssistantFlowRunner
   */
  protected AssistantFlowRunner $assistantFlowRunner;

  /**
   * The session bootstrap guard/observability service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AssistantSessionBootstrapGuard|null
   */
  protected $sessionBootstrapGuard;

  /**
   * The public read-endpoint abuse guard.
   *
   * @var \Drupal\ilas_site_assistant\Service\AssistantReadEndpointGuard|null
   */
  protected $readEndpointGuard;

  /**
   * The Voyage AI reranker service.
   *
   * @var \Drupal\ilas_site_assistant\Service\VoyageReranker|null
   */
  protected ?VoyageReranker $voyageReranker;

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
    FloodInterface $flood,
    CacheBackendInterface $conversation_cache,
    LoggerInterface $logger,
    AssistantFlowRunner $assistant_flow_runner,
    ResponseGrounder $response_grounder = NULL,
    SafetyClassifier $safety_classifier = NULL,
    SafetyResponseTemplates $safety_response_templates = NULL,
    OutOfScopeClassifier $out_of_scope_classifier = NULL,
    OutOfScopeResponseTemplates $out_of_scope_response_templates = NULL,
    PerformanceMonitor $performance_monitor = NULL,
    ConversationLogger $conversation_logger = NULL,
    AbTestingService $ab_testing = NULL,
    SafetyViolationTracker $violation_tracker = NULL,
    LangfuseTracer $langfuse_tracer = NULL,
    TopIntentsPack $top_intents_pack = NULL,
    SourceGovernanceService $source_governance = NULL,
    VectorIndexHygieneService $vector_index_hygiene = NULL,
    RetrievalConfigurationService $retrieval_configuration = NULL,
    RequestTrustInspector $request_trust_inspector = NULL,
    CsrfTokenGenerator $csrf_token_generator = NULL,
    EnvironmentDetector $environment_detector = NULL,
    AssistantSessionBootstrapGuard $session_bootstrap_guard = NULL,
    PreRoutingDecisionEngine $pre_routing_decision_engine = NULL,
    AssistantReadEndpointGuard $read_endpoint_guard = NULL,
    VoyageReranker $voyage_reranker = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->intentRouter = $intent_router;
    $this->faqIndex = $faq_index;
    $this->resourceFinder = $resource_finder;
    $this->policyFilter = $policy_filter;
    $this->analyticsLogger = $analytics_logger;
    $this->llmEnhancer = $llm_enhancer;
    $this->fallbackGate = $fallback_gate;
    $this->flood = $flood;
    $this->conversationCache = $conversation_cache;
    $this->logger = $logger;
    $this->assistantFlowRunner = $assistant_flow_runner;
    $this->responseGrounder = $response_grounder;
    $this->safetyClassifier = $safety_classifier;
    $this->safetyResponseTemplates = $safety_response_templates;
    $this->outOfScopeClassifier = $out_of_scope_classifier;
    $this->outOfScopeResponseTemplates = $out_of_scope_response_templates;
    $this->performanceMonitor = $performance_monitor;
    $this->conversationLogger = $conversation_logger;
    $this->abTesting = $ab_testing;
    $this->violationTracker = $violation_tracker;
    $this->langfuseTracer = $langfuse_tracer;
    $this->topIntentsPack = $top_intents_pack;
    $this->sourceGovernance = $source_governance;
    $this->vectorIndexHygiene = $vector_index_hygiene;
    $this->retrievalConfiguration = $retrieval_configuration;
    $this->requestTrustInspector = $request_trust_inspector;
    $this->csrfTokenGenerator = $csrf_token_generator;
    $this->environmentDetector = $environment_detector;
    $this->sessionBootstrapGuard = $session_bootstrap_guard;
    $this->readEndpointGuard = $read_endpoint_guard;
    $this->voyageReranker = $voyage_reranker;
    $this->preRoutingDecisionEngine = $pre_routing_decision_engine;
  }

  /**
   * {@inheritdoc}
   *
   * Service wiring classification (AFRP-05):
   * - MANDATORY (direct get): config.factory, intent_router, faq_index,
   *   resource_finder, policy_filter, analytics_logger, llm_enhancer,
   *   fallback_gate, flood, cache, logger, assistant_flow_runner,
   *   safety_classifier,
   *   safety_response_templates, out_of_scope_classifier,
   *   out_of_scope_response_templates, request_trust_inspector, csrf_token,
   *   environment_detector, session_bootstrap_guard,
   *   pre_routing_decision_engine, read_endpoint_guard.
   * - OPTIONAL (has/get/NULL): response_grounder, performance_monitor,
   *   conversation_logger, ab_testing, safety_violation_tracker,
   *   langfuse_tracer, top_intents_pack, source_governance,
   *   vector_index_hygiene, retrieval_configuration, voyage_reranker.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ilas_site_assistant.intent_router'),
      $container->get('ilas_site_assistant.faq_index'),
      $container->get('ilas_site_assistant.resource_finder'),
      $container->get('ilas_site_assistant.policy_filter'),
      $container->get('ilas_site_assistant.analytics_logger'),
      $container->get('ilas_site_assistant.llm_enhancer'),
      $container->get('ilas_site_assistant.fallback_gate'),
      $container->get('flood'),
      $container->get('cache.ilas_site_assistant'),
      $container->get('logger.channel.ilas_site_assistant'),
      $container->get('ilas_site_assistant.assistant_flow_runner'),
      $container->has('ilas_site_assistant.response_grounder') ? $container->get('ilas_site_assistant.response_grounder') : NULL,
      $container->get('ilas_site_assistant.safety_classifier'),
      $container->get('ilas_site_assistant.safety_response_templates'),
      $container->get('ilas_site_assistant.out_of_scope_classifier'),
      $container->get('ilas_site_assistant.out_of_scope_response_templates'),
      $container->has('ilas_site_assistant.performance_monitor') ? $container->get('ilas_site_assistant.performance_monitor') : NULL,
      $container->has('ilas_site_assistant.conversation_logger') ? $container->get('ilas_site_assistant.conversation_logger') : NULL,
      $container->has('ilas_site_assistant.ab_testing') ? $container->get('ilas_site_assistant.ab_testing') : NULL,
      $container->has('ilas_site_assistant.safety_violation_tracker') ? $container->get('ilas_site_assistant.safety_violation_tracker') : NULL,
      $container->has('ilas_site_assistant.langfuse_tracer') ? $container->get('ilas_site_assistant.langfuse_tracer') : NULL,
      $container->has('ilas_site_assistant.top_intents_pack') ? $container->get('ilas_site_assistant.top_intents_pack') : NULL,
      $container->has('ilas_site_assistant.source_governance') ? $container->get('ilas_site_assistant.source_governance') : NULL,
      $container->has('ilas_site_assistant.vector_index_hygiene') ? $container->get('ilas_site_assistant.vector_index_hygiene') : NULL,
      $container->has('ilas_site_assistant.retrieval_configuration') ? $container->get('ilas_site_assistant.retrieval_configuration') : NULL,
      $container->get('ilas_site_assistant.request_trust_inspector'),
      $container->get('csrf_token'),
      $container->get('ilas_site_assistant.environment_detector'),
      $container->get('ilas_site_assistant.session_bootstrap_guard'),
      $container->get('ilas_site_assistant.pre_routing_decision_engine'),
      $container->get('ilas_site_assistant.read_endpoint_guard'),
      $container->has('ilas_site_assistant.voyage_reranker') ? $container->get('ilas_site_assistant.voyage_reranker') : NULL,
    );
  }

  /**
   * Creates a JSON response with security headers.
   *
   * @param array $data
   *   The response data.
   * @param int $status
   *   The HTTP status code.
   * @param array $extra_headers
   *   Additional headers to include.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with security headers.
   */
  protected function jsonResponse(array $data, int $status = 200, array $extra_headers = [], string $request_id = ''): JsonResponse {
    $headers = array_merge(self::SECURITY_HEADERS, $extra_headers);
    if ($request_id !== '') {
      $headers['X-Correlation-ID'] = $request_id;
    }
    return new JsonResponse($data, $status, $headers);
  }

  /**
   * Primes a monitored request so response-time recording can finalize later.
   */
  private function primePerformanceMonitoring(Request $request, string $endpoint): void {
    if (!$request->attributes->has(PerformanceMonitor::ATTRIBUTE_START_TIME)) {
      $request->attributes->set(PerformanceMonitor::ATTRIBUTE_START_TIME, microtime(TRUE));
    }
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_ENDPOINT, $endpoint);
  }

  /**
   * Annotates the request with its final monitoring classification.
   */
  private function annotatePerformanceOutcome(
    Request $request,
    string $endpoint,
    bool $success,
    int $status_code,
    string $outcome,
    bool $denied = FALSE,
    bool $degraded = FALSE,
    string $scenario = 'unknown',
  ): void {
    $this->primePerformanceMonitoring($request, $endpoint);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_SUCCESS, $success);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_STATUS_CODE, $status_code);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_OUTCOME, $outcome);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_DENIED, $denied);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_DEGRADED, $degraded);
    $request->attributes->set(PerformanceMonitor::ATTRIBUTE_SCENARIO, $scenario);
  }

  /**
   * Builds an annotated JSON response for response-subscriber monitoring.
   */
  private function monitoredJsonResponse(
    Request $request,
    string $endpoint,
    array $data,
    int $status,
    array $extra_headers,
    string $request_id,
    bool $success,
    string $outcome,
    bool $denied = FALSE,
    bool $degraded = FALSE,
    string $scenario = 'unknown',
  ): JsonResponse {
    $this->annotatePerformanceOutcome(
      $request,
      $endpoint,
      $success,
      $status,
      $outcome,
      $denied,
      $degraded,
      $scenario,
    );

    return $this->jsonResponse($data, $status, $extra_headers, $request_id);
  }

  /**
   * Annotates a cacheable response for response-subscriber monitoring.
   */
  private function monitoredCacheableResponse(
    Request $request,
    string $endpoint,
    CacheableJsonResponse $response,
    bool $success,
    string $outcome,
    bool $denied = FALSE,
    bool $degraded = FALSE,
    string $scenario = 'unknown',
  ): CacheableJsonResponse {
    $this->annotatePerformanceOutcome(
      $request,
      $endpoint,
      $success,
      $response->getStatusCode(),
      $outcome,
      $denied,
      $degraded,
      $scenario,
    );

    return $response;
  }

  // ─── ACCEPTABLE DIRECT INSTANTIATION (AFRP-05) ─────────────────────
  // The following classes are instantiated via `new` in method bodies.
  // Each is justified and excluded from DI governance:
  //
  // - UuidGenerator (~line 588): Drupal core utility, no deps, stateless.
  // - OfficeLocationResolver (~lines 1351,1522,2932,3453): Stateless data
  //   resolver with hardcoded office data, no external deps.
  // - ResponseBuilder (~lines 1735,2879): Lightweight builder scoped to a
  //   single request, requires runtime data ($canonical_urls, topIntentsPack).
  // - CacheableMetadata, CacheableJsonResponse, JsonResponse: Framework
  //   value objects / response classes, always instantiated directly.
  // ─────────────────────────────────────────────────────────────────────

  /**
   * Resolves a correlation ID from the request or generates one.
   *
   * Accepts an inbound X-Correlation-ID header if it passes UUID4 validation.
   * Falls back to generating a new UUID.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return string
   *   A valid UUID4 correlation ID.
   */
  private function resolveCorrelationId(Request $request): string {
    $header = $request->headers->get('X-Correlation-ID', '');
    if ($header !== '' && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $header)) {
      return $header;
    }
    $uuid_generator = new UuidGenerator();
    return $uuid_generator->generate();
  }

  /**
   * Normalizes the public /message request context to the approved schema.
   *
   * Unknown keys are stripped deterministically. The only accepted key is
   * quickAction, and it must match the controller short-circuit allowlist.
   *
   * @param mixed $context
   *   Raw decoded context value from the request payload.
   *
   * @return array
   *   Normalized context safe to pass downstream.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the provided context is not a JSON object.
   */
  private function normalizeRequestContext($context): array {
    if ($context === NULL) {
      return [];
    }

    if (!is_array($context) || array_is_list($context)) {
      throw new \InvalidArgumentException('Context must be an object.');
    }

    $normalized = [];
    if (
      array_key_exists('quickAction', $context) &&
      is_string($context['quickAction']) &&
      isset(self::REQUEST_CONTEXT_QUICK_ACTIONS[$context['quickAction']])
    ) {
      $normalized['quickAction'] = $context['quickAction'];
    }

    return $normalized;
  }

  /**
   * Returns normalized request-trust diagnostics for the supplied request.
   */
  private function inspectRequestTrust(Request $request): array {
    if ($this->requestTrustInspector) {
      return $this->requestTrustInspector->inspectRequest($request);
    }

    $forwarded_for = trim((string) $request->headers->get('X-Forwarded-For', ''));
    $forwarded_headers = [
      'x_forwarded_for' => $forwarded_for !== '' ? $forwarded_for : NULL,
      'x_forwarded_host' => ($value = trim((string) $request->headers->get('X-Forwarded-Host', ''))) !== '' ? $value : NULL,
      'x_forwarded_port' => ($value = trim((string) $request->headers->get('X-Forwarded-Port', ''))) !== '' ? $value : NULL,
      'x_forwarded_proto' => ($value = trim((string) $request->headers->get('X-Forwarded-Proto', ''))) !== '' ? $value : NULL,
      'forwarded' => ($value = trim((string) $request->headers->get('Forwarded', ''))) !== '' ? $value : NULL,
    ];
    $forwarded_header_present = FALSE;
    foreach ($forwarded_headers as $value) {
      if ($value !== NULL && $value !== '') {
        $forwarded_header_present = TRUE;
        break;
      }
    }

    $effective_client_ip = (string) ($request->getClientIp() ?? $request->server->get('REMOTE_ADDR', ''));
    $remote_addr = (string) $request->server->get('REMOTE_ADDR', '');
    $forwarded_for_chain = $forwarded_for !== '' ? array_map('trim', explode(',', $forwarded_for)) : [];
    $effective_client_ip_chain = $effective_client_ip !== '' ? [$effective_client_ip] : [];
    $redundant_self_forwarded_chain = $this->isRedundantSelfForwardedChain(
      $remote_addr,
      $effective_client_ip,
      $effective_client_ip_chain,
      $forwarded_for_chain,
    );
    $status = RequestTrustInspector::STATUS_DIRECT_REMOTE_ADDR;
    if ($forwarded_header_present) {
      $status = $redundant_self_forwarded_chain
        ? RequestTrustInspector::STATUS_REDUNDANT_SELF_FORWARDED_CHAIN
        : RequestTrustInspector::STATUS_FORWARDED_HEADERS_UNTRUSTED;
    }

    return [
      'status' => $status,
      'effective_client_ip' => $effective_client_ip,
      'effective_client_ip_chain' => $effective_client_ip_chain,
      'forwarded_for_chain' => $forwarded_for_chain,
      'remote_addr' => $remote_addr,
      'reverse_proxy_enabled' => FALSE,
      'configured_trusted_proxies' => [],
      'configured_trusted_headers' => NULL,
      'runtime_trusted_proxies' => [],
      'runtime_trusted_header_set' => Request::getTrustedHeaderSet(),
      'forwarded_header_present' => $forwarded_header_present,
      'forwarded_headers' => $forwarded_headers,
      'remote_addr_is_configured_proxy' => FALSE,
      'remote_addr_is_runtime_trusted_proxy' => FALSE,
      'redundant_self_forwarded_chain' => $redundant_self_forwarded_chain,
      'invalid_configured_proxy_entries' => [],
    ];
  }

  /**
   * Resolves request identity for flood keys and logs trust-chain warnings.
   */
  private function resolveFloodTrustContext(Request $request, string $request_id, string $event): array {
    $trust_context = $this->inspectRequestTrust($request);
    if ($this->shouldLogFloodTrustWarning($trust_context)) {
      $this->logger->warning(
        'event={event} request_id={request_id} trust_status={trust_status} effective_client_ip={effective_client_ip} remote_addr={remote_addr} forwarded_for={forwarded_for} configured_trusted_proxies={configured_trusted_proxies} runtime_trusted_proxies={runtime_trusted_proxies}',
        [
          'event' => $event,
          'request_id' => $request_id,
          'trust_status' => (string) ($trust_context['status'] ?? RequestTrustInspector::STATUS_DIRECT_REMOTE_ADDR),
          'effective_client_ip' => (string) ($trust_context['effective_client_ip'] ?? ''),
          'remote_addr' => (string) ($trust_context['remote_addr'] ?? ''),
          'forwarded_for' => (string) (($trust_context['forwarded_headers']['x_forwarded_for'] ?? NULL) ?? ''),
          'configured_trusted_proxies' => json_encode($trust_context['configured_trusted_proxies'] ?? []),
          'runtime_trusted_proxies' => json_encode($trust_context['runtime_trusted_proxies'] ?? []),
        ],
      );
    }
    return $trust_context;
  }

  /**
   * Returns TRUE when forwarded IPs only repeat REMOTE_ADDR.
   */
  private function isRedundantSelfForwardedChain(string $remote_addr, string $effective_client_ip, array $effective_client_ips, array $forwarded_for_chain): bool {
    if ($remote_addr === '' || $forwarded_for_chain === [] || $effective_client_ip !== $remote_addr) {
      return FALSE;
    }

    foreach ($forwarded_for_chain as $forwarded_ip) {
      if (!is_string($forwarded_ip) || $forwarded_ip === '' || $forwarded_ip !== $remote_addr) {
        return FALSE;
      }
    }

    foreach ($effective_client_ips as $effective_ip) {
      if (!is_string($effective_ip) || $effective_ip === '' || $effective_ip !== $remote_addr) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns TRUE when the trust context represents a material flood-key risk.
   */
  private function shouldLogFloodTrustWarning(array $trust_context): bool {
    if (empty($trust_context['forwarded_header_present'])) {
      return FALSE;
    }

    if (!empty($trust_context['invalid_configured_proxy_entries'])) {
      return TRUE;
    }

    $status = (string) ($trust_context['status'] ?? RequestTrustInspector::STATUS_DIRECT_REMOTE_ADDR);
    if ($status === RequestTrustInspector::STATUS_TRUSTED_FORWARDED_CHAIN) {
      return FALSE;
    }
    if ($status === RequestTrustInspector::STATUS_TRUSTED_PROXY_MISMATCH) {
      return TRUE;
    }

    $remote_addr = (string) ($trust_context['remote_addr'] ?? '');
    $effective_client_ip = (string) ($trust_context['effective_client_ip'] ?? '');
    if ($effective_client_ip !== '' && $remote_addr !== '' && $effective_client_ip !== $remote_addr) {
      return TRUE;
    }

    foreach (($trust_context['forwarded_for_chain'] ?? []) as $forwarded_ip) {
      if (is_string($forwarded_ip) && $forwarded_ip !== '' && $forwarded_ip !== $remote_addr) {
        return TRUE;
      }
    }

    if (!empty($trust_context['remote_addr_is_configured_proxy']) || !empty($trust_context['remote_addr_is_runtime_trusted_proxy'])) {
      return TRUE;
    }

    if ($remote_addr === '') {
      return FALSE;
    }

    return filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
  }

  /**
   * Returns the current request when one exists, otherwise a synthetic request.
   */
  private function currentDiagnosticsRequest(): Request {
    $container = \Drupal::getContainer();
    if ($container && $container->has('request_stack')) {
      $current_request = $container->get('request_stack')->getCurrentRequest();
      if ($current_request instanceof Request) {
        return $current_request;
      }
    }

    return Request::create('https://localhost/assistant/api/health', 'GET');
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
    // Resolve correlation ID: accept inbound header or generate new UUID.
    $request_id = $this->resolveCorrelationId($request);
    $this->primePerformanceMonitoring($request, PerformanceMonitor::ENDPOINT_MESSAGE);

    // Rate limiting — keyed by client IP.
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $trust_context = $this->resolveFloodTrustContext($request, $request_id, 'assistant_message_flood_identity');
    $ip = (string) ($trust_context['effective_client_ip'] ?? '');
    $flood_id = 'ilas_assistant:' . $ip;
    $per_min = (int) ($config->get('rate_limit_per_minute') ?? 15);
    $per_hr = (int) ($config->get('rate_limit_per_hour') ?? 120);

    if (!$this->flood->isAllowed('ilas_assistant_min', $per_min, 60, $flood_id)) {
      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_MESSAGE, [
        'error' => 'Too many requests. Please wait a moment before trying again.',
        'type' => 'rate_limit',
        'request_id' => $request_id,
      ], 429, ['Retry-After' => '60'], $request_id, FALSE, 'message.rate_limit_minute', TRUE);
    }
    if (!$this->flood->isAllowed('ilas_assistant_hr', $per_hr, 3600, $flood_id)) {
      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_MESSAGE, [
        'error' => 'You have reached the hourly limit. Please try again later.',
        'type' => 'rate_limit',
        'request_id' => $request_id,
      ], 429, ['Retry-After' => '3600'], $request_id, FALSE, 'message.rate_limit_hour', TRUE);
    }
    $this->flood->register('ilas_assistant_min', 60, $flood_id);
    $this->flood->register('ilas_assistant_hr', 3600, $flood_id);

    // Validate content type.
    $content_type = (string) $request->headers->get('Content-Type', '');
    if (strpos($content_type, 'application/json') === FALSE) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        ['error' => 'Invalid content type', 'request_id' => $request_id],
        400,
        [],
        $request_id,
        FALSE,
        'message.invalid_content_type',
        TRUE,
      );
    }

    // Parse request body.
    $content = $request->getContent();
    if (strlen($content) > 2000) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        ['error' => 'Request too large', 'request_id' => $request_id],
        413,
        [],
        $request_id,
        FALSE,
        'message.request_too_large',
        TRUE,
      );
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['message'])) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        ['error' => 'Invalid request', 'request_id' => $request_id],
        400,
        [],
        $request_id,
        FALSE,
        'message.invalid_request',
        TRUE,
      );
    }

    try {
      $context = $this->normalizeRequestContext($data['context'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_MESSAGE, [
        'error' => 'Invalid request',
        'error_code' => 'invalid_context',
        'message' => 'Context must be a JSON object when provided.',
        'request_id' => $request_id,
      ], 400, [], $request_id, FALSE, 'message.invalid_context', TRUE);
    }

    // Guard against runaway processing: cap message pipeline to 25 seconds.
    // The client has a 15s AbortController timeout but the server would
    // otherwise continue until PHP max_execution_time kills it.
    $previous_time_limit = (int) ini_get('max_execution_time');
    set_time_limit(25);

    // Start performance tracking.
    $start_time = microtime(TRUE);

    try {

    $user_message = $this->sanitizeInput($data['message']);
    if ($user_message === '') {
      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_MESSAGE, [
        'error' => 'Invalid request',
        'error_code' => 'invalid_message',
        'message' => 'Message is empty after sanitization.',
        'request_id' => $request_id,
      ], 400, [], $request_id, FALSE, 'message.invalid_message', TRUE);
    }
    // Normalize for classifier checks: strips evasion techniques
    // (interstitial punctuation, Unicode tricks, spaced-out letters).
    // $user_message is kept intact for display, intent routing, and retrieval.
    $normalized_message = InputNormalizer::normalize($user_message);
    $langfuse_input = $this->buildLangfuseInputPayload($user_message);

    // Start Langfuse trace (if enabled and sampled) after sanitization.
    $this->langfuseTracer?->startTrace(
      $request_id,
      'assistant.message',
      array_merge(
        [
          'environment' => $config->get('langfuse.environment') ?? 'production',
        ],
        $langfuse_input['metadata'],
      ),
      $langfuse_input['display'],
    );

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
      'pre_routing_decision' => NULL,
      'policy_check' => ['passed' => TRUE, 'violation_type' => NULL],
      'llm_used' => FALSE,
      'processing_stages' => [],
    ] : NULL;

    // Parse ephemeral conversation ID (client-generated UUID).
    $conversation_id = NULL;
    $server_history = [];
    $clarify_meta = [
      'clarify_count' => 0,
      'prior_question_hash' => '',
      'updated_at' => 0,
    ];

    // Compute session fingerprint for conversation cache binding.
    // Defense-in-depth: prevents UUID-based cache poisoning if a
    // conversation ID leaks (logs, shared computers, Langfuse traces).
    $session_fingerprint = '';
    if ($request->hasSession() && $request->getSession()->isStarted()) {
      $session_fingerprint = hash('sha256', $request->getSession()->getId());
    }

    if (!empty($data['conversation_id']) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $data['conversation_id'])) {
      $conversation_id = $data['conversation_id'];
      $cache_key = 'ilas_conv:' . $conversation_id;
      $cached = $this->conversationCache->get($cache_key);
      if ($cached) {
        $cached_data = $cached->data;
        // Verify session ownership. If the cache entry has a stored
        // fingerprint and it differs from the current session, treat
        // the conversation as new (do not load stale/foreign history).
        $stored_fp = $cached_data['_session_fp'] ?? '';
        if ($stored_fp !== '' && $session_fingerprint !== '' && $stored_fp !== $session_fingerprint) {
          $this->logger->warning(
            '[@request_id] Conversation cache session mismatch for @conv_id — treating as new conversation.',
            ['@request_id' => $request_id, '@conv_id' => $conversation_id]
          );
        }
        else {
          $server_history = is_array($cached_data) ? array_filter($cached_data, 'is_int', ARRAY_FILTER_USE_KEY) : [];
        }
      }
      $clarify_meta = $this->loadClarifyMeta($conversation_id);

      // Abuse detection: repeated identical messages.
      if (count($server_history) >= 3) {
        $recent_messages = array_column(array_slice($server_history, -3), 'text');
        if (count(array_unique($recent_messages)) === 1 && $recent_messages[0] === PiiRedactor::redactForStorage($user_message, 200)) {
          $response_data = [
            'type' => 'escalation',
            'escalation_type' => 'repeated',
            'message' => (string) $this->t('It looks like you may be having trouble. Please call our Legal Advice Line for direct assistance.'),
            'actions' => $this->getEscalationActions(),
            'request_id' => $request_id,
          ];
          $langfuse_output = $this->buildLangfuseOutputPayload(
            (string) ($response_data['message'] ?? ''),
            (string) ($response_data['type'] ?? 'unknown'),
            'repeated_message_escalation',
          );
          $this->langfuseTracer?->endTrace(
            output: $langfuse_output['display'],
            metadata: array_merge(
              [
                'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
                'success' => TRUE,
                'response_type' => $response_data['type'] ?? 'unknown',
                'reason_code' => 'repeated_message_escalation',
              ],
              $langfuse_output['metadata'],
            ),
          );
          $response_data = $this->assembleContractFields($response_data, NULL, 'safety');
          return $this->monitoredJsonResponse(
            $request,
            PerformanceMonitor::ENDPOINT_MESSAGE,
            $response_data,
            200,
            [],
            $request_id,
            TRUE,
            'message.repeated_message_escalation',
          );
        }
      }
    }

    // Compute A/B testing variant assignments (deterministic per conversation).
    $ab_assignments = [];
    if ($conversation_id && $this->abTesting && $this->abTesting->isEnabled()) {
      $ab_assignments = $this->abTesting->getAssignments($conversation_id);
      if (!empty($ab_assignments)) {
        $this->analyticsLogger->log('ab_assignment', ObservabilityPayloadMinimizer::serializeAssignments($ab_assignments));
      }
    }

    // Extract keywords for debug (avoid storing raw user text).
    if ($debug_mode) {
      $debug_meta['extracted_keywords'] = $this->extractKeywords($user_message);
      if (!empty($ab_assignments)) {
        $debug_meta['ab_assignments'] = $ab_assignments;
      }
      $debug_meta['processing_stages'][] = 'input_sanitized';
    }

    // ─── PRE-ROUTING DECISION CONTRACT (v3.0) ────────────────────────
    // Safety, out-of-scope, policy fallback, and urgency override precedence
    // are evaluated once by PreRoutingDecisionEngine.
    // ─────────────────────────────────────────────────────────────────
    $this->langfuseTracer?->startSpan('pre_routing.evaluate');
    $pre_routing_decision = $this->preRoutingDecisionEngine->evaluate($normalized_message);
    $safety_classification = $pre_routing_decision['safety'];
    $oos_classification = $pre_routing_decision['oos'];
    $policy_result = $pre_routing_decision['policy'];
    $safety_flags_for_gate = $pre_routing_decision['urgency_signals'] ?? [];
    $routing_override_intent = $pre_routing_decision['routing_override_intent'];

    if ($debug_mode) {
      $debug_meta['safety_flags'] = $safety_flags_for_gate;
      $debug_meta['safety_classification'] = [
        'class' => $safety_classification['class'] ?? SafetyClassifier::CLASS_SAFE,
        'reason_code' => $safety_classification['reason_code'] ?? 'safe',
        'escalation_level' => $safety_classification['escalation_level'] ?? SafetyClassifier::ESCALATION_NONE,
        'is_safe' => $safety_classification['is_safe'] ?? TRUE,
      ];
      $debug_meta['oos_classification'] = [
        'is_out_of_scope' => $oos_classification['is_out_of_scope'] ?? FALSE,
        'category' => $oos_classification['category'] ?? OutOfScopeClassifier::CATEGORY_IN_SCOPE,
        'reason_code' => $oos_classification['reason_code'] ?? 'in_scope',
        'response_type' => $oos_classification['response_type'] ?? OutOfScopeClassifier::RESPONSE_IN_SCOPE,
      ];
      $debug_meta['policy_check'] = [
        'passed' => !($policy_result['violation'] ?? FALSE),
        'violation_type' => $policy_result['type'] ?? NULL,
      ];
      $debug_meta['pre_routing_decision'] = [
        'decision_type' => $pre_routing_decision['decision_type'],
        'winner_source' => $pre_routing_decision['winner_source'],
        'reason_code' => $pre_routing_decision['reason_code'],
        'routing_override_intent' => $routing_override_intent,
      ];
      $debug_meta['processing_stages'][] = 'safety_classified';
      $debug_meta['processing_stages'][] = 'oos_classified';
      $debug_meta['processing_stages'][] = 'policy_checked';
      $debug_meta['processing_stages'][] = 'pre_routing_evaluated';
    }

    if ($pre_routing_decision['decision_type'] === PreRoutingDecisionEngine::DECISION_SAFETY_EXIT) {
      $safety_response = $this->safetyResponseTemplates
        ? $this->safetyResponseTemplates->getResponse($safety_classification)
        : [
          'type' => ($safety_classification['requires_refusal'] ?? FALSE) ? 'refusal' : 'escalation',
          'escalation_type' => $safety_classification['class'] ?? 'safety',
          'escalation_level' => $safety_classification['escalation_level'] ?? 'standard',
          'message' => (string) $this->t('I cannot help with that here. If you are in immediate danger, call 911.'),
          'links' => [],
        ];

      $this->analyticsLogger->log('safety_violation', $safety_classification['reason_code']);
      if ($this->violationTracker) {
        $this->violationTracker->record(time());
      }

      if ($debug_mode) {
        $debug_meta['policy_check'] = [
          'passed' => FALSE,
          'violation_type' => $safety_classification['class'],
          'reason_code' => $safety_classification['reason_code'],
        ];
        $debug_meta['final_action'] = ($safety_classification['requires_refusal'] ?? FALSE) ? 'refusal' : 'escalation';
        $debug_meta['reason_code'] = $safety_classification['reason_code'];
        $debug_meta['intent_selected'] = 'safety_' . $safety_classification['class'];
      }

      $response_data = [
        'type' => $safety_response['type'],
        'escalation_type' => $safety_response['escalation_type'] ?? ($safety_classification['class'] ?? 'safety'),
        'escalation_level' => $safety_response['escalation_level'] ?? ($safety_classification['escalation_level'] ?? 'standard'),
        'message' => $safety_response['message'],
        'links' => $safety_response['links'] ?? [],
        'actions' => $this->getEscalationActions(),
        'reason_code' => $safety_classification['reason_code'],
        'request_id' => $request_id,
      ];

      if (!empty($safety_response['disclaimer'])) {
        $response_data['disclaimer'] = $safety_response['disclaimer'];
      }

      if ($debug_mode) {
        $response_data['_debug'] = $debug_meta;
      }

      $safety_telemetry = TelemetrySchema::normalize(
        intent: 'safety_exit',
        safety_class: $safety_classification['class'],
        fallback_path: 'none',
        request_id: $request_id,
      );
      $this->logger->notice('[@request_id] Safety exit: class=@class reason=@reason level=@level', TelemetrySchema::toLogContext(
        $safety_telemetry,
        [
          '@class' => $safety_classification['class'],
          '@reason' => $safety_classification['reason_code'],
          '@level' => $safety_classification['escalation_level'],
        ]
      ));

      $this->langfuseTracer?->endSpan([
        'decision_type' => $pre_routing_decision['decision_type'],
        'winner_source' => $pre_routing_decision['winner_source'],
        'reason_code' => $pre_routing_decision['reason_code'],
      ]);
      $langfuse_output = $this->buildLangfuseOutputPayload(
        (string) ($response_data['message'] ?? ''),
        (string) ($response_data['type'] ?? 'unknown'),
        $response_data['reason_code'] ?? NULL,
      );
      $this->langfuseTracer?->endTrace(
        output: $langfuse_output['display'],
        metadata: array_merge(
          [
            'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
            'success' => TRUE,
          ],
          $safety_telemetry,
          $langfuse_output['metadata'],
        )
      );

      $response_data = $this->assembleContractFields($response_data, NULL, 'safety');
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        $response_data,
        200,
        [],
        $request_id,
        TRUE,
        'message.safety_exit',
      );
    }

    if ($pre_routing_decision['decision_type'] === PreRoutingDecisionEngine::DECISION_OOS_EXIT) {
      $oos_response = $this->outOfScopeResponseTemplates
        ? $this->outOfScopeResponseTemplates->getResponse($oos_classification)
        : [
          'type' => 'out_of_scope',
          'response_mode' => 'redirect',
          'escalation_type' => $oos_classification['category'] ?? 'out_of_scope',
          'message' => (string) $this->t('That request is outside what Idaho Legal Aid Services can help with here.'),
          'links' => [],
          'suggestions' => [],
          'can_still_help' => FALSE,
        ];

      $this->analyticsLogger->log('out_of_scope', $oos_classification['reason_code']);

      if ($debug_mode) {
        $debug_meta['final_action'] = 'out_of_scope';
        $debug_meta['reason_code'] = $oos_classification['reason_code'];
        $debug_meta['intent_selected'] = 'oos_' . $oos_classification['category'];
      }

      $response_data = [
        'type' => $oos_response['type'],
        'response_mode' => $oos_response['response_mode'] ?? 'redirect',
        'escalation_type' => $oos_response['escalation_type'] ?? ($oos_classification['category'] ?? 'out_of_scope'),
        'message' => $oos_response['message'],
        'links' => $oos_response['links'] ?? [],
        'suggestions' => $oos_response['suggestions'] ?? [],
        'actions' => $this->getEscalationActions(),
        'reason_code' => $oos_classification['reason_code'],
        'can_still_help' => $oos_response['can_still_help'] ?? FALSE,
        'request_id' => $request_id,
      ];

      if (!empty($oos_response['disclaimer'])) {
        $response_data['disclaimer'] = $oos_response['disclaimer'];
      }

      if ($debug_mode) {
        $response_data['_debug'] = $debug_meta;
      }

      $oos_telemetry = TelemetrySchema::normalize(
        intent: 'oos_' . $oos_classification['category'],
        safety_class: $safety_classification ? $safety_classification['class'] : 'safe',
        fallback_path: 'none',
        request_id: $request_id,
      );
      $this->logger->notice('[@request_id] Out-of-scope exit: category=@category reason=@reason', TelemetrySchema::toLogContext(
        $oos_telemetry,
        [
          '@category' => $oos_classification['category'],
          '@reason' => $oos_classification['reason_code'],
        ]
      ));

      $this->langfuseTracer?->endSpan([
        'decision_type' => $pre_routing_decision['decision_type'],
        'winner_source' => $pre_routing_decision['winner_source'],
        'reason_code' => $pre_routing_decision['reason_code'],
      ]);
      $langfuse_output = $this->buildLangfuseOutputPayload(
        (string) ($response_data['message'] ?? ''),
        (string) ($response_data['type'] ?? 'unknown'),
        $response_data['reason_code'] ?? NULL,
      );
      $this->langfuseTracer?->endTrace(
        output: $langfuse_output['display'],
        metadata: array_merge(
          [
            'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
            'success' => TRUE,
          ],
          $oos_telemetry,
          $langfuse_output['metadata'],
        )
      );

      $response_data = $this->assembleContractFields($response_data, NULL, 'oos');
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        $response_data,
        200,
        [],
        $request_id,
        TRUE,
        'message.out_of_scope_exit',
      );
    }

    if ($pre_routing_decision['decision_type'] === PreRoutingDecisionEngine::DECISION_POLICY_EXIT) {
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
        'escalation_level' => $policy_result['escalation_level'] ?? 'standard',
        'message' => $policy_result['response'] ?? '',
        'links' => $policy_result['links'] ?? [],
        'actions' => $this->getEscalationActions(),
        'reason_code' => 'policy_' . $policy_result['type'],
        'request_id' => $request_id,
      ];

      if ($debug_mode) {
        $response_data['_debug'] = $debug_meta;
      }

      $policy_telemetry = TelemetrySchema::normalize(
        intent: 'policy_violation',
        safety_class: $safety_classification ? $safety_classification['class'] : 'safe',
        fallback_path: 'none',
        request_id: $request_id,
      );
      $this->logger->notice('[@request_id] Policy violation exit: type=@type', TelemetrySchema::toLogContext(
        $policy_telemetry,
        [
          '@type' => $policy_result['type'],
        ]
      ));

      $this->langfuseTracer?->endSpan([
        'decision_type' => $pre_routing_decision['decision_type'],
        'winner_source' => $pre_routing_decision['winner_source'],
        'reason_code' => $pre_routing_decision['reason_code'],
      ]);
      $langfuse_output = $this->buildLangfuseOutputPayload(
        (string) ($response_data['message'] ?? ''),
        (string) ($response_data['type'] ?? 'unknown'),
        $response_data['reason_code'] ?? NULL,
      );
      $this->langfuseTracer?->endTrace(
        output: $langfuse_output['display'],
        metadata: array_merge(
          [
            'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
            'success' => TRUE,
          ],
          $policy_telemetry,
          $langfuse_output['metadata'],
        )
      );

      $response_data = $this->assembleContractFields($response_data, NULL, 'policy');
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_MESSAGE,
        $response_data,
        200,
        [],
        $request_id,
        TRUE,
        'message.policy_exit',
      );
    }

    $this->langfuseTracer?->endSpan([
      'decision_type' => $pre_routing_decision['decision_type'],
      'winner_source' => $pre_routing_decision['winner_source'],
      'reason_code' => $pre_routing_decision['reason_code'],
      'override_risk_category' => $routing_override_intent['risk_category'] ?? NULL,
    ]);

    // Pending follow-up slot-fill: the runner owns state and flow-policy
    // decisions while the controller still owns response construction.
    if ($conversation_id) {
      $pending_flow_decision = $this->assistantFlowRunner->evaluatePending([
        'conversation_id' => $conversation_id,
        'user_message' => $user_message,
        'is_location_like' => $this->isLocationLikeOfficeReply($user_message),
        'is_explicit_office_followup' => $this->isExplicitOfficeFollowupTurn($user_message),
      ]);

      if (($pending_flow_decision['status'] ?? 'continue') === 'handled') {
        $this->applyOfficeFollowupStateDecision($conversation_id, $pending_flow_decision);

        if (($pending_flow_decision['action'] ?? 'none') === 'resolve' && !empty($pending_flow_decision['office'])) {
          return $this->handleOfficeFollowUp($request, $pending_flow_decision['office'], $user_message, $conversation_id, $server_history, $request_id, $debug_mode, $debug_meta, $start_time);
        }

        if (($pending_flow_decision['action'] ?? 'none') === 'clarify' && !empty($pending_flow_decision['offices'])) {
          return $this->handleOfficeFollowUpClarify($request, $pending_flow_decision['offices'], $user_message, $conversation_id, $server_history, $request_id, $debug_mode, $debug_meta, $start_time);
        }
      }

      $this->applyOfficeFollowupStateDecision($conversation_id, $pending_flow_decision);
    }

    // Quick-action short-circuit: when the request comes from a suggestion
    // button click, bypass all classifiers/routers and use the action directly.
    $this->langfuseTracer?->startSpan('intent.route');
    // Turn classification: determine NEW/FOLLOW_UP/INVENTORY/RESET before routing.
    $turn_type = TurnClassifier::classifyTurn($user_message, $server_history, time());
    $this->langfuseTracer?->addEvent('turn.classified', [
      'turn_type' => $turn_type,
      'history_length' => count($server_history),
    ]);
    if ($debug_mode) {
      $debug_meta['turn_type'] = $turn_type;
      $debug_meta['processing_stages'][] = 'turn_classified';
    }

    $quick_action = $context['quickAction'] ?? NULL;
    if ($quick_action && isset(self::REQUEST_CONTEXT_QUICK_ACTIONS[$quick_action])) {
      $intent = [
        'type' => self::REQUEST_CONTEXT_QUICK_ACTIONS[$quick_action],
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
    elseif ($turn_type === TurnClassifier::TURN_INVENTORY) {
      // Inventory routing: resolve to forms/guides/services inventory
      // based on message keywords, bypassing normal intent routing.
      $inventory_type = TurnClassifier::resolveInventoryType($user_message);
      $intent = [
        'type' => $inventory_type,
        'confidence' => 0.90,
        'source' => 'turn_classifier_inventory',
        'extraction' => [],
      ];

      if ($debug_mode) {
        $debug_meta['intent_selected'] = $inventory_type;
        $debug_meta['intent_source'] = 'turn_classifier_inventory';
        $debug_meta['intent_confidence'] = 0.90;
        $debug_meta['processing_stages'][] = 'inventory_shortcircuit';
      }
    }
    else {
      // Route the intent via normal classification pipeline.
      $intent = $this->intentRouter->route($user_message, $context);
    }

    // History-aware fallback: if direct routing returns unknown, check
    // conversation history for a dominant recent intent.
    $direct_intent_type = $intent['type'] ?? 'unknown';

    // Enhanced follow-up: when TurnClassifier detects FOLLOW_UP, proactively
    // resolve history context even before routing fails. This allows topic
    // context to be available for partial matches, not just unknown.
    if ($turn_type === TurnClassifier::TURN_FOLLOW_UP && !empty($server_history)) {
      $topic_ctx = HistoryIntentResolver::extractTopicContext($server_history);
      if ($topic_ctx && !empty($topic_ctx['area'])) {
        // If routing returned unknown, use full history fallback.
        if ($direct_intent_type === 'unknown') {
          $history_config = $this->configFactory->get('ilas_site_assistant.settings');
          $history_fallback_settings = $history_config->get('history_fallback') ?? [];
          if ($history_fallback_settings['enabled'] ?? TRUE) {
            $history_result = HistoryIntentResolver::resolveFromHistory(
              $server_history, $user_message, time(), $history_fallback_settings
            );
            if ($history_result) {
              $h_topic_ctx = $history_result['topic_context'] ?? NULL;
              $intent = [
                'type' => $history_result['intent'],
                'confidence' => min(0.65, $history_result['confidence']),
                'source' => 'history_fallback',
                'extraction' => $intent['extraction'] ?? [],
                'history_meta' => $history_result,
              ];
              if ($h_topic_ctx) {
                $intent['area'] = $h_topic_ctx['area'] ?? NULL;
                $intent['topic_id'] = $h_topic_ctx['topic_id'] ?? NULL;
                $intent['topic'] = $h_topic_ctx['topic'] ?? NULL;
              }
            }
          }
        }
        else {
          // Routing found a match, but enrich it with topic context from
          // history so downstream can use it (e.g., service_area follow-up).
          if (empty($intent['area'])) {
            $intent['area'] = $topic_ctx['area'];
            $intent['topic_id'] = $topic_ctx['topic_id'] ?? NULL;
            $intent['topic'] = $topic_ctx['topic'] ?? NULL;
          }
        }
      }
    }
    elseif ($direct_intent_type === 'unknown' && !empty($server_history)) {
      // Standard history fallback for non-follow-up unknown intents.
      $history_config = $this->configFactory->get('ilas_site_assistant.settings');
      $history_fallback_settings = $history_config->get('history_fallback') ?? [];
      if ($history_fallback_settings['enabled'] ?? TRUE) {
        $history_result = HistoryIntentResolver::resolveFromHistory(
          $server_history, $user_message, time(), $history_fallback_settings
        );
        if ($history_result) {
          $topic_ctx = $history_result['topic_context'] ?? NULL;
          $intent = [
            'type' => $history_result['intent'],
            'confidence' => min(0.65, $history_result['confidence']),
            'source' => 'history_fallback',
            'extraction' => $intent['extraction'] ?? [],
            'history_meta' => $history_result,
          ];
          if ($topic_ctx) {
            $intent['area'] = $topic_ctx['area'] ?? NULL;
            $intent['topic_id'] = $topic_ctx['topic_id'] ?? NULL;
            $intent['topic'] = $topic_ctx['topic'] ?? NULL;
          }
        }
      }
    }

    // Follow-up continuity guard: when the user asks a generic follow-up
    // question, preserve the prior service-area context instead of drifting
    // into generic resources/offices navigation.
    $followup_topic_context = HistoryIntentResolver::extractTopicContext($server_history);
    $history_area = $followup_topic_context['area'] ?? NULL;
    $generic_followup_intents = [
      'unknown',
      'resources',
      'faq',
      'meta_help',
      'meta_information',
      'services_overview',
      'forms_finder',
      'guides_finder',
    ];
    $is_acknowledgement_turn = (bool) preg_match('/\b(thanks|thank\s*you|gracias|ok(?:ay)?|got\s*it|sounds\s*good)\b/u', mb_strtolower($user_message));
    $is_follow_up_turn = ($turn_type === TurnClassifier::TURN_FOLLOW_UP);

    // Office continuity guard: if a follow-up asks for office details and
    // office context can be resolved from recent history, force office intent.
    if (!empty($server_history) && $this->isOfficeDetailRequest($user_message)) {
      $office_resolver = new OfficeLocationResolver();
      $office_from_context = $this->resolveOfficeFromMessageOrHistory($user_message, $server_history, $office_resolver);
      if ($office_from_context) {
        $intent = [
          'type' => 'offices',
          'confidence' => max(0.82, (float) ($intent['confidence'] ?? 0.0)),
          'source' => 'office_detail_followup_context',
          'extraction' => $intent['extraction'] ?? [],
        ];
      }
    }

    $intent_type = (string) ($intent['type'] ?? 'unknown');
    $intent_area = (string) ($intent['area'] ?? '');
    $is_contextual_legal_complaint = (
      !empty($server_history) &&
      $history_area &&
      $intent_type === 'feedback' &&
      !$this->isExplicitSiteFeedbackRequest($user_message) &&
      (bool) preg_match('/\b(complaint|grievance|queja|file)\b/u', mb_strtolower($user_message)) &&
      !HistoryIntentResolver::detectResetSignal($user_message)
    );

    if ($is_contextual_legal_complaint) {
      $intent = [
        'type' => 'service_area',
        'area' => $history_area,
        'topic_id' => $followup_topic_context['topic_id'] ?? NULL,
        'topic' => $followup_topic_context['topic'] ?? NULL,
        'confidence' => max(0.70, (float) ($intent['confidence'] ?? 0.0)),
        'source' => 'followup_service_area_complaint_continuity',
        'extraction' => $intent['extraction'] ?? [],
      ];
      $intent_type = 'service_area';
      $intent_area = $history_area;
    }

    $service_area_drift = (
      $is_follow_up_turn &&
      $history_area &&
      $intent_type === 'service_area' &&
      $intent_area !== '' &&
      $intent_area !== $history_area &&
      !HistoryIntentResolver::detectResetSignal($user_message) &&
      !$this->isExplicitServiceAreaShift($user_message, $history_area)
    );

    if (
      !empty($server_history) &&
      $history_area &&
      (
        (
          in_array($intent_type, $generic_followup_intents, TRUE) &&
          empty($intent['area']) &&
          !$is_acknowledgement_turn
        ) ||
        $service_area_drift
      ) &&
      !HistoryIntentResolver::detectResetSignal($user_message) &&
      !$this->isExplicitServiceAreaShift($user_message, $history_area)
    ) {
      $intent = [
        'type' => 'service_area',
        'area' => $history_area,
        'topic_id' => $followup_topic_context['topic_id'] ?? NULL,
        'topic' => $followup_topic_context['topic'] ?? NULL,
        'confidence' => max(0.70, (float) ($intent['confidence'] ?? 0.0)),
        'source' => 'followup_service_area_continuity',
        'extraction' => $intent['extraction'] ?? [],
      ];
    }

    if ($debug_mode) {
      $debug_meta['intent_selected'] = $intent['type'];
      $debug_meta['intent_confidence'] = $this->fallbackGate->calculateIntentConfidence($intent, $user_message);
      $debug_meta['route_source'] = $intent['source'] ?? 'direct';
      $debug_meta['processing_stages'][] = 'intent_routed';
      if (isset($intent['history_meta'])) {
        $debug_meta['direct_intent'] = $direct_intent_type;
        $debug_meta['history_fallback'] = [
          'fallback_intent' => $intent['history_meta']['intent'],
          'fallback_confidence' => $intent['history_meta']['confidence'],
          'turns_analyzed' => $intent['history_meta']['turns_analyzed'],
          'fallback_reason' => $intent['history_meta']['reason'],
        ];
      }
    }

    $topic_context = HistoryIntentResolver::extractTopicContext($server_history);
    $this->langfuseTracer?->endSpan([
      'type' => $intent['type'] ?? 'unknown',
      'confidence' => $intent['confidence'] ?? NULL,
      'source' => $intent['source'] ?? 'direct',
      'turn_type' => $turn_type,
      'topic_context' => $topic_context ? $topic_context['area'] : NULL,
    ]);

    // Early retrieval for gate evaluation (search FAQ/resources for context).
    $this->langfuseTracer?->startSpan('retrieval.early');
    $early_retrieval = [];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $skip_retrieval_intents = ['greeting', 'apply_for_help', 'apply'];
    if ($config->get('enable_faq') && !in_array($intent['type'], $skip_retrieval_intents)) {
      $early_retrieval = $this->faqIndex->search($user_message, 3);
    }
    $top_score = !empty($early_retrieval) ? ($early_retrieval[0]['score'] ?? NULL) : NULL;
    $this->langfuseTracer?->endSpan(['result_count' => count($early_retrieval), 'top_score' => $top_score]);

    // Evaluate fallback gate to decide: answer, clarify, or use LLM.
    $this->langfuseTracer?->startSpan('gate.evaluate');
    $gate_context = [
      'message' => $user_message,
      'policy_violation' => $pre_routing_decision['decision_type'] === PreRoutingDecisionEngine::DECISION_POLICY_EXIT,
    ];
    $gate_decision = $this->fallbackGate->evaluate(
      $intent,
      $early_retrieval,
      $routing_override_intent,
      $gate_context
    );
    $this->langfuseTracer?->endSpan([
      'decision' => $gate_decision['decision'] ?? 'unknown',
      'reason_code' => $gate_decision['reason_code'] ?? NULL,
      'confidence' => $gate_decision['confidence'] ?? NULL,
    ]);

    if ($debug_mode) {
      $debug_meta['gate_decision'] = $gate_decision['decision'];
      $debug_meta['gate_reason_code'] = $gate_decision['reason_code'];
      $debug_meta['gate_confidence'] = $gate_decision['confidence'];
      $debug_meta['processing_stages'][] = 'gate_evaluated';
    }

    // Handle gate decision.
    // Never override quick-action intents — they are deterministic button clicks.
    $is_quick_action = ($intent['source'] ?? '') === 'quick_action';
    if (!$is_quick_action && $gate_decision['decision'] === FallbackGate::DECISION_HARD_ROUTE) {
      // Enforce hard-route decisions so urgent/deadline signals cannot be
      // silently bypassed by normal navigation routing.
      $hard_route_source = (string) ($gate_decision['reason_code'] ?? '');
      if (($intent['type'] ?? '') !== 'high_risk' && ($intent['type'] ?? '') !== 'out_of_scope') {
        if ($hard_route_source === FallbackGate::REASON_SAFETY_URGENT && $routing_override_intent !== NULL) {
          $intent = array_merge($routing_override_intent, [
            'confidence' => max(
              (float) ($routing_override_intent['confidence'] ?? 1.0),
              (float) ($gate_decision['confidence'] ?? 1.0)
            ),
            'source' => 'pre_routing_gate_hard_route',
            'extraction' => $intent['extraction'] ?? [],
          ]);
        }
        else {
          $intent = [
            'type' => 'apply_for_help',
            'confidence' => max(0.90, (float) ($gate_decision['confidence'] ?? 0.90)),
            'source' => 'gate_hard_route',
            'extraction' => $intent['extraction'] ?? [],
          ];
        }
      }
      if ($debug_mode) {
        $debug_meta['intent_selected'] = $intent['type'];
        $debug_meta['intent_source'] = 'gate_hard_route';
        $debug_meta['gate_hard_route_reason'] = $hard_route_source;
        $debug_meta['processing_stages'][] = 'hard_route_forced';
      }
    }
    elseif (!$is_quick_action && $gate_decision['decision'] === FallbackGate::DECISION_FALLBACK_LLM && $this->llmEnhancer->isEnabled()) {
      // Try LLM classification for low-confidence cases.
      $llm_model = $config->get('llm.model') ?? 'gemini-1.5-flash';
      $this->langfuseTracer?->startGeneration('llm.classify', $llm_model, [
        'temperature' => $config->get('llm.temperature') ?? 0.3,
        'max_tokens' => $config->get('llm.max_tokens') ?? 150,
      ], $langfuse_input['metadata']);
      $llm_intent = $this->llmEnhancer->classifyIntent($user_message, $intent['type'], $ip);
      $this->langfuseTracer?->endGeneration(
        'intent=' . ($llm_intent !== '' ? $llm_intent : 'unknown'),
        $this->llmEnhancer->getLastUsage() ?? []
      );
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
    $this->langfuseTracer?->startSpan('intent.process', ['intent_type' => $intent['type'] ?? 'unknown']);
    $response = $this->processIntent($intent, $user_message, $context, $request_id, $server_history);
    $this->langfuseTracer?->endSpan([
      'response_type' => $response['type'] ?? 'unknown',
      'result_count' => count($response['results'] ?? []),
      'has_fallback_url' => !empty($response['fallback_url']),
      'fallback_level' => $response['fallback_level'] ?? NULL,
      'has_suggestions' => !empty($response['suggestions']) || !empty($response['topic_suggestions']),
    ]);

    // CRITICAL: Enforce canonical URL for hard-route intents.
    $canonical_urls = ilas_site_assistant_get_canonical_urls();
    $builder = new ResponseBuilder($canonical_urls, $this->topIntentsPack);
    $response = $builder->enforceHardRouteUrlWithOverrideIntent($response, $intent, $routing_override_intent);

    // Apply Voyage AI reranking (if enabled, after retrieval, before grounding).
    $rerank_meta = NULL;
    if ($this->voyageReranker && !empty($response['results'])
        && count($response['results']) >= 2
        && in_array($response['type'] ?? '', ResponseGrounder::CITATION_REQUIRED_TYPES, TRUE)) {
      $this->langfuseTracer?->startSpan('rerank.voyage');
      $rerank_result = $this->voyageReranker->rerank($user_message, $response['results']);
      $response['results'] = $rerank_result['items'];
      $rerank_meta = $rerank_result['meta'];
      $this->langfuseTracer?->endSpan($rerank_meta);
      if ($debug_mode) {
        $debug_meta['processing_stages'][] = ($rerank_meta['applied'] ?? FALSE)
          ? 'voyage_reranked' : 'voyage_skipped';
        $debug_meta['rerank_meta'] = $rerank_meta;
      }
    }

    // Apply response grounding (add citations, validate info).
    if ($this->responseGrounder && !empty($response['results'])) {
      $this->langfuseTracer?->startSpan('response.ground');
      $response = $this->responseGrounder->groundResponse($response, $response['results']);
      $this->langfuseTracer?->endSpan([
        'citations_added' => !empty($response['sources']),
      ]);
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

    if ($this->sourceGovernance && !empty($response['results']) && is_array($response['results'])) {
      try {
        $this->sourceGovernance->recordObservationBatch($response['results']);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Source governance observation failed: @class @error_signature', $this->buildExceptionContext($e));
      }
    }

    if ($debug_mode) {
      $rateLimiter = $this->llmEnhancer->getRateLimiter();
      if ($rateLimiter && $rateLimiter->wasRateLimited()) {
        $debug_meta['global_rate_limit_triggered'] = TRUE;
        $debug_meta['processing_stages'][] = 'global_rate_limit_triggered';
        $debug_meta['global_rate_limit_state'] = $rateLimiter->getCurrentState();
      }
    }

    // Final response sanitation: enforce grounded-message replacement,
    // apply freshness caveats, and remove legacy LLM-only payload fields.
    $this->langfuseTracer?->startSpan('safety.post_generation');
    $post_generation_safety = $this->enforcePostGenerationSafety($response, $request_id);
    $response = $post_generation_safety['response'];
    $this->langfuseTracer?->endSpan($post_generation_safety['meta']);

    if ($debug_mode) {
      $debug_meta['processing_stages'][] = 'post_generation_safety';
    }

    // Clarify-loop prevention: if the same clarify question repeats too many
    // times in a conversation, force a deterministic loop-break response.
    if ($conversation_id) {
      $response = $this->applyClarifyLoopGuard($response, $conversation_id, $request_id, $clarify_meta);
      if ($debug_mode) {
        $debug_meta['clarify_loop_meta'] = $this->loadClarifyMeta($conversation_id);
      }
    }

    // Log the interaction.
    $this->analyticsLogger->log($intent['type'], $intent['value'] ?? '');
    if (($intent['type'] ?? '') === 'disambiguation') {
      $this->analyticsLogger->logDisambiguation($intent, $user_message);
    }

    // Log history fallback usage for observability.
    if (($intent['source'] ?? '') === 'history_fallback') {
      $this->analyticsLogger->log('history_fallback_used', $intent['type']);
    }

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

    $normalized_intent = ResponseBuilder::normalizeIntentType($intent['type'] ?? 'unknown');
    if ($conversation_id) {
      $post_response_flow_decision = $this->assistantFlowRunner->evaluatePostResponse([
        'conversation_id' => $conversation_id,
        'intent_type' => $normalized_intent,
        'has_followup_prompt' => !empty($response['followup']),
      ]);
      $this->applyOfficeFollowupStateDecision($conversation_id, $post_response_flow_decision);
    }

    // Store conversation turn in cache for multi-turn continuity.
    if ($conversation_id) {
      $server_history[] = [
        'role' => 'user',
        'text' => PiiRedactor::redactForStorage($user_message, 200),
        'intent' => $intent['type'] ?? 'unknown',
        'route_source' => $intent['source'] ?? 'direct',
        'safety_flags' => $safety_flags_for_gate,
        'timestamp' => time(),
        'area' => $intent['area'] ?? $intent['service_area'] ?? NULL,
        'topic_id' => $intent['topic_id'] ?? NULL,
        'topic' => $intent['topic'] ?? NULL,
        'response_type' => $response['type'] ?? NULL,
      ];
      // Keep only last 10 entries (5 exchanges).
      $server_history = array_slice($server_history, -10);

      // Build cache data: history + session fingerprint + A/B assignments.
      $cache_data = $server_history;
      if ($session_fingerprint !== '') {
        $cache_data['_session_fp'] = $session_fingerprint;
      }
      if (!empty($ab_assignments)) {
        // Store assignments alongside history keyed separately so they persist.
        $this->conversationCache->set(
          'ilas_conv_ab:' . $conversation_id,
          $ab_assignments,
          time() + 1800
        );
      }

      // Store with 30-minute TTL.
      $this->conversationCache->set(
        'ilas_conv:' . $conversation_id,
        $cache_data,
        time() + 1800
      );

      // Multi-turn safety pattern detection.
      if (count($server_history) >= 3) {
        $recent_flags = [];
        foreach (array_slice($server_history, -3) as $turn) {
          $recent_flags = array_merge($recent_flags, $turn['safety_flags'] ?? []);
        }
        if (count($recent_flags) >= 3) {
          $this->logger->warning(
            '[@request_id] Multi-turn safety pattern detected for conversation @id: @flags',
            ['@request_id' => $request_id, '@id' => $conversation_id, '@flags' => implode(', ', $recent_flags)]
          );
        }
      }
    }

    // Opt-in conversation logging (for QA/debugging).
    if ($conversation_id && $this->conversationLogger) {
      $this->conversationLogger->logExchange(
        $conversation_id,
        $user_message,
        $response['message'] ?? '',
        $intent['type'] ?? 'unknown',
        $response['type'] ?? 'unknown',
        $request_id
      );
    }

    // Attach A/B variant assignments to response for frontend consumption.
    if (!empty($ab_assignments)) {
      $response['ab_variants'] = $ab_assignments;
    }

    $success_telemetry = TelemetrySchema::normalize(
      intent: $intent['type'] ?? 'unknown',
      safety_class: $safety_classification ? $safety_classification['class'] : 'safe',
      fallback_path: $gate_decision['decision'] ?? 'none',
      request_id: $request_id,
    );
    $this->logger->info('[@request_id] Request complete: intent=@intent safety=@safety gate=@gate reason=@reason type=@type', TelemetrySchema::toLogContext(
      $success_telemetry,
      [
        '@reason' => $response['reason_code'] ?? 'none',
        '@type' => $response['type'] ?? 'unknown',
      ]
    ));

    $duration_ms = (microtime(TRUE) - $start_time) * 1000;
    $response['request_id'] = $request_id;
    // Include turn_type in response metadata when not a default NEW turn.
    if ($turn_type !== TurnClassifier::TURN_NEW) {
      $response['turn_type'] = $turn_type;
    }
    $response = $this->assembleContractFields($response, $gate_decision, 'normal');

    // Attach governance summary to every normal response.
    if ($this->sourceGovernance) {
      $response['governance'] = $this->sourceGovernance->getGovernanceSummary();
    }

    // PHARD-03: Refuse when answerable + low confidence + no citations.
    $is_citation_required = in_array($response['type'] ?? '', ResponseGrounder::CITATION_REQUIRED_TYPES, TRUE);
    if ($is_citation_required && empty($response['citations']) && ($response['confidence'] ?? 0) <= 0.5) {
      $response['message'] = (string) $this->t("I wasn't able to find specific information on that topic. For help with your situation, please call our Legal Advice Line or apply for help.");
      $response['type'] = 'clarify_no_grounding';
      $response['confidence'] = 0.0;
      $response['decision_reason'] = 'answerable_type_no_citations_low_confidence';
      $this->analyticsLogger->log('grounding_refusal', $request_id ?? '');
    }

    $retrieval_trace = $this->collectRetrievalTraceMetadata($response);
    $this->langfuseTracer?->addEvent('request.complete', [
      'intent_type' => $intent['type'] ?? 'unknown',
      'response_type' => $response['type'] ?? 'unknown',
      'is_quick_action' => $is_quick_action,
    ] + $retrieval_trace);
    $langfuse_output = $this->buildLangfuseOutputPayload(
      (string) ($response['message'] ?? ''),
      (string) ($response['type'] ?? 'unknown'),
      $response['reason_code'] ?? NULL,
    );
    $this->langfuseTracer?->endTrace(
      output: $langfuse_output['display'],
      metadata: array_merge(
        [
          'duration_ms' => $duration_ms,
          'success' => TRUE,
          'intent_type' => $intent['type'] ?? 'unknown',
          'response_type' => $response['type'] ?? 'unknown',
          'is_quick_action' => $is_quick_action,
          'conversation_hash' => $conversation_id ? hash('sha256', $conversation_id) : NULL,
          'turn_type' => $turn_type,
          'fallback_level' => $response['fallback_level'] ?? NULL,
        ],
        $retrieval_trace,
        $success_telemetry,
        $langfuse_output['metadata'],
      )
    );

    return $this->monitoredJsonResponse(
      $request,
      PerformanceMonitor::ENDPOINT_MESSAGE,
      $response,
      200,
      [],
      $request_id,
      TRUE,
      'message.success',
      FALSE,
      FALSE,
      $this->classifyScenario($intent['type'] ?? 'unknown'),
    );

    }
    catch (\Throwable $e) {
      $error_telemetry = TelemetrySchema::normalize(
        intent: isset($intent) ? ($intent['type'] ?? 'unknown') : 'pre_intent',
        safety_class: isset($safety_classification) ? ($safety_classification['class'] ?? 'unknown') : 'pre_safety',
        fallback_path: 'error',
        request_id: $request_id,
      );
      $this->logger->error(
        '[@request_id] Unhandled exception in message pipeline: @class @error_signature',
        TelemetrySchema::toLogContext(
          $error_telemetry,
          $this->buildExceptionContext($e)
        )
      );

      // Capture to Sentry with assistant-specific tags.
      if (function_exists('\Sentry\captureException')) {
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($error_telemetry) {
          $scope->setTag('module', 'ilas_site_assistant');
          $scope->setTag('endpoint', 'message');
          $scope->setTag(TelemetrySchema::FIELD_REQUEST_ID, $error_telemetry[TelemetrySchema::FIELD_REQUEST_ID]);
          $scope->setTag(TelemetrySchema::FIELD_INTENT, $error_telemetry[TelemetrySchema::FIELD_INTENT]);
          $scope->setTag(TelemetrySchema::FIELD_SAFETY_CLASS, $error_telemetry[TelemetrySchema::FIELD_SAFETY_CLASS]);
          $scope->setTag(TelemetrySchema::FIELD_FALLBACK_PATH, $error_telemetry[TelemetrySchema::FIELD_FALLBACK_PATH]);
          $scope->setTag(TelemetrySchema::FIELD_ENV, $error_telemetry[TelemetrySchema::FIELD_ENV]);
        });
        \Sentry\captureException($e);
      }

      // End Langfuse trace on error.
      $retrieval_trace = $this->collectRetrievalTraceMetadata([]);
      $this->langfuseTracer?->addEvent('error', [
        'class' => get_class($e),
        'error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ] + $retrieval_trace, 'ERROR');
      $langfuse_output = $this->buildLangfuseOutputPayload(
        'Something went wrong. Please try again or contact us directly.',
        'internal_error',
        'internal_error',
      );
      $this->langfuseTracer?->endTrace(
        output: $langfuse_output['display'],
        metadata: array_merge(
          [
            'success' => FALSE,
            'error' => get_class($e),
            'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
          ],
          $retrieval_trace,
          $error_telemetry,
          $langfuse_output['metadata'],
        )
      );

      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_MESSAGE, [
        'error' => [
          'code' => 'internal_error',
          'message' => 'Something went wrong. Please try again or contact us directly.',
        ],
        'request_id' => $request_id,
      ], 500, [], $request_id, FALSE, 'message.internal_error', FALSE, FALSE, 'error');
    }
    finally {
      // Restore original time limit so subsequent Drupal processing is
      // not constrained by the message-pipeline guard.
      set_time_limit($previous_time_limit);
    }
  }

  /**
   * Lightweight analytics tracking endpoint.
   *
   * Accepts tracking events without running the full message pipeline.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming the event was recorded.
   */
  public function track(Request $request) {
    $request_id = $this->resolveCorrelationId($request);
    $this->primePerformanceMonitoring($request, PerformanceMonitor::ENDPOINT_TRACK);

    // Hybrid browser proof: same-origin Origin/Referer first, then recovery-
    // only bootstrap token fallback when browser headers are missing.
    $track_proof = $this->evaluateTrackWriteProof($request);
    if (!$track_proof['allowed']) {
      $this->logger->notice(
        'event={event} reason={reason} proof_mode={proof_mode} origin_present={origin_present} referer_present={referer_present} token_present={token_present} origin={origin} referer={referer} path={path}',
        [
          'event' => 'track_origin_deny',
          'reason' => $track_proof['code'],
          'proof_mode' => $track_proof['mode'],
          'origin_present' => $request->headers->has('Origin') ? 'yes' : 'no',
          'referer_present' => $request->headers->has('Referer') ? 'yes' : 'no',
          'token_present' => $request->headers->has('X-CSRF-Token') ? 'yes' : 'no',
          'origin' => (string) $request->headers->get('Origin', ''),
          'referer' => (string) $request->headers->get('Referer', ''),
          'path' => $request->getPathInfo(),
        ],
      );
      return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_TRACK, [
        'error' => 'Forbidden',
        'error_code' => $track_proof['code'],
        'message' => $track_proof['message'],
        'request_id' => $request_id,
      ], 403, [], $request_id, FALSE, 'track.' . $track_proof['code'], TRUE);
    }

    // Rate limit tracking events per resolved client IP.
    $trust_context = $this->resolveFloodTrustContext($request, $request_id, 'assistant_track_flood_identity');
    $ip = (string) ($trust_context['effective_client_ip'] ?? '');
    $track_flood_id = 'ilas_assistant_track:' . $ip;
    if (!$this->flood->isAllowed('ilas_assistant_track', 60, 60, $track_flood_id)) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['error' => 'Too many requests', 'request_id' => $request_id],
        429,
        ['Retry-After' => '60'],
        $request_id,
        FALSE,
        'track.rate_limit',
        TRUE,
      );
    }
    $this->flood->register('ilas_assistant_track', 60, $track_flood_id);

    $content_type = (string) $request->headers->get('Content-Type', '');
    if (strpos($content_type, 'application/json') === FALSE) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['error' => 'Invalid content type', 'request_id' => $request_id],
        400,
        [],
        $request_id,
        FALSE,
        'track.invalid_content_type',
        TRUE,
      );
    }

    $content = $request->getContent();
    if (strlen($content) > 1000) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['error' => 'Request too large', 'request_id' => $request_id],
        413,
        [],
        $request_id,
        FALSE,
        'track.request_too_large',
        TRUE,
      );
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['error' => 'Invalid request', 'request_id' => $request_id],
        400,
        [],
        $request_id,
        FALSE,
        'track.invalid_request',
        TRUE,
      );
    }

    $event_type = $this->sanitizeInput($data['event_type'] ?? '');
    $event_value = $this->sanitizeInput($data['event_value'] ?? '');

    if (empty($event_type)) {
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['error' => 'Missing event_type', 'request_id' => $request_id],
        400,
        [],
        $request_id,
        FALSE,
        'track.missing_event_type',
        TRUE,
      );
    }

    // Only allow known event types.
    $allowed_types = [
      'chat_open', 'suggestion_click', 'resource_click',
      'hotline_click', 'apply_click', 'apply_cta_click',
      'apply_secondary_click', 'service_area_click', 'topic_selected',
      'feedback_helpful', 'feedback_not_helpful',
      'ui_troubleshooting', 'ui_fallback_used',
    ];

    if (in_array($event_type, $allowed_types)) {
      $this->analyticsLogger->log($event_type, $event_value);
      return $this->monitoredJsonResponse(
        $request,
        PerformanceMonitor::ENDPOINT_TRACK,
        ['ok' => TRUE, 'request_id' => $request_id],
        200,
        [],
        $request_id,
        TRUE,
        'track.success',
      );
    }

    return $this->monitoredJsonResponse(
      $request,
      PerformanceMonitor::ENDPOINT_TRACK,
      ['ok' => TRUE, 'request_id' => $request_id],
      200,
      [],
      $request_id,
      TRUE,
      'track.ignored_event_type',
    );
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
   * SECURITY: Debug mode is ONLY enabled via server-side environment variable.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if debug mode is enabled.
   */
  protected function isDebugMode(Request $request): bool {
    if (Settings::get('ilas_site_assistant_debug_metadata_force_disable', FALSE)) {
      return FALSE;
    }

    if ($this->isLiveEnvironment()) {
      return FALSE;
    }

    return getenv('ILAS_CHATBOT_DEBUG') === '1';
  }

  /**
   * Returns TRUE when running in Pantheon live environment.
   */
  protected function isLiveEnvironment(): bool {
    return $this->environmentDetector?->isLiveEnvironment() ?? false;
  }

  /**
   * Extracts keywords from text for debug output.
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
   * Returns authoritative urgency signals for history storage.
   */
  protected function getUrgencySignalsForHistory(string $message, ?array $debug_meta = NULL): array {
    if (is_array($debug_meta) && isset($debug_meta['safety_flags']) && is_array($debug_meta['safety_flags'])) {
      return $debug_meta['safety_flags'];
    }

    $normalized_message = InputNormalizer::normalize($message);
    $decision = $this->preRoutingDecisionEngine->evaluate($normalized_message);
    return $decision['urgency_signals'] ?? [];
  }

  /**
   * Returns TRUE when the user explicitly signals topic switching.
   */
  protected function isExplicitServiceAreaShift(string $message, string $history_area): bool {
    $lower = mb_strtolower($message);
    if ($history_area === '') {
      return FALSE;
    }

    $shift_signal = (bool) preg_match('/\b(new|different|another|instead|switch(?:ing)?|other)\s+(issue|problem|topic|question)\b/u', $lower);
    if (!$shift_signal) {
      return FALSE;
    }

    $area_keywords = [
      'housing' => ['eviction', 'landlord', 'tenant', 'rent', 'lease', 'foreclosure'],
      'family' => ['divorce', 'custody', 'child support', 'visitation', 'protection order', 'domestic'],
      'consumer' => ['debt', 'collection', 'credit', 'scam', 'fraud', 'bankruptcy'],
      'seniors' => ['elder', 'senior', 'guardianship', 'probate', 'power of attorney'],
      'health' => ['medicaid', 'medicare', 'snap', 'ssi', 'ssdi', 'benefits'],
      'civil_rights' => ['discrimination', 'employment', 'fired', 'wages', 'harassment', 'civil rights'],
    ];

    foreach ($area_keywords as $area => $keywords) {
      if ($area === $history_area) {
        continue;
      }
      foreach ($keywords as $keyword) {
        if (str_contains($lower, $keyword)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Calculates a confidence score for the detected intent.
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

      if (isset($result['score'])) {
        $item['score'] = $result['score'];
      }
      if (isset($result['source'])) {
        $item['source'] = $result['source'];
      }
      if (isset($result['source_class'])) {
        $item['source_class'] = $result['source_class'];
      }
      if (!empty($result['freshness']) && is_array($result['freshness'])) {
        if (isset($result['freshness']['status'])) {
          $item['freshness_status'] = $result['freshness']['status'];
        }
        if (array_key_exists('age_days', $result['freshness'])) {
          $item['freshness_age_days'] = $result['freshness']['age_days'];
        }
      }
      if (isset($result['governance_flags']) && is_array($result['governance_flags'])) {
        $item['governance_flags'] = array_values($result['governance_flags']);
      }

      $meta[] = $item;
    }

    return $meta;
  }

  /**
   * Builds Langfuse-safe retrieval trace metadata for the completed response.
   */
  protected function collectRetrievalTraceMetadata(array $response): array {
    $results = !empty($response['results']) && is_array($response['results'])
      ? $response['results']
      : [];

    $source_classes = [];
    $lexical_result_count = 0;
    $vector_result_count = 0;

    foreach ($results as $result) {
      $source_class = isset($result['source_class']) && is_string($result['source_class'])
        ? $result['source_class']
        : NULL;
      if ($source_class !== NULL && $source_class !== '') {
        $source_classes[$source_class] = TRUE;
      }

      $is_vector = FALSE;
      if ($source_class !== NULL) {
        $is_vector = str_ends_with($source_class, '_vector');
      }
      elseif (($result['source'] ?? 'lexical') === 'vector') {
        $is_vector = TRUE;
      }

      if ($is_vector) {
        $vector_result_count++;
      }
      else {
        $lexical_result_count++;
      }
    }

    $operations = [];
    if ($this->faqIndex && method_exists($this->faqIndex, 'drainRetrievalTelemetry')) {
      $operations = array_merge($operations, $this->faqIndex->drainRetrievalTelemetry());
    }
    if ($this->resourceFinder && method_exists($this->resourceFinder, 'drainRetrievalTelemetry')) {
      $operations = array_merge($operations, $this->resourceFinder->drainRetrievalTelemetry());
    }

    $degraded_reason = NULL;
    $operation_source_classes = [];
    $operation_lexical_result_count = 0;
    $operation_vector_result_count = 0;
    $vector_attempted = FALSE;
    foreach ($operations as $operation) {
      if (!empty($operation['degraded_reason']) && is_string($operation['degraded_reason'])) {
        $degraded_reason = $operation['degraded_reason'];
      }
      $vector_attempted = $vector_attempted || !empty($operation['vector_attempted']);
      foreach (($operation['source_classes'] ?? []) as $source_class) {
        if (is_string($source_class) && $source_class !== '') {
          $operation_source_classes[$source_class] = TRUE;
        }
      }
      $operation_lexical_result_count += (int) ($operation['lexical_result_count'] ?? 0);
      $operation_vector_result_count += (int) ($operation['vector_result_count'] ?? 0);
    }

    $vector_enabled_effective = (bool) ($this->configFactory->get('ilas_site_assistant.settings')->get('vector_search.enabled') ?? FALSE);
    $effective_vector_result_count = !empty($operations) ? $operation_vector_result_count : $vector_result_count;
    $effective_lexical_result_count = !empty($operations) ? $operation_lexical_result_count : $lexical_result_count;
    $vector_status = 'disabled';
    if ($degraded_reason !== NULL) {
      $vector_status = 'degraded';
    }
    elseif ($effective_vector_result_count > 0) {
      $vector_status = 'used';
    }
    elseif ($vector_attempted) {
      $vector_status = 'attempted_without_results';
    }
    elseif ($vector_enabled_effective) {
      $vector_status = 'enabled_not_needed';
    }

    return [
      'vector_enabled_effective' => $vector_enabled_effective,
      'vector_attempted' => $vector_attempted,
      'vector_status' => $vector_status,
      'vector_result_count' => $effective_vector_result_count,
      'lexical_result_count' => $effective_lexical_result_count,
      'source_classes' => array_values(array_unique(array_merge(
        array_keys($source_classes),
        array_keys($operation_source_classes),
      ))),
      'degraded_reason' => $degraded_reason,
      'retrieval_operations' => array_slice($operations, 0, 6),
    ];
  }

  /**
   * Returns quick suggestions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with suggestions or a throttled error body.
   */
  public function suggest(Request $request) {
    $request_id = $this->resolveCorrelationId($request);
    $this->primePerformanceMonitoring($request, PerformanceMonitor::ENDPOINT_SUGGEST);
    $query = (string) $request->query->get('q', '');
    $type = (string) $request->query->get('type', 'all');
    $cache_meta = new CacheableMetadata();
    $cache_meta->setCacheContexts(['url.query_args:q', 'url.query_args:type']);
    $cache_meta->setCacheTags(['node_list']);

    try {
      $suggestions = [];

      if (strlen($query) >= 2) {
        if ($this->readEndpointGuard) {
          $decision = $this->readEndpointGuard->evaluate($request, 'suggest');
          if (!$decision['allowed']) {
            return $this->suggestRateLimitResponse($request, $request_id, (int) ($decision['retry_after'] ?? 60));
          }
        }

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

      $response = new CacheableJsonResponse([
        'suggestions' => array_slice($suggestions, 0, 6),
      ], 200, self::SECURITY_HEADERS);
      $cache_meta->setCacheMaxAge(300);
      $response->addCacheableDependency($cache_meta);
      return $this->monitoredCacheableResponse(
        $request,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        $response,
        TRUE,
        strlen($query) < 2 ? 'suggest.short_query_empty' : 'suggest.success',
      );
    }
    catch (\Throwable $e) {
      // Deterministic fallback: read endpoint should degrade to an empty set.
      $this->logger->error('[@request_id] suggest endpoint fallback due to @class: @error_signature', [
        '@request_id' => $request_id,
      ] + $this->buildExceptionContext($e));
      $response = new CacheableJsonResponse([
        'suggestions' => [],
      ], 200, self::SECURITY_HEADERS);
      $cache_meta->setCacheMaxAge(60);
      $response->addCacheableDependency($cache_meta);
      return $this->monitoredCacheableResponse(
        $request,
        PerformanceMonitor::ENDPOINT_SUGGEST,
        $response,
        FALSE,
        'suggest.degraded_empty',
        FALSE,
        TRUE,
      );
    }
  }

  /**
   * Returns FAQ data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with FAQ data or a throttled error body.
   */
  public function faq(Request $request) {
    $request_id = $this->resolveCorrelationId($request);
    $this->primePerformanceMonitoring($request, PerformanceMonitor::ENDPOINT_FAQ);
    $query = (string) $request->query->get('q', '');
    $id = $request->query->get('id');
    $faq_mode = $this->determineFaqReadMode($id, $query);

    $cache_meta = new CacheableMetadata();
    $cache_meta->setCacheContexts(['url.query_args:q', 'url.query_args:id']);
    $cache_meta->setCacheTags(['node_list', 'config:ilas_site_assistant.settings']);

    try {
      if ($this->readEndpointGuard) {
        $decision = $this->readEndpointGuard->evaluate($request, 'faq');
        if (!$decision['allowed']) {
          return $this->faqRateLimitResponse($request, $faq_mode, $request_id, (int) ($decision['retry_after'] ?? 60));
        }
      }

      if ($id) {
        $faq = $this->faqIndex->getById($id);
        if ($faq) {
          $response = new CacheableJsonResponse(
            ['faq' => $this->filterFaqForPublicApi($faq, self::FAQ_ID_PUBLIC_FIELDS)],
            200,
            self::SECURITY_HEADERS,
          );
          $cache_meta->setCacheMaxAge(300);
          $response->addCacheableDependency($cache_meta);
          return $this->monitoredCacheableResponse(
            $request,
            PerformanceMonitor::ENDPOINT_FAQ,
            $response,
            TRUE,
            'faq.id_success',
          );
        }
        $response = new CacheableJsonResponse(['error' => 'FAQ not found'], 404, self::SECURITY_HEADERS);
        $cache_meta->setCacheMaxAge(300);
        $response->addCacheableDependency($cache_meta);
        return $this->monitoredCacheableResponse(
          $request,
          PerformanceMonitor::ENDPOINT_FAQ,
          $response,
          FALSE,
          'faq.id_not_found',
        );
      }

      if (strlen($query) >= 2) {
        $query = $this->sanitizeInput($query);
        $results = $this->faqIndex->search($query, 5);
        $filtered = array_map(
          fn(array $item) => $this->filterFaqForPublicApi($item, self::FAQ_SEARCH_PUBLIC_FIELDS),
          $results,
        );
        $response = new CacheableJsonResponse([
          'results' => $filtered,
          'count' => count($results),
        ], 200, self::SECURITY_HEADERS);
        $cache_meta->setCacheMaxAge(300);
        $response->addCacheableDependency($cache_meta);
        return $this->monitoredCacheableResponse(
          $request,
          PerformanceMonitor::ENDPOINT_FAQ,
          $response,
          TRUE,
          'faq.search_success',
        );
      }

      $categories = $this->faqIndex->getCategories();
      $response = new CacheableJsonResponse(['categories' => $categories], 200, self::SECURITY_HEADERS);
      $cache_meta->setCacheMaxAge(300);
      $response->addCacheableDependency($cache_meta);
      return $this->monitoredCacheableResponse(
        $request,
        PerformanceMonitor::ENDPOINT_FAQ,
        $response,
        TRUE,
        'faq.categories_success',
      );
    }
    catch (\Throwable $e) {
      // Deterministic fallback for FAQ read path failures.
      $this->logger->error('[@request_id] faq endpoint fallback due to @class: @error_signature', [
        '@request_id' => $request_id,
      ] + $this->buildExceptionContext($e));

      if ($id) {
        $response = new CacheableJsonResponse(['error' => 'FAQ not found'], 404, self::SECURITY_HEADERS);
        $cache_meta->setCacheMaxAge(60);
        $response->addCacheableDependency($cache_meta);
        return $this->monitoredCacheableResponse(
          $request,
          PerformanceMonitor::ENDPOINT_FAQ,
          $response,
          FALSE,
          'faq.degraded_id_not_found',
          FALSE,
          TRUE,
        );
      }

      if (strlen($query) >= 2) {
        $response = new CacheableJsonResponse([
          'results' => [],
          'count' => 0,
        ], 200, self::SECURITY_HEADERS);
        $cache_meta->setCacheMaxAge(60);
        $response->addCacheableDependency($cache_meta);
        return $this->monitoredCacheableResponse(
          $request,
          PerformanceMonitor::ENDPOINT_FAQ,
          $response,
          FALSE,
          'faq.degraded_empty_results',
          FALSE,
          TRUE,
        );
      }

      $response = new CacheableJsonResponse(['categories' => []], 200, self::SECURITY_HEADERS);
      $cache_meta->setCacheMaxAge(60);
      $response->addCacheableDependency($cache_meta);
      return $this->monitoredCacheableResponse(
        $request,
        PerformanceMonitor::ENDPOINT_FAQ,
        $response,
        FALSE,
        'faq.degraded_categories',
        FALSE,
        TRUE,
      );
    }
  }

  /**
   * Strips internal fields from a FAQ result for public serialization.
   */
  private function filterFaqForPublicApi(array $item, array $allowed_fields): array {
    return array_intersect_key($item, array_flip($allowed_fields));
  }

  /**
   * Returns the read mode for the FAQ endpoint.
   */
  private function determineFaqReadMode(mixed $id, string $query): string {
    if ($id !== NULL && $id !== '') {
      return 'id';
    }

    if (strlen($query) >= 2) {
      return 'query';
    }

    return 'categories';
  }

  /**
   * Builds the suggest rate-limit response.
   */
  private function suggestRateLimitResponse(Request $request, string $request_id, int $retry_after): JsonResponse {
    return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_SUGGEST, [
      'suggestions' => [],
      'error' => 'Too many suggestion requests. Please wait a moment before trying again.',
      'type' => 'rate_limit',
      'request_id' => $request_id,
    ], 429, [
      'Retry-After' => (string) $retry_after,
    ], $request_id, FALSE, 'suggest.rate_limit', TRUE);
  }

  /**
   * Builds the FAQ rate-limit response for the active mode.
   */
  private function faqRateLimitResponse(Request $request, string $mode, string $request_id, int $retry_after): JsonResponse {
    $response = [
      'error' => 'Too many FAQ requests. Please wait a moment before trying again.',
      'type' => 'rate_limit',
      'request_id' => $request_id,
    ];

    switch ($mode) {
      case 'id':
        $response['faq'] = NULL;
        break;

      case 'query':
        $response['results'] = [];
        $response['count'] = 0;
        break;

      default:
        $response['categories'] = [];
        break;
    }

    return $this->monitoredJsonResponse($request, PerformanceMonitor::ENDPOINT_FAQ, $response, 429, [
      'Retry-After' => (string) $retry_after,
    ], $request_id, FALSE, 'faq.rate_limit', TRUE);
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
   * @param string $request_id
   *   Per-request correlation UUID for structured logging.
   *
   * @return array
   *   Response data.
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $canonical_urls = ilas_site_assistant_get_canonical_urls();

    // Use the shared ResponseBuilder for the canonical response skeleton.
    $builder = new ResponseBuilder($canonical_urls, $this->topIntentsPack);
    $contract = $builder->buildFromIntent($intent, $message);

    // Normalize intent type for enrichment logic.
    $intent_type = ResponseBuilder::normalizeIntentType($intent['type'] ?? 'unknown');

    // Build the API response from the contract, enriching with service data.
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
      case 'navigation':
        $page_key = (string) ($intent['page_key'] ?? '');
        $is_hotline_hours_request = (bool) preg_match('/\b(hours?\s*(can|do)\s*i\s*call|linea\s*de\s*ayuda|legal\s*advice\s*line|hotline)\b/u', mb_strtolower($message));

        if ($page_key === 'offices') {
          // Guard against office over-routing: hotline-hours requests should
          // resolve to hotline guidance, not office navigation.
          if ($is_hotline_hours_request) {
            $response['type'] = 'navigation';
            $response['response_mode'] = 'navigate';
            $response['message'] = $this->t('Our Legal Advice Line can help. Phone intakes are Monday-Wednesday, 10:00 a.m.-1:30 p.m. Mountain (9:00 a.m.-12:30 p.m. Pacific).');
            $response['primary_action'] = [
              'label' => $this->t('Contact Hotline'),
              'url' => $canonical_urls['hotline'],
            ];
            $response['secondary_actions'] = [
              [
                'label' => $this->t('Call (208) 746-7541'),
                'url' => 'tel:2087467541',
              ],
              [
                'label' => $this->t('Apply for Help'),
                'url' => $canonical_urls['apply'],
              ],
            ];
            $response['reason_code'] = 'hotline_hours_requested';
            break;
          }

          // Office detail requests should return office-specific data.
          $resolver = new OfficeLocationResolver();
          $office = $this->resolveOfficeFromMessageOrHistory($message, $server_history, $resolver);
          $office_in_message = $resolver->resolve($message) !== null;
          if ($office && ($office_in_message || $this->isOfficeDetailRequest($message))) {
            $response['type'] = 'office_location';
            $response['response_mode'] = 'navigate';
            $response['message'] = $this->buildOfficeDetailMessage($office);
            $response['office'] = [
              'name' => $office['name'],
              'address' => $office['address'],
              'phone' => $office['phone'],
              'hours' => $office['hours'] ?? $this->t('Hours may vary. Please call to confirm current office hours.'),
            ];
            $response['url'] = $office['url'];
            $response['primary_action'] = [
              'label' => $this->t('@name Office Details', ['@name' => $office['name']]),
              'url' => $office['url'],
            ];
            $response['secondary_actions'] = [
              [
                'label' => $this->t('Call @phone', ['@phone' => $office['phone']]),
                'url' => 'tel:' . preg_replace('/[^\d]/', '', $office['phone']),
              ],
              [
                'label' => $this->t('All Offices'),
                'url' => $canonical_urls['offices'],
              ],
            ];
            $response['reason_code'] = 'office_detail_requested';
          }

          if (($response['type'] ?? '') === 'navigation') {
            $response['message'] = $this->t('Find an office near you for legal help. If you are not sure where to start, you can also Apply for Help or call our Legal Advice Line.');
            $response['secondary_actions'] = [
              [
                'label' => $this->t('Apply for Help'),
                'url' => $canonical_urls['apply'],
              ],
              [
                'label' => $this->t('Call Legal Advice Line'),
                'url' => $canonical_urls['hotline'],
              ],
            ];
            $response['caveat'] = $this->t('Information is general, not legal advice.');
          }
        }
        break;

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

      case 'forms_inventory':
        $response['type'] = 'forms_inventory';
        $response['response_mode'] = 'clarify';
        $response['message'] = $this->t('We have forms and resources organized by legal topic. Choose a category:');
        $response['topic_suggestions'] = [
          [
            'label' => $this->t('Housing & Eviction'),
            'action' => 'forms_housing',
            'url' => $canonical_urls['service_areas']['housing'],
            'description' => $this->t('Eviction defense, tenant rights, housing assistance'),
          ],
          [
            'label' => $this->t('Family & Custody'),
            'action' => 'forms_family',
            'url' => $canonical_urls['service_areas']['family'],
            'description' => $this->t('Divorce, custody, child support, protection orders'),
          ],
          [
            'label' => $this->t('Consumer & Debt'),
            'action' => 'forms_consumer',
            'url' => $canonical_urls['service_areas']['consumer'],
            'description' => $this->t('Debt collection, bankruptcy, scams, identity theft'),
          ],
          [
            'label' => $this->t('Seniors & Guardianship'),
            'action' => 'forms_seniors',
            'url' => $canonical_urls['service_areas']['seniors'],
            'description' => $this->t('Guardianship, elder abuse, estate planning, nursing home'),
          ],
          [
            'label' => $this->t('Health & Benefits'),
            'action' => 'forms_benefits',
            'url' => $canonical_urls['service_areas']['health'],
            'description' => $this->t('Medicaid, SNAP, SSI/SSDI, disability benefits'),
          ],
          [
            'label' => $this->t('Safety & Protection Orders'),
            'action' => 'forms_safety',
            'url' => $canonical_urls['service_areas']['family'],
            'description' => $this->t('Protection orders, domestic violence resources'),
          ],
        ];
        $response['primary_action'] = [
          'label' => $this->t('Browse All Forms'),
          'url' => $canonical_urls['forms'],
        ];
        // Plain-text fallback if chip rendering fails on the frontend.
        $response['text_fallback'] = $this->t("Choose a category: Housing & Eviction, Family & Custody, Consumer & Debt, Seniors & Guardianship, Health & Benefits, or Safety & Protection Orders. You can also browse all forms at @url.", [
          '@url' => $canonical_urls['forms'],
        ]);
        $this->logger->info('[@request_id] Forms inventory request served', [
          '@request_id' => $request_id,
        ]);
        break;

      case 'guides_inventory':
        $response['type'] = 'guides_inventory';
        $response['response_mode'] = 'clarify';
        $response['message'] = $this->t('We have self-help guides organized by legal topic. Choose a category:');
        $response['topic_suggestions'] = [
          [
            'label' => $this->t('Housing & Eviction'),
            'action' => 'guides_housing',
            'url' => $canonical_urls['service_areas']['housing'],
            'description' => $this->t('Eviction defense, tenant rights, housing assistance'),
          ],
          [
            'label' => $this->t('Family & Custody'),
            'action' => 'guides_family',
            'url' => $canonical_urls['service_areas']['family'],
            'description' => $this->t('Divorce, custody, child support, protection orders'),
          ],
          [
            'label' => $this->t('Consumer & Debt'),
            'action' => 'guides_consumer',
            'url' => $canonical_urls['service_areas']['consumer'],
            'description' => $this->t('Debt collection, bankruptcy, scams, identity theft'),
          ],
          [
            'label' => $this->t('Seniors & Guardianship'),
            'action' => 'guides_seniors',
            'url' => $canonical_urls['service_areas']['seniors'],
            'description' => $this->t('Guardianship, elder abuse, estate planning, nursing home'),
          ],
          [
            'label' => $this->t('Health & Benefits'),
            'action' => 'guides_benefits',
            'url' => $canonical_urls['service_areas']['health'],
            'description' => $this->t('Medicaid, SNAP, SSI/SSDI, disability benefits'),
          ],
          [
            'label' => $this->t('Employment & Safety'),
            'action' => 'guides_employment',
            'url' => $canonical_urls['service_areas']['civil_rights'],
            'description' => $this->t('Employment rights, workplace safety, discrimination'),
          ],
        ];
        $response['primary_action'] = [
          'label' => $this->t('Browse All Guides'),
          'url' => $canonical_urls['guides'],
        ];
        // Plain-text fallback if chip rendering fails on the frontend.
        $response['text_fallback'] = $this->t("Choose a category: Housing & Eviction, Family & Custody, Consumer & Debt, Seniors & Guardianship, Health & Benefits, or Employment & Safety. You can also browse all guides at @url.", [
          '@url' => $canonical_urls['guides'],
        ]);
        $this->logger->info('[@request_id] Guides inventory request served', [
          '@request_id' => $request_id,
        ]);
        break;

      case 'services_inventory':
        $response['type'] = 'services_inventory';
        $response['response_mode'] = 'navigate';
        $response['message'] = $this->t('Idaho Legal Aid Services provides free civil legal help in these areas:');
        $response['topic_suggestions'] = [
          [
            'label' => $this->t('Housing'),
            'action' => 'topic_housing',
            'url' => $canonical_urls['service_areas']['housing'],
            'description' => $this->t('Eviction, landlord/tenant, foreclosure, mobile homes'),
          ],
          [
            'label' => $this->t('Family'),
            'action' => 'topic_family',
            'url' => $canonical_urls['service_areas']['family'],
            'description' => $this->t('Divorce, custody, child support, protection orders, adoption'),
          ],
          [
            'label' => $this->t('Consumer'),
            'action' => 'topic_consumer',
            'url' => $canonical_urls['service_areas']['consumer'],
            'description' => $this->t('Debt collection, bankruptcy, scams, identity theft, garnishment'),
          ],
          [
            'label' => $this->t('Seniors'),
            'action' => 'topic_seniors',
            'url' => $canonical_urls['service_areas']['seniors'],
            'description' => $this->t('Guardianship, elder abuse, estate planning, probate'),
          ],
          [
            'label' => $this->t('Health & Benefits'),
            'action' => 'topic_health',
            'url' => $canonical_urls['service_areas']['health'],
            'description' => $this->t('Medicaid, SNAP, SSI/SSDI, disability, insurance'),
          ],
          [
            'label' => $this->t('Civil Rights'),
            'action' => 'topic_civil_rights',
            'url' => $canonical_urls['service_areas']['civil_rights'],
            'description' => $this->t('Discrimination, employment rights, voting rights'),
          ],
        ];
        $response['primary_action'] = [
          'label' => $this->t('Apply for Help'),
          'url' => $canonical_urls['apply'],
        ];
        $response['text_fallback'] = $this->t('Choose a service area: Housing, Family, Consumer, Seniors, Health & Benefits, or Civil Rights. You can also apply for help at @url.', [
          '@url' => $canonical_urls['apply'],
        ]);
        $this->logger->info('[@request_id] Services inventory request served', [
          '@request_id' => $request_id,
        ]);
        break;

      case 'forms':
        if (!$config->get('enable_resources')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can find forms on our Forms page.');
          break;
        }
        $form_query = $intent['topic'] ?? $message;
        $form_topic_keywords = $this->extractFormTopicKeywords($form_query);
        if (empty($form_topic_keywords)) {
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
          $response['text_fallback'] = $this->t('Type a form topic like eviction, divorce, debt, guardianship, safety, or benefits. You can also browse all forms at @url.', [
            '@url' => $canonical_urls['forms'],
          ]);
          $response['form_finder_mode'] = TRUE;
          $this->logger->info(
            '[@request_id] Form Finder clarification triggered for query_hash=@query_hash length=@query_length_bucket profile=@redaction_profile',
            $this->buildFinderQueryLogContext($request_id, $message)
          );
          break;
        }
        $results = $this->resourceFinder->findForms($form_topic_keywords, 6);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['forms'];
        $response['fallback_label'] = $this->t('Browse all forms');
        if (count($results) > 0) {
          $response['message'] = $this->t('Here are some forms that might help:');
          $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
          $response['caveat'] = $response['disclaimer'];
        }
        else {
          // Zero results — try broader area search if area context available.
          $intent_area = $intent['area'] ?? '';
          if ($intent_area && $config->get('enable_resources')) {
            $broader_results = $this->resourceFinder->findResources($intent_area, 4);
            if (!empty($broader_results)) {
              $response['type'] = 'resources';
              $response['results'] = $broader_results;
              $response['message'] = $this->t('I couldn\'t find exact form matches, but here are related @area resources:', ['@area' => str_replace('_', ' ', $intent_area)]);
              $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
              $this->logger->info('[@request_id] Forms search broadened: keyword_count=@keyword_count area=@area results=@count', [
                '@request_id' => $request_id,
                '@keyword_count' => ObservabilityPayloadMinimizer::keywordCount($form_topic_keywords),
                '@area' => $intent_area,
                '@count' => count($broader_results),
              ]);
              break;
            }
          }
          // Still no results — show topic chips instead of dead-end.
          $response['type'] = 'form_finder_clarify';
          $response['response_mode'] = 'clarify';
          $response['message'] = $this->t('I couldn\'t find matching forms for that query. Try a different keyword, or pick a topic:');
          $response['topic_suggestions'] = [
            ['label' => $this->t('Housing'), 'action' => 'forms_housing'],
            ['label' => $this->t('Family'), 'action' => 'forms_family'],
            ['label' => $this->t('Consumer'), 'action' => 'forms_consumer'],
            ['label' => $this->t('Seniors'), 'action' => 'forms_seniors'],
          ];
          $response['primary_action'] = [
            'label' => $this->t('Browse All Forms'),
            'url' => $canonical_urls['forms'],
          ];
          $response['text_fallback'] = $this->t('Try a different form keyword, or choose a topic like Housing, Family, Consumer, or Seniors. You can also browse all forms at @url.', [
            '@url' => $canonical_urls['forms'],
          ]);
          $response['caveat'] = $this->t('This is general information, not legal advice. For legal advice, call our Legal Advice Line or apply for help.');
        }
        $this->logger->info(
          '[@request_id] Form Finder search: query_hash=@query_hash length=@query_length_bucket keyword_count=@keyword_count results=@count',
          $this->buildFinderQueryLogContext($request_id, $message, $form_topic_keywords, count($results))
        );
        break;

      case 'guides':
        if (!$config->get('enable_resources')) {
          $response['type'] = 'navigation';
          $response['message'] = $this->t('You can find guides on our Guides page.');
          break;
        }
        $guide_query = $intent['topic'] ?? $message;
        $guide_topic_keywords = $this->extractFinderTopicKeywords($guide_query, 'guides');
        if (empty($guide_topic_keywords)) {
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
          $response['text_fallback'] = $this->t('Type a guide topic like eviction, divorce, debt, employment, benefits, or safety. You can also browse all guides at @url.', [
            '@url' => $canonical_urls['guides'],
          ]);
          $response['guide_finder_mode'] = TRUE;
          $this->logger->info(
            '[@request_id] Guide Finder clarification triggered for query_hash=@query_hash length=@query_length_bucket profile=@redaction_profile',
            $this->buildFinderQueryLogContext($request_id, $message)
          );
          break;
        }
        $results = $this->resourceFinder->findGuides($guide_topic_keywords, 6);
        $response['results'] = $results;
        $response['fallback_url'] = $canonical_urls['guides'];
        $response['fallback_label'] = $this->t('Browse all guides');
        if (count($results) > 0) {
          $response['message'] = $this->t('Here are some guides that might help:');
          $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
          $response['caveat'] = $response['disclaimer'];
        }
        else {
          // Zero results — try broader area search if area context available.
          $intent_area = $intent['area'] ?? '';
          if ($intent_area && $config->get('enable_resources')) {
            $broader_results = $this->resourceFinder->findResources($intent_area, 4);
            if (!empty($broader_results)) {
              $response['type'] = 'resources';
              $response['results'] = $broader_results;
              $response['message'] = $this->t('I couldn\'t find exact guide matches, but here are related @area resources:', ['@area' => str_replace('_', ' ', $intent_area)]);
              $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
              $this->logger->info('[@request_id] Guides search broadened: keyword_count=@keyword_count area=@area results=@count', [
                '@request_id' => $request_id,
                '@keyword_count' => ObservabilityPayloadMinimizer::keywordCount($guide_topic_keywords),
                '@area' => $intent_area,
                '@count' => count($broader_results),
              ]);
              break;
            }
          }
          // Still no results — show topic chips instead of dead-end.
          $response['type'] = 'guide_finder_clarify';
          $response['response_mode'] = 'clarify';
          $response['message'] = $this->t('I couldn\'t find matching guides for that query. Try a different keyword, or pick a topic:');
          $response['topic_suggestions'] = [
            ['label' => $this->t('Housing'), 'action' => 'guides_housing'],
            ['label' => $this->t('Family'), 'action' => 'guides_family'],
            ['label' => $this->t('Consumer'), 'action' => 'guides_consumer'],
            ['label' => $this->t('Seniors'), 'action' => 'guides_seniors'],
          ];
          $response['primary_action'] = [
            'label' => $this->t('Browse All Guides'),
            'url' => $canonical_urls['guides'],
          ];
          $response['text_fallback'] = $this->t('Try a different guide keyword, or choose a topic like Housing, Family, Consumer, or Seniors. You can also browse all guides at @url.', [
            '@url' => $canonical_urls['guides'],
          ]);
          $response['caveat'] = $this->t('This is general information, not legal advice. For legal advice, call our Legal Advice Line or apply for help.');
        }
        $this->logger->info(
          '[@request_id] Guide Finder search: query_hash=@query_hash length=@query_length_bucket keyword_count=@keyword_count results=@count',
          $this->buildFinderQueryLogContext($request_id, $message, $guide_topic_keywords, count($results))
        );
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
        $response['message'] = $this->t('Idaho Legal Aid Services offers three Apply for Help options. If this is urgent, call our Legal Advice Line while you apply.');
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
        $response['message'] = $this->t('Our Legal Advice Line can help, and Spanish interpretation is available. Here\'s the information:');
        break;

      case 'donate':
        $response['cta'] = $this->t('Donate');
        $response['message'] = $this->t('Thank you for considering a donation! Here\'s how you can help:');
        break;

      case 'offices':
        $is_hotline_hours_request = (bool) preg_match('/\b(hours?\s*(can|do)\s*i\s*call|linea\s*de\s*ayuda|legal\s*advice\s*line|hotline|call\s*anytime|appointment)\b/u', mb_strtolower($message));
        if ($is_hotline_hours_request) {
          $response['type'] = 'navigation';
          $response['response_mode'] = 'navigate';
          $response['message'] = $this->t('Our Legal Advice Line can help. Phone intakes are Monday-Wednesday, 10:00 a.m.-1:30 p.m. Mountain (9:00 a.m.-12:30 p.m. Pacific).');
          $response['primary_action'] = [
            'label' => $this->t('Contact Hotline'),
            'url' => $canonical_urls['hotline'],
          ];
          $response['secondary_actions'] = [
            [
              'label' => $this->t('Call (208) 746-7541'),
              'url' => 'tel:2087467541',
            ],
            [
              'label' => $this->t('Apply for Help'),
              'url' => $canonical_urls['apply'],
            ],
          ];
          $response['reason_code'] = 'hotline_hours_requested';
          break;
        }

        $resolver = new OfficeLocationResolver();
        $office = $this->resolveOfficeFromMessageOrHistory($message, $server_history, $resolver);
        $office_in_message = $resolver->resolve($message) !== null;
        if ($office && ($office_in_message || $this->isOfficeDetailRequest($message))) {
          $response['type'] = 'office_location';
          $response['response_mode'] = 'navigate';
          $response['message'] = $this->buildOfficeDetailMessage($office);
          $response['office'] = [
            'name' => $office['name'],
            'address' => $office['address'],
            'phone' => $office['phone'],
            'hours' => $office['hours'] ?? $this->t('Hours may vary. Please call to confirm current office hours.'),
          ];
          $response['url'] = $office['url'];
          $response['primary_action'] = [
            'label' => $this->t('@name Office Details', ['@name' => $office['name']]),
            'url' => $office['url'],
          ];
          $response['secondary_actions'] = [
            [
              'label' => $this->t('Call @phone', ['@phone' => $office['phone']]),
              'url' => 'tel:' . preg_replace('/[^\d]/', '', $office['phone']),
            ],
            [
              'label' => $this->t('All Offices'),
              'url' => $canonical_urls['offices'],
            ],
          ];
          $response['reason_code'] = 'office_detail_requested';
          break;
        }

        $response['cta'] = $this->t('Find Offices');
        $response['message'] = $this->t('Find an office near you for legal help. You can also Apply for Help or call our Legal Advice Line.');
        $response['secondary_actions'] = [
          [
            'label' => $this->t('Apply for Help'),
            'url' => $canonical_urls['apply'],
          ],
          [
            'label' => $this->t('Call Legal Advice Line'),
            'url' => $canonical_urls['hotline'],
          ],
        ];
        $response['caveat'] = $this->t('Information is general, not legal advice.');
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
        $response['message'] = $this->t('Hi there! What can I help you find?');
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
        $area_label = ucfirst(str_replace('_', ' ', $area));

        // Follow-up detection: if user already visited this service area,
        // try to show deeper resources instead of repeating the same link.
        if ($config->get('enable_resources') && $this->isFollowUpInSameArea($intent, $server_history)) {
          $resource_results = $this->resourceFinder->findResources($area . ' ' . $message, 6);
          if (!empty($resource_results)) {
            $response['type'] = 'resources';
            $response['message'] = $this->t('Here are @area resources that may help:', ['@area' => $area_label]);
            $response['results'] = $resource_results;
            $response['disclaimer'] = $this->t('These are informational resources only. If you need legal advice, please apply for help or call our Legal Advice Line.');
            $response['fallback_url'] = $canonical_urls['service_areas'][$area] ?? $canonical_urls['services'];
            $response['fallback_label'] = $this->t('Browse @area resources', ['@area' => $area_label]);
            $this->logger->info('[@request_id] Follow-up detected in same area: @area, showing @count resources', [
              '@request_id' => $request_id,
              '@area' => $area,
              '@count' => count($resource_results),
            ]);
            break;
          }
          // No resource results — show actionable options instead of dead-end.
          $area_url = $canonical_urls['service_areas'][$area] ?? $canonical_urls['services'];
          $response['message'] = $this->t('I can help you find more @area resources. Here are some options:', ['@area' => $area_label]);
          $response['links'] = [
            ['label' => $this->t('Browse @area Page', ['@area' => $area_label]), 'url' => $area_url, 'type' => 'services'],
            ['label' => $this->t('Find @area Forms', ['@area' => $area_label]), 'url' => $canonical_urls['forms'], 'type' => 'forms'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
            ['label' => $this->t('Call Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
          ];
          $this->logger->info('[@request_id] Follow-up detected in same area: @area, no resources found, showing options', [
            '@request_id' => $request_id,
            '@area' => $area,
          ]);
          break;
        }

        // First mention of this service area — show the service area page.
        $response['message'] = $this->t('Here\'s our @area legal help page: @hint', [
          '@area' => $area_label,
          '@hint' => $this->getServiceAreaContextHint($area),
        ]);
        $response['cta'] = $this->t('View @area Resources', ['@area' => $area_label]);
        $response['secondary_actions'] = [
          [
            'label' => $this->t('Apply for Help'),
            'url' => $canonical_urls['apply'],
          ],
          [
            'label' => $this->t('Call Legal Advice Line'),
            'url' => $canonical_urls['hotline'],
          ],
        ];
        $response['caveat'] = $this->t('This is general information, not legal advice.');
        break;

      case 'disambiguation':
        $options = $intent['options'] ?? [];
        $question = $intent['question'] ?? $this->t('What are you looking for?');
        $option_links = [];
        $legacy_value_alias_used = FALSE;
        foreach ($options as $option) {
          // Canonical key is 'intent'; 'value' is deprecated (IMP-REL-03).
          if (!isset($option['intent']) && isset($option['value'])) {
            $this->logger->warning('[@request_id] Deprecated: disambiguation option uses "value" key instead of canonical "intent". Source: @source', [
              '@request_id' => $request_id ?: 'n/a',
              '@source' => $intent['reason'] ?? 'unknown',
            ]);
            $legacy_value_alias_used = TRUE;
          }
          $opt_intent = $option['intent'] ?? $option['value'] ?? '';
          if ($opt_intent === '') {
            continue;
          }
          $opt_url = $builder->resolveIntentUrl($opt_intent);
          $option_links[] = [
            'label' => $option['label'] ?? $opt_intent,
            'action' => $opt_intent,
            'url' => $opt_url,
          ];
        }
        if ($legacy_value_alias_used) {
          $this->logger->warning('[@request_id] Legacy disambiguation option alias `value` consumed; canonical key is `intent`.', [
            '@request_id' => $request_id ?: 'n/a',
          ]);
          $this->analyticsLogger->log('disambiguation_legacy_value_alias', 'value_to_intent');
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
        break;

      case 'ui_troubleshooting':
        // User is reporting that UI elements (chips, buttons, categories)
        // are not displaying. Provide text-based fallback links.
        $prev_type = '';
        if (!empty($server_history)) {
          $last = end($server_history);
          $prev_type = $last['intent_type'] ?? $last['type'] ?? '';
        }

        if ($prev_type === 'forms_inventory') {
          $response['message'] = $this->t("I'm sorry the categories aren't displaying correctly. Here are direct links to our form categories:");
          $response['links'] = [
            ['label' => $this->t('Housing & Eviction Forms'), 'url' => $canonical_urls['service_areas']['housing'], 'type' => 'forms'],
            ['label' => $this->t('Family & Custody Forms'), 'url' => $canonical_urls['service_areas']['family'], 'type' => 'forms'],
            ['label' => $this->t('Consumer & Debt Forms'), 'url' => $canonical_urls['service_areas']['consumer'], 'type' => 'forms'],
            ['label' => $this->t('Seniors & Guardianship Forms'), 'url' => $canonical_urls['service_areas']['seniors'], 'type' => 'forms'],
            ['label' => $this->t('Health & Benefits Forms'), 'url' => $canonical_urls['service_areas']['health'], 'type' => 'forms'],
            ['label' => $this->t('Browse All Forms'), 'url' => $canonical_urls['forms'], 'type' => 'forms'],
          ];
        }
        elseif ($prev_type === 'guides_inventory') {
          $response['message'] = $this->t("I'm sorry the categories aren't displaying correctly. Here are direct links to our guide categories:");
          $response['links'] = [
            ['label' => $this->t('Housing & Eviction Guides'), 'url' => $canonical_urls['service_areas']['housing'], 'type' => 'guides'],
            ['label' => $this->t('Family & Custody Guides'), 'url' => $canonical_urls['service_areas']['family'], 'type' => 'guides'],
            ['label' => $this->t('Consumer & Debt Guides'), 'url' => $canonical_urls['service_areas']['consumer'], 'type' => 'guides'],
            ['label' => $this->t('Browse All Guides'), 'url' => $canonical_urls['guides'], 'type' => 'guides'],
          ];
        }
        else {
          $response['message'] = $this->t("I'm sorry you're having trouble. Here are some ways I can help:");
          $response['links'] = [
            ['label' => $this->t('Browse Forms'), 'url' => $canonical_urls['forms'], 'type' => 'forms'],
            ['label' => $this->t('Browse Guides'), 'url' => $canonical_urls['guides'], 'type' => 'guides'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
            ['label' => $this->t('Call Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
          ];
        }
        $response['type'] = 'ui_troubleshooting';
        $response['followup'] = $this->t('Tip: Try refreshing the page if elements are not displaying correctly.');
        break;

      case 'clarify':
        // Check if the original intent has a clarifier defined in the pack.
        $original_for_clarify = $intent['original_intent'] ?? '';
        $clarifier = $original_for_clarify && $this->topIntentsPack
          ? $this->topIntentsPack->getClarifier($original_for_clarify)
          : NULL;
        if ($clarifier) {
          $response['message'] = $clarifier['question'];
          $response['topic_suggestions'] = array_map(function ($opt) use ($builder) {
            return [
              'label' => $opt['label'],
              'action' => $opt['intent'],
              'url' => $builder->resolveIntentUrl($opt['intent']),
            ];
          }, $clarifier['options'] ?? []);
        }
        else {
          $response['message'] = $this->t("I'd like to help! Could you describe your legal issue in a bit more detail? For example, try typing something like \"I'm being evicted\", \"custody questions\", or \"help with debt\". You can also use the buttons below to find forms, guides, or FAQs.");
        }
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

        // No FAQ or resource results — use the graduated fallback tree.
        $fallback = FallbackTreeEvaluator::evaluateLevel(
          $intent['type'] ?? 'unknown',
          [],
          $server_history,
          $this->topIntentsPack,
          $canonical_urls
        );
        $response['message'] = $this->t($fallback['message']);
        $response['primary_action'] = $fallback['primary_action'];
        $response['links'] = $fallback['links'];
        $response['fallback_level'] = $fallback['level'];
        $this->analyticsLogger->log('generic_answer', (string) $fallback['level']);
        if (!empty($fallback['suggestions'])) {
          $response['suggestions'] = $fallback['suggestions'];
        }
        $response['actions'] = $this->getEscalationActions();
        break;
    }

    // Chip enrichment: if the response doesn't already have topic_suggestions,
    // look up chips from the Top Intents Pack and add as suggestions.
    if (empty($response['topic_suggestions']) && $this->topIntentsPack) {
      $chip_key = $intent['type'] ?? 'unknown';
      $chips = $this->topIntentsPack->getChips($chip_key);
      // Fallback to original_intent when primary key yields no chips.
      if (empty($chips) && !empty($intent['original_intent'])) {
        $chips = $this->topIntentsPack->getChips($intent['original_intent']);
      }
      if (!empty($chips)) {
        $response['suggestions'] = array_map(function ($chip) {
          return ['label' => $chip['label'], 'action' => $chip['intent']];
        }, $chips);
      }
    }

    return $response;
  }

  /**
   * Applies final safety and contract sanitation before serialization.
   *
   * The public response contract is now message-only for assistant copy.
   * This method preserves deterministic grounded-message enforcement and
   * freshness caveats while stripping any legacy LLM summary artifacts from
   * the payload.
   *
   * @param array $response
   *   The response data.
   * @param string $request_id
   *   Per-request correlation UUID for structured logging.
   *
   * @return array
   *   Array with:
   *   - response: sanitized response payload safe for serialization.
   *   - meta: tracing metadata describing any enforcement action taken.
   */
  protected function enforcePostGenerationSafety(array $response, string $request_id): array {
    $meta = [
      'review_flag_triggered' => FALSE,
      'message_replaced' => FALSE,
      'weak_grounding_detected' => FALSE,
      'stale_citations_caveat_added' => FALSE,
      'llm_artifacts_stripped' => FALSE,
      'enforcement_actions' => [],
    ];
    $safe_fallback = $this->getPostGenerationSafeFallback();

    // Check 1: _requires_review flag from ResponseGrounder.
    // Enforcement: SOFT — replaces message with safe fallback.
    if (!empty($response['_requires_review'])) {
      $meta['review_flag_triggered'] = TRUE;
      $meta['enforcement_actions'][] = 'requires_review';
      $this->logger->warning(
        '[@request_id] Post-generation safety: _requires_review flag set, replacing final response content',
        ['@request_id' => $request_id]
      );
      $this->analyticsLogger->log('post_gen_safety_review_flag', $request_id);
      $response['message'] = $safe_fallback;
      $meta['message_replaced'] = TRUE;
    }

    // Check 2: Weak grounding — answerable type without citations.
    // Enforcement: SOFT — logged; confidence downgrade applied separately
    // in assembleContractFields().
    if (!empty($response['_grounding_weak'])) {
      $meta['weak_grounding_detected'] = TRUE;
      $meta['enforcement_actions'][] = 'grounding_weak';
      $this->logger->warning(
        '[@request_id] Post-generation safety: grounding weak, type=@type reason=@reason',
        ['@request_id' => $request_id, '@type' => $response['type'] ?? 'unknown', '@reason' => $response['_grounding_weak_reason'] ?? 'unknown']
      );
      $this->analyticsLogger->log('post_gen_safety_weak_grounding', $request_id);
    }

    // Check 3: Stale citations caveat.
    // Enforcement: SOFT — adds freshness_caveat to response body.
    if (!empty($response['_all_citations_stale'])) {
      $response['freshness_caveat'] = 'Some of the information cited may not reflect the most recent updates. Please verify details by contacting our office or checking our website directly.';
      $this->analyticsLogger->log('post_gen_stale_citations', $request_id);
      $meta['stale_citations_caveat_added'] = TRUE;
      $meta['enforcement_actions'][] = 'all_citations_stale';
    }

    $meta['llm_artifacts_stripped'] = array_key_exists('llm_summary', $response)
      || array_key_exists('llm_enhanced', $response);

    // Strip internal flags and legacy response-enhancement artifacts.
    unset($response['llm_summary'], $response['llm_enhanced']);
    unset($response['_requires_review']);
    unset($response['_validation_warnings']);
    unset($response['_grounding_version']);
    unset($response['_grounding_weak'], $response['_grounding_weak_reason']);
    unset($response['_all_citations_stale'], $response['_stale_citation_count']);

    return [
      'response' => $response,
      'meta' => $meta,
    ];
  }

  /**
   * Returns the safe replacement text for unsafe post-generation output.
   */
  protected function getPostGenerationSafeFallback(): string {
    return (string) $this->t(self::POST_GENERATION_SAFE_FALLBACK);
  }

  /**
   * Returns quick suggestion buttons.
   */
  protected function getQuickSuggestions() {
    return [
      ['label' => $this->t('Find a form'), 'action' => 'forms'],
      ['label' => $this->t('Find a guide'), 'action' => 'guides'],
      ['label' => $this->t('Search FAQs'), 'action' => 'faq'],
      ['label' => $this->t('Apply for help'), 'action' => 'apply'],
    ];
  }

  /**
   * Loads clarify-loop metadata for a conversation.
   */
  protected function loadClarifyMeta(string $conversation_id): array {
    $cached = $this->conversationCache->get('ilas_conv_meta:' . $conversation_id);
    $data = (is_object($cached) && is_array($cached->data ?? NULL))
      ? $cached->data
      : [];

    return [
      'clarify_count' => max(0, (int) ($data['clarify_count'] ?? 0)),
      'prior_question_hash' => (string) ($data['prior_question_hash'] ?? ''),
      'updated_at' => (int) ($data['updated_at'] ?? 0),
    ];
  }

  /**
   * Persists clarify-loop metadata for a conversation.
   */
  protected function saveClarifyMeta(string $conversation_id, array $meta): void {
    $this->conversationCache->set(
      'ilas_conv_meta:' . $conversation_id,
      [
        'clarify_count' => max(0, (int) ($meta['clarify_count'] ?? 0)),
        'prior_question_hash' => (string) ($meta['prior_question_hash'] ?? ''),
        'updated_at' => time(),
      ],
      time() + self::CONVERSATION_STATE_TTL
    );
  }

  /**
   * Applies loop prevention for repeated clarify responses.
   */
  protected function applyClarifyLoopGuard(array $response, string $conversation_id, string $request_id, array $meta): array {
    if (!$this->isClarifyLikeResponse($response)) {
      $this->saveClarifyMeta($conversation_id, [
        'clarify_count' => 0,
        'prior_question_hash' => '',
      ]);
      return $response;
    }

    $message = trim((string) ($response['message'] ?? ''));
    if ($message === '') {
      $this->saveClarifyMeta($conversation_id, [
        'clarify_count' => 0,
        'prior_question_hash' => '',
      ]);
      return $response;
    }

    $normalized = mb_strtolower((string) preg_replace('/\s+/', ' ', $message));
    $question_hash = hash('sha256', $normalized);
    $prior_hash = (string) ($meta['prior_question_hash'] ?? '');
    $clarify_count = ($prior_hash === $question_hash)
      ? ((int) ($meta['clarify_count'] ?? 0) + 1)
      : 1;

    if ($clarify_count >= self::CLARIFY_LOOP_THRESHOLD) {
      $response = [
        'type' => 'clarify_loop_break',
        'response_mode' => 'clarify',
        'message' => (string) $this->t('I may be repeating myself. Choose one of these options, or contact our Legal Advice Line for direct help.'),
        'topic_suggestions' => $this->getClarifyLoopBreakSuggestions(),
        'actions' => $this->getEscalationActions(),
        'reason_code' => 'clarify_loop_break',
        'request_id' => $request_id,
      ];
      $clarify_count = 0;
      $question_hash = '';
      $this->logger->notice('[@request_id] Clarify loop-break activated for conversation @conversation_id', [
        '@request_id' => $request_id,
        '@conversation_id' => $conversation_id,
      ]);
      $this->analyticsLogger->log('clarify_loop_break', $conversation_id);
    }

    $this->saveClarifyMeta($conversation_id, [
      'clarify_count' => $clarify_count,
      'prior_question_hash' => $question_hash,
    ]);

    return $response;
  }

  /**
   * Determines whether the response is clarify-like.
   */
  protected function isClarifyLikeResponse(array $response): bool {
    $response_mode = (string) ($response['response_mode'] ?? '');
    $response_type = (string) ($response['type'] ?? '');

    if ($response_mode === 'clarify') {
      return TRUE;
    }

    return in_array($response_type, [
      'clarify',
      'disambiguation',
      'form_finder_clarify',
      'guide_finder_clarify',
      'office_location_clarify',
    ], TRUE);
  }

  /**
   * Returns deterministic loop-break suggestion chips.
   */
  protected function getClarifyLoopBreakSuggestions(): array {
    $canonical_urls = ilas_site_assistant_get_canonical_urls();

    return [
      [
        'label' => $this->t('Find forms'),
        'action' => 'forms',
        'url' => $canonical_urls['forms'],
      ],
      [
        'label' => $this->t('Find guides'),
        'action' => 'guides',
        'url' => $canonical_urls['guides'],
      ],
      [
        'label' => $this->t('Apply for help'),
        'action' => 'apply',
        'url' => $canonical_urls['apply'],
      ],
      [
        'label' => $this->t('Call Legal Advice Line'),
        'action' => 'hotline',
        'url' => $canonical_urls['hotline'],
      ],
    ];
  }

  /**
   * Returns escalation action buttons.
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
   */
  protected function extractFinderTopicKeywords(string $query, string $finder_type = 'forms'): string {
    $lower = strtolower(trim($query));
    $type_noise = [
      'forms' => '/\b(form|forms|froms|formulario|formularios|paperwork|papers|documents?|court\s*papers?)\b/i',
      'guides' => '/\b(guide|guides|giude|giudes|guia|guias|manual|manuals|handbook|handbooks|instructions?|how[\s-]*to|step[\s-]*by[\s-]*step|self[\s-]*help)\b/i',
    ];
    $noise_patterns = [
      '/^(find|get|need|download|where|show|read|browse)\s*(me\s*)?(\b(a|the|is|are|some|any|all)\b\s*)?/i',
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
    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'me', 'my', 'can', 'do', 'how', 'what', 'where',
      'please', 'find', 'get', 'need', 'show', 'download', 'looking', 'browse', 'read',
      'you', 'your', 'have', 'has', 'had', 'does', 'did', 'they', 'them',
      'their', 'we', 'our', 'it', 'its', 'that', 'this', 'those', 'these',
      'there', 'which', 'been', 'be', 'about', 'any', 'all', 'some', 'also',
      'just', 'give', 'us', 'would', 'will', 'should', 'could',
    ];
    $words = array_filter(explode(' ', $cleaned), function ($w) use ($stop_words) {
      return strlen($w) >= 2 && !in_array($w, $stop_words);
    });
    if (empty($words)) {
      return '';
    }
    return implode(' ', $words);
  }

  /**
   * Checks if the current intent is a follow-up in the same service area.
   *
   * @param array $intent
   *   The current intent.
   * @param array $server_history
   *   The conversation history.
   *
   * @return bool
   *   TRUE if any of the last 3 history entries share the same area.
   */
  protected function isFollowUpInSameArea(array $intent, array $server_history): bool {
    $current_area = $intent['area'] ?? '';
    if (empty($current_area) || empty($server_history)) {
      return FALSE;
    }

    // Check the last 3 history entries for the same area.
    $recent = array_slice($server_history, -3);
    foreach ($recent as $entry) {
      if (($entry['area'] ?? '') === $current_area) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads pending office follow-up state for a conversation.
   */
  protected function loadOfficeFollowupState(string $conversation_id): ?array {
    return $this->assistantFlowRunner->loadOfficeFollowupState($conversation_id);
  }

  /**
   * Persists office follow-up state with bounded lifecycle metadata.
   */
  protected function saveOfficeFollowupState(string $conversation_id, array $state): void {
    $this->assistantFlowRunner->saveOfficeFollowupState($conversation_id, $state);
  }

  /**
   * Clears pending office follow-up state for a conversation.
   */
  protected function clearOfficeFollowupState(string $conversation_id): void {
    $this->assistantFlowRunner->clearOfficeFollowupState($conversation_id);
  }

  /**
   * Applies the runner's office follow-up state operation.
   */
  protected function applyOfficeFollowupStateDecision(string $conversation_id, array $decision): void {
    if ($conversation_id === '') {
      return;
    }

    $state_operation = (string) ($decision['state_operation'] ?? 'none');
    if ($state_operation === 'clear') {
      $this->clearOfficeFollowupState($conversation_id);
      return;
    }

    if ($state_operation === 'save' && !empty($decision['state_payload']) && is_array($decision['state_payload'])) {
      $this->saveOfficeFollowupState($conversation_id, $decision['state_payload']);
    }
  }

  /**
   * Returns TRUE when a message looks like a location reply.
   */
  protected function isLocationLikeOfficeReply(string $message): bool {
    $normalized = mb_strtolower(trim($message));
    if ($normalized === '') {
      return FALSE;
    }

    if (preg_match('/\b(county|city|boise|pocatello|twin\s*falls|lewiston|idaho\s*falls|coeur\s*d\'?alene|nampa|meridian)\b/u', $normalized)) {
      return TRUE;
    }

    if (preg_match('/\b(ada|canyon|kootenai|bonneville|bannock|twin\s*falls|latah|nez\s*perce)\s*county\b/u', $normalized)) {
      return TRUE;
    }

    if (preg_match('/\b(i\s*(am|m)|live\s*in|located\s*in|near|around|from)\s+(boise|pocatello|twin\s*falls|lewiston|idaho\s*falls|coeur\s*d\'?alene|nampa|meridian|ada|canyon|kootenai|bonneville|bannock|latah|nez\s*perce)\b/u', $normalized)) {
      return TRUE;
    }

    if (preg_match('/\b\d{5}\b/u', $normalized)) {
      // Zip-code-like reply during office follow-up.
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns TRUE when user explicitly continues office/location discussion.
   */
  protected function isExplicitOfficeFollowupTurn(string $message): bool {
    $normalized = mb_strtolower(trim($message));
    if ($normalized === '') {
      return FALSE;
    }

    return (bool) preg_match('/\b(office|location|address|hours?|open|close|closest|near\s*me|which\s*office|directions?)\b/u', $normalized);
  }

  /**
   * Returns TRUE if message is asking for office details.
   */
  protected function isOfficeDetailRequest(string $message): bool {
    $normalized = mb_strtolower(trim($message));
    return (bool) preg_match('/\b(address|location|hours?|open|close|after\s*work|when\s*can\s*i\s*go|walk\s*in|appointment|appt|where|office|closest|nearest|near\s*me|which\s*office|what\s*office|directions?|visit)\b/u', $normalized);
  }

  /**
   * Returns TRUE when message clearly targets site/service feedback.
   */
  protected function isExplicitSiteFeedbackRequest(string $message): bool {
    $normalized = mb_strtolower(trim($message));
    return (bool) preg_match('/\b(website|site|service|staff|feedback|review|suggestion|comment|experience|pagina|sitio|servicio|comentario|sugerencia)\b/u', $normalized);
  }

  /**
   * Builds a user-visible office detail message with hours.
   */
  protected function buildOfficeDetailMessage(array $office): string {
    $hours = (string) ($office['hours'] ?? $this->t('Hours may vary. Please call to confirm current office hours.'));
    return (string) $this->t("Here are the details for the @office office:\n\nAddress: @address\nPhone: @phone\nHours: @hours", [
      '@office' => $office['name'] ?? $this->t('local'),
      '@address' => $office['address'] ?? $this->t('Address unavailable'),
      '@phone' => $office['phone'] ?? $this->t('Call for contact details'),
      '@hours' => $hours,
    ]);
  }

  /**
   * Returns a short context hint for service-area responses.
   */
  protected function getServiceAreaContextHint(string $area): string {
    $hints = [
      'housing' => $this->t('Topics include eviction notices, landlord/tenant rights, repairs, and foreclosure concerns.'),
      'family' => $this->t('Topics include custody, child support, divorce, and protection orders.'),
      'consumer' => $this->t('Topics include debt collection, scams, garnishment, and bankruptcy issues.'),
      'seniors' => $this->t('Topics include elder abuse, guardianship, probate, and power of attorney concerns.'),
      'health' => $this->t('Topics include SNAP, Medicaid, Medicare, SSI/SSDI, and other public benefits.'),
      'civil_rights' => $this->t('Topics include employment rights, discrimination, and workplace retaliation concerns.'),
    ];

    return (string) ($hints[$area] ?? $this->t('We can help you find the right legal resources and next steps.'));
  }

  /**
   * Resolves office from current message, then recent conversation history.
   */
  protected function resolveOfficeFromMessageOrHistory(string $message, array $server_history, OfficeLocationResolver $resolver): ?array {
    $office = $resolver->resolve($message);
    if ($office) {
      return $office;
    }

    $recent = array_reverse(array_slice($server_history, -6));
    foreach ($recent as $entry) {
      $text = (string) ($entry['text'] ?? '');
      if ($text === '') {
        continue;
      }
      $history_office = $resolver->resolve($text);
      if ($history_office) {
        return $history_office;
      }
    }

    return NULL;
  }

  /**
   * Backwards-compatible wrapper for form finder keyword extraction.
   */
  protected function extractFormTopicKeywords(string $query): string {
    return $this->extractFinderTopicKeywords($query, 'forms');
  }

  /**
   * Builds visible-plus-metadata Langfuse input payload fields for user text.
   */
  protected function buildLangfuseInputPayload(string $text): array {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata($text);
    return [
      'display' => sprintf(
        'hash=%s len=%s redact=%s',
        ObservabilityPayloadMinimizer::hashPrefix($metadata['text_hash']),
        $metadata['length_bucket'],
        $metadata['redaction_profile'],
      ),
      'metadata' => [
        'input_hash' => $metadata['text_hash'],
        'input_length_bucket' => $metadata['length_bucket'],
        'input_redaction_profile' => $metadata['redaction_profile'],
      ],
    ];
  }

  /**
   * Builds visible-plus-metadata Langfuse output payload fields.
   */
  protected function buildLangfuseOutputPayload(string $text, string $response_type, ?string $reason_code = NULL): array {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata($text);
    $reason = $reason_code !== NULL && $reason_code !== '' ? $reason_code : 'none';

    return [
      'display' => sprintf(
        'type=%s reason=%s hash=%s len=%s',
        $response_type,
        $reason,
        ObservabilityPayloadMinimizer::hashPrefix($metadata['text_hash']),
        $metadata['length_bucket'],
      ),
      'metadata' => [
        'response_type' => $response_type,
        'reason_code' => $reason_code,
        'output_hash' => $metadata['text_hash'],
        'output_length_bucket' => $metadata['length_bucket'],
        'output_redaction_profile' => $metadata['redaction_profile'],
      ],
    ];
  }

  /**
   * Builds metadata-only finder log context for a user query.
   */
  protected function buildFinderQueryLogContext(string $request_id, string $query, array|string $keywords = [], ?int $count = NULL): array {
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata($query);
    $context = [
      '@request_id' => $request_id,
      '@query_hash' => $metadata['text_hash'],
      '@query_length_bucket' => $metadata['length_bucket'],
      '@redaction_profile' => $metadata['redaction_profile'],
      '@keyword_count' => ObservabilityPayloadMinimizer::keywordCount($keywords),
    ];

    if ($count !== NULL) {
      $context['@count'] = $count;
    }

    return $context;
  }

  /**
   * Builds metadata-only exception context for logs and events.
   */
  protected function buildExceptionContext(\Throwable $throwable): array {
    return [
      '@class' => get_class($throwable),
      '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($throwable),
    ];
  }

  /**
   * Sanitizes user input.
   */
  protected function sanitizeInput(string $input) {
    $input = strip_tags($input);
    $input = mb_substr($input, 0, 500);
    $input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
    $input = preg_replace('/\s+/', ' ', $input);
    return trim($input);
  }

  /**
   * Returns appropriate message for high-risk situations.
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
   */
  protected function isEmergencyMessage(string $message): bool {
    $patterns = [
      '/\b(call\s*911|dial\s*911)\b/i',
      '/\b(being\s+attacked|someone\s+is\s+dying)\b/i',
      '/\b(heart\s+attack|stroke|not\s+breathing)\b/i',
      '/\b(breaking\s+in\s+right\s+now)\b/i',
      '/\b(active\s+shooter|gun|weapon)\b/i',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if the message indicates a criminal matter.
   */
  protected function isCriminalMatter(string $message): bool {
    $patterns = [
      '/\b(criminal\s+(defense|lawyer|case|charge))\b/i',
      '/\b(arrested|arrest\s+warrant)\b/i',
      '/\b(felony|misdemeanor)\b/i',
      '/\b(dui|dwi|drunk\s+driving)\b/i',
      '/\b(public\s+defender)\b/i',
      '/\b(jail|prison|incarcerat)\b/i',
      '/\b(probation\s+violation|parole)\b/i',
    ];
    if (preg_match('/\b(expunge|seal|clear)\s+(my\s+)?record/i', $message)) {
      return FALSE;
    }
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if the message indicates a non-Idaho matter.
   */
  protected function isNonIdaho(string $message): bool {
    if (preg_match('/\bidaho\b/i', $message)) {
      return FALSE;
    }
    $patterns = [
      '/\b(out\s+of\s+state|different\s+state|another\s+state|not\s+in\s+idaho)\b/i',
      '/\b(live|living|reside|residing|located|based|am\s+in|i\'m\s+in)\s+(in\s+)?(alabama|alaska|arizona|arkansas|california|colorado|connecticut|delaware|florida|georgia|hawaii|illinois|indiana|iowa|kansas|kentucky|louisiana|maine|maryland|massachusetts|michigan|minnesota|mississippi|missouri|montana|nebraska|nevada|new\s+hampshire|new\s+jersey|new\s+mexico|new\s+york|north\s+carolina|north\s+dakota|ohio|oklahoma|oregon|pennsylvania|rhode\s+island|south\s+carolina|south\s+dakota|tennessee|texas|utah|vermont|virginia|washington\s+state|west\s+virginia|wisconsin|wyoming)\b/i',
      '/\b(oregon|washington\s+state|montana|nevada|utah|wyoming)\b/i',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Handles a resolved office follow-up response.
   *
   * @param array $office
   *   The resolved office data (name, address, phone, url).
   * @param string $user_message
   *   The user's message.
   * @param string $conversation_id
   *   The conversation UUID.
   * @param array $server_history
   *   The conversation history.
   * @param string $request_id
   *   The per-request correlation ID.
   * @param bool $debug_mode
   *   Whether debug mode is enabled.
   * @param array|null $debug_meta
   *   Debug metadata array or NULL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with office details.
   */
  protected function handleOfficeFollowUp(Request $request, array $office, string $user_message, string $conversation_id, array $server_history, string $request_id, bool $debug_mode, ?array $debug_meta, float $start_time): JsonResponse {
    $response = [
      'type' => 'office_location',
      'response_mode' => 'navigate',
      'message' => $this->buildOfficeDetailMessage($office),
      'office' => [
        'name' => $office['name'],
        'address' => $office['address'],
        'phone' => $office['phone'],
        'hours' => $office['hours'] ?? $this->t('Hours may vary. Please call to confirm current office hours.'),
      ],
      'primary_action' => [
        'label' => $this->t('@name Office Details', ['@name' => $office['name']]),
        'url' => $office['url'],
      ],
      'secondary_actions' => [
        [
          'label' => $this->t('Call @phone', ['@phone' => $office['phone']]),
          'url' => 'tel:' . preg_replace('/[^\d]/', '', $office['phone']),
        ],
        [
          'label' => $this->t('All Offices'),
          'url' => '/contact/offices',
        ],
      ],
      'reason_code' => 'office_followup_resolved',
      'request_id' => $request_id,
    ];

    if ($debug_mode) {
      $debug_meta['intent_selected'] = 'office_location_followup';
      $debug_meta['intent_source'] = 'followup_slot_fill';
      $debug_meta['final_action'] = 'office_location';
      $debug_meta['reason_code'] = 'office_followup_resolved';
      $debug_meta['processing_stages'][] = 'followup_office_resolved';
      $response['_debug'] = $debug_meta;
    }

    // Log analytics.
    $this->analyticsLogger->log('office_location_followup', (string) ($office['url'] ?? ''));

    // Store conversation turn.
    $server_history[] = [
      'role' => 'user',
      'text' => PiiRedactor::redactForStorage($user_message, 200),
      'intent' => 'office_location_followup',
      'safety_flags' => $this->getUrgencySignalsForHistory($user_message, $debug_meta),
      'timestamp' => time(),
    ];
    $server_history = array_slice($server_history, -10);
    $cache_data = $server_history;
    if ($request->hasSession() && $request->getSession()->isStarted()) {
      $cache_data['_session_fp'] = hash('sha256', $request->getSession()->getId());
    }
    $this->conversationCache->set(
      'ilas_conv:' . $conversation_id,
      $cache_data,
      time() + 1800
    );

    // Conversation logger.
    if ($this->conversationLogger) {
      $this->conversationLogger->logExchange(
        $conversation_id,
        $user_message,
        $response['message'] ?? '',
        'office_location_followup',
        'office_location',
        $request_id
      );
    }

    $langfuse_output = $this->buildLangfuseOutputPayload(
      (string) ($response['message'] ?? ''),
      (string) ($response['type'] ?? 'unknown'),
      $response['reason_code'] ?? NULL,
    );
    $this->langfuseTracer?->endTrace(
      output: $langfuse_output['display'],
      metadata: array_merge(
        [
          'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
          'success' => TRUE,
          'response_type' => $response['type'] ?? 'unknown',
          'reason_code' => $response['reason_code'] ?? NULL,
          'followup_type' => 'office_location',
        ],
        $langfuse_output['metadata'],
      ),
    );

    return $this->monitoredJsonResponse(
      $request,
      PerformanceMonitor::ENDPOINT_MESSAGE,
      $response,
      200,
      [],
      $request_id,
      TRUE,
      'message.office_followup_resolved',
    );
  }

  /**
   * Handles an unresolved office follow-up with clarification.
   *
   * @param array $all_offices
   *   All ILAS offices keyed by slug.
   * @param string $user_message
   *   The user's message.
   * @param string $conversation_id
   *   The conversation UUID.
   * @param array $server_history
   *   The conversation history.
   * @param string $request_id
   *   The per-request correlation ID.
   * @param bool $debug_mode
   *   Whether debug mode is enabled.
   * @param array|null $debug_meta
   *   Debug metadata array or NULL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with all offices for clarification.
   */
  protected function handleOfficeFollowUpClarify(Request $request, array $all_offices, string $user_message, string $conversation_id, array $server_history, string $request_id, bool $debug_mode, ?array $debug_meta, float $start_time): JsonResponse {
    $offices_list = [];
    foreach ($all_offices as $office) {
      $offices_list[] = [
        'name' => $office['name'],
        'phone' => $office['phone'],
        'url' => $office['url'],
      ];
    }

    $response = [
      'type' => 'office_location_clarify',
      'response_mode' => 'clarify',
      'message' => $this->t("I wasn't able to identify that location. Here are our five offices \u{2014} which is closest to you?"),
      'offices' => $offices_list,
      'primary_action' => [
        'label' => $this->t('View All Offices'),
        'url' => '/contact/offices',
      ],
      'secondary_actions' => [],
      'reason_code' => 'office_followup_clarify',
      'request_id' => $request_id,
    ];

    if ($debug_mode) {
      $debug_meta['intent_selected'] = 'office_location_followup';
      $debug_meta['intent_source'] = 'followup_slot_fill';
      $debug_meta['final_action'] = 'office_location_clarify';
      $debug_meta['reason_code'] = 'office_followup_clarify';
      $debug_meta['processing_stages'][] = 'followup_office_clarify';
      $response['_debug'] = $debug_meta;
    }

    // Log analytics.
    $this->analyticsLogger->log('office_location_followup_miss', 'unresolved');

    // Store conversation turn.
    $server_history[] = [
      'role' => 'user',
      'text' => PiiRedactor::redactForStorage($user_message, 200),
      'intent' => 'office_location_followup_miss',
      'safety_flags' => $this->getUrgencySignalsForHistory($user_message, $debug_meta),
      'timestamp' => time(),
    ];
    $server_history = array_slice($server_history, -10);
    $cache_data = $server_history;
    if ($request->hasSession() && $request->getSession()->isStarted()) {
      $cache_data['_session_fp'] = hash('sha256', $request->getSession()->getId());
    }
    $this->conversationCache->set(
      'ilas_conv:' . $conversation_id,
      $cache_data,
      time() + 1800
    );

    // Conversation logger.
    if ($this->conversationLogger) {
      $this->conversationLogger->logExchange(
        $conversation_id,
        $user_message,
        $response['message'] ?? '',
        'office_location_followup_miss',
        'office_location_clarify',
        $request_id
      );
    }

    $langfuse_output = $this->buildLangfuseOutputPayload(
      (string) ($response['message'] ?? ''),
      (string) ($response['type'] ?? 'unknown'),
      $response['reason_code'] ?? NULL,
    );
    $this->langfuseTracer?->endTrace(
      output: $langfuse_output['display'],
      metadata: array_merge(
        [
          'duration_ms' => (microtime(TRUE) - $start_time) * 1000,
          'success' => TRUE,
          'response_type' => $response['type'] ?? 'unknown',
          'reason_code' => $response['reason_code'] ?? NULL,
          'followup_type' => 'office_location_clarify',
        ],
        $langfuse_output['metadata'],
      ),
    );

    return $this->monitoredJsonResponse(
      $request,
      PerformanceMonitor::ENDPOINT_MESSAGE,
      $response,
      200,
      [],
      $request_id,
      TRUE,
      'message.office_followup_clarify',
    );
  }

  /**
   * Health check endpoint for monitoring.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with health status.
   */
  public function health() {
    $status = 'healthy';
    $checks = [];
    $httpCode = 200;
    $container = \Drupal::getContainer();
    $checks['proxy_trust'] = $this->inspectRequestTrust($this->currentDiagnosticsRequest());
    $sloDefinitions = ($container && $container->has('ilas_site_assistant.slo_definitions'))
      ? $container->get('ilas_site_assistant.slo_definitions')
      : NULL;

    if ($this->performanceMonitor) {
      $summary = $this->performanceMonitor->getSummary();
      $status = $summary['status'];
      $checks['latency_p95_ms'] = $summary['p95'];
      $checks['error_rate_pct'] = $summary['error_rate'];
      $checks['availability_pct'] = $summary['availability_pct'] ?? max(0, 100 - (float) $summary['error_rate']);
      $checks['throughput_per_min'] = $summary['throughput_per_min'];

      if (str_starts_with($status, 'degraded')) {
        $status = 'degraded';
        $httpCode = 503;
      }

      if ($sloDefinitions) {
        $checks['slo_targets'] = [
          'availability_target_pct' => $sloDefinitions->getAvailabilityTargetPct(),
          'latency_p95_target_ms' => $sloDefinitions->getLatencyP95TargetMs(),
          'error_rate_target_pct' => $sloDefinitions->getErrorRateTargetPct(),
        ];
        if ($checks['availability_pct'] < $checks['slo_targets']['availability_target_pct']) {
          $status = 'degraded';
          $httpCode = 503;
        }
      }
    }

    $checks['faq_index'] = $this->faqIndex ? 'ok' : 'unavailable';
    $checks['intent_router'] = $this->intentRouter ? 'ok' : 'unavailable';

    // Cron health check.
    if ($container && $container->has('ilas_site_assistant.cron_health_tracker') && $sloDefinitions) {
      $cronHealth = $container->get('ilas_site_assistant.cron_health_tracker')
        ->getHealthStatus($sloDefinitions);
      $checks['cron'] = $cronHealth;
      if ($cronHealth['status'] !== 'healthy') {
        $status = 'degraded';
        $httpCode = 503;
      }
    }

    // Queue health check.
    if ($container && $container->has('ilas_site_assistant.queue_health_monitor') && $sloDefinitions) {
      $queueHealth = $container->get('ilas_site_assistant.queue_health_monitor')
        ->getQueueHealthStatus($sloDefinitions);
      $checks['queue'] = $queueHealth;
      if ($queueHealth['status'] !== 'healthy') {
        $status = 'degraded';
        $httpCode = 503;
      }
    }

    if ($this->sourceGovernance) {
      $source_governance = $this->sourceGovernance->getSnapshot();
      $checks['source_governance'] = $source_governance;
      if (($source_governance['status'] ?? 'unknown') === 'degraded') {
        $status = 'degraded';
        $httpCode = 503;
      }
    }

    if ($this->vectorIndexHygiene) {
      $vector_index_hygiene = $this->vectorIndexHygiene->getSnapshot();
      $checks['vector_index_hygiene'] = $vector_index_hygiene;
      if (($vector_index_hygiene['status'] ?? 'unknown') === 'degraded') {
        $status = 'degraded';
        $httpCode = 503;
      }
    }

    if ($this->retrievalConfiguration) {
      $retrieval_configuration = $this->retrievalConfiguration->getHealthSnapshot();
      $checks['retrieval_configuration'] = $retrieval_configuration;
      if (($retrieval_configuration['status'] ?? 'unknown') === 'degraded') {
        $status = 'degraded';
        $httpCode = 503;
      }
    }

    if ($status !== 'healthy' && $status !== 'degraded') {
      $httpCode = 503;
    }

    return $this->jsonResponse([
      'status' => $status,
      'timestamp' => date('c'),
      'checks' => $checks,
    ], $httpCode);
  }

  /**
   * Detailed metrics endpoint for monitoring dashboards.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with detailed metrics.
   */
  public function metrics() {
    if (!$this->performanceMonitor) {
      return $this->jsonResponse([
        'error' => 'Performance monitoring not enabled',
      ], 503);
    }

    $summary = $this->performanceMonitor->getSummary();

    $container = \Drupal::getContainer();
    $sloDefinitions = ($container && $container->has('ilas_site_assistant.slo_definitions'))
      ? $container->get('ilas_site_assistant.slo_definitions')
      : NULL;

    $response = [
      'timestamp' => date('c'),
      'metrics' => $summary,
      'proxy_trust' => $this->inspectRequestTrust($this->currentDiagnosticsRequest()),
      'thresholds' => [
        'availability_pct' => $sloDefinitions ? $sloDefinitions->getAvailabilityTargetPct() : 99.5,
        'p95_latency_ms' => $sloDefinitions ? $sloDefinitions->getLatencyP95TargetMs() : PerformanceMonitor::THRESHOLD_P95_MS,
        'p99_latency_ms' => $sloDefinitions ? $sloDefinitions->getLatencyP99TargetMs() : 5000,
        'error_rate_pct' => $sloDefinitions ? $sloDefinitions->getErrorRateTargetPct() : PerformanceMonitor::THRESHOLD_ERROR_RATE * 100,
        'error_budget_window_hours' => $sloDefinitions ? $sloDefinitions->getErrorBudgetWindowHours() : 168,
        'cron_max_age_seconds' => $sloDefinitions ? $sloDefinitions->getCronMaxAgeSeconds() : 7200,
        'cron_expected_cadence_seconds' => $sloDefinitions ? $sloDefinitions->getCronExpectedCadenceSeconds() : 3600,
        'queue_max_depth' => $sloDefinitions ? $sloDefinitions->getQueueMaxDepth() : 10000,
        'queue_max_age_seconds' => $sloDefinitions ? $sloDefinitions->getQueueMaxAgeSeconds() : 3600,
      ],
    ];

    if ($container && $container->has('ilas_site_assistant.cron_health_tracker') && $sloDefinitions) {
      $response['cron'] = $container->get('ilas_site_assistant.cron_health_tracker')
        ->getHealthStatus($sloDefinitions);
    }

    // Add queue metrics if available.
    if ($container && $container->has('ilas_site_assistant.queue_health_monitor') && $sloDefinitions) {
      $response['queue'] = $container->get('ilas_site_assistant.queue_health_monitor')
        ->getQueueHealthStatus($sloDefinitions);
    }

    if ($this->sourceGovernance) {
      $source_governance = $this->sourceGovernance->getSnapshot();
      $response['metrics']['source_governance'] = [
        'status' => $source_governance['status'] ?? 'unknown',
        'total' => $source_governance['total'] ?? 0,
        'stale' => $source_governance['stale'] ?? 0,
        'unknown' => $source_governance['unknown'] ?? 0,
        'missing_source_url' => $source_governance['missing_source_url'] ?? 0,
        'stale_ratio_pct' => $source_governance['stale_ratio_pct'] ?? 0.0,
        'unknown_ratio_pct' => $source_governance['unknown_ratio_pct'] ?? 0.0,
        'missing_source_url_ratio_pct' => $source_governance['missing_source_url_ratio_pct'] ?? 0.0,
        'min_observations' => $source_governance['min_observations'] ?? 0,
        'min_observations_met' => $source_governance['min_observations_met'] ?? FALSE,
        'last_alert_at' => $source_governance['last_alert_at'] ?? NULL,
        'next_alert_eligible_at' => $source_governance['next_alert_eligible_at'] ?? NULL,
        'cooldown_seconds_remaining' => $source_governance['cooldown_seconds_remaining'] ?? 0,
        'by_source_class' => $source_governance['by_source_class'] ?? [],
        'by_retrieval_method' => $source_governance['by_retrieval_method'] ?? [],
      ];
      $response['thresholds']['source_governance'] = $source_governance['thresholds'] ?? [];
    }

    if ($this->vectorIndexHygiene) {
      $vector_index_hygiene = $this->vectorIndexHygiene->getSnapshot();
      $response['metrics']['vector_index_hygiene'] = [
        'status' => $vector_index_hygiene['status'] ?? 'unknown',
        'policy_version' => $vector_index_hygiene['policy_version'] ?? 'p2_del_03_v1',
        'refresh_mode' => $vector_index_hygiene['refresh_mode'] ?? 'incremental',
        'recorded_at' => $vector_index_hygiene['recorded_at'] ?? NULL,
        'totals' => $vector_index_hygiene['totals'] ?? [],
        'indexes' => $vector_index_hygiene['indexes'] ?? [],
        'last_alert_at' => $vector_index_hygiene['last_alert_at'] ?? NULL,
        'next_alert_eligible_at' => $vector_index_hygiene['next_alert_eligible_at'] ?? NULL,
        'cooldown_seconds_remaining' => $vector_index_hygiene['cooldown_seconds_remaining'] ?? 0,
      ];
      $response['thresholds']['vector_index_hygiene'] = $vector_index_hygiene['thresholds'] ?? [];
    }

    if ($this->sessionBootstrapGuard) {
      $session_bootstrap = $this->sessionBootstrapGuard->getSnapshot();
      $response['metrics']['session_bootstrap'] = [
        'window_started_at' => $session_bootstrap['window_started_at'] ?? NULL,
        'recorded_at' => $session_bootstrap['recorded_at'] ?? NULL,
        'new_session_requests' => $session_bootstrap['new_session_requests'] ?? 0,
        'rate_limited_requests' => $session_bootstrap['rate_limited_requests'] ?? 0,
        'last_new_session_at' => $session_bootstrap['last_new_session_at'] ?? NULL,
        'last_rate_limited_at' => $session_bootstrap['last_rate_limited_at'] ?? NULL,
      ];
      $response['thresholds']['session_bootstrap'] = $session_bootstrap['thresholds'] ?? [];
    }

    $costControlSummary = $this->llmEnhancer->getCostControlSummary();
    if (is_array($costControlSummary)) {
      $response['metrics']['cost_control'] = [
        'daily_calls' => $costControlSummary['daily_calls'] ?? 0,
        'monthly_calls' => $costControlSummary['monthly_calls'] ?? 0,
        'cache_hits' => $costControlSummary['cache_hits'] ?? 0,
        'cache_misses' => $costControlSummary['cache_misses'] ?? 0,
        'cache_requests' => $costControlSummary['cache_requests'] ?? 0,
        'cache_hit_rate' => $costControlSummary['cache_hit_rate'] ?? NULL,
        'kill_switch_active' => $costControlSummary['kill_switch_active'] ?? FALSE,
        'sample_rate' => $costControlSummary['sample_rate'] ?? 1.0,
      ];
      $response['thresholds']['cost_control'] = [
        'daily_call_limit' => $costControlSummary['daily_limit'] ?? 0,
        'monthly_call_limit' => $costControlSummary['monthly_limit'] ?? 0,
        'cache_hit_rate_target' => $costControlSummary['cache_hit_rate_target'] ?? 0.0,
        'per_ip_hourly_call_limit' => $costControlSummary['per_ip_hourly_call_limit'] ?? 0,
        'per_ip_window_seconds' => $costControlSummary['per_ip_window_seconds'] ?? 0,
      ];
    }

    return $this->jsonResponse($response);
  }

  /**
   * Assembles formal response contract fields for 200-response paths.
   *
   * Adds confidence, citations[], and decision_reason to the response data.
   * These fields surface existing internal signals as formal contract fields.
   *
   * @param array $response
   *   The response data array being built.
   * @param array|null $gate_decision
   *   The FallbackGate decision array, or NULL for deterministic early exits.
   * @param string $path_type
   *   The pipeline path type: 'safety', 'oos', 'policy', or 'normal'.
   *
   * @return array
   *   The response data with contract fields added.
   */
  private function assembleContractFields(array $response, ?array $gate_decision, string $path_type): array {
    // confidence: normalized float 0-1, always present on 200 responses.
    $response['confidence'] = $this->normalizeContractConfidence($response, $gate_decision, $path_type);

    // citations: prefer ResponseGrounder sources; derive safely from results when needed.
    $response['citations'] = $this->normalizeContractCitations($response);

    // PHARD-03: Downgrade confidence for citation-required types missing citations.
    $response_type = $response['type'] ?? 'unknown';
    if (in_array($response_type, ResponseGrounder::CITATION_REQUIRED_TYPES, TRUE)
        && empty($response['citations'])
        && !empty($response['results'])) {
      $response['confidence'] = min($response['confidence'], 0.3);
    }

    // decision_reason: human-readable string from reason codes or path defaults.
    $response['decision_reason'] = $this->normalizeContractDecisionReason($response, $gate_decision, $path_type);

    // PHARD-03: Append citations_unavailable to decision_reason when applicable.
    if (in_array($response_type, ResponseGrounder::CITATION_REQUIRED_TYPES, TRUE)
        && empty($response['citations'])
        && !empty($response['results'])) {
      $response['decision_reason'] .= '; citations_unavailable';
    }

    return $response;
  }

  /**
   * Normalizes response confidence to a finite float in [0, 1].
   */
  private function normalizeContractConfidence(array $response, ?array $gate_decision, string $path_type): float {
    $raw_confidence = $gate_decision['confidence'] ?? ($response['confidence'] ?? NULL);

    if (!is_numeric($raw_confidence)) {
      // Deterministic hard-route paths default to full confidence.
      if (in_array($path_type, ['safety', 'oos', 'policy'], TRUE)) {
        return 1.0;
      }
      return 0.0;
    }

    $confidence = (float) $raw_confidence;
    if (!is_finite($confidence)) {
      if (in_array($path_type, ['safety', 'oos', 'policy'], TRUE)) {
        return 1.0;
      }
      return 0.0;
    }

    return round(max(0.0, min(1.0, $confidence)), 4);
  }

  /**
   * Normalizes citations from sources, with safe fallback derivation.
   */
  private function normalizeContractCitations(array $response): array {
    if (!empty($response['sources']) && is_array($response['sources'])) {
      $citations = [];
      foreach ($response['sources'] as $item) {
        if (!is_array($item)) {
          continue;
        }

        $url = $this->sanitizeCitationUrl($item['url'] ?? NULL);
        if ($url === NULL) {
          continue;
        }

        $item['url'] = $url;
        $citations[] = $item;
      }

      return $citations;
    }

    if (empty($response['results']) || !is_array($response['results'])) {
      return [];
    }

    $citations = [];
    foreach ($response['results'] as $result) {
      if (!is_array($result)) {
        continue;
      }

      $title = NULL;
      if (!empty($result['title']) && is_string($result['title'])) {
        $title = $result['title'];
      }
      elseif (!empty($result['question']) && is_string($result['question'])) {
        $title = $result['question'];
      }

      $url = NULL;
      if (!empty($result['source_url']) && is_string($result['source_url'])) {
        $url = $result['source_url'];
      }
      elseif (!empty($result['url']) && is_string($result['url'])) {
        $url = $result['url'];
      }
      $url = $this->sanitizeCitationUrl($url);

      $source = NULL;
      if (!empty($result['source_class']) && is_string($result['source_class'])) {
        $source = $result['source_class'];
      }
      elseif (!empty($result['source']) && is_string($result['source'])) {
        $source = $result['source'];
      }

      if ($url === NULL) {
        continue;
      }

      $citation = [];
      if ($title !== NULL) {
        $citation['title'] = $title;
      }
      if ($url !== NULL) {
        $citation['url'] = $url;
      }
      if ($source !== NULL) {
        $citation['source'] = $source;
      }
      $citations[] = $citation;
    }

    return $citations;
  }

  /**
   * Applies the authoritative citation URL allowlist.
   */
  private function sanitizeCitationUrl(mixed $url): ?string {
    if (!$this->sourceGovernance || !is_string($url)) {
      return NULL;
    }

    return $this->sourceGovernance->sanitizeCitationUrl($url);
  }

  /**
   * Normalizes decision reason from reason code descriptions or path defaults.
   */
  private function normalizeContractDecisionReason(array $response, ?array $gate_decision, string $path_type): string {
    $reason_code = $response['reason_code'] ?? ($gate_decision['reason_code'] ?? NULL);
    if (is_string($reason_code) && $reason_code !== '') {
      $descriptions = FallbackGate::getReasonCodeDescriptions();
      return $descriptions[$reason_code] ?? $reason_code;
    }

    $path_reasons = [
      'safety' => 'Safety classification triggered immediate routing',
      'oos' => 'Request classified as outside service scope',
      'policy' => 'Policy filter triggered immediate routing',
    ];
    return $path_reasons[$path_type] ?? 'Deterministic routing';
  }

  /**
   * Evaluates whether a /track request presents an approved write proof.
   *
   * Origin is authoritative when present. Referer is the fallback when Origin
   * is absent. A session-bound X-CSRF-Token is recovery-only and may be used
   * only when both browser headers are missing.
   *
   * @return array{allowed: bool, mode: string, code?: string, message?: string}
   *   Proof evaluation result.
   */
  private function evaluateTrackWriteProof(Request $request): array {
    if ($request->headers->has('Origin')) {
      $origin = trim((string) $request->headers->get('Origin', ''));
      if ($this->isSameOriginUrl($origin, $request)) {
        return [
          'allowed' => TRUE,
          'mode' => 'origin',
        ];
      }

      return [
        'allowed' => FALSE,
        'mode' => 'origin',
        'code' => 'track_origin_mismatch',
        'message' => 'Origin header must match the site origin.',
      ];
    }

    if ($request->headers->has('Referer')) {
      $referer = trim((string) $request->headers->get('Referer', ''));
      if ($this->isSameOriginUrl($referer, $request)) {
        return [
          'allowed' => TRUE,
          'mode' => 'referer',
        ];
      }

      return [
        'allowed' => FALSE,
        'mode' => 'referer',
        'code' => 'track_origin_mismatch',
        'message' => 'Referer header must match the site origin.',
      ];
    }

    if (!$request->headers->has('X-CSRF-Token')) {
      return [
        'allowed' => FALSE,
        'mode' => 'missing_browser_proof',
        'code' => 'track_proof_missing',
        'message' => 'Same-origin browser proof is required for tracking requests.',
      ];
    }

    if ($this->isValidTrackFallbackToken($request->headers->get('X-CSRF-Token'))) {
      return [
        'allowed' => TRUE,
        'mode' => 'csrf_fallback',
      ];
    }

    return [
      'allowed' => FALSE,
      'mode' => 'csrf_fallback',
      'code' => 'track_proof_invalid',
      'message' => 'The tracking recovery token is invalid or expired.',
    ];
  }

  /**
   * Returns TRUE when a header URL matches the current request origin.
   */
  private function isSameOriginUrl(string $value, Request $request): bool {
    if ($value === '' || $value === 'null') {
      return FALSE;
    }

    $parsed = parse_url($value);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
      return FALSE;
    }

    $request_scheme = strtolower($request->getScheme());
    $request_host = strtolower($request->getHost());
    $request_port = $request->getPort();
    $request_port = $request_port ?: ($request_scheme === 'https' ? 443 : 80);

    $parsed_scheme = strtolower((string) $parsed['scheme']);
    $parsed_host = strtolower((string) $parsed['host']);
    $parsed_port = $parsed['port'] ?? ($parsed_scheme === 'https' ? 443 : ($parsed_scheme === 'http' ? 80 : NULL));

    return $parsed_scheme === $request_scheme
      && $parsed_host === $request_host
      && $parsed_port === $request_port;
  }

  /**
   * Validates the recovery-only CSRF token accepted for /track fallback.
   */
  private function isValidTrackFallbackToken(?string $csrf_token): bool {
    if ($csrf_token === NULL || $csrf_token === '' || $this->csrfTokenGenerator === NULL) {
      return FALSE;
    }

    return $this->csrfTokenGenerator->validate($csrf_token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $this->csrfTokenGenerator->validate($csrf_token, 'rest');
  }

}
