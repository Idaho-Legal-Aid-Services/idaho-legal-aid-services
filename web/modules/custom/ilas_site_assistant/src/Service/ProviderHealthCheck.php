<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Site\Settings;

/**
 * Composite live readiness check for Cohere, Voyage, and Pinecone.
 *
 * Real network calls. Output is sanitized — never echoes raw API keys.
 * Used by the ilas:providers-health drush command.
 */
final class ProviderHealthCheck {

  public function __construct(
    private readonly CohereGenerationProbe $cohereProbe,
    private readonly VoyageReranker $voyageReranker,
    private readonly VectorIndexHygieneService $vectorIndexHygiene,
  ) {}

  /**
   * Runs all three provider checks and returns a sanitized report.
   *
   * @return array<string, mixed>
   */
  public function run(): array {
    $report = [
      'ok' => FALSE,
      'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
      'cohere' => $this->checkCohere(),
      'voyage' => $this->checkVoyage(),
      'pinecone' => $this->checkPinecone(),
      'grounded_generation' => $this->checkGroundedGeneration(),
    ];

    $report['ok'] = ($report['cohere']['ok'] ?? FALSE)
      && ($report['voyage']['ok'] ?? FALSE)
      && ($report['pinecone']['ok'] ?? FALSE);

    return $report;
  }

  /**
   * @return array<string, mixed>
   */
  private function checkCohere(): array {
    $probe = $this->cohereProbe->probe();
    $rawKey = Settings::get('ilas_cohere_api_key', '');

    return [
      'ok' => (bool) ($probe['generation_probe_passed'] ?? FALSE),
      'enabled' => (bool) ($probe['enabled'] ?? FALSE),
      'provider_is_cohere' => (bool) ($probe['provider_is_cohere'] ?? FALSE),
      'model' => $probe['model'] ?? NULL,
      'reachable' => (bool) ($probe['reachable'] ?? FALSE),
      'generation_probe_passed' => (bool) ($probe['generation_probe_passed'] ?? FALSE),
      'latency_ms' => $probe['latency_ms'] ?? NULL,
      'key_present' => $this->keyPresent($rawKey),
      'key_fingerprint' => $this->keyFingerprint($rawKey),
      'reason' => $probe['reason'] ?? NULL,
      'last_error' => $this->sanitizeError($probe['last_error'] ?? NULL),
    ];
  }

