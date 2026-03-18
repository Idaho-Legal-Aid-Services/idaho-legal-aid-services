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
 * Service for finding forms, guides, and resources.
 *
 * Uses Search API content index for text search with fallback to
 * direct entity queries when the index is unavailable.
 *
 * When vector search is enabled, supplements sparse lexical results with
 * semantic search from a Pinecone-backed vector index.
 */
class ResourceFinder {

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
   */
  const QUERY_CACHE_TTL = 300;

  /**
   * Maximum tolerated duration for a vector search call (ms).
   */
  const MAX_VECTOR_MS = 2000;

  /**
   * Backoff duration after a vector search timeout/failure (seconds).
   */
  const VECTOR_BACKOFF_SECONDS = 120;

  /**
   * Cache key used to suppress repeated degraded vector attempts.
   */
  const VECTOR_BACKOFF_CACHE_ID = 'ilas_site_assistant.vector_backoff.resource';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The topic resolver service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicResolver
   */
  protected $topicResolver;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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
   * Request-local retrieval telemetry summaries for observability traces.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $retrievalTelemetry = [];

  /**
   * The source freshness/provenance governance service.
   *
   * @var \Drupal\ilas_site_assistant\Service\SourceGovernanceService|null
   */
  protected $sourceGovernance;

  /**
   * Retrieval configuration resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\RetrievalConfigurationService|null
   */
  protected ?RetrievalConfigurationService $retrievalConfiguration = NULL;

  /**
   * Cache ID for resource data.
   */
  const CACHE_ID = 'ilas_site_assistant.resources';

  /**
   * Constructs a ResourceFinder object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TopicResolver $topic_resolver,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager,
    RankingEnhancer $ranking_enhancer = NULL,
    ConfigFactoryInterface $config_factory = NULL,
    RetrievalConfigurationService $retrieval_configuration = NULL,
    SourceGovernanceService $source_governance = NULL
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->topicResolver = $topic_resolver;
    $this->cache = $cache;
    $this->languageManager = $language_manager;
    $this->rankingEnhancer = $ranking_enhancer;
    $this->configFactory = $config_factory;
    $this->retrievalConfiguration = $retrieval_configuration;
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
   * Gets the Search API index.
   *
   * Tries the dedicated assistant_resources index first. Falls back to the
   * generic content index if the dedicated index is unavailable.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index or NULL if not available.
   */
  protected function getIndex() {
    if ($this->index === NULL) {
      // Try dedicated assistant resources index first.
      $index_id = $this->retrievalConfiguration?->getResourceIndexId();
      $this->index = $index_id ? Index::load($index_id) : NULL;
      if (!$this->index || !$this->index->status()) {
        // Fall back to generic content index.
        $fallback_index_id = $this->retrievalConfiguration?->getResourceFallbackIndexId();
        $this->index = $fallback_index_id ? Index::load($fallback_index_id) : NULL;
      }
    }
    return $this->index;
  }

  /**
   * Checks if the dedicated assistant resources index is in use.
   *
   * @return bool
   *   TRUE if using the dedicated index, FALSE if using fallback.
   */
  public function isUsingDedicatedIndex(): bool {
    $index = $this->getIndex();
    $configured_index_id = $this->retrievalConfiguration?->getResourceIndexId();
    return $index && $configured_index_id !== NULL && $index->id() === $configured_index_id;
  }

  /**
   * Checks if Search API index is available.
   *
   * @return bool
   *   TRUE if the index is available and usable.
   */
  public function isIndexAvailable() {
    $index = $this->getIndex();
    return $index && $index->status();
  }

  /**
   * Gets all resources indexed for search.
   *
   * @return array
   *   Array of resource data.
   */
  public function getAllResources() {
    $cache = $this->cache->get(self::CACHE_ID);
    if ($cache) {
      return $cache->data;
    }

    $resources = $this->indexResources();

    // Cache for 1 hour.
    $this->cache->set(self::CACHE_ID, $resources, time() + 3600, ['node_list:resource']);

    return $resources;
  }

