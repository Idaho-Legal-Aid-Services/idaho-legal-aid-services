<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant\Service\RetrievalContract;
use Drupal\search_api\Entity\Index;

/**
 * Service for searching FAQ and accordion content via Search API.
 *
 * Uses the faq_accordion Search API index to provide full-text search
 * with stemming, partial matching, and deep-link URL generation.
 *
 * When vector search is enabled, supplements sparse lexical results with
 * semantic search from a Pinecone-backed vector index.
 */
class FaqIndex {

  /**
   * Multiplier used to size bounded legacy candidate loads.
   */
  const LEGACY_CANDIDATE_MULTIPLIER = 8;

  /**
   * Minimum candidate set size for bounded legacy retrieval.
   */
  const LEGACY_CANDIDATE_MIN = 20;

  /**
   * Maximum candidate set size for bounded legacy retrieval.
   */
  const LEGACY_CANDIDATE_MAX = 100;

  /**
   * Cache TTL for per-query memoization (seconds).
   *
   * Short-lived to avoid serving stale content and keeps DB/cache footprint
   * modest for Pantheon Basic (no Redis/Solr).
   */
  const QUERY_CACHE_TTL = 300;

  /**
   * Maximum tolerated duration for a vector search call (ms) before we
   * temporarily disable vector queries to protect latency.
   */
  const MAX_VECTOR_MS = 2000;

  /**
   * Backoff duration after a vector search timeout/failure (seconds).
   */
  const VECTOR_BACKOFF_SECONDS = 120;

  /**
   * Cache key used to suppress repeated degraded vector attempts.
   */
  const VECTOR_BACKOFF_CACHE_ID = 'ilas_site_assistant.vector_backoff.faq';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Retrieval configuration resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\RetrievalConfigurationService
   */
  protected RetrievalConfigurationService $retrievalConfiguration;

  /**
   * The ranking enhancer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\RankingEnhancer
   */
  protected $rankingEnhancer;

  /**
   * The source freshness/provenance governance service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SourceGovernanceService|null
   */
  protected $sourceGovernance;

  /**
   * The Search API index.
   *
   * @var \Drupal\search_api\IndexInterface|null
   */
  protected $index;

  /**
   * Request-local retrieval telemetry summaries for observability traces.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $retrievalTelemetry = [];

  /**
   * Cache ID for parent URL mappings.
   */
  const PARENT_URL_CACHE_ID = 'ilas_site_assistant.parent_urls';

  /**
   * Constructs a FaqIndex object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LanguageManagerInterface $language_manager,
    RetrievalConfigurationService $retrieval_configuration,
    RankingEnhancer $ranking_enhancer = NULL,
    SourceGovernanceService $source_governance = NULL
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->retrievalConfiguration = $retrieval_configuration;
    $this->rankingEnhancer = $ranking_enhancer;
    $this->sourceGovernance = $source_governance;
  }

  /**
   * Gets the current language code.
   *
   * @return string
   *   The current language code (defaults to 'en').
   */
  protected function getCurrentLanguage() {
    return $this->languageManager->getCurrentLanguage()->getId();
  }

  /**
   * Returns TRUE when a result URL matches the current language context.
   */
  protected function urlMatchesCurrentLanguage(string $url): bool {
    if (!isset($this->languageManager) || !is_object($this->languageManager)) {
      return TRUE;
    }

    $current = $this->getCurrentLanguage();
    $default = $this->languageManager->getDefaultLanguage()->getId();
    $path = parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? $path : '';

    $languages = method_exists($this->languageManager, 'getLanguages')
      ? array_keys($this->languageManager->getLanguages())
      : [$default];
    $prefixedLanguages = array_values(array_filter($languages, static fn(string $langcode): bool => $langcode !== $default));

    if ($current === $default) {
      if ($prefixedLanguages === []) {
        return TRUE;
      }

      $pattern = '#^/(' . implode('|', array_map(static fn(string $langcode): string => preg_quote($langcode, '#'), $prefixedLanguages)) . ')(/|$)#';
      return preg_match($pattern, $path) !== 1;
    }

    return preg_match('#^/' . preg_quote($current, '#') . '(/|$)#', $path) === 1;
  }

  /**
   * Returns TRUE when resolved parent metadata proves current-language scope.
   */
  protected function parentInfoMatchesCurrentLanguage(array $parent_info): bool {
    $parent_langcode = (string) ($parent_info['langcode'] ?? '');
    if ($parent_langcode !== '' && $parent_langcode !== $this->getCurrentLanguage()) {
      return FALSE;
    }

    $url = (string) ($parent_info['url'] ?? '');
    if ($url === '') {
      return FALSE;
    }

    return $this->urlMatchesCurrentLanguage($url);
  }

  /**
   * Returns TRUE when one built FAQ item matches the current language context.
   */
  protected function itemMatchesCurrentLanguage(array $item): bool {
    $parent_langcode = (string) ($item['parent_lang'] ?? '');
    if ($parent_langcode !== '' && $parent_langcode !== $this->getCurrentLanguage()) {
      return FALSE;
    }

    $url = (string) ($item['parent_url'] ?? $item['url'] ?? '');
    if ($url === '') {
      return FALSE;
    }

    return $this->urlMatchesCurrentLanguage($url);
  }

  /**
   * Filters built FAQ items to the current language context.
   *
   * @param array $items
   *   Built FAQ result items.
   *
   * @return array
   *   The subset whose resolved URLs match the current request language.
   */
  protected function filterItemsByCurrentLanguage(array $items): array {
    return array_filter(
      $items,
      fn(array $item): bool => $this->itemMatchesCurrentLanguage($item),
    );
  }

  /**
   * Gets the Search API index.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index or NULL if not available.
   */
  protected function getIndex() {
    if ($this->index === NULL) {
      $index_id = $this->retrievalConfiguration->getFaqIndexId();
      $this->index = $index_id ? Index::load($index_id) : NULL;
    }
    return $this->index;
  }

  /**
   * Checks if Search API index is available and usable.
   *
   * @return bool
   *   TRUE if the index is available.
   */
  public function isIndexAvailable() {
    $index = $this->getIndex();
    return $index && $index->status();
  }