  /**
   * Grounded-generation readiness (informational; not part of `ok`).
   *
   * Runs the grounded probe only when generation is runtime-ready so a dark
   * deployment never spends a provider call.
   *
   * @return array<string, mixed>
   */
  private function checkGroundedGeneration(): array {
    $summary = $this->cohereProbe->getGroundedReadinessSummary();
    $result = [
      'ok' => NULL,
      'generation_enabled' => (bool) ($summary['generation_enabled'] ?? FALSE),
      'generation_runtime_ready' => (bool) ($summary['generation_runtime_ready'] ?? FALSE),
      'grounded_probe_passed' => FALSE,
      'citation_count' => 0,
      'latency_ms' => NULL,
      'reason' => 'generation_disabled',
      'last_error' => NULL,
    ];
    if (!$result['generation_runtime_ready']) {
      return $result;
    }
    $probe = $this->cohereProbe->probeGrounded();
    $result['ok'] = (bool) ($probe['grounded_probe_passed'] ?? FALSE);
    $result['grounded_probe_passed'] = $result['ok'];
    $result['citation_count'] = (int) ($probe['citation_count'] ?? 0);
    $result['latency_ms'] = $probe['latency_ms'] ?? NULL;
    $result['reason'] = $probe['reason'] ?? NULL;
    $result['last_error'] = $this->sanitizeError($probe['last_error'] ?? NULL);
    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private function checkVoyage(): array {
    $summary = $this->voyageReranker->getRuntimeSummary();
    $rawKey = Settings::get('ilas_voyage_api_key', '');

    $result = [
      'ok' => FALSE,
      'enabled' => (bool) ($summary['enabled'] ?? FALSE),
      'model' => $summary['rerank_model'] ?? NULL,
      'key_present' => $this->keyPresent($rawKey),
      'key_fingerprint' => $this->keyFingerprint($rawKey),
      'circuit_state' => $summary['circuit_breaker']['state'] ?? NULL,
    ];

    if (!($summary['runtime_ready'] ?? FALSE)) {
      $result['reason'] = empty($summary['enabled']) ? 'disabled' : 'no_api_key';
      $result['reachable'] = FALSE;
      return $result;
    }

    // Force a real Voyage rerank call with two trivial documents — bypasses
    // the min_results_to_rerank gate by sending exactly the configured min.
    $items = [
      ['title' => 'Health check document A', 'description' => 'first sentinel item.'],
      ['title' => 'Health check document B', 'description' => 'second sentinel item.'],
    ];
    $start = microtime(TRUE);
    $rerank = $this->voyageReranker->rerank('provider health check', $items, ['top_k' => 2]);
    $latencyMs = round((microtime(TRUE) - $start) * 1000, 1);

    $meta = $rerank['meta'] ?? [];
    $applied = (bool) ($meta['applied'] ?? FALSE);
    $fallback = $meta['fallback_reason'] ?? NULL;

    $result['reachable'] = $applied;
    $result['ok'] = $applied;
    $result['rerank_returned_n'] = is_array($rerank['items'] ?? NULL) ? count($rerank['items']) : 0;
    $result['latency_ms'] = $latencyMs;
    $result['fallback_reason'] = $fallback;
    $result['top_score'] = $meta['top_score'] ?? NULL;

    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private function checkPinecone(): array {
    $result = [
      'ok' => FALSE,
      'indexes' => [],
    ];

    try {
      $snapshot = $this->vectorIndexHygiene->refreshSnapshot(TRUE, NULL);
    }
    catch (\Throwable $e) {
      $result['error'] = $this->sanitizeError($e->getMessage());
      return $result;
    }

    $allReachable = TRUE;
    $sawAny = FALSE;
    foreach (($snapshot['indexes'] ?? []) as $managedKey => $indexSnapshot) {
      if (!is_array($indexSnapshot)) {
        continue;
      }
      $sawAny = TRUE;
      $expected = is_array($indexSnapshot['expected'] ?? NULL) ? $indexSnapshot['expected'] : [];
      $observed = is_array($indexSnapshot['observed'] ?? NULL) ? $indexSnapshot['observed'] : [];

      $expectedDim = $expected['dimensions'] ?? ($indexSnapshot['expected_dimensions'] ?? NULL);
      $actualDim = $observed['dimensions'] ?? NULL;
      $probeStatus = $indexSnapshot['probe_status'] ?? 'unknown';
      $hygieneStatus = $indexSnapshot['status'] ?? 'unknown';
      $reachable = $probeStatus === 'pass' && $hygieneStatus !== 'critical';

      if (!$reachable) {
        $allReachable = FALSE;
      }

      $result['indexes'][] = [
        'key' => $managedKey,
        'name' => $indexSnapshot['index_id'] ?? NULL,
        'reachable' => $reachable,
        'dimension' => $actualDim,
        'expected_dimension' => $expectedDim,
        'dimension_matches' => $expectedDim !== NULL && $actualDim !== NULL && (int) $expectedDim === (int) $actualDim,
        'embeddings_engine' => $observed['embeddings_engine'] ?? ($expected['embeddings_engine'] ?? NULL),
        'expected_embeddings_engine' => $expected['embeddings_engine'] ?? NULL,
        'probe_status' => $probeStatus,
        'hygiene_status' => $hygieneStatus,
        'last_probe_at' => $indexSnapshot['last_probe_at'] ?? NULL,
        'probe_error' => $this->sanitizeError($indexSnapshot['probe_error'] ?? NULL),
      ];
    }

    $result['ok'] = $sawAny && $allReachable;
    $result['overall_status'] = $snapshot['status'] ?? 'unknown';

    return $result;
  }

  /**
   * Returns TRUE when the API-key value is non-empty (string or boolean cast).
   *
   * @return bool
   */
  private function keyPresent(mixed $key): bool {
    if (is_string($key)) {
      return trim($key) !== '';
    }
    return (bool) $key;
  }

  /**
   * Returns the first 8 hex chars of sha256(key) so operators can confirm the
   * right key loaded without ever seeing the secret. Empty string when absent.
   */
  private function keyFingerprint(mixed $key): string {
    if (!is_string($key) || $key === '') {
      return '';
    }
    return substr(hash('sha256', $key), 0, 8);
  }

  /**
   * Redacts bearer tokens and 32+ char opaque strings out of provider error
   * messages before logging or returning them to the operator dashboard.
   *
   * @return mixed
   */
  private function sanitizeError(mixed $error): mixed {
    if (!is_string($error) || $error === '') {
      return $error;
    }
    $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9\-_.]+/i', 'Bearer [REDACTED]', $error) ?? $error;
    $sanitized = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[REDACTED]', $sanitized) ?? $sanitized;
    return $sanitized;
  }

}
