<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Bounded request-time LLM orchestration for ambiguous-intent recovery.
 */
class LlmEnhancer {

  private const POLICY_VERSION = '2.0';
  private const MAX_SYNC_RETRY_DELAY_MS = 250;
  private const VALID_INTENTS = [
    'eligibility',
    'faq',
    'forms',
    'guides',
    'resources',
    'apply',
    'hotline',
    'offices',
    'services',
    'services_inventory',
    'forms_inventory',
    'guides_inventory',
    'risk_detector',
    'donate',
    'feedback',
    'greeting',
    'thanks',
    'unknown',
    'clarify',
  ];

  /**
   * Intent-classification system prompt.
   */
  private const INTENT_SYSTEM_PROMPT = <<<PROMPT
You classify incoming messages for a public legal aid assistant.

Return exactly one canonical intent label from this list:
- eligibility
- faq
- forms
- guides
- resources
- apply
- hotline
- offices
- services
- services_inventory
- forms_inventory
- guides_inventory
- risk_detector
- donate
- feedback
- greeting
- thanks
- clarify
- unknown

Rules:
- Choose the narrowest supported assistant intent when the request clearly fits.
- Use `clarify` when the user is asking a legal-help question but the message is too vague to route confidently.
- Use `unknown` only when the message is not understandable enough to classify.
- Do not invent labels.
- Do not provide prose outside the required JSON object.
PROMPT;

  /**
   * Constructor.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    protected ?PolicyFilter $policyFilter = NULL,
    protected ?CacheBackendInterface $cache = NULL,
    protected ?LlmCircuitBreaker $circuitBreaker = NULL,
    protected ?LlmRateLimiter $rateLimiter = NULL,
    protected ?CostControlPolicy $costControlPolicy = NULL,
    protected ?EnvironmentDetector $environmentDetector = NULL,
    protected ?RequestTimeLlmTransportInterface $transport = NULL,
  ) {
    $this->logger = $loggerFactory->get('ilas_site_assistant');
    $this->transport ??= new CohereLlmTransport($httpClient);
    $this->environmentDetector ??= new EnvironmentDetector();
  }

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Last normalized usage snapshot.
   *
   * @var array<string, int>|null
   */
  protected ?array $lastUsage = NULL;

  /**
   * Returns TRUE when request-time LLM is enabled and runtime-ready.
   */
  public function isEnabled(): bool {
    return $this->getLlmSetting('enabled', FALSE) && $this->transport->isConfigured();
  }

  /**
   * Returns the configured provider identifier.
   */
  public function getProviderId(): string {
    return $this->transport->getProviderId();
  }

  /**
   * Returns the active request-time model identifier.
   */
  public function getModelId(): string {
    return $this->transport->getModelId();
  }

  /**
   * Classifies an ambiguous request into a canonical deterministic intent.
   */
  public function classifyIntent(string $query, string $currentIntent = 'unknown', ?string $userIdentifier = NULL): string {
    if (!$this->isEnabled()) {
      return $currentIntent;
    }

    if ($currentIntent !== 'unknown') {
      return $currentIntent;
    }

    $normalized_query = InputNormalizer::normalize($query);
    if ($normalized_query === '') {
      return 'unknown';
    }

    $sanitized_query = $this->policyFilter
      ? $this->policyFilter->sanitizeForLlmPrompt($normalized_query)
      : $normalized_query;
    if ($sanitized_query === '') {
      return $currentIntent;
    }

    if ($this->circuitBreaker && !$this->circuitBreaker->canAttempt()) {
      $this->logger->warning('Skipping request-time LLM classification because the circuit breaker is open.');
      return $currentIntent;
    }

    try {
      $messages = $this->buildClassificationMessages($sanitized_query);
      $response = $this->completeStructuredRequest(
        'intent_classification',
        $messages,
        $this->buildIntentResponseSchema(),
        [
          'user_identifier' => $userIdentifier,
          'max_tokens' => max(32, min(128, (int) $this->getLlmSetting('max_tokens', 150))),
          'temperature' => max(0.0, min(0.4, (float) $this->getLlmSetting('temperature', 0.3))),
          'timeout' => 5.0,
          'connect_timeout' => 2.0,
          'safety_mode' => $this->mapSafetyThresholdToCohereMode(),
        ],
      );

      $intent = strtolower(trim((string) ($response['intent'] ?? 'unknown')));
      if (!in_array($intent, self::VALID_INTENTS, TRUE)) {
        $this->logger->warning('Request-time LLM returned a non-canonical intent label.', ['intent' => $intent]);
        return $currentIntent;
      }

      return $intent;
    }
    catch (\Throwable $e) {
      $this->logger->error('Request-time LLM intent classification failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      if ($this->circuitBreaker) {
        $this->circuitBreaker->recordFailure();
      }
      return $currentIntent;
    }
  }