  /**
   * Indexes all resource nodes.
   *
   * @return array
   *   Array of indexed resources.
   */
  protected function indexResources() {
    $resources = [];
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Load all published resource nodes.
    $nids = $node_storage->getQuery()
      ->condition('type', 'resource')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    $nodes = $node_storage->loadMultiple($nids);

    foreach ($nodes as $node) {
      $resources[$node->id()] = $this->buildIndexedResource($node);
    }

    return $resources;
  }

  /**
   * Builds indexed resource data used by legacy retrieval.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Indexed resource metadata.
   */
  protected function buildIndexedResource($node): array {
    $resource = [
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'title_lower' => strtolower($node->getTitle()),
      'url' => $node->toUrl()->toString(),
      'source_url' => $node->toUrl()->toString(),
      'updated_at' => method_exists($node, 'getChangedTime') ? (int) $node->getChangedTime() : NULL,
      'topics' => [],
      'topic_names' => [],
      'service_areas' => [],
      'type' => $this->determineResourceType($node),
      'has_file' => FALSE,
      'has_link' => FALSE,
      'description' => '',
    ];

    if ($node->hasField('field_topics') && !$node->get('field_topics')->isEmpty()) {
      foreach ($node->get('field_topics')->referencedEntities() as $topic) {
        $resource['topics'][] = $topic->id();
        $resource['topic_names'][] = strtolower($topic->getName());
      }
    }

    if ($node->hasField('field_service_areas') && !$node->get('field_service_areas')->isEmpty()) {
      foreach ($node->get('field_service_areas')->referencedEntities() as $area) {
        $resource['service_areas'][] = [
          'id' => $area->id(),
          'name' => $area->getName(),
        ];
      }
    }

    if ($node->hasField('field_file') && !$node->get('field_file')->isEmpty()) {
      $resource['has_file'] = TRUE;
    }

    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $resource['has_link'] = TRUE;
    }

    if ($node->hasField('field_main_content') && !$node->get('field_main_content')->isEmpty()) {
      $body = $node->get('field_main_content')->value;
      $resource['description'] = $this->cleanDescription(strip_tags($body), $node->getTitle());
    }

    $resource['keywords'] = $this->extractKeywords(
      $node->getTitle() . ' ' . implode(' ', $resource['topic_names']) . ' ' . $resource['description']
    );

