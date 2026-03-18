<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Voyage AI reranker for second-stage semantic reranking.
 *
 * Reorders retrieval results using Voyage AI's rerank API after lexical
 * and optional vector retrieval, before response grounding. Implements a
 * self-contained circuit breaker (three-state: closed -> open -> half_open)
 * following the LlmCircuitBreaker pattern.
 *
 * Fail-safe: on any failure, returns original items unchanged.
 */
class VoyageReranker implements RerankerInterface {

  /**
   * Voyage rerank API endpoint.
   */
  const API_ENDPOINT = 'https://api.voyageai.com/v1/rerank';

  /**
   * State API key for circuit breaker data.
   */
  const CIRCUIT_STATE_KEY = 'ilas_site_assistant.voyage_circuit_breaker';

  /**
   * Maximum document text length sent to Voyage (characters).
   */
  const MAX_DOCUMENT_LENGTH = 1000;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The state service for circuit breaker persistence.
   */
  protected StateInterface $state;

  /**
   * Constructs a VoyageReranker.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerInterface $logger,
    StateInterface $state,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function rerank(string $query, array $items, array $options = []): array {
    $meta = $this->defaultMeta();

    try {
      return $this->doRerank($query, $items, $options, $meta);
    }
    catch (\Throwable $e) {
      $this->logger->error('Voyage reranker unexpected error: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      $meta['fallback_reason'] = 'unexpected_error';
      return ['items' => $items, 'meta' => $meta];
    }
  }

  /**
   * Checks if reranking is enabled (config + API key present).
   *
   * @return bool
   *   TRUE if both config enabled and API key is set.
   */
  public function isEnabled(): bool {
    $config = $this->getConfig();
    if (empty($config['enabled'])) {
      return FALSE;
    }
    $key = Settings::get('ilas_voyage_api_key', '');
    return $key !== '' && $key !== FALSE;
  }