  /**
   * Greeting variation remains retired in the Cohere-first transition.
   */
  public function generateGreeting(string $userInput, string $baseGreeting = 'Hello! How can I help you today?'): ?string {
    return NULL;
  }

  /**
   * Returns the normalized last-usage snapshot.
   *
   * @return array<string, int>|null
   *   Normalized token usage or NULL when unavailable.
   */
  public function getLastUsage(): ?array {
    return $this->lastUsage;
  }

  /**
   * Returns the rate limiter instance when wired.
   */
  public function getRateLimiter(): ?LlmRateLimiter {
    return $this->rateLimiter;
  }

  /**
   * Returns the current cost-control summary when available.
   */
  public function getCostControlSummary(): ?array {
    return $this->costControlPolicy?->getSummary();
  }

  /**
   * Executes a structured LLM request with cache/retry/policy controls.
   *
   * @param string $operation
   *   Logical operation name.
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized messages.
   * @param array<string, mixed> $schema
   *   JSON schema.
   * @param array<string, mixed> $options
   *   Transport options.
   *
   * @return array<string, mixed>
   *   Structured response payload.
   */
  protected function completeStructuredRequest(string $operation, array $messages, array $schema, array $options = []): array {
    $cache_ttl = max(0, (int) $this->getLlmSetting('cache_ttl', 3600));
    $cache_key = $this->buildCacheKey($operation, $messages, $schema, $options);
    $budget_key = $this->buildBudgetKey($options['user_identifier'] ?? NULL);

    if ($this->costControlPolicy) {
      $policy_result = $this->costControlPolicy->beginRequest($budget_key);
      if (!is_array($policy_result) || empty($policy_result['allowed'])) {
        $reason = is_array($policy_result) ? (string) ($policy_result['reason'] ?? 'unknown') : 'unknown';
        throw new \RuntimeException('Request-time LLM budget exceeded: ' . $reason);
      }
    }

    if ($this->cache && $cache_ttl > 0) {
      $cached = $this->cache->get($cache_key);
      if ($cached && is_array($cached->data)) {
        if ($this->costControlPolicy) {
          $this->costControlPolicy->recordCacheHit();
        }
        /** @var array<string, mixed> $cached_payload */
        $cached_payload = $cached->data;
        return $cached_payload;
      }
    }

    if ($this->costControlPolicy) {
      $this->costControlPolicy->recordCacheMiss();
    }

    $attempt = 0;
    $max_retries = max(0, (int) $this->getLlmSetting('max_retries', 1));
    do {
      try {
        $result = $this->dispatchStructuredJsonRequest($messages, $schema, $options);
        $payload = $result['payload'] ?? [];
        if (!is_array($payload)) {
          throw new \RuntimeException('Request-time LLM transport returned a non-array payload.');
        }
        $usage = $result['usage'] ?? NULL;
        $this->lastUsage = is_array($usage) ? $usage : NULL;

        if ($this->costControlPolicy && is_array($usage)) {
          $this->costControlPolicy->recordCall($usage);
        }

        if ($this->cache && $cache_ttl > 0) {
          $this->cache->set($cache_key, $payload, time() + $cache_ttl);
        }

        if ($this->circuitBreaker) {
          $this->circuitBreaker->recordSuccess();
        }

        return $payload;
      }
      catch (RequestException $e) {
        $attempt++;
        $status_code = $e->getResponse()?->getStatusCode();
        if ($attempt > $max_retries || !$this->isRetryableStatusCode($status_code)) {
          throw $e;
        }
        $this->sleepMilliseconds($this->backoffDelayMs($attempt));
      }
      catch (GuzzleException $e) {
        $attempt++;
        if ($attempt > $max_retries) {
          throw $e;
        }
        $this->sleepMilliseconds($this->backoffDelayMs($attempt));
      }
    } while ($attempt <= $max_retries);

    throw new \RuntimeException('Request-time LLM request failed after retries.');
  }

  /**
   * Dispatches the transport call. Overridable for focused tests.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized messages.
   * @param array<string, mixed> $schema
   *   JSON schema.
   * @param array<string, mixed> $options
   *   Transport options.
   *
   * @return array{
   *   payload: array<string, mixed>,
   *   usage?: array<string, int>
   * }
   *   Structured response and optional usage.
   */
  protected function dispatchStructuredJsonRequest(array $messages, array $schema, array $options = []): array {
    return $this->transport->completeStructuredJson($messages, $schema, $options);
  }

