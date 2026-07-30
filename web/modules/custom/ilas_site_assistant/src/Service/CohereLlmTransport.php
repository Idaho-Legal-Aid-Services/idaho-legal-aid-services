<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

/**
 * Cohere transport for bounded request-time classification calls.
 */
class CohereLlmTransport implements RequestTimeLlmTransportInterface {

  private const API_ENDPOINT = 'https://api.cohere.com/v2/chat';
  private const DEFAULT_MODEL = 'command-a-03-2025';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ?LlmRuntimeConfigResolver $runtimeConfig = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return 'cohere';
  }

  /**
   * {@inheritdoc}
   */
  public function getModelId(): string {
    return $this->runtimeConfig?->getModelId() ?? self::DEFAULT_MODEL;
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    if ($this->runtimeConfig !== NULL) {
      $summary = $this->runtimeConfig->resolve();
      return (bool) ($summary['provider_is_cohere'] ?? FALSE)
        && (bool) ($summary['model_configured'] ?? FALSE)
        && (bool) ($summary['key_present'] ?? FALSE);
    }

    return $this->getApiKey() !== '' && $this->getModelId() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function completeStructuredJson(array $messages, array $schema, array $options = []): array {
    $api_key = $this->getApiKey();
    if ($api_key === '') {
      throw new \RuntimeException('Missing ILAS_COHERE_API_KEY runtime secret.');
    }

    $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
        'X-Client-Name' => 'ilas-site-assistant',
      ],
      'json' => [
        'model' => $options['model'] ?? $this->getModelId(),
        'messages' => $messages,
        'stream' => FALSE,
        'max_tokens' => (int) ($options['max_tokens'] ?? 128),
        'temperature' => (float) ($options['temperature'] ?? 0.2),
        'safety_mode' => $options['safety_mode'] ?? 'CONTEXTUAL',
        'response_format' => [
          'type' => 'json_object',
          'schema' => $schema,
        ],
      ],
      'timeout' => (float) ($options['timeout'] ?? 5.0),
      'connect_timeout' => (float) ($options['connect_timeout'] ?? 2.0),
    ]);

    /** @var array<string, mixed> $body */
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $text = $this->extractTextPayload($body);
    if ($text === '') {
      throw new \RuntimeException('Cohere response did not contain structured text content.');
    }

    /** @var array<string, mixed> $payload */
    $payload = json_decode($this->stripJsonFences($text), TRUE, 512, JSON_THROW_ON_ERROR);

    return [
      'payload' => $payload,
      'usage' => $this->extractUsage($body),
    ];
  }

  /**
   * Executes a plain-text Cohere chat request for explicit diagnostics probes.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Provider-normalized chat messages.
   * @param array<string, mixed> $options
   *   Provider-neutral request options.
   *
   * @return array{text: string, usage: array<string, int>}
   *   Text response and optional normalized usage.
   */
  public function completeText(array $messages, array $options = []): array {
    $api_key = $this->getApiKey();
    if ($api_key === '') {
      throw new \RuntimeException('Missing ILAS_COHERE_API_KEY runtime secret.');
    }

    $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
        'X-Client-Name' => 'ilas-site-assistant',
      ],
      'json' => [
        'model' => $options['model'] ?? $this->getModelId(),
        'messages' => $messages,
        'stream' => FALSE,
        'max_tokens' => (int) ($options['max_tokens'] ?? 16),
        'temperature' => (float) ($options['temperature'] ?? 0.0),
        'safety_mode' => $options['safety_mode'] ?? 'CONTEXTUAL',
      ],
      'timeout' => (float) ($options['timeout'] ?? 5.0),
      'connect_timeout' => (float) ($options['connect_timeout'] ?? 2.0),
    ]);

    /** @var array<string, mixed> $body */
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);

    return [
      'text' => $this->extractTextPayload($body),
      'usage' => $this->extractUsage($body),
    ];
  }

  /**
   * Returns the configured Cohere API key.
   */
  protected function getApiKey(): string {
    if ($this->runtimeConfig !== NULL) {
      return $this->runtimeConfig->getApiKey();
    }

    return trim((string) Settings::get('ilas_cohere_api_key', ''));
  }

  /**
   * Extracts textual content from the Cohere response body.
   *
   * @param array<string, mixed> $body
   *   Decoded response body.
   */
  protected function extractTextPayload(array $body): string {
    $content = $body['message']['content'] ?? [];
    if (!is_array($content)) {
      return '';
    }

    foreach ($content as $part) {
      if (is_array($part) && ($part['type'] ?? NULL) === 'text' && isset($part['text'])) {
        return trim((string) $part['text']);
      }
    }

    return '';
  }

  /**
   * Extracts normalized token usage.
   *
   * @param array<string, mixed> $body
   *   Decoded response body.
   *
   * @return array<string, int>
   *   Usage values normalized to input/output/total.
   */
  protected function extractUsage(array $body): array {
    $usage = $body['usage']['tokens'] ?? [];
    if (!is_array($usage)) {
      return [];
    }

    $input = (int) ($usage['input_tokens'] ?? 0);
    $output = (int) ($usage['output_tokens'] ?? 0);

    return [
      'input' => $input,
      'output' => $output,
      'total' => $input + $output,
    ];
  }

  /**
   * Removes optional markdown fences from JSON text.
   */
  protected function stripJsonFences(string $text): string {
    $trimmed = trim($text);
    if (str_starts_with($trimmed, '```')) {
      $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
      $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
    }
    return trim($trimmed);
  }

}
