<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Interface for second-stage semantic reranking of retrieval results.
 */
interface RerankerInterface {

  /**
   * Reranks retrieval results by semantic relevance to the query.
   *
   * @param string $query
   *   The user query.
   * @param array $items
   *   The retrieval results to rerank.
   * @param array $options
   *   Optional overrides (e.g., top_k, model).
   *
   * @return array
   *   Associative array with:
   *   - 'items': The (possibly reordered) result items.
   *   - 'meta': Telemetry metadata (attempted, applied, model, latency_ms,
   *     fallback_reason, top_score, score_delta, input_count, order_changed).
   */
  public function rerank(string $query, array $items, array $options = []): array;

}