  /**
   * Builds request messages for intent classification.
   *
   * @return array<int, array<string, mixed>>
   *   Provider-normalized messages.
   */
  protected function buildClassificationMessages(string $query): array {
    $system_prompt = self::INTENT_SYSTEM_PROMPT;
    $language_hint = $this->buildLanguageHint($query);
    if ($language_hint !== '') {
      $system_prompt .= "\n\n" . $language_hint;
    }

    return [
      [
        'role' => 'system',
        'content' => $system_prompt,
      ],
      [
        'role' => 'user',
        'content' => "User message:\n" . $query,
      ],
    ];
  }

  /**
   * Builds the JSON schema for intent responses.
   *
   * @return array<string, mixed>
   *   Cohere-compatible JSON schema.
   */
  protected function buildIntentResponseSchema(): array {
    return [
      'name' => 'assistant_intent_response',
      'schema' => [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'required' => ['intent'],
        'properties' => [
          'intent' => [
            'type' => 'string',
            'enum' => self::VALID_INTENTS,
          ],
        ],
      ],
    ];
  }

  /**
   * Maps legacy safety thresholds into Cohere safety modes.
   */
  protected function mapSafetyThresholdToCohereMode(): string {
    return match ((string) $this->getLlmSetting('safety_threshold', 'BLOCK_MEDIUM_AND_ABOVE')) {
      'BLOCK_LOW_AND_ABOVE' => 'STRICT',
      'BLOCK_ONLY_HIGH' => 'OFF',
      default => 'CONTEXTUAL',
    };
  }

  /**
   * Returns TRUE when the status code is retryable.
   */
  protected function isRetryableStatusCode(?int $statusCode): bool {
    return in_array($statusCode, [429, 500, 502, 503, 504], TRUE);
  }

  /**
   * Returns the retry backoff delay in milliseconds.
   */
  protected function backoffDelayMs(int $attempt): int {
    return min(self::MAX_SYNC_RETRY_DELAY_MS, 50 * (2 ** max(0, $attempt - 1)));
  }

  /**
   * Sleep wrapper for testability.
   */
  protected function sleepMilliseconds(int $delayMs): void {
    if ($delayMs > 0) {
      usleep($delayMs * 1000);
    }
  }

  /**
   * Builds a stable cache key for structured requests.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized messages.
   * @param array<string, mixed> $schema
   *   JSON schema.
   * @param array<string, mixed> $options
   *   Transport options.
   */
  protected function buildCacheKey(string $operation, array $messages, array $schema, array $options = []): string {
    $cache_fingerprint = [
      'policy_version' => self::POLICY_VERSION,
      'provider' => $this->getProviderId(),
      'model' => $this->getModelId(),
      'operation' => $operation,
      'messages' => $messages,
      'schema' => $schema,
      'options' => [
        'max_tokens' => $options['max_tokens'] ?? NULL,
        'temperature' => $options['temperature'] ?? NULL,
        'safety_mode' => $options['safety_mode'] ?? NULL,
      ],
    ];
    return 'ilas_site_assistant:llm:' . hash('sha256', json_encode($cache_fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Returns the normalized LLM config.
   */
  protected function getAssistantConfig(): ImmutableConfig {
    return $this->configFactory->get('ilas_site_assistant.settings');
  }

  /**
   * Returns one request-time LLM config value.
   */
  protected function getLlmSetting(string $key, mixed $default = NULL): mixed {
    $value = $this->getAssistantConfig()->get('llm.' . $key);
    return $value ?? $default;
  }

  /**
   * Builds a stable budget identity.
   */
  protected function buildBudgetKey(?string $userIdentifier): string {
    $identifier = trim((string) $userIdentifier);
    if ($identifier === '') {
      return 'global';
    }
    return hash('sha256', $identifier);
  }

  /**
   * Adds a language-preservation hint when the input is not English.
   */
  protected function buildLanguageHint(string $text): string {
    if (preg_match('/[^\x00-\x7F]/', $text)) {
      return 'Preserve the user language when inferring intent.';
    }
    return '';
  }

  /**
   * Checks whether candidate text drifted into legal advice.
   */
  protected function containsLegalAdvice(string $text): bool {
    return PostGenerationLegalAdviceDetector::containsLegalAdvice($text);
  }

}
