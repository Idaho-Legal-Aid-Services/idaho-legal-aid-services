<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker for LLM API calls.
 *
 * Stops calling the LLM API after detecting sustained failures, immediately
 * returning to the rule-based fallback path. After a cooldown period, allows
 * a single probe request through (half-open state) to test recovery.
 *
 * State machine:
 *   closed  --[failures >= threshold]--> open
 *   open    --[cooldown elapsed]-------> half_open
 *   half_open --[success]--------------> closed
 *   half_open --[failure]--------------> open
 */
class LlmCircuitBreaker {

  /**
   * State API key for circuit breaker data.
   */
  const STATE_KEY = 'ilas_site_assistant.llm_circuit_breaker';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Shared admission/state coordinator.
   *
   * @var \Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator
   */
  protected LlmAdmissionCoordinator $admissionCoordinator;

  /**
   * Constructs an LlmCircuitBreaker.
   */
  public function __construct(
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    LlmAdmissionCoordinator $admission_coordinator,
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->admissionCoordinator = $admission_coordinator;
  }

  /**
   * Checks if the circuit allows a request through.
   *
   * This compatibility method is read-only and does not reserve a half-open
   * probe slot. Production callers should use tryAcquireAdmission().
   *
   * @return bool
   *   TRUE if a request is allowed (closed or half-open), FALSE if open.
   */
  public function isAvailable(): bool {
    $data = $this->getState();

    if ($data['state'] === 'closed') {
      return TRUE;
    }

    if ($data['state'] === 'open') {
      $cooldown = $this->getConfig('cooldown_seconds');
      return (time() - $data['opened_at']) >= $cooldown;
    }

    // half_open: allow the probe request through.
    return TRUE;
  }

  /**
   * Atomically acquires circuit-breaker admission.
   *
   * @return bool
   *   TRUE when the request may proceed, FALSE otherwise.
   */
  public function tryAcquireAdmission(): bool {
    return $this->admissionCoordinator->tryAcquireCircuitAdmission();
  }

  /**
   * Records a successful API call.
   */
  public function recordSuccess(): void {
    $this->admissionCoordinator->recordCircuitSuccess();
  }

  /**
   * Records a failed upstream API call (after all retries are exhausted).
   *
   * Contract: feed this ONLY genuine transport/provider failures (Guzzle
   * exceptions, malformed or non-array payloads, retries exhausted). Local
   * admission denials from CostControlPolicy::beginRequest() (budget, rate
   * limit, kill switch, breaker cooldown, sampling) are signalled with
   * \Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException and
   * must never be recorded here; doing so lets one client's budget
   * exhaustion open the site-wide breaker for everyone.
   */
  public function recordFailure(): void {
    $this->admissionCoordinator->recordCircuitFailure();
  }

  /**
   * Returns the current circuit breaker state.
   *
   * @return array
   *   State array with keys: state, consecutive_failures, last_failure_time,
   *   opened_at.
   */
  public function getState(): array {
    $data = $this->state->get(self::STATE_KEY);
    if (!is_array($data) || !isset($data['state'])) {
      return $this->defaultState();
    }
    return $data;
  }

  /**
   * Force-resets the circuit breaker to closed state.
   */
  public function reset(): void {
    $this->admissionCoordinator->resetCircuitBreaker();
    $this->logger->info('LLM circuit breaker manually reset to closed.');
  }

  /**
   * Returns the default (closed) state.
   *
   * @return array
   *   Default state array.
   */
  protected function defaultState(): array {
    return [
      'state' => 'closed',
      'consecutive_failures' => 0,
      'last_failure_time' => 0,
      'opened_at' => 0,
    ];
  }

  /**
   * Persists circuit breaker state.
   *
   * @param array $data
   *   The state data to persist.
   */
  protected function setState(array $data): void {
    $this->state->set(self::STATE_KEY, $data);
  }

  /**
   * Gets a circuit breaker config value.
   *
   * @param string $key
   *   The config key under llm.circuit_breaker.
   *
   * @return int
   *   The config value.
   */
  protected function getConfig(string $key): int {
    $defaults = [
      'failure_threshold' => 3,
      'failure_window_seconds' => 60,
      'cooldown_seconds' => 300,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('llm.circuit_breaker.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

}
