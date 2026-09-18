<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Transport contract for grounded (document-cited) answer generation.
 *
 * Implementations send retrieved site documents to the provider and return
 * plain text plus provider-native citations. They never return provider
 * secrets and never throw on empty citations; transport/HTTP failures
 * propagate as exceptions so the caller can record breaker state.
 */
interface GroundedGenerationTransportInterface {

  /**
   * Generates a grounded answer from provider-normalized messages + documents.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized chat messages (system + user).
   * @param array<int, array{id: string, data: array<string, string>}> $documents
   *   Documents the answer must be grounded in.
   * @param array<string, mixed> $options
   *   Provider-neutral options: model, max_tokens, temperature, safety_mode,
   *   citation_mode, timeout, connect_timeout.
   *
   * @return array{
   *   text: string,
   *   citations: array<int, array{start: int, end: int, text: string, document_ids: array<int, string>}>,
   *   finish_reason: string|null,
   *   usage: array<string, int>
   *   }
   *   Generated text, normalized citations, finish reason, and token usage.
   */
  public function completeGrounded(array $messages, array $documents, array $options = []): array;

}