  /**
   * Searches FAQs and accordions by query.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   * @param string|null $type
   *   Filter by type: 'faq_item', 'accordion_item', or NULL for all.
   *
   * @return array
   *   Array of matching items with deep-link URLs.
   */
  public function search(string $query, int $limit = 5, ?string $type = NULL) {
    $cache_key = $this->buildQueryCacheKey($query, $limit, $type);
    if ($cache_key) {
      $cached = $this->cache->get($cache_key);
      if ($cached) {
        $this->recordRetrievalTelemetry(
          $query,
          $type,
          is_array($cached->data) ? $cached->data : [],
          [
            'enabled' => !empty($this->getVectorSearchConfig()['enabled']),
            'should_attempt' => NULL,
            'reason' => 'query_cache_hit',
          ],
          $this->buildVectorOutcome(FALSE, 'cached', 'query_cache_hit'),
          'query_cache',
          TRUE,
        );
        return $cached->data;
      }
    }

    $index = $this->getIndex();

    // Fall back to legacy method if index not available.
    if (!$index || !$index->status()) {
      $results = $this->searchLegacy($query, $limit);
      $this->recordRetrievalTelemetry(
        $query,
        $type,
        $results,
        [
          'enabled' => !empty($this->getVectorSearchConfig()['enabled']),
          'should_attempt' => FALSE,
          'reason' => 'lexical_index_unavailable',
        ],
        $this->buildVectorOutcome(FALSE, 'not_evaluated', 'lexical_index_unavailable'),
        'legacy',
      );
      return $results;
    }

    try {
      $search_query = $index->query();
      $search_query->keys($query);
      $search_query->range(0, $limit);

      // Filter by current language to avoid duplicate results for translations.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      // Filter by paragraph type if specified.
      if ($type) {
        $search_query->addCondition('paragraph_type', $type);
      }

      // Execute search.
      $results = $search_query->execute();

      $items = [];
      foreach ($results->getResultItems() as $result_item) {
        try {
          $item = $this->buildResultItem($result_item);
          if ($item) {
            $items[] = $item;
          }
        }
        catch (\Exception $e) {
          // Skip items that fail to load.
          continue;
        }
      }
      $items = array_values($this->filterItemsByCurrentLanguage($items));

      // Supplement with vector search if lexical results are sparse.
      $supplement = $this->supplementWithVectorResultsDetailed($items, $query, $limit, $type);
      $items = $supplement['items'];

      if ($cache_key && $this->isVectorOutcomeCacheable($supplement['vector_outcome'])) {
        $this->cache->set($cache_key, $items, time() + self::QUERY_CACHE_TTL, [
          'paragraph_list',
          'config:ilas_site_assistant.settings',
        ]);
      }
      $this->recordRetrievalTelemetry(
        $query,
        $type,
        $items,
        $supplement['decision'] ?? NULL,
        $supplement['vector_outcome'] ?? NULL,
        'search_api',
      );
      return $items;
    }
    catch (\Exception $e) {
      // Fall back to legacy on error.
      \Drupal::logger('ilas_site_assistant')->warning('Search API query failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      $results = $this->searchLegacy($query, $limit);
      if ($cache_key) {
        $this->cache->set($cache_key, $results, time() + self::QUERY_CACHE_TTL, [
          'paragraph_list',
          'config:ilas_site_assistant.settings',
        ]);
      }
      $this->recordRetrievalTelemetry(
        $query,
        $type,
        $results,
        [
          'enabled' => !empty($this->getVectorSearchConfig()['enabled']),
          'should_attempt' => FALSE,
          'reason' => 'lexical_query_exception',
        ],
        $this->buildVectorOutcome(FALSE, 'not_evaluated', 'lexical_query_exception'),
        'legacy',
      );
      return $results;
    }
  }

  /**
   * Returns and clears request-local retrieval telemetry summaries.
   *
   * @return array<int, array<string, mixed>>
   *   Buffered retrieval telemetry rows.
   */
  public function drainRetrievalTelemetry(): array {
    $telemetry = $this->retrievalTelemetry;
    $this->retrievalTelemetry = [];
    return $telemetry;
  }

  /**
   * Builds a cache key for memoizing query results without storing PII.
   */
  protected function buildQueryCacheKey(string $query, int $limit, ?string $type): ?string {
    // Redact/normalize to avoid storing PII as part of the cache key.
    $sanitized = PiiRedactor::redactForStorage($query, 120);
    if ($sanitized === '') {
      return NULL;
    }

    $langcode = $this->getCurrentLanguage();
    $type_token = $type ?: 'all';
    $hash = hash('sha256', $sanitized . '|' . $limit . '|' . $type_token . '|' . $langcode);
    return 'faq.search:' . $hash;
  }

  /**
   * Records privacy-safe retrieval telemetry for request-scoped tracing.
   */
  protected function recordRetrievalTelemetry(
    string $query,
    ?string $type,
    array $items,
    ?array $decision,
    ?array $vector_outcome,
    string $path,
    bool $cache_hit = FALSE,
  ): void {
    $query_metadata = ObservabilityPayloadMinimizer::buildTextMetadata($query);
    $source_classes = [];
    $lexical_result_count = 0;
    $vector_result_count = 0;

    foreach ($items as $item) {
      $source_class = isset($item['source_class']) && is_string($item['source_class'])
        ? $item['source_class']
        : NULL;
      if ($source_class !== NULL && $source_class !== '') {
        $source_classes[$source_class] = TRUE;
      }

      $is_vector = FALSE;
      if ($source_class !== NULL) {
        $is_vector = str_ends_with($source_class, '_vector');
      }
      elseif (($item['source'] ?? 'lexical') === 'vector') {
        $is_vector = TRUE;
      }

      if ($is_vector) {
        $vector_result_count++;
      }
      else {
        $lexical_result_count++;
      }
    }

    $vector_outcome = $vector_outcome ?? $this->buildVectorOutcome(FALSE, 'not_evaluated', 'not_evaluated');
    $this->retrievalTelemetry[] = [
      'service' => 'faq',
      'path' => $path,
      'cache_hit' => $cache_hit,
      'type_filter' => $type ?? 'all',
      'query_hash' => $query_metadata['text_hash'],
      'query_length_bucket' => $query_metadata['length_bucket'],
      'query_redaction_profile' => $query_metadata['redaction_profile'],
      'vector_enabled_effective' => (bool) (($decision['enabled'] ?? $this->getVectorSearchConfig()['enabled']) ?? FALSE),
      'vector_attempted' => (bool) ($vector_outcome['attempted'] ?? FALSE),
      'vector_status' => (string) ($vector_outcome['status'] ?? 'not_evaluated'),
      'vector_decision_reason' => $decision['reason'] ?? 'not_evaluated',
      'degraded_reason' => in_array(($vector_outcome['status'] ?? ''), ['degraded', 'backoff'], TRUE)
        ? ($vector_outcome['reason'] ?? 'degraded')
        : NULL,
      'vector_elapsed_ms' => isset($vector_outcome['elapsed_ms']) ? (int) $vector_outcome['elapsed_ms'] : NULL,
      'result_count' => count($items),
      'lexical_result_count' => $lexical_result_count,
      'vector_result_count' => $vector_result_count,
      'source_classes' => array_keys($source_classes),
    ];
  }

  /**
   * Builds a result item from a Search API result.
   *
   * @param \Drupal\search_api\Item\ItemInterface $result_item
   *   The search result item.
   *
   * @return array|null
   *   The formatted result or NULL on failure.
   */
  protected function buildResultItem($result_item) {
    $paragraph = $result_item->getOriginalObject()->getValue();

    if (!$paragraph) {
      return NULL;
    }

    $type = $paragraph->bundle();
    $item = [
      'id' => 'faq_' . $paragraph->id(),
      'paragraph_id' => $paragraph->id(),
      'type' => $type,
    ];

    // Extract fields based on type.
    if ($type === 'faq_item') {
      $item['question'] = $this->getFieldValue($paragraph, 'field_faq_question');
      $item['answer'] = $this->getFieldValue($paragraph, 'field_faq_answer', TRUE);
      $item['answer_snippet'] = $this->truncate($item['answer'], 200);
      $item['title'] = $item['question']; // Alias for consistency.
    }
    elseif ($type === 'accordion_item') {
      $item['title'] = $this->getFieldValue($paragraph, 'field_accordion_title');
      $item['body'] = $this->getFieldValue($paragraph, 'field_accordion_body', TRUE);
      $item['answer'] = $item['body']; // Alias for FAQ-like response.
      $item['answer_snippet'] = $this->truncate($item['body'], 200);
      $item['question'] = $item['title']; // Alias for consistency.
    }

    // Get anchor ID.
    $custom_anchor = $this->getFieldValue($paragraph, 'field_anchor_id');
    $item['anchor'] = $custom_anchor ?: $this->generateAnchorSlug($item['question'] ?? $item['title']);

    // Get parent URL.
    $parent_info = $this->getParentInfo($paragraph);
    if (!$this->parentInfoMatchesCurrentLanguage($parent_info)) {
      return NULL;
    }

    $item['category'] = $parent_info['title'] ?? NULL;
    $item['parent_url'] = $parent_info['url'];
    $item['url'] = $item['parent_url'] . '#' . $item['anchor'];
    $item['source_url'] = $item['url'];
    $item['updated_at'] = $parent_info['changed'] ?? NULL;
    $item['source'] = 'lexical';

    if ($this->sourceGovernance) {
      $item = $this->sourceGovernance->annotateResult($item, 'faq_lexical');
    }

    return $item;
  }

  /**
   * Gets a field value from a paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity.
   * @param string $field_name
   *   The field name.
   * @param bool $strip_tags
   *   Whether to strip HTML tags.
   *
   * @return string
   *   The field value.
   */
  protected function getFieldValue($paragraph, $field_name, $strip_tags = FALSE) {
    if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $paragraph->get($field_name)->value ?? '';

    if ($strip_tags) {
      $value = strip_tags($value);
      $value = preg_replace('/\s+/', ' ', $value);
      $value = trim($value);
    }

    return $value;
  }

  /**
   * Generates an anchor slug from text.
   *
   * Matches the Twig slug transformation logic.
   *
   * @param string $text
   *   The text to convert.
   *
   * @return string
   *   The anchor slug.
   */
  protected function generateAnchorSlug(string $text) {
    // Lowercase.
    $slug = strtolower($text);

    // Replace spaces and slashes with hyphens.
    $slug = str_replace([' ', '/'], '-', $slug);

    // Remove punctuation.
    $slug = preg_replace('/[\'\"?:(),.!]/', '', $slug);

    // Collapse multiple hyphens.
    $slug = preg_replace('/-+/', '-', $slug);

    // Trim hyphens and limit length.
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 80);
    $slug = rtrim($slug, '-');

    return $slug;
  }

