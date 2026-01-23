<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api\Entity\Index;

/**
 * Service for finding forms, guides, and resources.
 *
 * Uses Search API content index for text search with fallback to
 * direct entity queries when the index is unavailable.
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
   * The Search API index ID.
   */
  const INDEX_ID = 'content';

  /**
   * Constructs a ResourceFinder object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TopicResolver $topic_resolver,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->topicResolver = $topic_resolver;
    $this->cache = $cache;
    $this->languageManager = $language_manager;
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
        $resource['description'] = mb_substr(strip_tags($body), 0, 200);
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

    try {
      $search_query = $index->query();
      $search_query->keys($query);

      // Filter to resource content type only.
      $search_query->addCondition('type', 'resource');

      // Filter by current language to avoid duplicate results for translations.
      $langcode = $this->getCurrentLanguage();
      $search_query->addCondition('search_api_language', $langcode);

      // Retrieve more results than limit to allow for type filtering.
      $fetch_limit = $type ? $limit * 3 : $limit;
      $search_query->range(0, $fetch_limit);

      $results = $search_query->execute();
      $items = [];

      // Extract keywords from query for relevance filtering.
      $query_keywords = $this->extractKeywords($query);

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

          // Relevance check: ensure at least one keyword appears in title or description.
          // This filters out false positives from boilerplate/footer content.
          if (!empty($query_keywords) && !$this->hasRelevantMatch($item, $query_keywords)) {
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
      $item['description'] = mb_substr(strip_tags($body), 0, 200);
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
          ];
          break;
        }
      }
    }

    return array_slice($results, 0, $limit);
  }

  /**
   * Clears the resource cache.
   */
  public function clearCache() {
    $this->cache->delete(self::CACHE_ID);
  }

}
