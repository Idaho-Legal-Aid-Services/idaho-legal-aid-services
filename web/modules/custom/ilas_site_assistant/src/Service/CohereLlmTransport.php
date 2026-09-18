<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

/**
 * Cohere transport for bounded request-time classification and grounded calls.
 */
class CohereLlmTransport implements RequestTimeLlmTransportInterface, GroundedGenerationTransportInterface {

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
   * {@inheritdoc}
   *
   * Cohere v2 /chat rejects response_format together with documents, so this
   * is a plain-text request: the provider grounds the answer in `documents`
   * and returns `message.citations[]` referencing our document ids.
   */
  public function completeGrounded(array $messages, array $documents, array $options = []): array {
    $api_key = $this->getApiKey();
    if ($api_key === '') {
      throw new \RuntimeException('Missing ILAS_COHERE_API_KEY runtime secret.');
    }

    $citation_mode = strtoupper(trim((string) ($options['citation_mode'] ?? 'FAST')));
    if (!in_array($citation_mode, ['FAST', 'ACCURATE'], TRUE)) {
      $citation_mode = 'FAST';
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
        'documents' => array_values($documents),
        'stream' => FALSE,
        'max_tokens' => (int) ($options['max_tokens'] ?? 220),
        'temperature' => (float) ($options['temperature'] ?? 0.2),
        'safety_mode' => $options['safety_mode'] ?? 'CONTEXTUAL',
        'citation_options' => ['mode' => $citation_mode],
      ],
      'timeout' => (float) ($options['timeout'] ?? 8.0),
      'connect_timeout' => (float) ($options['connect_timeout'] ?? 2.0),
    ]);

    /** @var array<string, mixed> $body */
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);

    return [
      'text' => $this->extractAllText($body),
      'citations' => $this->extractCitations($body),
      'finish_reason' => isset($body['finish_reason']) ? (string) $body['finish_reason'] : NULL,
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
   * Extracts and joins every text content part from the Cohere response body.
   *
   * Prose answers may span several content parts; extractTextPayload() keeps
   * only the first part because structured JSON responses are single-part.
   */
  protected function extractAllText(array $body): string {
    $content = $body['message']['content'] ?? [];
    if (!is_array($content)) {
      return '';
    }

    $parts = [];
    foreach ($content as $part) {
      if (is_array($part) && ($part['type'] ?? NULL) === 'text' && isset($part['text'])) {
        $text = trim((string) $part['text']);
        if ($text !== '') {
          $parts[] = $text;
        }
      }
    }

    return trim(implode("\n", $parts));
  }

  /**
   * Normalizes provider citations to character spans plus our document ids.
   *
   * @param array<string, mixed> $body
   *   Decoded response body.
   *
   * @return array<int, array{start: int, end: int, text: string, document_ids: array<int, string>}>
   *   Citations with document ids resolved from `sources[]` entries of type
   *   `document`. Malformed or missing citations yield an empty list.
   */
  protected function extractCitations(array $body): array {
    $raw = $body['message']['citations'] ?? [];
    if (!is_array($raw)) {
      return [];
    }

    $citations = [];
    foreach ($raw as $citation) {
      if (!is_array($citation)) {
        continue;
      }
      $document_ids = [];
      foreach ((array) ($citation['sources'] ?? []) as $source) {
        if (!is_array($source)) {
          continue;
        }
        $type = strtolower((string) ($source['type'] ?? 'document'));
        if ($type !== 'document') {
          continue;
        }
        $id = '';
        if (is_array($source['document'] ?? NULL) && isset($source['document']['id'])) {
          $id = (string) $source['document']['id'];
        }
        elseif (isset($source['id'])) {
          $id = (string) $source['id'];
        }
        $id = trim($id);
        if ($id !== '' && !in_array($id, $document_ids, TRUE)) {
          $document_ids[] = $id;
        }
      }
      $citations[] = [
        'start' => max(0, (int) ($citation['start'] ?? 0)),
        'end' => max(0, (int) ($citation['end'] ?? 0)),
        'text' => (string) ($citation['text'] ?? ''),
        'document_ids' => $document_ids,
      ];
    }

    return $citations;
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
