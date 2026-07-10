<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Attaches retrieval results to template-only responses (retrieve-first).
 *
 * Navigation, topic, service-area, and escalation responses historically
 * carried no retrieval results, so grounding, citations, and the public
 * retrieval contract stayed empty even when the site had topical content.
 * This service runs retrieval for those response types and attaches results
 * WITHOUT replacing the template message — downstream grounding and contract
 * assembly then operate on real evidence.
 *
 * Behavior contract:
 * - Never replaces or rewrites 'message'; only adds 'results'.
 * - Fails open: any retrieval error returns the response unchanged.
 * - Config-gated by retrieve_first.enabled (instant kill-switch) and
 *   retrieve_first.intent_types.
 */
class RetrievalAugmenter {

  /**
   * Escalation classes eligible for supplemental retrieval.
   *
   * Refusal-style safety classes (wrongdoing, criminal, prompt injection,
   * PII, legal advice, document drafting) must never carry retrieval
   * supplements — refusals stay minimal by design. Only informational
   * emergencies where topical documents genuinely help the user are listed,
   * each with a curated retrieval query.
   */
  protected const ESCALATION_RETRIEVAL_QUERIES = [
    SafetyClassifier::CLASS_EVICTION_EMERGENCY => 'eviction notice tenant rights',
    SafetyClassifier::CLASS_DV_EMERGENCY => 'protection order domestic violence',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FaqIndex $faqIndex,
    protected ResourceFinder $resourceFinder,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Whether the response should receive retrieve-first augmentation.
   *
   * @param array $response
   *   The intent-processed response.
   *
   * @return bool
   *   TRUE when augmentation is enabled, the response type is configured,
   *   and no retrieval results are attached yet.
   */
  public function applies(array $response): bool {
    $settings = $this->getSettings();
    if (empty($settings['enabled'])) {
      return FALSE;
    }
    if (!empty($response['results'])) {
      return FALSE;
    }
    $types = $settings['intent_types'] ?? [];
    return is_array($types) && in_array($response['type'] ?? '', $types, TRUE);
  }

  /**
   * Attaches retrieval results to a template-only response.
   *
   * @param array $response
   *   The intent-processed response (results empty).
   * @param array $intent
   *   The routed intent (used for topical query hints).
   * @param string $user_message
   *   The raw user message (query fallback).
   * @param array $early_retrieval
   *   FAQ hits from the gate's early retrieval pass, reused to avoid a
   *   duplicate FAQ query.
   *
   * @return array
   *   The response with 'results' and 'retrieval_supplemented' attached,
   *   or unchanged on failure / no matches.
   */
  public function augment(array $response, array $intent, string $user_message, array $early_retrieval = []): array {
    try {
      $settings = $this->getSettings();
      $max_results = max(1, (int) ($settings['max_results'] ?? 3));
      $query = $this->buildTopicalQuery($response, $intent, $user_message);
      if ($query === '') {
        return $response;
      }

      $resources = $this->resourceFinder->findResources($query, $max_results);
      // Retrieval genuinely ran — record the attempt even when it found
      // nothing, so meta.retrieval.attempted reflects reality.
      $response['retrieval_attempted'] = TRUE;
      $merged = $this->mergeResults($early_retrieval, $resources, $max_results, $user_message);
      if ($merged === []) {
        return $response;
      }

      $response['results'] = $merged;
      $response['retrieval_supplemented'] = TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Retrieve-first augmentation failed (@type): @class @error_signature', [
        '@type' => $response['type'] ?? 'unknown',
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
    return $response;
  }

  /**
   * Returns the curated retrieval query for an escalation safety class.
   *
   * @param array $safety_classification
   *   The safety classification array (class, requires_refusal, ...).
   *
   * @return string|null
   *   The retrieval query, or NULL when the class is refusal-style or not
   *   eligible for supplemental retrieval.
   */
  public function escalationQueryFor(array $safety_classification): ?string {
    if (!empty($safety_classification['requires_refusal'])) {
      return NULL;
    }
    $settings = $this->getSettings();
    if (empty($settings['enabled']) || !in_array('escalation', $settings['intent_types'] ?? [], TRUE)) {
      return NULL;
    }
    $class = (string) ($safety_classification['class'] ?? '');
    return self::ESCALATION_RETRIEVAL_QUERIES[$class] ?? NULL;
  }

  /**
   * Attaches retrieval results to a safety-escalation response.
   *
   * Safety copy always wins: the message, actions, and safety metadata are
   * never touched; results are appended additively and any failure returns
   * the response unchanged.
   */
  public function augmentEscalation(array $response_data, array $safety_classification): array {
    $query = $this->escalationQueryFor($safety_classification);
    if ($query === NULL || !empty($response_data['results'])) {
      return $response_data;
    }
    try {
      $settings = $this->getSettings();
      $max_results = max(1, (int) ($settings['max_results'] ?? 3));
      $results = $this->resourceFinder->findResources($query, $max_results);
      $response_data['retrieval_attempted'] = TRUE;
      if ($results === []) {
        return $response_data;
      }
      $response_data['results'] = array_slice(array_values($results), 0, $max_results);
      $response_data['retrieval_supplemented'] = TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Escalation retrieval augmentation failed (@safety_class): @class @error_signature', [
        '@safety_class' => $safety_classification['class'] ?? 'unknown',
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
    }
    return $response_data;
  }

  /**
   * Builds the retrieval query, preferring topical hints over raw input.
   */
  protected function buildTopicalQuery(array $response, array $intent, string $user_message): string {
    $hints = [
      $intent['matched_synonym'] ?? NULL,
      $response['topic']['name'] ?? NULL,
      $response['topic_name'] ?? NULL,
      isset($intent['area']) ? str_replace('_', ' ', (string) $intent['area']) : NULL,
    ];
    foreach ($hints as $hint) {
      if (is_string($hint) && trim($hint) !== '') {
        // Combine the hint with the user message so retrieval sees both the
        // resolved topic and the user's own phrasing.
        $hint = trim($hint);
        $message = trim($user_message);
        if ($message !== '' && mb_stripos($message, $hint) === FALSE) {
          return $hint . ' ' . $message;
        }
        return $message !== '' ? $message : $hint;
      }
    }
    return trim($user_message);
  }

  /**
   * Merges FAQ and resource results, capped at the configured maximum.
   *
   * Resource results lead when the user asked for a document-like thing
   * (forms, guides, packets); FAQ results lead otherwise. Duplicate URLs
   * are dropped.
   */
  protected function mergeResults(array $faq_results, array $resource_results, int $max_results, string $user_message): array {
    $resource_leading = (bool) preg_match('/\b(form|forms|packet|guide|guides|document|pdf|resource|resources)\b/i', $user_message);
    $ordered = $resource_leading
      ? array_merge(array_values($resource_results), array_values($faq_results))
      : array_merge(array_values($faq_results), array_values($resource_results));

    $merged = [];
    $seen_urls = [];
    foreach ($ordered as $item) {
      if (!is_array($item)) {
        continue;
      }
      $url = (string) ($item['url'] ?? '');
      $dedupe_key = $url !== '' ? $url : (string) ($item['id'] ?? count($merged));
      if (isset($seen_urls[$dedupe_key])) {
        continue;
      }
      $seen_urls[$dedupe_key] = TRUE;
      $merged[] = $item;
      if (count($merged) >= $max_results) {
        break;
      }
    }
    return $merged;
  }

  /**
   * Returns the retrieve_first settings block.
   */
  protected function getSettings(): array {
    $settings = $this->configFactory->get('ilas_site_assistant.settings')->get('retrieve_first');
    return is_array($settings) ? $settings : [];
  }

}
