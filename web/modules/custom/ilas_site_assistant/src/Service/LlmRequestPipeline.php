<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Shared admission -> cache -> retry -> breaker pipeline for LLM requests.
 *
 * Extracted from LlmEnhancer so intent classification and grounded answer
 * generation share byte-identical guard semantics: cost-control admission
 * (kill switch, breaker, global/daily/monthly/per-IP budgets, sampling),
 * response caching, bounded retries on transient HTTP failures, and
 * breaker success recording. Breaker FAILURE recording stays with the
 * caller, which knows whether a throwable is a provider fault.
 *
 * The budget counters are reserved at admission time by
 * LlmAdmissionCoordinator::beginRequest(); on success this pipeline records
 * token usage and estimated cost only, so a call is counted exactly once.
 */
final class LlmRequestPipeline {

  public const OPTION_CACHE_TTL = 'cache_ttl';
  public const OPTION_MAX_RETRIES = 'max_retries';
  public const OPTION_USER_IDENTIFIER = 'user_identifier';

  private const MAX_SYNC_RETRY_DELAY_MS = 250;

  /**
   * Last request proof snapshot (no prompts, no secrets).
   *
   * @var array<string, mixed>
   */
  private array $lastMeta = [];

  /**
   * Optional sleep override for tests.
   *
   * @var (\Closure(int): void)|null
   */
  private ?\Closure $sleeper;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?CacheBackendInterface $cache = NULL,
    private readonly ?LlmCircuitBreaker $circuitBreaker = NULL,
    private readonly ?CostControlPolicy $costControlPolicy = NULL,
    ?\Closure $sleeper = NULL,
  ) {
    $this->sleeper = $sleeper;
  }

  /**
   * Executes one guarded LLM request.
   *
   * @param string $operation
   *   Stable operation label (intent_classification | grounded_answer).
   * @param string $cacheKey
   *   Fully built cache id for the request fingerprint.
   * @param callable $dispatch
   *   Zero-argument callable performing the transport call. It must return
   *   ['payload' => mixed, 'usage' => array<string,int>|null] and throw on
   *   any transport or validation failure.
   * @param array<string, mixed> $options
   *   cache_ttl (int, default llm.cache_ttl), max_retries (int, default
   *   llm.max_retries), user_identifier (string|null), and
   *   LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT (bool).
   *
   * @return array{payload: mixed, meta: array<string, mixed>}
   *   The payload (fresh or cached) and the request proof snapshot.
   *
   * @throws \Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException
   *   When a local admission gate denies the request (never a provider fault).
   * @throws \Throwable
   *   Transport failures after retries are exhausted.
   */
  public function execute(string $operation, string $cacheKey, callable $dispatch, array $options = []): array {
    $cache_ttl = array_key_exists(self::OPTION_CACHE_TTL, $options)
      ? max(0, (int) $options[self::OPTION_CACHE_TTL])
      : max(0, (int) $this->getLlmSetting('cache_ttl', 3600));
    $max_retries = array_key_exists(self::OPTION_MAX_RETRIES, $options)
      ? max(0, (int) $options[self::OPTION_MAX_RETRIES])
      : max(0, (int) $this->getLlmSetting('max_retries', 1));
    $budget_key = self::buildBudgetKey($options[self::OPTION_USER_IDENTIFIER] ?? NULL);

    $this->lastMeta = [
      'operation' => $operation,
      'attempted' => TRUE,
      'cache_hit' => FALSE,
      'transport_attempted' => FALSE,
      'success' => FALSE,
    ];

    if ($this->costControlPolicy) {
      $policy_result = $this->costControlPolicy->beginRequest($budget_key, [
        LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT => !empty($options[LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT]),
        CostControlPolicy::OPTION_OPERATION => $operation,
      ]);
      if (!is_array($policy_result) || empty($policy_result['allowed'])) {
        $reason = is_array($policy_result) ? (string) ($policy_result['reason'] ?? 'unknown') : 'unknown';
        // Local admission decision, not a provider failure. Callers catch
        // this type ahead of \Throwable so it never reaches the breaker.
        $exception = new LlmAdmissionDeniedException($reason);
        $this->lastMeta += [];
        $this->lastMeta['success'] = FALSE;
        $this->lastMeta['transport_attempted'] = FALSE;
        $this->lastMeta['fallback_reason'] = $exception->getFallbackReason();
        $this->lastMeta['admission_reason'] = $reason;
        throw $exception;
      }
    }

    if ($this->cache && $cache_ttl > 0) {
      $cached = $this->cache->get($cacheKey);
      if ($cached && is_array($cached->data)) {
        if ($this->costControlPolicy) {
          $this->costControlPolicy->recordCacheHit();
        }
        $this->lastMeta['cache_hit'] = TRUE;
        $this->lastMeta['transport_attempted'] = FALSE;
        $this->lastMeta['success'] = TRUE;
        return ['payload' => $cached->data, 'meta' => $this->lastMeta];
      }
    }

    if ($this->costControlPolicy) {
      $this->costControlPolicy->recordCacheMiss();
    }

    $attempt = 0;
    do {
      try {
        $this->lastMeta['transport_attempted'] = TRUE;
        $this->lastMeta['attempt_number'] = $attempt + 1;
        $result = $dispatch();
        if (!is_array($result) || !array_key_exists('payload', $result)) {
          throw new \RuntimeException('Request-time LLM dispatch returned no payload.');
        }
        $payload = $result['payload'];
        $usage = $result['usage'] ?? NULL;
        $usage = is_array($usage) ? $usage : NULL;

        if ($this->costControlPolicy) {
          $this->costControlPolicy->recordUsage($usage, $operation);
        }

        if ($this->cache && $cache_ttl > 0 && is_array($payload)) {
          $this->cache->set($cacheKey, $payload, time() + $cache_ttl);
        }

        if ($this->circuitBreaker) {
          $this->circuitBreaker->recordSuccess();
        }

        $this->lastMeta['success'] = TRUE;
        $this->lastMeta['usage'] = $usage ?? [];

        return ['payload' => $payload, 'meta' => $this->lastMeta];
      }
      catch (RequestException $e) {
        $attempt++;
        $status_code = $e->getResponse()?->getStatusCode();
        $this->lastMeta['success'] = FALSE;
        $this->lastMeta['http_status'] = $status_code;
        $this->lastMeta['error_class'] = get_class($e);
        $this->lastMeta['error_signature'] = ObservabilityPayloadMinimizer::exceptionSignature($e);
        if ($attempt > $max_retries || !self::isRetryableStatusCode($status_code)) {
          throw $e;
        }
        $this->sleepMilliseconds(self::backoffDelayMs($attempt));
      }
      catch (GuzzleException $e) {
        $attempt++;
        $this->lastMeta['success'] = FALSE;
        $this->lastMeta['error_class'] = get_class($e);
        $this->lastMeta['error_signature'] = ObservabilityPayloadMinimizer::exceptionSignature($e);
        if ($attempt > $max_retries) {
          throw $e;
        }
        $this->sleepMilliseconds(self::backoffDelayMs($attempt));
      }
    } while ($attempt <= $max_retries);

    throw new \RuntimeException('Request-time LLM request failed after retries.');
  }

  /**
   * Returns the proof snapshot of the most recent execute() call.
   *
   * @return array<string, mixed>
   *   Safe metadata (no prompts, no secrets).
   */
  public function getLastMeta(): array {
    return $this->lastMeta;
  }

  /**
   * Builds a stable budget identity from a raw user identifier.
   */
  public static function buildBudgetKey(?string $userIdentifier): string {
    $identifier = trim((string) $userIdentifier);
    if ($identifier === '') {
      return 'global';
    }
    return hash('sha256', $identifier);
  }

  /**
   * Returns TRUE when the status code is retryable.
   */
  public static function isRetryableStatusCode(?int $statusCode): bool {
    return in_array($statusCode, [429, 500, 502, 503, 504], TRUE);
  }

  /**
   * Returns the retry backoff delay in milliseconds.
   */
  public static function backoffDelayMs(int $attempt): int {
    return min(self::MAX_SYNC_RETRY_DELAY_MS, 50 * (2 ** max(0, $attempt - 1)));
  }

  /**
   * Sleep wrapper (overridable through the constructor closure for tests).
   */
  private function sleepMilliseconds(int $delayMs): void {
    if ($this->sleeper !== NULL) {
      ($this->sleeper)($delayMs);
      return;
    }
    if ($delayMs > 0) {
      usleep($delayMs * 1000);
    }
  }

  /**
   * Returns one request-time LLM config value.
   */
  private function getLlmSetting(string $key, mixed $default = NULL): mixed {
    $value = $this->configFactory->get('ilas_site_assistant.settings')->get('llm.' . $key);
    return $value ?? $default;
  }

}
