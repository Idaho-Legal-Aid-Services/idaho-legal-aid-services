<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Looks up Langfuse traces and returns sanitized proof snapshots.
 */
class LangfuseTraceLookupService {

  /**
   * Maximum retry attempts accepted from callers.
   */
  private const MAX_ATTEMPTS = 30;

  /**
   * Path of the v2 observations read API, relative to langfuse.host.
   */
  private const OBSERVATIONS_PATH = '/api/public/v2/observations';

  /**
   * Maximum observation rows read for one trace.
   */
  private const OBSERVATION_LIMIT = 100;

  /**
   * Default search window: one week back.
   */
  public const DEFAULT_LOOKBACK_HOURS = 168;

  /**
   * Largest search window accepted.
   */
  private const MAX_LOOKBACK_HOURS = 2160;

  /**
   * Request-path metadata keys surfaced in lookup output.
   */
  private const REQUEST_PATH_FIELDS = [
    'surface',
    'request_id',
    'conversation_hash',
    'session_hash',
    'turn_type',
    'history_length',
    'intent_type',
    'intent_source',
    'fallback_path',
    'response_type',
    'reason_code',
    'quick_action',
    'selection_button_id',
    'selection_label',
    'selection_parent_button_id',
    'selection_source',
    'pending_flow_action',
    'office_followup_branch',
    'clarify_loop_break',
    'history_fallback_used',
    'user_text_redacted',
    'user_text_normalized_redacted',
    'assistant_text_redacted',
    'retrieval_artifacts',
    'grounding_sources',
    '_grounding_weak',
    '_grounding_weak_reason',
    'grounding_refusal_reason',
    'freshness_enforcement_level',
    'freshness_profile',
    'rerank_candidate_ids_before',
    'rerank_candidate_ids_after',
    'rerank_order_changed',
    'rerank_model',
    'rerank_score_delta',
    'rerank_latency_ms',
    'rerank_fallback_reason',
    'llm_provider',
    'llm_model',
    'llm_route_resolution',
    'vector_enabled_effective',
    'vector_attempted',
    'vector_status',
    'vector_result_count',
    'lexical_result_count',
    'degraded_reason',
    'input_preview_redacted',
    'output_preview_redacted',
    'is_quick_action',
    'fallback_level',
    'duration_ms',
    'success',
    'feedback_value',
    'feedback_response_request_id',
  ];

  /**
   * Constructs the lookup service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
  ) {
  }

  /**
   * Looks up a trace by ID with bounded retries for eventual consistency.
   *
   * Reads GET /api/public/v2/observations filtered by trace id. Langfuse
   * removed GET /api/public/traces/{id} and the trace list on 2026-11-16
   * (HTTP 410), and the v4 data model has no trace object: a trace is the set
   * of its observation rows, with the trace-level fields on the root one.
   *
   * @param string $traceId
   *   The request UUID or the 32-hex Langfuse trace id; both are accepted.
   * @param int $attempts
   *   Lookup attempts before returning not found.
   * @param int $delayMs
   *   Delay between attempts in milliseconds.
   * @param int $lookbackHours
   *   How far back to search. The v2 API requires a start-time window.
   *
   * @return array<string, mixed>
   *   Sanitized lookup result.
   */
  public function lookupTrace(string $traceId, int $attempts = 30, int $delayMs = 2000, int $lookbackHours = self::DEFAULT_LOOKBACK_HOURS): array {
    $traceId = trim($traceId);
    if ($traceId === '') {
      throw new \InvalidArgumentException('Trace ID must not be empty.');
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    if (!$config->get('langfuse.enabled')) {
      throw new \RuntimeException('Langfuse is not enabled in the current runtime.');
    }

    $publicKey = $config->get('langfuse.public_key') ?? '';
    $secretKey = $config->get('langfuse.secret_key') ?? '';
    if ($publicKey === '' || $secretKey === '') {
      throw new \RuntimeException('Langfuse credentials are not configured in the current runtime.');
    }

    $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
    $timeout = (float) ($config->get('langfuse.timeout') ?? 5.0);
    $requestAttempts = max(1, min($attempts, self::MAX_ATTEMPTS));
    $delayMs = max(0, min($delayMs, 10000));
    $lookbackHours = max(1, min($lookbackHours, self::MAX_LOOKBACK_HOURS));
    $langfuseTraceId = LangfuseOtlpPayloadBuilder::traceId($traceId);
    $url = $host . self::OBSERVATIONS_PATH;

    $result = [
      'found' => FALSE,
      'trace_id' => $traceId,
      'langfuse_trace_id' => $langfuseTraceId,
      'http_status' => 200,
      'attempts' => 0,
      'api_path' => self::OBSERVATIONS_PATH . '?traceId={trace_id}',
    ];

    for ($attempt = 1; $attempt <= $requestAttempts; $attempt++) {
      $now = time();
      try {
        $response = $this->httpClient->request('GET', $url, [
          'auth' => [$publicKey, $secretKey],
          'timeout' => $timeout,
          'connect_timeout' => $timeout,
          'query' => [
            'traceId' => $langfuseTraceId,
            'fromStartTime' => gmdate('Y-m-d\TH:i:s\Z', $now - $lookbackHours * 3600),
            // Allow for clock skew between this host and Langfuse.
            'toStartTime' => gmdate('Y-m-d\TH:i:s\Z', $now + 600),
            'fields' => 'core,basic,io,metadata',
            'limit' => self::OBSERVATION_LIMIT,
          ],
          'headers' => [
            'Accept' => 'application/json',
          ],
        ]);
      }
      catch (GuzzleException $e) {
        $statusCode = 0;
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
          $statusCode = $e->getResponse()->getStatusCode();
        }

        throw new \RuntimeException(sprintf(
          'Langfuse trace lookup failed with HTTP %d: %s',
          $statusCode,
          $e->getMessage(),
        ), 0, $e);
      }

      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($decoded) || !is_array($decoded['data'] ?? NULL)) {
        throw new \RuntimeException('Langfuse trace lookup returned an unexpected response shape.');
      }

      $rows = array_values(array_filter($decoded['data'], 'is_array'));
      $result['attempts'] = $attempt;
      $result['http_status'] = $response->getStatusCode();

      if ($rows !== []) {
        $result['found'] = TRUE;
        $result['trace'] = $this->sanitizeTrace($this->traceFromObservations($rows));
        return $result;
      }

      // Not ingested yet: OTLP is processed asynchronously.
      if ($attempt < $requestAttempts && $delayMs > 0) {
        usleep($delayMs * 1000);
      }
    }

