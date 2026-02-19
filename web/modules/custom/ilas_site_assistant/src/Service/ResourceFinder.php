<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
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
   * Cache ID for resource data.
   */
  const CACHE_ID = 'ilas_site_assistant.resources';

  /**
   * The dedicated Search API index ID for assistant resources.
   */
  const INDEX_ID = 'assistant_resources';

  /**
   * Fallback Search API index ID (generic content index).
   */
  const FALLBACK_INDEX_ID = 'content';

  /**
   * Constructs a ResourceFinder object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TopicResolver $topic_resolver,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager,
    RankingEnhancer $ranking_enhancer = NULL,
    ConfigFactoryInterface $config_factory = NULL
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->topicResolver = $topic_resolver;
    $this->cache = $cache;
    $this->languageManager = $language_manager;
    $this->rankingEnhancer = $ranking_enhancer;
    $this->configFactory = $config_factory;
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
   * Tries the dedicated assistant_resources index first. Falls back to the
   * generic content index if the dedicated index is unavailable.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index or NULL if not available.
   */
  protected function getIndex() {
    if ($this->index === NULL) {
      // Try dedicated assistant resources index first.
      $this->index = Index::load(self::INDEX_ID);
      if (!$this->index || !$this->index->status()) {
        // Fall back to generic content index.
        $this->index = Index::load(self::FALLBACK_INDEX_ID);
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
    return $index && $index->id() === self::INDEX_ID;
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
      $resource = [
        'id' => $node->id(),
        'title' => $node->getTitle(),
        'title_lower' => strtolower($node->getTitle()),
        'url' => $node->toUrl()->toString(),
        'topics' => [],
        'topic_names' => [],
        'service_areas' => [],
        'type' => $this->determineResourceType($node),
        'has_file' => FALSE,
        'has_link' => FALSE,
        'description' => '',
      ];

      // Get topics.
      if ($node->hasField('field_topics') && !$node->get('field_topics')->isEmpty()) {
        foreach ($node->get('field_topics')->referencedEntities() as $topic) {
          $resource['topics'][] = $topic->id();
          $resource['topic_names'][] = strtolower($topic->getName());
        }
      }

      // Get service areas.
      if ($node->hasField('field_service_areas') && !$node->get('field_service_areas')->isEmpty()) {
        foreach ($node->get('field_service_areas')->referencedEntities() as $area) {
          $resource['service_areas'][] = [
            'id' => $area->id(),
            'name' => $area->getName(),
          ];
        }
      }

      // Check for file attachment.
      if ($node->hasField('field_file') && !$node->get('field_file')->isEmpty()) {
        $resource['has_file'] = TRUE;
      }

      // Check for external link.
      if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
        $resource['has_link'] = TRUE;
      }

      // Get description/body if available.
      if ($node->hasField('field_main_content') && !$node->get('field_main_content')->isEmpty()) {
        $body = $node->get('field_main_content')->value;
        $resource['description'] = $this->cleanDescription(strip_tags($body), $node->getTitle());
      }

      // Build search keywords.
      $resource['keywords'] = $this->extractKeywords(
        $node->getTitle() . ' ' . implode(' ', $resource['topic_names']) . ' ' . $resource['description']
      );

      $resources[$node->id()] = $resource;
    }

    return $resources;
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
    $index = $this->getIndex();

    // Use Search API if available.
    if ($index && $index->status()) {
      return $this->findByTypeSearchApi($query, $type, $limit);
    }

    // Fall back to legacy method.
    return $this->findByTypeLegacy($query, $type, $limit);
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
   *   Array of matching resources.
   */
  protected function findByTypeSearchApi(string $query, ?string $type, int $limit) {
    $index = $this->getIndex();
    $using_dedicated = ($index->id() === self::INDEX_ID);

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
          $topic_results = $this->findByTopic($topic['id'], $limit - count($items));
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
      $items = $this->supplementWithVectorResults($items, $query, $type, $limit);

      return $items;
    }
    catch (\Exception $e) {
      \Drupal::logger('ilas_site_assistant')->warning('Search API query failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->findByTypeLegacy($query, $type, $limit);
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
      'type' => 'resource',
      'has_file' => FALSE,
      'has_link' => FALSE,
      'description' => '',
      'topics' => [],
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

    $vector_items = $this->findByTypeVector($query, $type, $limit);

    if (empty($vector_items)) {
      return $lexical_items;
    }

    // Build a map of existing items keyed by node ID.
    $merged = [];
    foreach ($lexical_items as $item) {
      $merged[$item['id']] = $item;
    }

    // Merge vector items, keeping higher-scored version for duplicates.
    foreach ($vector_items as $item) {
      if (!isset($merged[$item['id']])) {
        $merged[$item['id']] = $item;
      }
      elseif (($item['score'] ?? 0) > ($merged[$item['id']]['score'] ?? 0)) {
        $merged[$item['id']] = $item;
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
   *   Array of matching resource items with normalized scores.
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    $vector_config = $this->getVectorSearchConfig();
    $index_id = $vector_config['resource_index_id'] ?? 'assistant_resources_vector';
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

      // Fetch extra results to allow for type filtering.
      $fetch_limit = $type ? $limit * 3 : $limit;
      $search_query->range(0, $fetch_limit);

      // Filter by current language.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      $start_time = microtime(TRUE);
      $results = $search_query->execute();
      $elapsed_ms = round((microtime(TRUE) - $start_time) * 1000);

      if ($elapsed_ms > 5000) {
        \Drupal::logger('ilas_site_assistant')->warning(
          'Vector resource search took @ms ms for query "@query"',
          ['@ms' => $elapsed_ms, '@query' => PiiRedactor::redactForLog($query)]
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
          $item['type'] = $resource_type;
          // Normalize vector score to be comparable with lexical scores.
          $item['score'] = $raw_score * $normalization;
          $item['vector_score'] = $raw_score;
          $item['source'] = 'vector';
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
        \Drupal::logger('ilas_site_assistant')->info('Vector resource search returned @count results (@ms ms) for query "@query"', [
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

      \Drupal::logger('ilas_site_assistant')->log($level,
        'Vector resource search failed [@category]: @class - @message', [
          '@category' => $category,
          '@class' => $exception_class,
          '@message' => $e->getMessage(),
        ]
      );
      return [];
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
    $resources = $this->getAllResources();

    // Use enhanced ranking if available.
    if ($this->rankingEnhancer) {
      // Convert to format expected by ranking enhancer.
      $items = [];
      foreach ($resources as $resource) {
        $items[] = [
          'id' => $resource['id'],
          'title' => $resource['title'],
          'url' => $resource['url'],
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'topics' => $resource['topic_names'],
        ];
      }
      return $this->rankingEnhancer->scoreResourceResults($items, $query, $type, $limit);
    }

    // Fallback to basic ranking.
    $query_lower = strtolower($query);
    $query_keywords = $this->extractKeywords($query);

    // Also try to resolve topic from query.
    $topic = $this->topicResolver->resolveFromText($query);
    $topic_id = $topic ? $topic['id'] : NULL;

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
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'topics' => $resource['topic_names'],
          'score' => $score,
          'source' => 'lexical',
        ];
      }
    }

    // Sort by score descending.
    usort($results, function ($a, $b) {
      return $b['score'] - $a['score'];
    });

    return array_slice($results, 0, $limit);
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
    $resources = $this->getAllResources();
    $results = [];

    foreach ($resources as $resource) {
      if (in_array($topic_id, $resource['topics'])) {
        $results[] = [
          'id' => $resource['id'],
          'title' => $resource['title'],
          'url' => $resource['url'],
          'type' => $resource['type'],
          'description' => $resource['description'],
          'has_file' => $resource['has_file'],
          'has_link' => $resource['has_link'],
          'source' => 'lexical',
        ];
      }
    }

    return array_slice($results, 0, $limit);
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
    $resources = $this->getAllResources();
    $results = [];

    foreach ($resources as $resource) {
      foreach ($resource['service_areas'] as $area) {
        if ($area['id'] == $service_area_id) {
          $results[] = [
            'id' => $resource['id'],
            'title' => $resource['title'],
            'url' => $resource['url'],
            'type' => $resource['type'],
            'description' => $resource['description'],
            'source' => 'lexical',
          ];
          break;
        }
      }
    }

    return array_slice($results, 0, $limit);
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
    // Normalize whitespace (collapse newlines, tabs, multiple spaces).
    $text = preg_replace('/\s+/', ' ', trim($raw_text));

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
