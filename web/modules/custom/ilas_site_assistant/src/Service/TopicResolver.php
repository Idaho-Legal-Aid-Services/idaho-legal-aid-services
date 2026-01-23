<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for resolving topics and service areas.
 */
class TopicResolver {

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
   * Cache ID for topic data.
   */
  const CACHE_ID = 'ilas_site_assistant.topics';

  /**
   * Service area machine names mapped to URLs.
   */
  const SERVICE_AREA_URLS = [
    'housing' => '/legal-help/housing',
    'family' => '/legal-help/family',
    'seniors' => '/legal-help/seniors',
    'health' => '/legal-help/health',
    'consumer' => '/legal-help/consumer',
    'civil_rights' => '/legal-help/civil-rights',
  ];

  /**
   * Constructs a TopicResolver object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
  }

  /**
   * Gets all topics with their service area mappings.
   *
   * @return array
   *   Array of topic data.
   */
  public function getAllTopics() {
    $cache = $this->cache->get(self::CACHE_ID);
    if ($cache) {
      return $cache->data;
    }

    $topics = [];
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load all topics.
    $topic_terms = $term_storage->loadByProperties(['vid' => 'topics']);

    foreach ($topic_terms as $term) {
      $topic_data = [
        'id' => $term->id(),
        'name' => $term->getName(),
        'name_lower' => strtolower($term->getName()),
        'service_areas' => [],
        'service_area_urls' => [],
      ];

      // Get related service areas.
      if ($term->hasField('field_service_areas')) {
        $service_area_refs = $term->get('field_service_areas')->referencedEntities();
        foreach ($service_area_refs as $service_area) {
          $area_name = $service_area->getName();
          $area_key = $this->getServiceAreaKey($area_name);
          $topic_data['service_areas'][] = [
            'id' => $service_area->id(),
            'name' => $area_name,
            'key' => $area_key,
          ];
          if (isset(self::SERVICE_AREA_URLS[$area_key])) {
            $topic_data['service_area_urls'][] = self::SERVICE_AREA_URLS[$area_key];
          }
        }
      }

      $topics[$term->id()] = $topic_data;
    }

    // Cache for 1 hour.
    $this->cache->set(self::CACHE_ID, $topics, time() + 3600, ['taxonomy_term_list:topics']);

    return $topics;
  }

  /**
   * Resolves a topic from text input.
   *
   * @param string $text
   *   The text to search.
   *
   * @return array|null
   *   Topic data or NULL if not found.
   */
  public function resolveFromText(string $text) {
    $topics = $this->getAllTopics();
    $text_lower = strtolower($text);

    // First pass: exact name match.
    foreach ($topics as $topic) {
      if (strpos($text_lower, $topic['name_lower']) !== FALSE) {
        return $topic;
      }
    }

    // Second pass: word overlap scoring.
    $text_words = $this->tokenize($text);
    $best_match = NULL;
    $best_score = 0;

    foreach ($topics as $topic) {
      $topic_words = $this->tokenize($topic['name']);
      $overlap = array_intersect($text_words, $topic_words);
      $score = count($overlap);

      // Boost score if all topic words are present.
      if (count($overlap) === count($topic_words)) {
        $score += 2;
      }

      if ($score > $best_score && $score >= 2) {
        $best_score = $score;
        $best_match = $topic;
      }
    }

    return $best_match;
  }

  /**
   * Searches topics by query.
   *
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Array of matching topics.
   */
  public function searchTopics(string $query, int $limit = 5) {
    $topics = $this->getAllTopics();
    $query_lower = strtolower($query);
    $query_words = $this->tokenize($query);

    $results = [];

    foreach ($topics as $topic) {
      $score = 0;

      // Substring match.
      if (strpos($topic['name_lower'], $query_lower) !== FALSE) {
        $score += 3;
      }

      // Word overlap.
      $topic_words = $this->tokenize($topic['name']);
      $overlap = array_intersect($query_words, $topic_words);
      $score += count($overlap);

      if ($score > 0) {
        $results[] = [
          'id' => $topic['id'],
          'name' => $topic['name'],
          'score' => $score,
          'service_areas' => $topic['service_areas'],
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
   * Gets detailed information about a topic.
   *
   * @param int $topic_id
   *   The topic term ID.
   *
   * @return array|null
   *   Topic information or NULL if not found.
   */
  public function getTopicInfo(int $topic_id) {
    $topics = $this->getAllTopics();

    if (!isset($topics[$topic_id])) {
      return NULL;
    }

    $topic = $topics[$topic_id];

    // Get the primary service area URL.
    $service_area_url = !empty($topic['service_area_urls']) ? $topic['service_area_urls'][0] : NULL;

    return [
      'id' => $topic['id'],
      'name' => $topic['name'],
      'service_areas' => $topic['service_areas'],
      'service_area_url' => $service_area_url,
    ];
  }

  /**
   * Gets all service areas.
   *
   * @return array
   *   Array of service area data.
   */
  public function getServiceAreas() {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $service_areas = $term_storage->loadByProperties(['vid' => 'service_areas']);

    $results = [];
    foreach ($service_areas as $term) {
      $key = $this->getServiceAreaKey($term->getName());
      $results[] = [
        'id' => $term->id(),
        'name' => $term->getName(),
        'key' => $key,
        'url' => self::SERVICE_AREA_URLS[$key] ?? '/services',
      ];
    }

    return $results;
  }

  /**
   * Gets the URL for a service area.
   *
   * @param string $area_name
   *   The service area name.
   *
   * @return string
   *   The URL for the service area.
   */
  public function getServiceAreaUrl(string $area_name) {
    $key = $this->getServiceAreaKey($area_name);
    return self::SERVICE_AREA_URLS[$key] ?? '/services';
  }

  /**
   * Converts a service area name to a machine key.
   *
   * @param string $name
   *   The service area name.
   *
   * @return string
   *   The machine key.
   */
  protected function getServiceAreaKey(string $name) {
    $name_lower = strtolower($name);

    // Map common names to keys.
    $mappings = [
      'housing' => 'housing',
      'family' => 'family',
      'seniors' => 'seniors',
      'older adults' => 'seniors',
      'health' => 'health',
      'healthcare' => 'health',
      'consumer' => 'consumer',
      'civil rights' => 'civil_rights',
      'individual rights' => 'civil_rights',
    ];

    foreach ($mappings as $pattern => $key) {
      if (strpos($name_lower, $pattern) !== FALSE) {
        return $key;
      }
    }

    // Fallback: convert to machine name.
    return preg_replace('/[^a-z0-9]+/', '_', $name_lower);
  }

  /**
   * Tokenizes text into words.
   *
   * @param string $text
   *   The text to tokenize.
   *
   * @return array
   *   Array of lowercase words.
   */
  protected function tokenize(string $text) {
    // Convert to lowercase and split on non-word characters.
    $words = preg_split('/[\s\-_\/]+/', strtolower($text));
    // Filter out common stop words and short words.
    $stop_words = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'are', 'i', 'my'];
    return array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) >= 2 && !in_array($word, $stop_words);
    });
  }

  /**
   * Clears the topic cache.
   */
  public function clearCache() {
    $this->cache->delete(self::CACHE_ID);
  }

}