    return $resource;
  }

  /**
   * Determines the type of resource (form, guide, or general).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return string
   *   The resource type: 'form', 'guide', or 'resource'.
   */
  protected function determineResourceType($node) {
    $title = strtolower($node->getTitle());

    // Check for form indicators.
    $form_keywords = ['form', 'application', 'worksheet', 'checklist', 'fillable'];
    foreach ($form_keywords as $keyword) {
      if (strpos($title, $keyword) !== FALSE) {
        return 'form';
      }
    }

    // Check for guide indicators.
    $guide_keywords = ['guide', 'how to', 'instructions', 'manual', 'handbook', 'step-by-step'];
    foreach ($guide_keywords as $keyword) {
      if (strpos($title, $keyword) !== FALSE) {
        return 'guide';
      }
    }

    return 'resource';
  }

  /**
   * Extracts keywords from text.
   *
   * @param string $text
   *   The text to extract keywords from.
   *
   * @return array
   *   Array of lowercase keywords.
   */
  protected function extractKeywords(string $text) {
    $text = strtolower($text);
    $text = preg_replace('/[^\w\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text);

    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'this', 'that', 'your', 'our', 'how', 'what',
    ];

    return array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) >= 3 && !in_array($word, $stop_words);
    });
  }

  /**
   * Finds forms matching a query.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching forms.
   */
  public function findForms(string $query, int $limit = 3) {
    return $this->findByType($query, 'form', $limit);
  }

  /**
   * Finds guides matching a query.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching guides.
   */
  public function findGuides(string $query, int $limit = 3) {
    return $this->findByType($query, 'guide', $limit);
  }

  /**
   * Finds resources matching a query (all types).
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching resources.
   */
  public function findResources(string $query, int $limit = 3) {
    return $this->findByType($query, NULL, $limit);
  }

  /**
   * Finds resources by type and query.
   *
   * @param string $query
   *   The search query.
   * @param string|null $type
   *   The resource type filter (form, guide, or NULL for all).
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching resources.
   */
  protected function findByType(string $query, ?string $type, int $limit) {
    $cache_key = $this->buildQueryCacheKey($query, $type, $limit);
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

    // Use Search API if available.
    if ($index && $index->status()) {
      $search_payload = $this->findByTypeSearchApi($query, $type, $limit);
      $results = $search_payload['items'];
      if ($cache_key && $this->isVectorOutcomeCacheable($search_payload['vector_outcome'])) {
        $this->cache->set($cache_key, $results, time() + self::QUERY_CACHE_TTL, [
          'node_list',
          'config:ilas_site_assistant.settings',
        ]);
      }
      $this->recordRetrievalTelemetry(
        $query,
        $type,
        $results,
        $search_payload['decision'] ?? NULL,
        $search_payload['vector_outcome'] ?? NULL,
        'search_api',
      );
      return $results;
    }

    // Fall back to legacy method.
    $results = $this->findByTypeLegacy($query, $type, $limit);
    if ($cache_key) {
      $this->cache->set($cache_key, $results, time() + self::QUERY_CACHE_TTL, [
        'node_list',
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
        'reason' => 'lexical_index_unavailable',
      ],
      $this->buildVectorOutcome(FALSE, 'not_evaluated', 'lexical_index_unavailable'),
      'legacy',
    );
    return $results;
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
   * Builds a cache key for memoizing resource queries without storing PII.
   */
  protected function buildQueryCacheKey(string $query, ?string $type, int $limit): ?string {
    $sanitized = PiiRedactor::redactForStorage($query, 120);
    if ($sanitized === '') {
      return NULL;
    }
    $langcode = $this->getCurrentLanguage();
    $type_token = $type ?: 'all';
    $hash = hash('sha256', $sanitized . '|' . $type_token . '|' . $limit . '|' . $langcode);
    return 'resources.search:' . $hash;
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
      'service' => 'resource',
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
   * Finds resources using Search API.
   *
   * @param string $query
   *   The search query.
   * @param string|null $type
   *   The resource type filter (form, guide, or NULL for all).
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Structured retrieval payload with matching resources.
   */
  protected function findByTypeSearchApi(string $query, ?string $type, int $limit) {
    $index = $this->getIndex();
    $using_dedicated = $this->isUsingDedicatedIndex();

    try {
      $search_query = $index->query();
      $search_query->keys($query);

      // Only filter by content type when using the generic content index.
      // The dedicated assistant_resources index only contains resource nodes.
      if (!$using_dedicated) {
        $search_query->addCondition('type', 'resource');
      }

      // Filter by current language to avoid duplicate results for translations.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      // Retrieve more results than limit to allow for type filtering.
      $fetch_limit = $type ? $limit * 3 : $limit;
      $search_query->range(0, $fetch_limit);

      $results = $search_query->execute();
      $items = [];

      // Extract keywords from query for relevance filtering.
      // Only needed for the generic index which may return boilerplate matches.
      $query_keywords = $using_dedicated ? [] : $this->extractKeywords($query);

      foreach ($results->getResultItems() as $result_item) {
        try {
          $node = $result_item->getOriginalObject()->getValue();

          if (!$node) {
            continue;
          }

          // Determine resource type from title.
          $resource_type = $this->determineResourceType($node);

          // Filter by type if specified.
          if ($type !== NULL && $resource_type !== $type) {
            continue;
          }

          $item = $this->buildResourceItem($node);
          $item['type'] = $resource_type;
          $item['score'] = $result_item->getScore() ?? 0;

          // Relevance check: only needed for the generic content index to
          // filter out false positives from boilerplate/footer content.
          // The dedicated index only indexes relevant fields (title, body,
          // topics, service areas) so its scores are trustworthy.
          if (!$using_dedicated && !empty($query_keywords) && !$this->hasRelevantMatch($item, $query_keywords)) {
            continue;
          }

          $items[] = $item;

          // Stop if we have enough results.
          if (count($items) >= $limit) {
            break;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      // If Search API returned few results, try topic-based boost.
      if (count($items) < $limit) {
        $topic = $this->topicResolver->resolveFromText($query);
        if ($topic) {
          $topic_results = $this->findByTopic((int) $topic['id'], $limit - count($items));
          foreach ($topic_results as $topic_item) {
            // Avoid duplicates.
            $exists = FALSE;
            foreach ($items as $item) {
              if ($item['id'] == $topic_item['id']) {
                $exists = TRUE;
                break;
              }
            }
            if (!$exists && ($type === NULL || $topic_item['type'] === $type)) {
              $items[] = $topic_item;
            }
          }
        }
      }

      // Supplement with vector search if still sparse.
      return $this->supplementWithVectorResultsDetailed($items, $query, $type, $limit);
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')->warning('Search API query failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      return [
        'items' => $this->findByTypeLegacy($query, $type, $limit),
        'decision' => NULL,
        'vector_outcome' => $this->buildVectorOutcome(FALSE, 'healthy', 'lexical_search_failed'),
      ];
    }
  }

  /**
   * Builds a resource item from a node entity.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   The resource item data.
   */
  protected function buildResourceItem($node) {
    $item = [
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'url' => $node->toUrl()->toString(),
      'source_url' => $node->toUrl()->toString(),
      'type' => 'resource',
      'has_file' => FALSE,
      'has_link' => FALSE,
      'description' => '',
      'topics' => [],
      'updated_at' => method_exists($node, 'getChangedTime') ? (int) $node->getChangedTime() : NULL,
    ];

    // Get topics.
    if ($node->hasField('field_topics') && !$node->get('field_topics')->isEmpty()) {
      foreach ($node->get('field_topics')->referencedEntities() as $topic) {
        $item['topics'][] = strtolower($topic->getName());
      }
    }

    // Check for file attachment.
    if ($node->hasField('field_file') && !$node->get('field_file')->isEmpty()) {
      $item['has_file'] = TRUE;
    }

    // Check for external link.
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $item['has_link'] = TRUE;
    }

    // Get description/body if available.
    if ($node->hasField('field_main_content') && !$node->get('field_main_content')->isEmpty()) {
      $body = $node->get('field_main_content')->value;
      $item['description'] = $this->cleanDescription(strip_tags($body), $node->getTitle());
    }

    $item['source'] = 'lexical';
    if ($this->sourceGovernance) {
      $item = $this->sourceGovernance->annotateResult($item, 'resource_lexical');
    }

    return $item;
  }

  /**
   * Checks if a result item has a relevant match in title or description.
   *
   * This filters out false positives where the search term only matched
   * in boilerplate content (footer, sidebar, etc.) but not in the actual
   * page content.
   *
   * @param array $item
   *   The resource item.
   * @param array $keywords
   *   Keywords extracted from the search query.
   *
   * @return bool
   *   TRUE if at least one keyword appears in title or description.
   */
  protected function hasRelevantMatch(array $item, array $keywords) {
    $title_lower = strtolower($item['title'] ?? '');
    $description_lower = strtolower($item['description'] ?? '');
    $topics_str = strtolower(implode(' ', $item['topics'] ?? []));

    foreach ($keywords as $keyword) {
      // Check title (most important).
      if (strpos($title_lower, $keyword) !== FALSE) {
        return TRUE;
      }
      // Check description.
      if (strpos($description_lower, $keyword) !== FALSE) {
        return TRUE;
      }
      // Check topics.
      if (strpos($topics_str, $keyword) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  // =========================================================================
  // Vector search methods (Pinecone fallback enrichment)
  // =========================================================================

  /**
   * Gets the vector search configuration.
   *
   * @return array
   *   Vector search config with keys: enabled, resource_index_id,
   *   fallback_threshold, min_vector_score, score_normalization_factor,
   *   min_lexical_score.
   */
  protected function getVectorSearchConfig(): array {
    if (!$this->configFactory) {
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
   * the fallback threshold. Merges by node ID, keeping the higher-scored
   * version of any duplicate.
   *
   * @param array $lexical_items
   *   Results from lexical search.
   * @param string $query
   *   The original search query.
   * @param string|null $type
   *   Optional resource type filter.
   * @param int $limit
   *   Maximum total results to return.
   *
   * @return array
   *   Merged results, limited to $limit.
   */
  protected function supplementWithVectorResults(array $lexical_items, string $query, ?string $type, int $limit): array {
    return $this->supplementWithVectorResultsDetailed($lexical_items, $query, $type, $limit)['items'];
  }

  /**
   * Supplements lexical results and returns the decision and outcome metadata.
   *
   * @param array $lexical_items
   *   Results from lexical search.
   * @param string $query
   *   The original search query.
   * @param string|null $type
   *   Optional resource type filter.
   * @param int $limit
   *   Maximum total results to return.
   *
   * @return array
   *   Structured payload containing merged items, decision, and outcome.
   */
  protected function supplementWithVectorResultsDetailed(array $lexical_items, string $query, ?string $type, int $limit): array {
    $decision = $this->buildVectorDecisionMap($lexical_items);
    if (!$decision['should_attempt']) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $this->buildVectorOutcome(FALSE, 'healthy', $decision['reason']),
      ];
    }

    $vector_outcome = $this->normalizeVectorOutcome($this->findByTypeVector($query, $type, $limit));
    if (!in_array($vector_outcome['status'] ?? 'healthy', ['healthy', 'healthy_empty'], TRUE)) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $vector_outcome,
      ];
    }

    $vector_items = array_values(array_filter(
      $vector_outcome['items'] ?? [],
      fn(array $item): bool => $this->urlMatchesCurrentLanguage((string) ($item['url'] ?? '')),
    ));
    $vector_outcome['items'] = $vector_items;
    if (empty($vector_items)) {
      return [
        'items' => $lexical_items,
        'decision' => $decision,
        'vector_outcome' => $vector_outcome,
      ];
    }

    // Build a map of existing items keyed by node ID.
    $merged = [];
    foreach ($lexical_items as $item) {
      $merged[$item['id']] = $item;
    }

    // Merge vector items, applying lexical priority boost for comparison.
    foreach ($vector_items as $item) {
      if (!isset($merged[$item['id']])) {
        $merged[$item['id']] = $item;
      }
      else {
        // Apply lexical priority boost for comparison purposes only.
        $existing_score = $merged[$item['id']]['score'] ?? 0;
        $new_score = $item['score'] ?? 0;
        $existing_is_lexical = ($merged[$item['id']]['source'] ?? 'lexical') !== 'vector';
        $effective_existing = $existing_is_lexical ? $existing_score + RetrievalContract::LEXICAL_PRIORITY_BOOST : $existing_score;
        $new_is_lexical = ($item['source'] ?? 'lexical') !== 'vector';
        $effective_new = $new_is_lexical ? $new_score + RetrievalContract::LEXICAL_PRIORITY_BOOST : $new_score;
        if ($effective_new > $effective_existing) {
          $merged[$item['id']] = $item;
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
      \Drupal::logger('ilas_site_assistant')->info('Resource retrieval contract: vector supplemented lexical. lexical_count=@lc vector_count=@vc merged_count=@mc policy=@p', [
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
      $output_ids[$item['id']] = TRUE;
    }

    // Find highest-scoring lexical item not already in output.
    $best_lexical = NULL;
    foreach ($lexical_items as $item) {
      if (isset($output_ids[$item['id']])) {
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
   * Searches resources using the vector (Pinecone) index.
   *
   * @param string $query
   *   The search query.
   * @param string|null $type
   *   Optional resource type filter (form, guide, or NULL for all).
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Structured vector outcome payload.
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    $backoff_until = $this->getVectorBackoffUntil();
    if ($backoff_until > time()) {
      return $this->buildVectorOutcome(FALSE, 'backoff', 'backoff_active', [], NULL, FALSE) + [
        'backoff_until' => $backoff_until,
      ];
    }

    $vector_config = $this->getVectorSearchConfig();
    $index_id = $this->retrievalConfiguration?->getResourceVectorIndexId();
    $min_score = $vector_config['min_vector_score'] ?? 0.70;
    $normalization = $vector_config['score_normalization_factor'] ?? 100;

    if ($index_id === NULL) {
      \Drupal::logger('ilas_site_assistant')->warning('Vector resource search skipped because no vector index is configured.');
      $this->activateVectorBackoff();
      return $this->buildVectorOutcome(TRUE, 'degraded', 'index_id_unconfigured', [], NULL, FALSE);
    }

    try {
      $vector_index = Index::load($index_id);
      if (!$vector_index || !$vector_index->status()) {
        \Drupal::logger('ilas_site_assistant')->warning('Vector resource index @index is unavailable; preserving lexical results.', [
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

      // Fetch extra results to allow for type filtering.
      $fetch_limit = $type ? $limit * 3 : $limit;
      $search_query->range(0, $fetch_limit);

      // Filter by current language.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      $start_time = microtime(TRUE);
      $results = $search_query->execute();
      $elapsed_ms = round((microtime(TRUE) - $start_time) * 1000);

      if ($elapsed_ms > self::MAX_VECTOR_MS) {
        $this->activateVectorBackoff();
        \Drupal::logger('ilas_site_assistant')->warning(
          'Vector resource search exceeded @ms ms (threshold @thresh). Entering backoff.',
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

          $node = $result_item->getOriginalObject()->getValue();
          if (!$node) {
            continue;
          }

          // Filter by resource type if specified.
          $resource_type = $this->determineResourceType($node);
          if ($type !== NULL && $resource_type !== $type) {
            continue;
          }

          $item = $this->buildResourceItem($node);
          if (!$this->urlMatchesCurrentLanguage((string) ($item['url'] ?? ''))) {
            continue;
          }
          $item['type'] = $resource_type;
          // Normalize vector score to be comparable with lexical scores.
          $item['score'] = $raw_score * $normalization;
          $item['vector_score'] = $raw_score;
          $item['source'] = 'vector';
          if ($this->sourceGovernance) {
            $item = $this->sourceGovernance->annotateResult($item, 'resource_vector');
          }
          $items[] = $item;

          if (count($items) >= $limit) {
            break;
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      if (!empty($items)) {
        $query_metadata = ObservabilityPayloadMinimizer::buildTextMetadata($query);
        \Drupal::logger('ilas_site_assistant')->info('Vector resource search returned @count results (@ms ms) for query_hash=@query_hash length=@length_bucket profile=@redaction_profile', [
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
        'Vector resource search failed [@category]: @class @error_signature (backing off @seconds s)', [
          '@category' => $category,
          '@class' => $exception_class,
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
          '@seconds' => self::VECTOR_BACKOFF_SECONDS,
        ]
      );
      return $this->buildVectorOutcome(TRUE, 'degraded', $category, [], NULL, FALSE);
    }
  }

  /**
   * Finds resources using legacy method (direct entity query).
   *
   * @param string $query
   *   The search query.
   * @param string|null $type
   *   The resource type filter.
   * @param int $limit
   *   Maximum results.
   *
   * @return array
   *   Array of matching resources.
   */
  protected function findByTypeLegacy(string $query, ?string $type, int $limit) {
    $topic = $this->topicResolver->resolveFromText($query);
    $topic_id = $topic ? (int) $topic['id'] : NULL;
    $resources = $this->loadLegacyResourceCandidates($query, $limit, $topic_id);

    // Use enhanced ranking if available.
    if ($this->rankingEnhancer) {
      // Convert to format expected by ranking enhancer.
      $items = [];
      foreach ($resources as $resource) {
        $items[] = [
          'id' => $resource['id'],
          'title' => $resource['title'],
          'url' => $resource['url'],
          'source_url' => $resource['source_url'] ?? $resource['url'],
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'topics' => $resource['topic_names'],
          'updated_at' => $resource['updated_at'] ?? NULL,
        ];
      }
      $results = $this->rankingEnhancer->scoreResourceResults($items, $query, $type, $limit);
      if ($this->sourceGovernance) {
        $results = $this->sourceGovernance->annotateBatch($results, 'resource_lexical');
      }
      return $results;
    }

    // Fallback to basic ranking.
    $query_lower = strtolower($query);
    $query_keywords = $this->extractKeywords($query);

    // Also try to resolve topic from query.
    $results = [];

    foreach ($resources as $resource) {
      // Filter by type if specified.
      if ($type !== NULL && $resource['type'] !== $type) {
        continue;
      }

      $score = 0;

      // Exact topic match (highest weight).
      if ($topic_id && in_array($topic_id, $resource['topics'])) {
        $score += 10;
      }

      // Title substring match.
      if (strpos($resource['title_lower'], $query_lower) !== FALSE) {
        $score += 8;
      }

      // Topic name match.
      foreach ($resource['topic_names'] as $topic_name) {
        if (strpos($query_lower, $topic_name) !== FALSE || strpos($topic_name, $query_lower) !== FALSE) {
          $score += 5;
        }
      }

      // Keyword overlap.
      $overlap = array_intersect($query_keywords, $resource['keywords']);
      $score += count($overlap) * 2;

      if ($score > 0) {
        $results[] = [
          'id' => $resource['id'],
          'title' => $resource['title'],
          'url' => $resource['url'],
          'source_url' => $resource['source_url'] ?? $resource['url'],
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'topics' => $resource['topic_names'],
          'score' => $score,
          'source' => 'lexical',
          'updated_at' => $resource['updated_at'] ?? NULL,
        ];
      }
    }

    // Sort by score descending.
    usort($results, function ($a, $b) {
      return $b['score'] - $a['score'];
    });

    $results = array_slice($results, 0, $limit);
    if ($this->sourceGovernance) {
      $results = $this->sourceGovernance->annotateBatch($results, 'resource_lexical');
    }
    return $results;
  }

  /**
   * Finds resources by topic ID.
   *
   * @param int $topic_id
   *   The topic term ID.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching resources.
   */
  public function findByTopic(int $topic_id, int $limit = 5) {
    if ($limit <= 0) {
      return [];
    }

    $resources = $this->loadLegacyResourceCandidates('', $limit, $topic_id);
    $results = [];

    foreach ($resources as $resource) {
      if (in_array($topic_id, $resource['topics'])) {
        $results[] = [
          'id' => $resource['id'],
          'title' => $resource['title'],
          'url' => $resource['url'],
          'source_url' => $resource['source_url'] ?? $resource['url'],
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'source' => 'lexical',
          'updated_at' => $resource['updated_at'] ?? NULL,
        ];
      }
    }

    $results = array_slice($results, 0, $limit);
    if ($this->sourceGovernance) {
      $results = $this->sourceGovernance->annotateBatch($results, 'resource_lexical');
    }
    return $results;
  }

  /**
   * Finds resources by service area.
   *
   * @param int $service_area_id
   *   The service area term ID.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching resources.
   */
  public function findByServiceArea(int $service_area_id, int $limit = 5) {
    if ($limit <= 0) {
      return [];
    }

    $resources = $this->loadLegacyResourceCandidates('', $limit, NULL, $service_area_id);
    $results = [];

    foreach ($resources as $resource) {
      foreach ($resource['service_areas'] as $area) {
        if ($area['id'] == $service_area_id) {
          $results[] = [
            'id' => $resource['id'],
            'title' => $resource['title'],
            'url' => $resource['url'],
            'source_url' => $resource['source_url'] ?? $resource['url'],
            'type' => $resource['type'],
            'description' => $resource['description'],
            'source' => 'lexical',
            'updated_at' => $resource['updated_at'] ?? NULL,
          ];
          break;
        }
      }
    }

    $results = array_slice($results, 0, $limit);
    if ($this->sourceGovernance) {
      $results = $this->sourceGovernance->annotateBatch($results, 'resource_lexical');
    }
    return $results;
  }

  /**
   * Loads a bounded candidate set for legacy resource retrieval.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   The requested result limit.
   * @param int|null $topic_id
   *   Optional topic constraint.
   * @param int|null $service_area_id
   *   Optional service-area constraint.
   *
   * @return array
   *   Indexed resource candidates keyed by node ID.
   */
  protected function loadLegacyResourceCandidates(string $query, int $limit, ?int $topic_id = NULL, ?int $service_area_id = NULL): array {
    if ($limit <= 0) {
      return [];
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $candidate_limit = $this->getLegacyCandidateLimit($limit);
    $entity_query = $node_storage->getQuery()
      ->condition('type', 'resource')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->range(0, $candidate_limit);

    $match_group = $entity_query->orConditionGroup();
    $has_match_conditions = FALSE;
    $normalized_query = trim($query);
    $keywords = array_values(array_unique($this->extractKeywords($query)));

    if ($normalized_query !== '') {
      $match_group->condition('title', $normalized_query, 'CONTAINS');
      $match_group->condition('field_main_content.value', $normalized_query, 'CONTAINS');
      $has_match_conditions = TRUE;
    }

    foreach (array_slice($keywords, 0, 5) as $keyword) {
      $match_group->condition('title', $keyword, 'CONTAINS');
      $match_group->condition('field_main_content.value', $keyword, 'CONTAINS');
      $has_match_conditions = TRUE;
    }

    if ($topic_id !== NULL) {
      $match_group->condition('field_topics.target_id', $topic_id);
      $has_match_conditions = TRUE;
    }

    if ($service_area_id !== NULL) {
      $match_group->condition('field_service_areas.target_id', $service_area_id);
      $has_match_conditions = TRUE;
    }

    if ($has_match_conditions) {
      $entity_query->condition($match_group);
    }

    $nids = $entity_query->execute();
    if (empty($nids)) {
      return [];
    }

    $nodes = $node_storage->loadMultiple($nids);
    $resources = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid])) {
        $resources[$nid] = $this->buildIndexedResource($nodes[$nid]);
      }
    }

    return $resources;
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
   * Cleans a description string for display as a resource summary.
   *
   * Strips boilerplate phrases, removes title duplication, normalizes
   * whitespace, and caps at 240 characters ending on a word boundary.
   *
   * @param string $raw_text
   *   The raw body text (already stripped of HTML tags).
   * @param string $title
   *   The resource title (used to avoid duplication).
   *
   * @return string
   *   Cleaned description suitable for display.
   */
  protected function cleanDescription(string $raw_text, string $title = ''): string {
    // Decode HTML entities left behind by strip_tags() (e.g. &nbsp; -> U+00A0).
    $text = html_entity_decode($raw_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Replace non-breaking spaces (U+00A0) with normal spaces.
    $text = str_replace("\xC2\xA0", ' ', $text);
    // Normalize whitespace (collapse newlines, tabs, multiple spaces).
    $text = preg_replace('/\s+/', ' ', trim($text));

    if (empty($text)) {
      return '';
    }

    // Remove common navigation/boilerplate phrases that appear at the start.
    $boilerplate_patterns = [
      '/^(Home\s*[>»›|\/]\s*)+/i',
      '/^(Skip to (main )?content\.?\s*)/i',
      '/^(You are here:?\s*)/i',
      '/^(Breadcrumb\s*)/i',
      '/^(Main navigation\s*)/i',
      '/^(Idaho Legal Aid Services?\s*[>»›|\/]?\s*)/i',
      '/^(ILAS\s*[>»›|\/]?\s*)/i',
    ];
    foreach ($boilerplate_patterns as $pattern) {
      $text = preg_replace($pattern, '', $text);
    }

    // Remove global repeated phrases that appear in many resource bodies.
    $global_noise = [
      '/Idaho Legal Aid Services provides free legal help to low-income Idahoans\.?\s*/i',
      '/This (information|resource|document|form) (is|was) (provided|prepared|created) (by|for) Idaho Legal Aid Services?\.?\s*/i',
      '/For more information,?\s*(please\s*)?(visit|call|contact).*$/i',
      '/If you need (legal )?(help|advice|assistance),?\s*(please\s*)?(call|contact|apply|visit).*$/i',
      '/Disclaimer:.*$/i',
      '/Note:\s*This is not legal advice\.?\s*/i',
      '/Last (updated|revised|modified):?\s*\d.*$/i',
    ];
    foreach ($global_noise as $pattern) {
      $text = preg_replace($pattern, '', $text);
    }

    // Remove leading duplication of the title.
    if (!empty($title)) {
      $escaped_title = preg_quote($title, '/');
      $text = preg_replace('/^\s*' . $escaped_title . '\s*[\.\:\-]?\s*/i', '', $text);
    }

    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    // Cap at 240 characters, breaking at word boundary.
    if (mb_strlen($text) > 240) {
      $text = mb_substr($text, 0, 240);
      // Break at last space to avoid mid-word truncation.
      $last_space = strrpos($text, ' ');
      if ($last_space !== FALSE && $last_space > 160) {
        $text = substr($text, 0, $last_space);
      }
      $text = rtrim($text, '.,;:!? ') . '…';
    }

    return $text;
  }

  /**
   * Clears the resource cache.
   */
  public function clearCache() {
    $this->cache->delete(self::CACHE_ID);
  }

}
