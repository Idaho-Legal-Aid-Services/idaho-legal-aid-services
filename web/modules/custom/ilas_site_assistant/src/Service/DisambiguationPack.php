<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and caches the disambiguation catalog YAML.
 */
class DisambiguationPack {

  /**
   * Cache key for the parsed catalog.
   */
  const CACHE_KEY = 'ilas_site_assistant:disambiguation_pack';

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $cache;

  /**
   * Parsed catalog data.
   *
   * @var array|null
   */
  protected $data;

  /**
   * Constructs a DisambiguationPack.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Optional cache backend. NULL disables caching.
   */
  public function __construct(?CacheBackendInterface $cache = NULL) {
    $this->cache = $cache;
  }

  /**
   * Returns the catalog version.
   */
  public function getVersion(): string {
    $data = $this->loadData();
    return (string) ($data['version'] ?? 'unknown');
  }

  /**
   * Returns configured disambiguation families.
   */
  public function getFamilies(): array {
    $data = $this->loadData();
    return is_array($data['families'] ?? NULL) ? $data['families'] : [];
  }

  /**
   * Returns topic lexicon settings and topics.
   */
  public function getTopicLexicon(): array {
    $data = $this->loadData();
    return is_array($data['topic_lexicon'] ?? NULL) ? $data['topic_lexicon'] : [];
  }

  /**
   * Returns configured confusable intent pairs.
   */
  public function getConfusablePairs(): array {
    $data = $this->loadData();
    return is_array($data['confusable_pairs'] ?? NULL) ? $data['confusable_pairs'] : [];
  }

  /**
   * Returns the raw catalog.
   */
  public function getRawData(): array {
    return $this->loadData();
  }

  /**
   * Loads and caches the YAML catalog.
   */
  protected function loadData(): array {
    if ($this->data !== NULL) {
      return $this->data;
    }

    $yamlPath = dirname(__DIR__, 2) . '/config/routing/disambiguation.yml';
    $cacheKey = self::CACHE_KEY;
    if (file_exists($yamlPath)) {
      $mtime = (int) @filemtime($yamlPath);
      if ($mtime > 0) {
        $cacheKey .= ':' . $mtime;
      }
    }

    if ($this->cache) {
      $cached = $this->cache->get($cacheKey);
      if ($cached) {
        $this->data = $cached->data;
        return $this->data;
      }
    }

    if (!file_exists($yamlPath)) {
      $this->data = $this->defaultData('missing');
      return $this->data;
    }

    $parsed = Yaml::parseFile($yamlPath);
    $this->data = $this->normalizeData(is_array($parsed) ? $parsed : []);

    if ($this->cache) {
      $this->cache->set($cacheKey, $this->data, time() + 3600);
    }

    return $this->data;
  }

  /**
   * Returns normalized catalog data with required sections.
   */
  protected function normalizeData(array $data): array {
    $normalized = $this->defaultData('1.0');
    $normalized['version'] = (string) ($data['version'] ?? $normalized['version']);
    $normalized['families'] = is_array($data['families'] ?? NULL) ? $data['families'] : [];
    $normalized['topic_lexicon'] = is_array($data['topic_lexicon'] ?? NULL) ? $data['topic_lexicon'] : [];
    $normalized['confusable_pairs'] = is_array($data['confusable_pairs'] ?? NULL) ? $data['confusable_pairs'] : [];
    return $normalized;
  }

  /**
   * Returns the default catalog shape.
   */
  protected function defaultData(string $version): array {
    return [
      'version' => $version,
      'families' => [],
      'topic_lexicon' => [
        'modifiers' => [],
        'filler_words' => [],
        'lead_patterns' => [],
        'topics' => [],
      ],
      'confusable_pairs' => [],
    ];
  }

}
