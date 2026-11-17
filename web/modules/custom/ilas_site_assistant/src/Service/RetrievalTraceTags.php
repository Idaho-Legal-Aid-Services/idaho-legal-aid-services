<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Summarizes one request's retrieval operations as Sentry transaction tags.
 *
 * Input is the per-search telemetry FaqIndex and ResourceFinder already
 * record (service, vector status, salted query hash). Output is counts and
 * yes/no values only, so slow requests can be grouped by what they did.
 */
final class RetrievalTraceTags {

  public const TAG_VECTOR_SEARCHES = 'vector_searches';
  public const TAG_QUERY_IDENTICAL = 'faq_resource_query_identical';
  public const TAG_VECTOR_FALLBACK = 'vector_fallback';
  public const TAG_VECTOR_FALLBACK_REASON = 'vector_fallback_reason';
  public const TAG_VECTOR_BACKOFF = 'vector_backoff';
  public const TAG_BACKOFF_ACTIVATIONS = 'vector_backoff_activations';

  /**
   * Every tag this class can emit.
   */
  public const TAGS = [
    self::TAG_VECTOR_SEARCHES,
    self::TAG_QUERY_IDENTICAL,
    self::TAG_VECTOR_FALLBACK,
    self::TAG_VECTOR_FALLBACK_REASON,
    self::TAG_VECTOR_BACKOFF,
    self::TAG_BACKOFF_ACTIVATIONS,
  ];

  /**
   * Builds the tag set for a request.
   *
   * @param array $operations
   *   Retrieval telemetry rows from drainRetrievalTelemetry().
   *
   * @return array<string, string>
   *   Tag values:
   *   - vector_searches: vector searches attempted (cache hits and searches
   *     skipped by an active backoff are not counted).
   *   - faq_resource_query_identical: `yes` when the FAQ and the resource
   *     vector search embedded the same text, `no` when they differed, `na`
   *     unless both ran.
   *   - vector_fallback: `yes` when a vector search failed or ran over
   *     budget and lexical results were used. The telemetry does not say
   *     whether the embedding call or the vector store failed.
   *   - vector_fallback_reason: the degraded reason code, when there is one.
   *   - vector_backoff: which services skipped vector search because a
   *     backoff was already active (none, faq, resource, both).
   *   - vector_backoff_activations: failures in this request that started a
   *     backoff.
   */
  public static function fromOperations(array $operations): array {
    $attempted = 0;
    $activations = 0;
    $fallback_reason = NULL;
    $hashes = ['faq' => [], 'resource' => []];
    $backoff = ['faq' => FALSE, 'resource' => FALSE];

    foreach ($operations as $operation) {
      if (!is_array($operation)) {
        continue;
      }
      $service = (string) ($operation['service'] ?? '');
      $status = (string) ($operation['vector_status'] ?? '');

      if ($status === 'backoff' && isset($backoff[$service])) {
        $backoff[$service] = TRUE;
      }
      if (empty($operation['vector_attempted'])) {
        continue;
      }

      $attempted++;
      $hash = $operation['query_hash'] ?? NULL;
      if (isset($hashes[$service]) && is_string($hash) && $hash !== '') {
        $hashes[$service][$hash] = TRUE;
      }
      // Every degraded outcome of an attempted search starts a backoff.
      if ($status === 'degraded') {
        $activations++;
        $reason = $operation['degraded_reason'] ?? NULL;
        $fallback_reason ??= is_string($reason) && $reason !== '' ? $reason : 'degraded';
      }
    }

    $identical = 'na';
    if ($hashes['faq'] !== [] && $hashes['resource'] !== []) {
      $identical = array_diff_key($hashes['resource'], $hashes['faq']) === [] ? 'yes' : 'no';
    }

    $tags = [
      self::TAG_VECTOR_SEARCHES => (string) $attempted,
      self::TAG_QUERY_IDENTICAL => $identical,
      self::TAG_VECTOR_FALLBACK => $activations > 0 ? 'yes' : 'no',
      self::TAG_VECTOR_BACKOFF => match (TRUE) {
        $backoff['faq'] && $backoff['resource'] => 'both',
        $backoff['faq'] => 'faq',
        $backoff['resource'] => 'resource',
        default => 'none',
      },
      self::TAG_BACKOFF_ACTIVATIONS => (string) $activations,
    ];
    if ($fallback_reason !== NULL) {
      $tags[self::TAG_VECTOR_FALLBACK_REASON] = $fallback_reason;
    }

    return $tags;
  }

}
