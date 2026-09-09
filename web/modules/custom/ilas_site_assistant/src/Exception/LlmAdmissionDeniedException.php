<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Exception;

/**
 * Signals that a request-time LLM call was denied by a local admission gate.
 *
 * This is a client-side policy decision (per-IP, daily or monthly budget,
 * global rate limit, manual kill switch, circuit-breaker cooldown, sampling
 * gate, or admission-lock timeout). It is never evidence of an upstream
 * provider failure and MUST NOT be fed to LlmCircuitBreaker::recordFailure().
 */
final class LlmAdmissionDeniedException extends \RuntimeException {

  /**
   * Fallback-reason prefix used in LlmEnhancer request metadata.
   */
  public const FALLBACK_REASON_PREFIX = 'budget_';

  /**
   * Constructs the exception.
   *
   * @param string $reason
   *   Stable admission reason from LlmAdmissionCoordinator::evaluateRequest()
   *   (for example per_ip_budget_exceeded or circuit_breaker_open).
   * @param \Throwable|null $previous
   *   Optional previous exception.
   */
  public function __construct(
    private readonly string $reason,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct(sprintf('Request-time LLM admission denied (%s).', $reason), 0, $previous);
  }

  /**
   * Returns the stable admission reason.
   */
  public function getReason(): string {
    return $this->reason;
  }

  /**
   * Returns the fallback reason as surfaced in meta.generation.reason.
   */
  public function getFallbackReason(): string {
    return self::FALLBACK_REASON_PREFIX . $this->reason;
  }

}
