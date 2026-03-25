<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Exception;

/**
 * Signals that a required retrieval dependency is unavailable at runtime.
 */
final class RetrievalDependencyUnavailableException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $service
   *   Retrieval service name, such as faq or resource.
   * @param string $reason_code
   *   Stable machine-readable reason code.
   * @param array<string, mixed> $context
   *   Safe context describing the missing dependency.
   * @param \Throwable|null $previous
   *   Optional previous exception.
   */
  public function __construct(
    private readonly string $service,
    private readonly string $reason_code,
    private readonly array $context = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($this->buildMessage($service, $reason_code, $context), 0, $previous);
  }

  /**
   * Returns the retrieval service name.
   */
  public function getService(): string {
    return $this->service;
  }

  /**
   * Returns the stable machine-readable reason code.
   */
  public function getReasonCode(): string {
    return $this->reason_code;
  }

  /**
   * Returns safe dependency context.
   *
   * @return array<string, mixed>
   *   Safe exception context.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Builds a stable exception message.
   *
   * @param string $service
   *   Retrieval service name.
   * @param string $reason_code
   *   Stable machine-readable reason code.
   * @param array<string, mixed> $context
   *   Safe exception context.
   */
  private function buildMessage(string $service, string $reason_code, array $context): string {
    $dependency = (string) ($context['dependency_key'] ?? 'unknown_dependency');
    $failure = (string) ($context['failure_code'] ?? 'unknown_failure');
    return sprintf('%s retrieval dependency unavailable (%s: %s, %s).', $service, $reason_code, $dependency, $failure);
  }

}
