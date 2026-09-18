<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException;
use Psr\Log\LoggerInterface;

/**
 * Grounded answer generation (TOVR-18).
 *
 * When enabled, asks the request-time LLM to write a short plain-language
 * answer strictly from the retrieved FAQ/resource documents, with provider
 * citations mapped back to `results[]` indexes. Every guard failure returns
 * `used = FALSE` with a stable reason and leaves the deterministic template
 * untouched; this service never mutates the response and never throws.
 *
 * Guards in front of the provider: enablement (config + runtime secret +
 * key + generation kill switch), response type allowlist, time budget,
 * document availability, PII redaction of the user message, and the shared
 * admission pipeline (kill switch, breaker, global/daily/monthly/per-IP
 * budgets, sampling, cache). Guards behind it: empty output, refusal
 * sentinel, truncation, length, URLs, missing citations, legal-advice
 * phrasing, unsupported contact details, and language mismatch.
 */
class GroundedAnswerGenerator {

  public const OPERATION = 'grounded_answer';
  public const KILL_SWITCH_STATE_KEY = 'ilas_site_assistant.llm_generation.kill_switch';
  public const POLICY_VERSION = 'tovr18_v1';
  public const REFUSAL_SENTINEL = 'NO_ANSWER';
  public const OPTION_ELAPSED_MS = 'elapsed_ms';
  public const OPTION_EVAL_OVERRIDE = 'eval_override';
  public const OPTION_USER_IDENTIFIER = 'user_identifier';

  private const DEFAULT_RESPONSE_TYPES = ['faq', 'resources', 'search_results', 'eligibility'];
  private const PIPELINE_WATCHDOG_SECONDS = 25;
  private const ELAPSED_BUDGET_MS = 12000;
  private const MIN_TIMEOUT_SECONDS = 3.0;
  private const USER_MESSAGE_MAX_CHARS = 600;
  private const WORD_BUDGET_TOLERANCE = 1.35;