  /**
   * Gets parent node information for a paragraph.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity.
   *
   * @return array
   *   Array with 'title' and 'url' keys.
   */
  protected function getParentInfo($paragraph) {
    $parent = $paragraph->getParentEntity();

    // Walk up if nested in another paragraph.
    while ($parent && $parent->getEntityTypeId() === 'paragraph') {
      $parent = $parent->getParentEntity();
    }

    if ($parent && $parent->getEntityTypeId() === 'node') {
      try {
        return [
          'title' => $parent->getTitle(),
          'url' => $parent->toUrl()->toString(),
          'changed' => method_exists($parent, 'getChangedTime')
            ? (int) $parent->getChangedTime()
            : NULL,
          'langcode' => method_exists($parent, 'language') && $parent->language()
            ? $parent->language()->getId()
            : NULL,
        ];
      }
      catch (\Exception $e) {
        // Fall through to default.
      }
    }

    return [
      'title' => NULL,
      'url' => '',
      'changed' => NULL,
      'langcode' => NULL,
    ];
  }

  /**
   * Gets the default FAQ URL from config.
   *
   * @return string
   *   The default URL.
   */
  protected function getDefaultUrl() {
    if (!isset($this->configFactory) || !is_object($this->configFactory)) {
      return '/faq';
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    return $config->get('faq_node_path') ?? '/faq';
  }

  /**
   * Gets a specific FAQ/accordion by ID.
   *
   * @param string $id
   *   The item ID (format: faq_{paragraph_id}).
   *
   * @return array|null
   *   Item data or NULL if not found.
   */
  public function getById(string $id) {
    // Extract paragraph ID from faq_123 format.
    if (preg_match('/^faq_(\d+)$/', $id, $matches)) {
      $paragraph_id = $matches[1];

      try {
        $paragraph = $this->entityTypeManager
          ->getStorage('paragraph')
          ->load($paragraph_id);

        if ($paragraph) {
          // Create a mock result item structure.
          $item = $this->buildResultItemFromParagraph($paragraph);
          return ($item && $this->itemMatchesCurrentLanguage($item)) ? $item : NULL;
        }
      }
      catch (\Exception $e) {
        // Return NULL on error.
      }
    }

    return NULL;
  }

  /**
   * Builds a result item directly from a paragraph entity.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity.
   *
   * @return array|null
   *   The formatted result.
   */
  protected function buildResultItemFromParagraph($paragraph) {
    $type = $paragraph->bundle();

    if (!in_array($type, ['faq_item', 'accordion_item'])) {
      return NULL;
    }

    $item = [
      'id' => 'faq_' . $paragraph->id(),
      'paragraph_id' => $paragraph->id(),
      'type' => $type,
    ];

    if ($type === 'faq_item') {
      $item['question'] = $this->getFieldValue($paragraph, 'field_faq_question');
      $item['answer'] = $this->getFieldValue($paragraph, 'field_faq_answer', TRUE);
      $item['full_answer'] = $item['answer'];
      $item['title'] = $item['question'];
    }
    else {
      $item['title'] = $this->getFieldValue($paragraph, 'field_accordion_title');
      $item['body'] = $this->getFieldValue($paragraph, 'field_accordion_body', TRUE);
      $item['answer'] = $item['body'];
      $item['full_answer'] = $item['body'];
      $item['question'] = $item['title'];
    }

    $custom_anchor = $this->getFieldValue($paragraph, 'field_anchor_id');
    $item['anchor'] = $custom_anchor ?: $this->generateAnchorSlug($item['question']);

    $parent_info = $this->getParentInfo($paragraph);
    if (!$this->parentInfoMatchesCurrentLanguage($parent_info)) {
      return NULL;
    }

    $item['category'] = $parent_info['title'];
    $item['parent_url'] = $parent_info['url'];
    $item['url'] = $item['parent_url'] . '#' . $item['anchor'];
    $item['source_url'] = $item['url'];
    $item['updated_at'] = $parent_info['changed'] ?? NULL;
    $item['source'] = 'lexical';

    if ($this->sourceGovernance) {
      $item = $this->sourceGovernance->annotateResult($item, 'faq_lexical');
    }

    return $item;
  }

  /**
   * Gets FAQ categories/sections.
   *
   * @return array
   *   Array of category names with counts.
   */
  public function getCategories() {
    $index = $this->getIndex();

    if (!$index || !$index->status()) {
      return $this->getCategoriesLegacy();
    }

    // Get all items and group by parent.
    $search_query = $index->query();
    $search_query->range(0, 1000); // Get all.
    $search_query->addCondition('search_api_language', $this->getCurrentLanguage());
    $search_query->addCondition('paragraph_type', 'faq_item');

    try {
      $results = $search_query->execute();
      $categories = [];

      foreach ($results->getResultItems() as $result_item) {
        try {
          $item = $this->buildResultItem($result_item);
          if ($item && $this->itemMatchesCurrentLanguage($item)) {
            $category = $item['category'] ?? 'General';

            if (!isset($categories[$category])) {
              $categories[$category] = ['name' => $category, 'count' => 0];
            }
            $categories[$category]['count']++;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      return array_values($categories);
    }
    catch (\Exception $e) {
      return $this->getCategoriesLegacy();
    }
  }

  /**
   * Truncates text to a maximum length.
   *
   * @param string $text
   *   The text to truncate.
   * @param int $length
   *   Maximum length.
   *
   * @return string
   *   Truncated text.
   */
  protected function truncate(string $text, int $length) {
    if (mb_strlen($text) <= $length) {
      return $text;
    }
    return mb_substr($text, 0, $length) . '...';
  }

  /**
   * Clears the cache.
   */
  public function clearCache() {
    $this->cache->delete(self::PARENT_URL_CACHE_ID);
  }

  // =========================================================================
  // Vector search methods (Pinecone fallback enrichment)
  // =========================================================================

  /**
   * Gets the vector search configuration.
   *
   * @return array
   *   Vector search config with keys: enabled, faq_index_id,
   *   fallback_threshold, min_vector_score, score_normalization_factor,
   *   min_lexical_score.
   */
  protected function getVectorSearchConfig(): array {
    if (!isset($this->configFactory) || !is_object($this->configFactory)) {
      return ['enabled' => FALSE];
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');
    return $config->get('vector_search') ?? ['enabled' => FALSE];
  }

  /**
   * Validates that a vector index uses cosine similarity metric.
   *
   * Our score thresholds (min_vector_score) and normalization assume cosine
   * similarity scores in the 0-1 range. Other metrics (euclidean, dotproduct)
   * have different score ranges and semantics that would break scoring logic.
   *
   * @param \Drupal\search_api\Entity\Index $vector_index
   *   The Search API vector index to validate.
   *
   * @return bool
   *   TRUE if the index uses cosine similarity, FALSE otherwise.
   */
  protected function validateVectorMetric($vector_index): bool {
    try {
      $server = $vector_index->getServerInstance();
      if (!$server) {
        return FALSE;
      }
      $backend_config = $server->getBackendConfig();
      $metric = $backend_config['database_settings']['metric'] ?? '';
      if ($metric !== 'cosine_similarity') {
        \Drupal::logger('ilas_site_assistant')->warning(
          'Vector search metric mismatch: expected cosine_similarity, got @metric.',
          ['@metric' => $metric]
        );
        return FALSE;
      }
      return TRUE;
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')->warning(
        'Could not validate vector search metric: @class @error_signature',
        [
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Builds the lexical-vs-vector trigger decision before any vector query.
   *
   * @param array $lexical_items
   *   Results from lexical search.
   *
   * @return array
   *   Trigger decision metadata.
   */
  protected function buildVectorDecisionMap(array $lexical_items): array {
    $vector_config = $this->getVectorSearchConfig();
    $best_score = !empty($lexical_items) ? (float) max(array_column($lexical_items, 'score') ?: [0]) : 0.0;
    $decision = [
      'enabled' => !empty($vector_config['enabled']),
      'should_attempt' => FALSE,
      'reason' => 'disabled',
      'lexical_count' => count($lexical_items),
      'best_lexical_score' => $best_score,
    ];

    if (!$decision['enabled']) {
      return $decision;
    }

    $threshold = (int) ($vector_config['fallback_threshold'] ?? 2);
    $min_lexical_score = (float) ($vector_config['min_lexical_score'] ?? 0);

    if ($decision['lexical_count'] < $threshold) {
      $decision['should_attempt'] = TRUE;
      $decision['reason'] = 'sparse_lexical';
      return $decision;
    }

    if ($min_lexical_score > 0 && $decision['lexical_count'] > 0 && $best_score < $min_lexical_score) {
      $decision['should_attempt'] = TRUE;
      $decision['reason'] = 'low_quality_lexical';
      return $decision;
    }

    $decision['reason'] = 'sufficient_lexical';
    return $decision;
  }

  /**
   * Creates a normalized vector outcome payload.
   *
   * @param bool $attempted
   *   Whether vector retrieval was attempted.
   * @param string $status
   *   Outcome status.
   * @param string $reason
   *   Outcome reason.
   * @param array $items
   *   Vector items.
   * @param int|null $elapsed_ms
   *   Vector query duration in milliseconds, if known.
   * @param bool $cacheable
   *   Whether lexical results may be cached alongside this outcome.
   *
   * @return array
   *   Normalized outcome payload.
   */
  protected function buildVectorOutcome(
    bool $attempted,
    string $status,
    string $reason,
    array $items = [],
    ?int $elapsed_ms = NULL,
    bool $cacheable = TRUE,
  ): array {
    return [
      'attempted' => $attempted,
      'status' => $status,
      'reason' => $reason,
      'elapsed_ms' => $elapsed_ms,
      'cacheable' => $cacheable,
      'items' => $items,
    ];
  }

  /**
   * Normalizes vector helper returns for production code and unit-test doubles.
   *
   * @param array $vector_result
   *   Either a normalized outcome payload or a raw vector-item list.
   *
   * @return array
   *   Normalized outcome payload.
   */
  protected function normalizeVectorOutcome(array $vector_result): array {
    if (array_key_exists('status', $vector_result) && array_key_exists('items', $vector_result)) {
      return $vector_result + $this->buildVectorOutcome(FALSE, 'healthy', 'unknown');
    }

    $items = array_values($vector_result);
    return $this->buildVectorOutcome(
      TRUE,
      empty($items) ? 'healthy_empty' : 'healthy',
      empty($items) ? 'no_results_above_threshold' : 'results_available',
      $items,
      NULL,
      TRUE,
    );
  }

  /**
   * Returns whether the vector outcome allows normal query-cache writes.
   *
   * @param array $vector_outcome
   *   The vector outcome payload.
   *
   * @return bool
   *   TRUE when the query results are cacheable.
   */
  protected function isVectorOutcomeCacheable(array $vector_outcome): bool {
    return (bool) ($vector_outcome['cacheable'] ?? TRUE);
  }

  /**
   * Returns the active vector backoff-until timestamp.
   *
   * @return int
   *   Epoch seconds, or 0 when no backoff is active.
   */
  protected function getVectorBackoffUntil(): int {
    if (!$this->cache instanceof CacheBackendInterface) {
      return 0;
    }

    $cached = $this->cache->get(self::VECTOR_BACKOFF_CACHE_ID);
    if (!$cached) {
      return 0;
    }

    $until = (int) ($cached->data ?? 0);
    if ($until <= time()) {
      $this->cache->delete(self::VECTOR_BACKOFF_CACHE_ID);
      return 0;
    }

    return $until;
  }

  /**
   * Activates cross-request vector backoff.
   *
   * @return int
   *   The backoff-until timestamp.
   */
  protected function activateVectorBackoff(): int {
    $until = time() + self::VECTOR_BACKOFF_SECONDS;
    if ($this->cache instanceof CacheBackendInterface) {
      $this->cache->set(self::VECTOR_BACKOFF_CACHE_ID, $until, $until, [
        'config:ilas_site_assistant.settings',
      ]);
    }
    return $until;
  }

  /**
   * Supplements lexical results with vector search when results are sparse.
   *
   * Only fires when vector search is enabled and lexical results are below
   * the fallback threshold. Merges by paragraph_id, keeping the higher-scored
   * version of any duplicate.
   *
   * @param array $lexical_items
   *   Results from lexical search.
   * @param string $query
   *   The original search query.
   * @param int $limit
   *   Maximum total results to return.
   * @param string|null $type
   *   Optional paragraph type filter.
   *
   * @return array
   *   Merged results, limited to $limit.
   */
  protected function supplementWithVectorResults(array $lexical_items, string $query, int $limit, ?string $type = NULL): array {
    return $this->supplementWithVectorResultsDetailed($lexical_items, $query, $limit, $type)['items'];
  }

  /**
   * Supplements lexical results and returns the decision and outcome metadata.
   *
   * @param array $lexical_items
   *   Results from lexical search.
   * @param string $query
   *   The original search query.
   * @param int $limit
   *   Maximum total results to return.
   * @param string|null $type
   *   Optional paragraph type filter.
   *
   * @return array
   *   Structured payload containing merged items, decision, and outcome.
   */
  protected function supplementWithVectorResultsDetailed(array $lexical_items, string $query, int $limit, ?string $type = NULL): array {
    $decision = $this->buildVectorDecisionMap($lexical_items);
    if (!$decision['should_attempt']) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $this->buildVectorOutcome(FALSE, 'healthy', $decision['reason']),
      ];
    }

    $vector_outcome = $this->normalizeVectorOutcome($this->searchVector($query, $limit, $type));
    if (!in_array($vector_outcome['status'] ?? 'healthy', ['healthy', 'healthy_empty'], TRUE)) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $vector_outcome,
      ];
    }

    $vector_items = array_values(array_filter(
      $vector_outcome['items'] ?? [],
      fn(array $item): bool => $this->itemMatchesCurrentLanguage($item),
    ));
    $vector_outcome['items'] = $vector_items;
    if (empty($vector_items)) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $vector_outcome,
      ];
    }

    // Build a map of existing items keyed by paragraph_id.
    $merged = [];
    foreach ($lexical_items as $item) {
      $pid = $item['paragraph_id'] ?? $item['id'];
      $merged[$pid] = $item;
    }

    // Merge vector items, applying lexical priority boost for comparison.
    foreach ($vector_items as $item) {
      $pid = $item['paragraph_id'] ?? $item['id'];
      if (!isset($merged[$pid])) {
        $merged[$pid] = $item;
      }
      else {
        // Apply lexical priority boost for comparison purposes only.
        $existing_score = $merged[$pid]['score'] ?? 0;
        $new_score = $item['score'] ?? 0;
        $existing_is_lexical = ($merged[$pid]['source'] ?? 'lexical') !== 'vector';
        $effective_existing = $existing_is_lexical ? $existing_score + RetrievalContract::LEXICAL_PRIORITY_BOOST : $existing_score;
        $new_is_lexical = ($item['source'] ?? 'lexical') !== 'vector';
        $effective_new = $new_is_lexical ? $new_score + RetrievalContract::LEXICAL_PRIORITY_BOOST : $new_score;
        if ($effective_new > $effective_existing) {
          $merged[$pid] = $item;
        }
      }
    }

    // Sort by score descending and limit.
    $results = array_values($merged);
    usort($results, function ($a, $b) {
      return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    $results = array_slice($results, 0, $limit);

    // Ensure at least MIN_LEXICAL_PRESERVED lexical results if any existed.
    $results = $this->enforceMinLexicalPreserved($results, $lexical_items, $limit);

    // Log vector supplementation for observability.
    try {
      \Drupal::logger('ilas_site_assistant')->info('FAQ retrieval contract: vector supplemented lexical. lexical_count=@lc vector_count=@vc merged_count=@mc policy=@p', [
        '@lc' => count($lexical_items),
        '@vc' => count($vector_items),
        '@mc' => count($results),
        '@p' => RetrievalContract::POLICY_VERSION,
      ]);
    }
    catch (\Throwable $e) {
      // Logger unavailable outside Drupal bootstrap (unit tests).
    }

    return [
      'items' => $results,
      'decision' => $decision,
      'vector_outcome' => $vector_outcome,
    ];
  }

  /**
   * Ensures minimum lexical results are preserved in merged output.
   *
   * When vector results dominate the merge output and push all lexical results
   * below the limit cut, this method replaces the lowest-scoring vector result
   * with the highest-scoring lexical result from the original input.
   *
   * @param array $results
   *   Current merge output (sorted by score, sliced to limit).
   * @param array $lexical_items
   *   Original lexical input items.
   * @param int $limit
   *   Maximum results allowed.
   *
   * @return array
   *   Adjusted results with minimum lexical preservation enforced.
   */
  protected function enforceMinLexicalPreserved(array $results, array $lexical_items, int $limit): array {
    if (empty($lexical_items)) {
      return $results;
    }

    // Count lexical results currently in output.
    $lexical_count = 0;
    foreach ($results as $item) {
      if (($item['source'] ?? 'lexical') !== 'vector') {
        $lexical_count++;
      }
    }

    if ($lexical_count >= RetrievalContract::MIN_LEXICAL_PRESERVED) {
      return $results;
    }

    // Build set of IDs already in output.
    $output_ids = [];
    foreach ($results as $item) {
      $output_ids[$item['paragraph_id'] ?? $item['id']] = TRUE;
    }

    // Find highest-scoring lexical item not already in output.
    $best_lexical = NULL;
    foreach ($lexical_items as $item) {
      $pid = $item['paragraph_id'] ?? $item['id'];
      if (isset($output_ids[$pid])) {
        continue;
      }
      if ($best_lexical === NULL || ($item['score'] ?? 0) > ($best_lexical['score'] ?? 0)) {
        $best_lexical = $item;
      }
    }

    if ($best_lexical === NULL) {
      return $results;
    }

    // Replace the lowest-scoring vector item in output.
    $worst_vector_idx = NULL;
    $worst_vector_score = PHP_INT_MAX;
    foreach ($results as $idx => $item) {
      if (($item['source'] ?? 'lexical') === 'vector' && ($item['score'] ?? 0) < $worst_vector_score) {
        $worst_vector_score = $item['score'] ?? 0;
        $worst_vector_idx = $idx;
      }
    }

    if ($worst_vector_idx !== NULL) {
      $results[$worst_vector_idx] = $best_lexical;
    }

    return $results;
  }

  /**
   * Searches FAQs using the vector (Pinecone) index.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   * @param string|null $type
   *   Optional paragraph type filter.
   *
   * @return array
   *   Structured vector outcome payload.
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    $backoff_until = $this->getVectorBackoffUntil();
    if ($backoff_until > time()) {
      return $this->buildVectorOutcome(FALSE, 'backoff', 'backoff_active', [], NULL, FALSE) + [
        'backoff_until' => $backoff_until,
      ];
    }

    $vector_config = $this->getVectorSearchConfig();
    $index_id = $this->retrievalConfiguration->getFaqVectorIndexId();
    $min_score = $vector_config['min_vector_score'] ?? 0.70;
    $normalization = $vector_config['score_normalization_factor'] ?? 100;

    if ($index_id === NULL) {
      \Drupal::logger('ilas_site_assistant')->warning('Vector FAQ search skipped because no vector index is configured.');
      $this->activateVectorBackoff();
      return $this->buildVectorOutcome(TRUE, 'degraded', 'index_id_unconfigured', [], NULL, FALSE);
    }

    try {
      $vector_index = Index::load($index_id);
      if (!$vector_index || !$vector_index->status()) {
        \Drupal::logger('ilas_site_assistant')->warning('Vector FAQ index @index is unavailable; preserving lexical results.', [
          '@index' => $index_id,
        ]);
        $this->activateVectorBackoff();
        return $this->buildVectorOutcome(TRUE, 'degraded', 'index_unavailable', [], NULL, FALSE);
      }

      // Validate that the index uses cosine similarity metric.
      if (!$this->validateVectorMetric($vector_index)) {
        $this->activateVectorBackoff();
        return $this->buildVectorOutcome(TRUE, 'degraded', 'metric_validation_failed', [], NULL, FALSE);
      }

      $search_query = $vector_index->query();
      $search_query->keys($query);
      $search_query->range(0, $limit);

      // Filter by current language.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      // Filter by paragraph type if specified.
      if ($type) {
        $search_query->addCondition('paragraph_type', $type);
      }

      $start_time = microtime(TRUE);
      $results = $search_query->execute();
      $elapsed_ms = round((microtime(TRUE) - $start_time) * 1000);

      if ($elapsed_ms > self::MAX_VECTOR_MS) {
        $this->activateVectorBackoff();
        \Drupal::logger('ilas_site_assistant')->warning(
          'Vector FAQ search exceeded @ms ms (threshold @thresh). Entering backoff.',
          ['@ms' => $elapsed_ms, '@thresh' => self::MAX_VECTOR_MS]
        );
        return $this->buildVectorOutcome(TRUE, 'degraded', 'latency_budget_exceeded', [], $elapsed_ms, FALSE);
      }

      $items = [];

      foreach ($results->getResultItems() as $result_item) {
        try {
          // Get the raw cosine similarity score (0-1).
          $raw_score = $result_item->getScore() ?? 0;

          // Skip results below the minimum vector score threshold.
          if ($raw_score < $min_score) {
            continue;
          }

          $item = $this->buildResultItem($result_item);
          if ($item) {
            if (!$this->itemMatchesCurrentLanguage($item)) {
              continue;
            }
            // Normalize vector score to be comparable with lexical scores.
            $item['score'] = $raw_score * $normalization;
            $item['vector_score'] = $raw_score;
            $item['source'] = 'vector';
            if ($this->sourceGovernance) {
              $item = $this->sourceGovernance->annotateResult($item, 'faq_vector');
            }
            $items[] = $item;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      if (!empty($items)) {
        $query_metadata = ObservabilityPayloadMinimizer::buildTextMetadata($query);
        \Drupal::logger('ilas_site_assistant')->info('Vector FAQ search returned @count results (@ms ms) for query_hash=@query_hash length=@length_bucket profile=@redaction_profile', [
          '@count' => count($items),
          '@ms' => $elapsed_ms,
          '@query_hash' => $query_metadata['text_hash'],
          '@length_bucket' => $query_metadata['length_bucket'],
          '@redaction_profile' => $query_metadata['redaction_profile'],
        ]);
      }

      return $this->buildVectorOutcome(
        TRUE,
        empty($items) ? 'healthy_empty' : 'healthy',
        empty($items) ? 'no_results_above_threshold' : 'results_available',
        $items,
        $elapsed_ms,
        TRUE,
      );
    }
    catch (\Exception $e) {
      $exception_class = get_class($e);
      $level = 'error';
      $category = 'unexpected';

      if ($e instanceof \Drupal\search_api\SearchApiException) {
        $category = 'search_api';
        $level = 'warning';
      }
      elseif (str_contains($exception_class, 'GuzzleHttp') || str_contains($exception_class, 'ConnectException')) {
        $category = 'http_transport';
        $level = 'warning';
      }
      elseif (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'does not exist')) {
        $category = 'index_not_found';
      }

      $this->activateVectorBackoff();
      \Drupal::logger('ilas_site_assistant')->log($level,
        'Vector FAQ search failed [@category]: @class @error_signature (backing off @seconds s)', [
          '@category' => $category,
          '@class' => $exception_class,
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
          '@seconds' => self::VECTOR_BACKOFF_SECONDS,
        ]
      );
      return $this->buildVectorOutcome(TRUE, 'degraded', $category, [], NULL, FALSE);
    }
  }

  // =========================================================================
  // Legacy methods (fallback when Search API unavailable)
  // =========================================================================

  /**
   * Legacy search using direct entity queries.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results.
   *
   * @return array
   *   Search results.
   */
  protected function searchLegacy(string $query, int $limit) {
    $all_items = $this->loadLegacySearchItems($query, $limit);
    $all_items = $this->filterItemsByCurrentLanguage($all_items);

    // Use enhanced ranking if available.
    if ($this->rankingEnhancer) {
      $items = $this->rankingEnhancer->scoreFaqResults($all_items, $query, $limit);
      if ($this->sourceGovernance) {
        $items = $this->sourceGovernance->annotateBatch($items, 'faq_lexical');
      }
      return $items;
    }

    // Fallback to basic ranking.
    $query_lower = strtolower($query);
    $query_keywords = $this->extractKeywords($query);

    $results = [];

    foreach ($all_items as $item) {
      $score = 0;
      $question_lower = strtolower($item['question'] ?? $item['title'] ?? '');
      $answer_lower = strtolower($item['answer'] ?? '');

      // Exact substring match in question.
      if (strpos($question_lower, $query_lower) !== FALSE) {
        $score += 10;
      }

      // Word match in question.
      foreach ($query_keywords as $keyword) {
        if (strpos($question_lower, $keyword) !== FALSE) {
          $score += 3;
        }
      }

      // Keyword overlap.
      $item_keywords = $this->extractKeywords($item['question'] . ' ' . $item['answer']);
      $overlap = array_intersect($query_keywords, $item_keywords);
      $score += count($overlap) * 2;

      // Substring match in answer.
      if (strpos($answer_lower, $query_lower) !== FALSE) {
        $score += 2;
      }

      if ($score > 0) {
        $item['score'] = $score;
        $item['source'] = 'lexical';
        $results[] = $item;
      }
    }

    usort($results, function ($a, $b) {
      return $b['score'] - $a['score'];
    });

    $results = array_slice($results, 0, $limit);
    if ($this->sourceGovernance) {
      $results = $this->sourceGovernance->annotateBatch($results, 'faq_lexical');
    }
    return $results;
  }

  /**
   * Loads a bounded set of FAQ/accordion candidates for legacy search.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   The requested result limit.
   *
   * @return array
   *   Candidate FAQ items keyed by item ID.
   */
  protected function loadLegacySearchItems(string $query, int $limit): array {
    if ($limit <= 0) {
      return [];
    }

    $candidate_limit = $this->getLegacyCandidateLimit($limit);
    $candidate_ids = array_unique(array_merge(
      $this->queryLegacyParagraphIdsByBundle('faq_item', $query, $candidate_limit, [
        'field_faq_question',
        'field_faq_answer',
      ]),
      $this->queryLegacyParagraphIdsByBundle('accordion_item', $query, $candidate_limit, [
        'field_accordion_title',
        'field_accordion_body',
      ]),
    ));

    if ($candidate_ids === []) {
      return [];
    }

    rsort($candidate_ids, SORT_NUMERIC);
    $candidate_ids = array_slice($candidate_ids, 0, $candidate_limit);

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraphs = $paragraph_storage->loadMultiple($candidate_ids);
    $items = [];

    foreach ($candidate_ids as $paragraph_id) {
      if (!isset($paragraphs[$paragraph_id])) {
        continue;
      }
      $item = $this->buildResultItemFromParagraph($paragraphs[$paragraph_id]);
      if ($item) {
        $items[$item['id']] = $item;
      }
    }

    return $items;
  }

  /**
   * Queries bounded paragraph candidate IDs for one legacy FAQ bundle.
   *
   * @param string $bundle
   *   The paragraph bundle.
   * @param string $query
   *   The search query.
   * @param int $candidate_limit
   *   Maximum paragraph IDs to return.
   * @param array $fields
   *   Bundle-specific text fields used for matching.
   *
   * @return array
   *   Candidate paragraph IDs.
   */
  protected function queryLegacyParagraphIdsByBundle(string $bundle, string $query, int $candidate_limit, array $fields): array {
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $entity_query = $paragraph_storage->getQuery()
      ->condition('type', $bundle)
      ->accessCheck(TRUE)
      ->sort('id', 'DESC')
      ->range(0, $candidate_limit);

    $match_group = $entity_query->orConditionGroup();
    $has_match_conditions = FALSE;
    $normalized_query = trim($query);
    $keywords = array_values(array_unique($this->extractKeywords($query)));

    if ($normalized_query !== '') {
      foreach ($fields as $field) {
        $match_group->condition($field . '.value', $normalized_query, 'CONTAINS');
      }
      $has_match_conditions = TRUE;
    }

    foreach (array_slice($keywords, 0, 5) as $keyword) {
      foreach ($fields as $field) {
        $match_group->condition($field . '.value', $keyword, 'CONTAINS');
      }
      $has_match_conditions = TRUE;
    }

    if ($has_match_conditions) {
      $entity_query->condition($match_group);
    }

    return array_values($entity_query->execute());
  }

  /**
   * Returns the bounded candidate limit for legacy retrieval.
   */
  protected function getLegacyCandidateLimit(int $limit): int {
    return min(
      max($limit * self::LEGACY_CANDIDATE_MULTIPLIER, self::LEGACY_CANDIDATE_MIN),
      self::LEGACY_CANDIDATE_MAX
    );
  }

  /**
   * Gets all FAQs using legacy method.
   *
   * @return array
   *   All FAQ items.
   */
  protected function getAllFaqsLegacy() {
    $cache = $this->cache->get('ilas_site_assistant.faq_legacy');
    if ($cache) {
      return $cache->data;
    }

    $items = [];
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');

    // Load all faq_item paragraphs.
    $faq_items = $paragraph_storage->loadByProperties(['type' => 'faq_item']);
    foreach ($faq_items as $paragraph) {
      $item = $this->buildResultItemFromParagraph($paragraph);
      if ($item) {
        $items[$item['id']] = $item;
      }
    }

    // Load accordion_item paragraphs.
    $accordion_items = $paragraph_storage->loadByProperties(['type' => 'accordion_item']);
    foreach ($accordion_items as $paragraph) {
      $item = $this->buildResultItemFromParagraph($paragraph);
      if ($item) {
        $items[$item['id']] = $item;
      }
    }

    // Cache for 1 hour.
    $this->cache->set('ilas_site_assistant.faq_legacy', $items, time() + 3600, [
      'paragraph_list',
    ]);

    return $items;
  }

  /**
   * Gets categories using legacy method.
   *
   * @return array
   *   Categories with counts.
   */
  protected function getCategoriesLegacy() {
    $items = $this->filterItemsByCurrentLanguage($this->getAllFaqsLegacy());
    $categories = [];

    foreach ($items as $item) {
      $category = $item['category'] ?? 'General';
      if (!isset($categories[$category])) {
        $categories[$category] = ['name' => $category, 'count' => 0];
      }
      $categories[$category]['count']++;
    }

    return array_values($categories);
  }

  /**
   * Extracts keywords from text.
   *
   * @param string $text
   *   The text.
   *
   * @return array
   *   Keywords.
   */
  protected function extractKeywords(string $text) {
    $text = strtolower($text);
    $text = preg_replace('/[^\w\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text);

    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had',
      'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might',
      'can', 'this', 'that', 'these', 'those', 'i', 'you', 'we', 'they', 'it',
      'my', 'your', 'our', 'their', 'its', 'what', 'which', 'who', 'whom',
      'how', 'when', 'where', 'why', 'if', 'then', 'so', 'as', 'at', 'by',
    ];

    return array_values(array_unique(array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) >= 3 && !in_array($word, $stop_words);
    })));
  }

}
