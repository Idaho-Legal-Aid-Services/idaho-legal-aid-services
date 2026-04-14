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
   * Maximum traces fetched when falling back to list lookup.
   */
  private const LIST_FALLBACK_LIMIT = 25;

  /**
   * Request-path metadata keys surfaced in lookup output.
   */
  private const REQUEST_PATH_FIELDS = [
    'vector_enabled_effective',
    'vector_attempted',
    'vector_status',
    'vector_result_count',
    'lexical_result_count',
    'degraded_reason',
    'response_type',
    'reason_code',
    'input_preview_redacted',
    'output_preview_redacted',
    'intent_type',
    'is_quick_action',
    'fallback_level',
    'turn_type',
    'duration_ms',
    'success',
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
   * @return array<string, mixed>
   *   Sanitized lookup result.
   */
  public function lookupTrace(string $traceId, int $attempts = 30, int $delayMs = 2000): array {
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
    $url = $host . '/api/public/traces/' . rawurlencode($traceId);

    $lastNotFound = [
      'found' => FALSE,
      'trace_id' => $traceId,
      'http_status' => 404,
      'attempts' => 0,
      'api_path' => '/api/public/traces/{trace_id}',
    ];

    for ($attempt = 1; $attempt <= $requestAttempts; $attempt++) {
      try {
        $response = $this->httpClient->request('GET', $url, [
          'auth' => [$publicKey, $secretKey],
          'timeout' => $timeout,
          'connect_timeout' => $timeout,
          'headers' => [
            'Accept' => 'application/json',
          ],
        ]);

        $decoded = json_decode((string) $response->getBody(), TRUE);
        $trace = $this->extractTrace(is_array($decoded) ? $decoded : []);

        return [
          'found' => TRUE,
          'trace_id' => $traceId,
          'http_status' => $response->getStatusCode(),
          'attempts' => $attempt,
          'api_path' => '/api/public/traces/{trace_id}',
          'trace' => $this->sanitizeTrace($trace),
        ];
      }
      catch (GuzzleException $e) {
        $statusCode = 0;
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
          $statusCode = $e->getResponse()->getStatusCode();
        }

        if ($statusCode === 404) {
          $lastNotFound['attempts'] = $attempt;
          if ($attempt < $requestAttempts && $delayMs > 0) {
            usleep($delayMs * 1000);
          }
          continue;
        }

        $fallbackTrace = $this->lookupTraceFromList($traceId, $host, $publicKey, $secretKey, $timeout);
        if ($fallbackTrace !== NULL) {
          return [
            'found' => TRUE,
            'trace_id' => $traceId,
            'http_status' => 200,
            'attempts' => $attempt,
            'api_path' => '/api/public/traces?limit=' . self::LIST_FALLBACK_LIMIT,
            'trace' => $this->sanitizeTrace($fallbackTrace),
          ];
        }

        throw new \RuntimeException(sprintf(
          'Langfuse trace lookup failed with HTTP %d: %s',
          $statusCode,
          $e->getMessage(),
        ), 0, $e);
      }
    }

    $fallbackTrace = $this->lookupTraceFromList($traceId, $host, $publicKey, $secretKey, $timeout);
    if ($fallbackTrace !== NULL) {
      return [
        'found' => TRUE,
        'trace_id' => $traceId,
        'http_status' => 200,
        'attempts' => $requestAttempts,
        'api_path' => '/api/public/traces?limit=' . self::LIST_FALLBACK_LIMIT,
        'trace' => $this->sanitizeTrace($fallbackTrace),
      ];
    }

    return $lastNotFound;
  }

  /**
   * Attempts an exact-ID match through the recent trace list endpoint.
   *
   * @return array<string, mixed>|null
   *   Matching trace payload, or NULL when not found.
   */
  protected function lookupTraceFromList(
    string $traceId,
    string $host,
    string $publicKey,
    string $secretKey,
    float $timeout,
  ): ?array {
    try {
      $response = $this->httpClient->request('GET', $host . '/api/public/traces', [
        'auth' => [$publicKey, $secretKey],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'query' => [
          'limit' => self::LIST_FALLBACK_LIMIT,
        ],
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException(sprintf(
        'Langfuse trace list fallback failed: %s',
        $e->getMessage(),
      ), 0, $e);
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    $traces = is_array($decoded['data'] ?? NULL) ? $decoded['data'] : [];

    foreach ($traces as $trace) {
      if (is_array($trace) && (string) ($trace['id'] ?? '') === $traceId) {
        return $trace;
      }
    }

    return NULL;
  }

  /**
   * Extracts the trace object from the decoded API response.
   *
   * @param array<string, mixed> $decoded
   *   Decoded API response.
   *
   * @return array<string, mixed>
   *   Trace object.
   */
  protected function extractTrace(array $decoded): array {
    if (isset($decoded['id'])) {
      return $decoded;
    }

    if (is_array($decoded['data'] ?? NULL)) {
      return $decoded['data'];
    }

    if (is_array($decoded['trace'] ?? NULL)) {
      return $decoded['trace'];
    }

    throw new \RuntimeException('Langfuse trace lookup returned an unexpected response shape.');
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
      'environment' => $trace['environment'] ?? NULL,
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