  /**
   * System prompt for grounded answers.
   */
  private const SYSTEM_PROMPT = <<<PROMPT
You are the website assistant for Idaho Legal Aid Services, a nonprofit civil legal aid organization. Write a short, plain-language answer to the user's question using ONLY the information in the provided documents.

Rules:
- Use only facts stated in the documents. Do not add outside knowledge.
- If the documents do not answer the question, reply with exactly: NO_ANSWER
- Do not give legal advice, predict outcomes, or tell the user what they should file, sue for, or sign. Describe what the documents say, and suggest that they apply for help or call the Legal Advice Line for their specific situation.
- Never invent or repeat phone numbers, street addresses, dollar amounts, deadlines, dates, or web addresses. Do not include any URL.
- Write 2 to 5 plain sentences and at most 120 words. No markdown, bullet points, headings, or emoji.
- Answer in the same language as the user's question.
PROMPT;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LlmRuntimeConfigResolver $runtimeConfig,
    private readonly GroundedGenerationTransportInterface $transport,
    private readonly LlmRequestPipeline $pipeline,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
    private readonly ?PolicyFilter $policyFilter = NULL,
    private readonly ?ResponseGrounder $responseGrounder = NULL,
    private readonly ?LlmCircuitBreaker $circuitBreaker = NULL,
  ) {}

  /**
   * Returns TRUE when grounded generation is enabled and runtime-ready.
   */
  public function isEnabled(): bool {
    if ((bool) $this->state->get(self::KILL_SWITCH_STATE_KEY, FALSE)) {
      return FALSE;
    }
    $summary = $this->runtimeConfig->resolve();
    return !empty($summary['generation_runtime_ready']);
  }

  /**
   * Returns the provider identifier.
   */
  public function getProviderId(): string {
    return $this->runtimeConfig->getProviderId();
  }

  /**
   * Returns the model identifier.
   */
  public function getModelId(): string {
    return $this->runtimeConfig->getModelId();
  }

  /**
   * Returns the response types eligible for grounded generation.
   *
   * @return array<int, string>
   *   Eligible response types.
   */
  public function getEligibleResponseTypes(): array {
    $configured = $this->getGenerationSetting('response_types');
    if (!is_array($configured) || $configured === []) {
      return self::DEFAULT_RESPONSE_TYPES;
    }
    $types = [];
    foreach ($configured as $type) {
      $type = trim((string) $type);
      if ($type !== '') {
        $types[] = $type;
      }
    }
    return $types === [] ? self::DEFAULT_RESPONSE_TYPES : $types;
  }

  /**
   * Returns TRUE when a response is structurally eligible for generation.
   *
   * @param array<string, mixed> $response
   *   Assembled response (post-retrieval, post-grounding).
   */
  public function applies(array $response): bool {
    $type = (string) ($response['type'] ?? '');
    if (!in_array($type, $this->getEligibleResponseTypes(), TRUE)) {
      return FALSE;
    }
    if (empty($response['results']) || !is_array($response['results'])) {
      return FALSE;
    }
    $message = $response['message'] ?? NULL;
    if (!(is_string($message) || $message instanceof \Stringable) || trim((string) $message) === '') {
      return FALSE;
    }
    if (!empty($response['_requires_review'])) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generates a grounded answer or explains why it fell back.
   *
   * @param string $userMessage
   *   Raw user message (already HTML-sanitized by the controller).
   * @param array<string, mixed> $response
   *   Assembled response; only read, never mutated.
   * @param array<string, mixed> $options
   *   Options: user_identifier (string|null), elapsed_ms (float), eval_override
   *   ('off'|null), and LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT (bool).
   *
   * @return array<string, mixed>
   *   Result envelope: operation, provider, model, attempted, used, reason,
   *   message, citations, documents_count, usage, cache_hit, latency_ms,
   *   language, and (on denial) admission_reason.
   */
  public function generate(string $userMessage, array $response, array $options = []): array {
    $started = microtime(TRUE);
    $language = ResponseBuilder::looksLikeSpanish($userMessage) ? 'es' : 'en';
    $result = [
      'operation' => self::OPERATION,
      'provider' => $this->getProviderId(),
      'model' => $this->getModelId(),
      'attempted' => FALSE,
      'used' => FALSE,
      'reason' => 'not_attempted',
      'message' => NULL,
      'citations' => [],
      'documents_count' => 0,
      'usage' => [],
      'cache_hit' => FALSE,
      'latency_ms' => 0.0,
      'language' => $language,
    ];

    try {
      if (($options[self::OPTION_EVAL_OVERRIDE] ?? NULL) === 'off') {
        return $this->fallback($result, 'eval_override_off', $started);
      }
      if (!$this->isEnabled()) {
        $summary = $this->runtimeConfig->resolve();
        $reason = empty($summary['generation_enabled']) ? 'disabled' : 'not_configured';
        if ((bool) $this->state->get(self::KILL_SWITCH_STATE_KEY, FALSE)) {
          $reason = 'kill_switch';
        }
        return $this->fallback($result, $reason, $started);
      }
      if (!$this->applies($response)) {
        return $this->fallback($result, 'not_applicable', $started);
      }

      $elapsed_ms = max(0.0, (float) ($options[self::OPTION_ELAPSED_MS] ?? 0.0));
      if ($elapsed_ms > self::ELAPSED_BUDGET_MS) {
        return $this->fallback($result, 'time_budget_exhausted', $started);
      }
      $configured_timeout = (float) ($this->getGenerationSetting('timeout_seconds') ?? 8.0);
      $remaining = (self::PIPELINE_WATCHDOG_SECONDS - 3) - ($elapsed_ms / 1000);
      $timeout = min(max(self::MIN_TIMEOUT_SECONDS, $configured_timeout), max(self::MIN_TIMEOUT_SECONDS, $remaining));

      $results = array_values($response['results']);
      [$documents, $document_map] = $this->buildDocuments($results);
      $result['documents_count'] = count($documents);
      if ($documents === []) {
        return $this->fallback($result, 'no_documents', $started);
      }

      $sanitized = $this->sanitizeUserMessage($userMessage);
      if ($sanitized === '') {
        return $this->fallback($result, 'empty_after_sanitization', $started);
      }

      $messages = $this->buildMessages($sanitized, $language);
      $max_tokens = max(64, (int) ($this->getGenerationSetting('max_tokens') ?? 220));
      $temperature = max(0.0, min(1.0, (float) ($this->getGenerationSetting('temperature') ?? 0.2)));
      $citation_mode = (string) ($this->getGenerationSetting('citation_mode') ?? 'FAST');
      $transport_options = [
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
        'safety_mode' => $this->mapSafetyThresholdToCohereMode(),
        'citation_mode' => $citation_mode,
        'timeout' => $timeout,
      ];
      $cache_key = $this->buildCacheKey($language, $sanitized, $documents, $transport_options);
      $pipeline_options = [
        LlmRequestPipeline::OPTION_CACHE_TTL => max(0, (int) ($this->getGenerationSetting('cache_ttl') ?? 3600)),
        LlmRequestPipeline::OPTION_MAX_RETRIES => max(0, (int) ($this->getGenerationSetting('max_retries') ?? 0)),
        LlmRequestPipeline::OPTION_USER_IDENTIFIER => $options[self::OPTION_USER_IDENTIFIER] ?? NULL,
        LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT => !empty($options[LlmEvalTrafficPolicy::OPTION_PER_IP_EXEMPT]),
      ];

      $result['attempted'] = TRUE;
      try {
        $pipeline_result = $this->pipeline->execute(
          self::OPERATION,
          $cache_key,
          function () use ($messages, $documents, $transport_options): array {
            $completion = $this->transport->completeGrounded($messages, $documents, $transport_options);
            return [
              'payload' => [
                'text' => (string) ($completion['text'] ?? ''),
                'citations' => is_array($completion['citations'] ?? NULL) ? $completion['citations'] : [],
                'finish_reason' => isset($completion['finish_reason']) ? (string) $completion['finish_reason'] : NULL,
              ],
              'usage' => is_array($completion['usage'] ?? NULL) ? $completion['usage'] : NULL,
            ];
          },
          $pipeline_options,
        );
      }
      catch (LlmAdmissionDeniedException $e) {
        $result['admission_reason'] = $e->getReason();
        $this->logger->notice('Grounded generation admission denied (@reason); template answer kept.', [
          '@reason' => $e->getReason(),
        ]);
        return $this->fallback($result, 'admission_denied', $started);
      }
      catch (\Throwable $e) {
        $this->circuitBreaker?->recordFailure();
        $this->logger->error('Grounded generation failed: @class @error_signature', [
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]);
        return $this->fallback($result, 'exception', $started);
      }

      $meta = is_array($pipeline_result['meta'] ?? NULL) ? $pipeline_result['meta'] : [];
      $payload = is_array($pipeline_result['payload'] ?? NULL) ? $pipeline_result['payload'] : [];
      $result['cache_hit'] = !empty($meta['cache_hit']);
      $result['usage'] = is_array($meta['usage'] ?? NULL) ? $meta['usage'] : [];

      $text = trim((string) ($payload['text'] ?? ''));
      $finish_reason = strtoupper((string) ($payload['finish_reason'] ?? ''));
      $raw_citations = is_array($payload['citations'] ?? NULL) ? $payload['citations'] : [];

      if ($text === '') {
        return $this->fallback($result, 'empty_output', $started);
      }
      if (str_starts_with(strtoupper($text), self::REFUSAL_SENTINEL)) {
        return $this->fallback($result, 'refusal_sentinel', $started);
      }
      if ($finish_reason === 'MAX_TOKENS' && !preg_match('/[.!?…»"\')\]]$/u', $text)) {
        return $this->fallback($result, 'truncated', $started);
      }

      $text = $this->stripLightMarkdown($text);
      if (preg_match('~https?://|www\.~i', $text)) {
        return $this->fallback($result, 'url_in_output', $started);
      }
      $max_words = max(40, (int) ($this->getGenerationSetting('max_words') ?? 120));
      if (str_word_count(preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $text) ?? $text) > (int) ceil($max_words * self::WORD_BUDGET_TOLERANCE)) {
        return $this->fallback($result, 'too_long', $started);
      }

      $citations = $this->mapCitations($raw_citations, $document_map);
      if ($citations === []) {
        return $this->fallback($result, 'no_citations', $started);
      }
      if (PostGenerationLegalAdviceDetector::containsLegalAdvice($text)) {
        return $this->fallback($result, 'legal_advice_detected', $started);
      }
      if ($this->responseGrounder !== NULL) {
        $validation = $this->responseGrounder->validateGrounding($text, $results);
        if (empty($validation['valid'])) {
          return $this->fallback($result, 'unsupported_contact_info', $started);
        }
      }
      if ($language === 'es' && !preg_match('/\b(el|la|los|las|de|que|para|usted|puede|su|con|por)\b/iu', $text)) {
        return $this->fallback($result, 'language_mismatch', $started);
      }

      $result['used'] = TRUE;
      $result['reason'] = $result['cache_hit'] ? 'generated_cached' : 'generated';
      $result['message'] = $text;
      $result['citations'] = $citations;
      $result['latency_ms'] = round((microtime(TRUE) - $started) * 1000, 2);
      $this->logger->info('Grounded generation used (@reason): documents=@documents citations=@citations latency_ms=@latency', [
        '@reason' => $result['reason'],
        '@documents' => $result['documents_count'],
        '@citations' => count($citations),
        '@latency' => $result['latency_ms'],
      ]);
      return $result;
    }
    catch (\Throwable $e) {
      // Defensive: no guard failure may ever break the deterministic reply.
      $this->logger->error('Grounded generation guard failure: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return $this->fallback($result, 'exception', $started);
    }
  }

  /**
   * Builds provider documents from retrieval results.
   *
   * @param array<int, mixed> $results
   *   Zero-indexed retrieval results (already scrubbed by ResponseGrounder).
   *
   * @return array{0: array<int, array{id: string, data: array<string, string>}>, 1: array<string, array{result_index: int, url: string, title: string}>}
   *   Documents for the provider and the id -> result map.
   */
  private function buildDocuments(array $results): array {
    $max_documents = max(1, min(6, (int) ($this->getGenerationSetting('max_documents') ?? 4)));
    $snippet_chars = max(200, (int) ($this->getGenerationSetting('snippet_chars') ?? 1200));
    $documents = [];
    $map = [];

    foreach ($results as $index => $item) {
      if (!is_array($item)) {
        continue;
      }
      $is_faq = array_key_exists('question', $item) || array_key_exists('answer', $item);
      $title = $is_faq
        ? (string) ($item['question'] ?? $item['title'] ?? '')
        : (string) ($item['title'] ?? '');
      $body = $is_faq
        ? (string) ($item['answer'] ?? $item['full_answer'] ?? $item['answer_snippet'] ?? '')
        : (string) ($item['description'] ?? $item['snippet'] ?? $item['summary'] ?? '');
      $title = $this->cleanText($title, 300);
      $snippet = $this->cleanText($body, $snippet_chars);
      if ($snippet === '') {
        continue;
      }
      $url = trim((string) ($item['url'] ?? ''));
      if ($is_faq && $url !== '' && !str_contains($url, '#') && !empty($item['anchor'])) {
        $url .= '#' . ltrim((string) $item['anchor'], '#');
      }
      $id = 'doc_' . (int) $index;
      $documents[] = [
        'id' => $id,
        'data' => [
          'title' => $title,
          'snippet' => $snippet,
        ],
      ];
      $map[$id] = [
        'result_index' => (int) $index,
        'url' => $url,
        'title' => $title,
      ];
      if (count($documents) >= $max_documents) {
        break;
      }
    }

    return [$documents, $map];
  }

  /**
   * Strips HTML, decodes entities, collapses whitespace, and caps length.
   */
  private function cleanText(string $text, int $maxChars): string {
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (mb_strlen($text) > $maxChars) {
      $text = rtrim(mb_substr($text, 0, $maxChars)) . '…';
    }
    return $text;
  }

  /**
   * Redacts PII and bounds the user message before it reaches the provider.
   */
  private function sanitizeUserMessage(string $userMessage): string {
    $text = $this->policyFilter !== NULL
      ? $this->policyFilter->sanitizeForLlmPrompt($userMessage)
      : PiiRedactor::redact($userMessage);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return mb_substr($text, 0, self::USER_MESSAGE_MAX_CHARS);
  }

  /**
   * Builds provider-normalized chat messages.
   *
   * @return array<int, array<string, string>>
   *   System + user messages.
   */
  private function buildMessages(string $sanitizedMessage, string $language): array {
    $system = self::SYSTEM_PROMPT;
    if ($language === 'es') {
      $system .= "\n- The user wrote in Spanish. Responde en español.";
    }
    return [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user', 'content' => "User question:\n" . $sanitizedMessage],
    ];
  }

  /**
   * Maps legacy safety thresholds into Cohere safety modes.
   */
  private function mapSafetyThresholdToCohereMode(): string {
    $threshold = (string) ($this->configFactory->get('ilas_site_assistant.settings')->get('llm.safety_threshold') ?? 'BLOCK_MEDIUM_AND_ABOVE');
    return $threshold === 'BLOCK_LOW_AND_ABOVE' ? 'STRICT' : 'CONTEXTUAL';
  }

  /**
   * Builds a stable cache id for a grounded request.
   *
   * @param string $language
   *   Detected user language ('en' | 'es').
   * @param string $sanitizedMessage
   *   PII-redacted, bounded user message.
   * @param array<int, array{id: string, data: array<string, string>}> $documents
   *   Provider documents.
   * @param array<string, mixed> $options
   *   Transport options that influence the output.
   */
  private function buildCacheKey(string $language, string $sanitizedMessage, array $documents, array $options): string {
    $fingerprint = [
      'policy_version' => self::POLICY_VERSION,
      'provider' => $this->getProviderId(),
      'model' => $this->getModelId(),
      'language' => $language,
      'message' => $sanitizedMessage,
      'documents' => array_map(
        static fn(array $document): string => $document['id'] . ':' . hash('sha256', ($document['data']['title'] ?? '') . "\0" . ($document['data']['snippet'] ?? '')),
        $documents,
      ),
      'max_tokens' => $options['max_tokens'] ?? NULL,
      'temperature' => $options['temperature'] ?? NULL,
      'citation_mode' => $options['citation_mode'] ?? NULL,
      'safety_mode' => $options['safety_mode'] ?? NULL,
    ];
    return 'ilas_site_assistant:llm:grounded:' . hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Removes light markdown the prompt forbids but models still emit.
   */
  private function stripLightMarkdown(string $text): string {
    $text = preg_replace('/(\*\*|__|`)/u', '', $text) ?? $text;
    $text = preg_replace('/^\s*(?:[-*•]\s+|#{1,6}\s+|\d+[.)]\s+)/mu', '', $text) ?? $text;
    $text = preg_replace('/\s*\n+\s*/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * Maps provider citations to result indexes, deduped by result.
   *
   * @param array<int, mixed> $rawCitations
   *   Normalized transport citations.
   * @param array<string, array{result_index: int, url: string, title: string}> $documentMap
   *   Document id -> result map.
   *
   * @return array<int, array{result_index: int, document_id: string, url: string, title: string, start: int, end: int}>
   *   Public citations ordered by first appearance.
   */
  private function mapCitations(array $rawCitations, array $documentMap): array {
    $by_result = [];
    foreach ($rawCitations as $citation) {
      if (!is_array($citation)) {
        continue;
      }
      foreach ((array) ($citation['document_ids'] ?? []) as $document_id) {
        $resolved = $this->resolveDocumentId((string) $document_id, $documentMap);
        if ($resolved === NULL) {
          continue;
        }
        $entry = $documentMap[$resolved];
        $index = $entry['result_index'];
        $start = max(0, (int) ($citation['start'] ?? 0));
        if (!isset($by_result[$index]) || $start < $by_result[$index]['start']) {
          $by_result[$index] = [
            'result_index' => $index,
            'document_id' => $resolved,
            'url' => $entry['url'],
            'title' => $entry['title'],
            'start' => $start,
            'end' => max($start, (int) ($citation['end'] ?? $start)),
          ];
        }
      }
    }
    usort($by_result, static fn(array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['result_index'] <=> $b['result_index']);
    return array_values($by_result);
  }

  /**
   * Resolves a provider document id (exact, or with a `:n` suffix) to ours.
   *
   * @param string $documentId
   *   Provider-reported document id.
   * @param array<string, mixed> $documentMap
   *   Document id -> result map.
   */
  private function resolveDocumentId(string $documentId, array $documentMap): ?string {
    $documentId = trim($documentId);
    if ($documentId === '') {
      return NULL;
    }
    if (isset($documentMap[$documentId])) {
      return $documentId;
    }
    $base = preg_replace('/:\d+$/', '', $documentId) ?? $documentId;
    if ($base !== $documentId && isset($documentMap[$base])) {
      return $base;
    }
    return NULL;
  }

  /**
   * Finalizes a fallback result.
   *
   * @param array<string, mixed> $result
   *   Result envelope.
   * @param string $reason
   *   Stable fallback reason.
   * @param float $started
   *   Microtime the attempt started.
   *
   * @return array<string, mixed>
   *   Result envelope with used = FALSE.
   */
  private function fallback(array $result, string $reason, float $started): array {
    $result['used'] = FALSE;
    $result['message'] = NULL;
    $result['citations'] = [];
    $result['reason'] = $reason;
    $result['latency_ms'] = round((microtime(TRUE) - $started) * 1000, 2);
    if ($result['attempted'] && !in_array($reason, ['admission_denied', 'exception'], TRUE)) {
      $this->logger->notice('Grounded generation fell back to the template (@reason).', ['@reason' => $reason]);
    }
    return $result;
  }

  /**
   * Returns one llm.generation.* config value.
   */
  private function getGenerationSetting(string $key): mixed {
    return $this->configFactory->get('ilas_site_assistant.settings')->get('llm.generation.' . $key);
  }

}