  /**
   * Internal reranking logic.
   */
  protected function doRerank(string $query, array $items, array $options, array &$meta): array {
    // Gate: disabled.
    if (!$this->isEnabled()) {
      $meta['fallback_reason'] = empty($this->getConfig()['enabled']) ? 'disabled' : 'no_api_key';
      return ['items' => $items, 'meta' => $meta];
    }

    $config = $this->getConfig();

    // Gate: insufficient results.
    $min_results = (int) ($config['min_results_to_rerank'] ?? 2);
    if (count($items) < $min_results) {
      $meta['fallback_reason'] = 'insufficient_results';
      return ['items' => $items, 'meta' => $meta];
    }

    // Gate: circuit breaker open.
    if (!$this->circuitAllowsRequest()) {
      $meta['fallback_reason'] = 'circuit_open';
      return ['items' => $items, 'meta' => $meta];
    }

    // Prepare documents for Voyage API.
    $max_candidates = (int) ($config['max_candidates'] ?? 20);
    $candidates = array_slice($items, 0, $max_candidates);
    $documents = array_map([$this, 'extractDocument'], $candidates);

    $meta['attempted'] = TRUE;
    $meta['input_count'] = count($documents);
    $meta['model'] = $options['model'] ?? $config['rerank_model'] ?? 'rerank-2';

    // Call Voyage API.
    $top_k = (int) ($options['top_k'] ?? $config['top_k'] ?? 5);
    $timeout = (float) ($config['api_timeout'] ?? 3.0);

    $start = microtime(TRUE);

    try {
      $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . Settings::get('ilas_voyage_api_key'),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $meta['model'],
          'query' => $query,
          'documents' => $documents,
          'top_k' => min($top_k, count($documents)),
        ],
        'timeout' => $timeout,
        'connect_timeout' => min($timeout, 2.0),
      ]);
    }
    catch (ConnectException $e) {
      $meta['latency_ms'] = round((microtime(TRUE) - $start) * 1000, 1);
      $meta['fallback_reason'] = 'timeout';
      $this->recordCircuitFailure();
      $this->logger->warning('Voyage reranker timeout: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return $this->fallbackOrThrow($config, $items, $meta);
    }
    catch (RequestException $e) {
      $meta['latency_ms'] = round((microtime(TRUE) - $start) * 1000, 1);
      $meta['fallback_reason'] = 'api_error';
      $this->recordCircuitFailure();
      $status_code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      $this->logger->warning('Voyage reranker API error (@code): @class @error_signature', [
        '@code' => $status_code,
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return $this->fallbackOrThrow($config, $items, $meta);
    }

    $meta['latency_ms'] = round((microtime(TRUE) - $start) * 1000, 1);

    // Parse response.
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body) || empty($body['data']) || !is_array($body['data'])) {
      $meta['fallback_reason'] = 'malformed_response';
      $this->recordCircuitFailure();
      $this->logger->warning('Voyage reranker malformed response.');
      return $this->fallbackOrThrow($config, $items, $meta);
    }

    // Success path.
    $this->recordCircuitSuccess();

    // Build reordered results.
    $original_order = array_map(fn($i) => $i, array_keys($candidates));
    $reranked_items = [];
    $new_order = [];

    foreach ($body['data'] as $entry) {
      if (!isset($entry['index']) || !isset($candidates[$entry['index']])) {
        continue;
      }
      $item = $candidates[$entry['index']];
      $item['voyage_score'] = $entry['relevance_score'] ?? NULL;
      $reranked_items[] = $item;
      $new_order[] = $entry['index'];
    }

    // If Voyage returned nothing usable, fall back.
    if (empty($reranked_items)) {
      $meta['fallback_reason'] = 'malformed_response';
      return ['items' => $items, 'meta' => $meta];
    }

    // Append any items beyond max_candidates that weren't sent to Voyage.
    $overflow = array_slice($items, $max_candidates);
    $reranked_items = array_merge($reranked_items, $overflow);

    // Populate telemetry.
    $meta['applied'] = TRUE;
    $meta['top_score'] = $reranked_items[0]['voyage_score'] ?? NULL;
    if (count($reranked_items) >= 2) {
      $meta['score_delta'] = ($reranked_items[0]['voyage_score'] ?? 0) - ($reranked_items[1]['voyage_score'] ?? 0);
    }
    $meta['order_changed'] = $new_order !== array_slice($original_order, 0, count($new_order));

    return ['items' => $reranked_items, 'meta' => $meta];
  }

  /**
   * Extracts a document string from a retrieval item for Voyage.
   *
   * @param array $item
   *   A retrieval result item.
   *
   * @return string
   *   Concatenated text for reranking.
   */
  public function extractDocument(array $item): string {
    // Title/question.
    $title = $item['question'] ?? $item['title'] ?? '';

    // Body/answer.
    $body = $item['answer_snippet'] ?? $item['answer'] ?? $item['description'] ?? '';

    $text = trim($title . ' ' . $body);

    if (mb_strlen($text) > self::MAX_DOCUMENT_LENGTH) {
      $text = mb_substr($text, 0, self::MAX_DOCUMENT_LENGTH);
    }

    return $text;
  }

  /**
   * Returns whether the circuit breaker allows a request.
   *
   * @return bool
   *   TRUE if closed or half-open (cooldown elapsed), FALSE if open.
   */
  protected function circuitAllowsRequest(): bool {
    $data = $this->getCircuitState();

    if ($data['state'] === 'closed') {
      return TRUE;
    }

    if ($data['state'] === 'open') {
      $cb_config = $this->getConfig()['circuit_breaker'] ?? [];
      $cooldown = (int) ($cb_config['cooldown_seconds'] ?? 300);
      if ((time() - $data['opened_at']) >= $cooldown) {
        // Transition to half-open: allow one probe.
        $data['state'] = 'half_open';
        $this->setCircuitState($data);
        return TRUE;
      }
      return FALSE;
    }

    // half_open: allow probe request.
    return TRUE;
  }

  /**
   * Records a circuit breaker success.
   */
  protected function recordCircuitSuccess(): void {
    $data = $this->getCircuitState();
    // Any success resets to closed.
    if ($data['state'] !== 'closed') {
      $this->logger->info('Voyage circuit breaker closed after successful probe.');
    }
    $this->setCircuitState($this->defaultCircuitState());
  }

  /**
   * Records a circuit breaker failure.
   */
  protected function recordCircuitFailure(): void {
    $data = $this->getCircuitState();
    $cb_config = $this->getConfig()['circuit_breaker'] ?? [];
    $threshold = (int) ($cb_config['failure_threshold'] ?? 3);

    if ($data['state'] === 'half_open') {
      // Half-open probe failed: reopen.
      $data['state'] = 'open';
      $data['opened_at'] = time();
      $data['consecutive_failures']++;
      $this->setCircuitState($data);
      $this->logger->warning('Voyage circuit breaker re-opened after half-open probe failure.');
      return;
    }

    $data['consecutive_failures']++;
    $data['last_failure_time'] = time();

    if ($data['consecutive_failures'] >= $threshold) {
      $data['state'] = 'open';
      $data['opened_at'] = time();
      $this->logger->warning('Voyage circuit breaker opened after @count consecutive failures.', [
        '@count' => $data['consecutive_failures'],
      ]);
    }

    $this->setCircuitState($data);
  }

  /**
   * Returns the current circuit breaker state.
   */
  public function getCircuitState(): array {
    $data = $this->state->get(self::CIRCUIT_STATE_KEY);
    if (!is_array($data) || !isset($data['state'])) {
      return $this->defaultCircuitState();
    }
    return $data;
  }

  /**
   * Persists circuit breaker state.
   */
  protected function setCircuitState(array $data): void {
    $this->state->set(self::CIRCUIT_STATE_KEY, $data);
  }

  /**
   * Returns the default (closed) circuit breaker state.
   */
  protected function defaultCircuitState(): array {
    return [
      'state' => 'closed',
      'consecutive_failures' => 0,
      'last_failure_time' => 0,
      'opened_at' => 0,
    ];
  }

  /**
   * Returns default reranking meta.
   */
  protected function defaultMeta(): array {
    return [
      'attempted' => FALSE,
      'applied' => FALSE,
      'model' => NULL,
      'latency_ms' => NULL,
      'fallback_reason' => NULL,
      'top_score' => NULL,
      'score_delta' => NULL,
      'input_count' => 0,
      'order_changed' => FALSE,
    ];
  }

  /**
   * Gets the voyage config section.
   */
  protected function getConfig(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    return $config->get('voyage') ?? [];
  }

  /**
   * Returns fallback or throws based on fallback_on_error config.
   */
  protected function fallbackOrThrow(array $config, array $items, array $meta): array {
    if (($config['fallback_on_error'] ?? TRUE) === FALSE) {
      throw new \RuntimeException('Voyage reranker failed: ' . ($meta['fallback_reason'] ?? 'unknown'));
    }
    return ['items' => $items, 'meta' => $meta];
  }

}