    return $result;
  }

  /**
   * Rebuilds a trace-shaped array from v2 observation rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Observation rows of one trace, non-empty.
   *
   * @return array<string, mixed>
   *   Trace payload in the shape sanitizeTrace() reads.
   */
  protected function traceFromObservations(array $rows): array {
    $root = NULL;
    foreach ($rows as $row) {
      if (!empty($row['isRootObservation'])) {
        $root = $row;
        break;
      }
    }
    // A trace whose root span has not arrived yet (or a feedback event read
    // on its own) still has a first observation to describe it by.
    if ($root === NULL) {
      usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['startTime'] ?? ''), (string) ($b['startTime'] ?? '')));
      $root = $rows[0];
    }

    $rawMetadata = is_array($root['metadata'] ?? NULL) ? $root['metadata'] : [];
    $tags = $rawMetadata['attributes.langfuse.trace.tags'] ?? [];
    $name = $rawMetadata['attributes.langfuse.trace.name'] ?? ($root['name'] ?? NULL);

    // Langfuse files unmapped OTLP attributes, the scope and the resource
    // under prefixed keys next to the trace metadata; they are transport
    // detail, not request-path fields.
    $metadata = array_filter(
      $rawMetadata,
      static fn($key): bool => preg_match('/^(attributes|resourceAttributes|scope)\./', (string) $key) !== 1,
      ARRAY_FILTER_USE_KEY,
    );

    return [
      'name' => $name,
      'timestamp' => $root['startTime'] ?? NULL,
      'environment' => $root['environment'] ?? NULL,
      'sessionId' => $root['sessionId'] ?? NULL,
      'tags' => is_array($tags) ? $tags : [],
      'input' => $root['input'] ?? NULL,
      'output' => $root['output'] ?? NULL,
      'metadata' => $metadata,
      'root_observation_found' => !empty($root['isRootObservation']),
      // The root span stands in for the trace itself, as the trace object
      // did before; count only what happened inside it.
      'observations' => array_values(array_filter($rows, static fn(array $row): bool => empty($row['isRootObservation']))),
    ];
  }

  /**
   * Builds the sanitized trace payload exposed by the lookup command.
   *
   * @param array<string, mixed> $trace
   *   Raw trace payload.
   *
   * @return array<string, mixed>
   *   Sanitized trace payload.
   */
  protected function sanitizeTrace(array $trace): array {
    $metadata = is_array($trace['metadata'] ?? NULL) ? $trace['metadata'] : [];
    $observations = is_array($trace['observations'] ?? NULL) ? $trace['observations'] : [];

    return [
      'name' => $trace['name'] ?? NULL,
      'timestamp' => $trace['timestamp'] ?? NULL,
      'created_at' => $trace['createdAt'] ?? NULL,
      'updated_at' => $trace['updatedAt'] ?? NULL,
      'root_observation_found' => (bool) ($trace['root_observation_found'] ?? FALSE),
      'environment' => $trace['environment'] ?? NULL,
      'session_id' => $trace['sessionId'] ?? NULL,
      'tags' => is_array($trace['tags'] ?? NULL) ? array_values($trace['tags']) : [],
      'input' => $this->sanitizeVisibleValue($trace['input'] ?? NULL),
      'output' => $this->sanitizeVisibleValue($trace['output'] ?? NULL),
      'observation_count' => count($observations),
      'metadata_keys' => array_values(array_map('strval', array_keys($metadata))),
      'request_path_fields' => $this->buildRequestPathFieldMap($metadata),
    ];
  }

  /**
   * Converts visible trace values into scalar-safe summaries.
   */
  protected function sanitizeVisibleValue(mixed $value): mixed {
    if ($value === NULL || is_scalar($value)) {
      return $value;
    }

    if (is_array($value)) {
      return ObservabilityPayloadMinimizer::summarizeScalarMap($value);
    }

    return gettype($value);
  }

  /**
   * Builds a presence/value map for request-path metadata keys.
   *
   * @param array<string, mixed> $metadata
   *   Trace metadata.
   *
   * @return array<string, array{present: bool, value: mixed}>
   *   Presence/value map.
   */
  protected function buildRequestPathFieldMap(array $metadata): array {
    $fields = [];
    foreach (self::REQUEST_PATH_FIELDS as $key) {
      $fields[$key] = [
        'present' => array_key_exists($key, $metadata),
        'value' => $metadata[$key] ?? NULL,
      ];
    }

    return $fields;
  }

}
