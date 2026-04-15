<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Narrow transport contract for request-time structured LLM calls.
 */
interface RequestTimeLlmTransportInterface {

  /**
   * Returns the request-time provider identifier.
   */
  public function getProviderId(): string;

  /**
   * Returns the request-time model identifier.
   */
  public function getModelId(): string;

  /**
   * Returns TRUE when the transport is configured for runtime use.
   */
  public function isConfigured(): bool;

  /**
   * Executes a structured JSON completion request.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized chat messages.
   * @param array<string, mixed> $schema
   *   JSON schema for the response object.
   * @param array<string, mixed> $options
   *   Provider-neutral request options.
   *
   * @return array{
   *   payload: array<string, mixed>,
   *   usage?: array<string, int>
   * }
   *   Structured payload and optional token usage.
   */
  public function completeStructuredJson(array $messages, array $schema, array $options = []): array;

}
