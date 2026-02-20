<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
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
   * Timestamp (epoch seconds) until which vector search is skipped due to a
   * recent timeout/failure. Static so it applies across instances per request.
   *
   * @var int
   */
  protected static int $vectorBackoffUntil = 0;

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
   * The ranking enhancer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\RankingEnhancer
   */
  protected $rankingEnhancer;

  /**
   * The Search API index.
   *
   * @var \Drupal\search_api\IndexInterface|null
   */
  protected $index;

  /**
   * Cache ID for parent URL mappings.
   */
  const PARENT_URL_CACHE_ID = 'ilas_site_assistant.parent_urls';

  /**
   * The Search API index ID.
   */
  const INDEX_ID = 'faq_accordion';

  /**
   * Constructs a FaqIndex object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LanguageManagerInterface $language_manager,
    RankingEnhancer $ranking_enhancer = NULL
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->rankingEnhancer = $ranking_enhancer;
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
   * Gets the Search API index.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index or NULL if not available.
   */
  protected function getIndex() {
    if ($this->index === NULL) {
      $this->index = Index::load(self::INDEX_ID);
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
        return $cached->data;
      }
    }

    $index = $this->getIndex();

    // Fall back to legacy method if index not available.
    if (!$index || !$index->status()) {
      return $this->searchLegacy($query, $limit);
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

      // Supplement with vector search if lexical results are sparse.
      $items = $this->supplementWithVectorResults($items, $query, $limit, $type);

      if ($cache_key) {
        $this->cache->set($cache_key, $items, time() + self::QUERY_CACHE_TTL, [
          'paragraph_list',
          'config:ilas_site_assistant.settings',
        ]);
      }
      return $items;
    }
    catch (\Exception $e) {
      // Fall back to legacy on error.
      \Drupal::logger('ilas_site_assistant')->warning('Search API query failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $results = $this->searchLegacy($query, $limit);
      if ($cache_key) {
        $this->cache->set($cache_key, $results, time() + self::QUERY_CACHE_TTL, [
          'paragraph_list',
          'config:ilas_site_assistant.settings',
        ]);
      }
      return $results;
    }
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
    $item['category'] = $parent_info['title'] ?? NULL;
    $item['parent_url'] = $parent_info['url'] ?? $this->getDefaultUrl();
    $item['url'] = $item['parent_url'] . '#' . $item['anchor'];
    $item['source_url'] = $item['url'];
    $item['source'] = 'lexical';

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
        ];
      }
      catch (\Exception $e) {
        // Fall through to default.
      }
    }

    return [
      'title' => NULL,
      'url' => $this->getDefaultUrl(),
    ];
  }

  /**
   * Gets the default FAQ URL from config.
   *
   * @return string
   *   The default URL.
   */
  protected function getDefaultUrl() {
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
          return $this->buildResultItemFromParagraph($paragraph);
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
    $item['category'] = $parent_info['title'];
    $item['parent_url'] = $parent_info['url'];
    $item['url'] = $item['parent_url'] . '#' . $item['anchor'];
    $item['source_url'] = $item['url'];
    $item['source'] = 'lexical';

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
    $search_query->addCondition('paragraph_type', 'faq_item');

    try {
      $results = $search_query->execute();
      $categories = [];

      foreach ($results->getResultItems() as $result_item) {
        try {
          $paragraph = $result_item->getOriginalObject()->getValue();
          if ($paragraph) {
            $parent_info = $this->getParentInfo($paragraph);
            $category = $parent_info['title'] ?? 'General';

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
        'Could not validate vector search metric: @message',
        ['@message' => $e->getMessage()]
      );
      return FALSE;
    }
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
    $vector_config = $this->getVectorSearchConfig();

    if (empty($vector_config['enabled'])) {
      return $lexical_items;
    }

    $threshold = $vector_config['fallback_threshold'] ?? 2;
    $min_lexical_score = $vector_config['min_lexical_score'] ?? 0;

    // Fire vector search when lexical results are sparse (count-based) OR
    // when all lexical results score below the quality threshold.
    $has_enough_results = count($lexical_items) >= $threshold;
    $has_quality_results = TRUE;
    if ($min_lexical_score > 0 && !empty($lexical_items)) {
      $best_score = max(array_column($lexical_items, 'score') ?: [0]);
      $has_quality_results = $best_score >= $min_lexical_score;
    }

    if ($has_enough_results && $has_quality_results) {
      return $lexical_items;
    }

    $vector_items = $this->searchVector($query, $limit, $type);

    if (empty($vector_items)) {
      return $lexical_items;
    }

    // Build a map of existing items keyed by paragraph_id.
    $merged = [];
    foreach ($lexical_items as $item) {
      $pid = $item['paragraph_id'] ?? $item['id'];
      $merged[$pid] = $item;
    }

    // Merge vector items, keeping higher-scored version for duplicates.
    foreach ($vector_items as $item) {
      $pid = $item['paragraph_id'] ?? $item['id'];
      if (!isset($merged[$pid])) {
        $merged[$pid] = $item;
      }
      elseif (($item['score'] ?? 0) > ($merged[$pid]['score'] ?? 0)) {
        $merged[$pid] = $item;
      }
    }

    // Sort by score descending and limit.
    $results = array_values($merged);
    usort($results, function ($a, $b) {
      return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    return array_slice($results, 0, $limit);
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
   *   Array of matching items with normalized scores.
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    // Quick circuit breaker: skip if we've recently seen a failure/timeout.
    if (time() < static::$vectorBackoffUntil) {
      return [];
    }

    $vector_config = $this->getVectorSearchConfig();
    $index_id = $vector_config['faq_index_id'] ?? 'faq_accordion_vector';
    $min_score = $vector_config['min_vector_score'] ?? 0.70;
    $normalization = $vector_config['score_normalization_factor'] ?? 100;

    try {
      $vector_index = Index::load($index_id);
      if (!$vector_index || !$vector_index->status()) {
        return [];
      }

      // Validate that the index uses cosine similarity metric.
      if (!$this->validateVectorMetric($vector_index)) {
        return [];
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
        static::$vectorBackoffUntil = time() + self::VECTOR_BACKOFF_SECONDS;
        \Drupal::logger('ilas_site_assistant')->warning(
          'Vector FAQ search exceeded @ms ms (threshold @thresh). Entering backoff.',
          ['@ms' => $elapsed_ms, '@thresh' => self::MAX_VECTOR_MS]
        );
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
            // Normalize vector score to be comparable with lexical scores.
            $item['score'] = $raw_score * $normalization;
            $item['vector_score'] = $raw_score;
            $item['source'] = 'vector';
            $items[] = $item;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      if (!empty($items)) {
        \Drupal::logger('ilas_site_assistant')->info('Vector FAQ search returned @count results (@ms ms) for query "@query"', [
          '@count' => count($items),
          '@ms' => $elapsed_ms,
          '@query' => PiiRedactor::redactForLog($query),
        ]);
      }

      return $items;
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

      static::$vectorBackoffUntil = time() + self::VECTOR_BACKOFF_SECONDS;
      \Drupal::logger('ilas_site_assistant')->log($level,
        'Vector FAQ search failed [@category]: @class - @message (backing off @seconds s)', [
          '@category' => $category,
          '@class' => $exception_class,
          '@message' => $e->getMessage(),
          '@seconds' => self::VECTOR_BACKOFF_SECONDS,
        ]
      );
      return [];
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
    $all_items = $this->getAllFaqsLegacy();

    // Use enhanced ranking if available.
    if ($this->rankingEnhancer) {
      return $this->rankingEnhancer->scoreFaqResults($all_items, $query, $limit);
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

    return array_slice($results, 0, $limit);
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
    $items = $this->getAllFaqsLegacy();
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
