<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and caches the Top Intents Pack YAML configuration.
 *
 * Provides lookup, synonym matching, chips, and clarifier retrieval
 * for the unified intents config at config/intents/top_intents.yml.
 *
 * Pure read-only service with no side effects.
 */
class TopIntentsPack {

  /**
   * Cache key for the parsed intents pack.
   */
  const CACHE_KEY = 'ilas_site_assistant:top_intents_pack';

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $cache;

  /**
   * Parsed intents data (lazy-loaded).
   *
   * @var array|null
   */
  protected $data;

  /**
   * Reverse synonym index (lazy-built).
   *
   * @var array|null
   */
  protected $synonymIndex;

  /**
   * Constructs a TopIntentsPack.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Cache backend for parsed YAML. NULL disables caching.
   */
  public function __construct(?CacheBackendInterface $cache = NULL) {
    $this->cache = $cache;
  }

  /**
   * Returns the full intent entry for a given key.
   *
   * @param string $intent_key
   *   The canonical intent key (e.g., 'topic_family_custody').
   *
   * @return array|null
   *   Full intent entry array, or NULL if not found.
   */
  public function lookup(string $intent_key): ?array {
    $data = $this->loadData();
    $intents = $data['intents'] ?? [];
    return $intents[$intent_key] ?? NULL;
  }

  /**
   * Reverse synonym lookup: finds intent key from normalized user input.
   *
   * Checks if any synonym from the intents pack appears as a whole-word/phrase
   * match in the normalized input. Returns the first matching intent key.
   *
   * @param string $normalized_input
   *   Lowercased, trimmed user input.
   *
   * @return string|null
   *   The matching intent key, or NULL if no match.
   */
  public function matchSynonyms(string $normalized_input): ?string {
    $index = $this->buildSynonymIndex();
    $input = mb_strtolower(trim($normalized_input));

    foreach ($index as $synonym => $intent_key) {
      if ($this->matchesSynonym($input, $synonym)) {
        return $intent_key;
      }
    }

    return NULL;
  }

  /**
   * Checks whether a synonym matches as a bounded word/phrase.
   */
  protected function matchesSynonym(string $input, string $synonym): bool {
    $normalized_synonym = mb_strtolower(trim($synonym));
    if ($normalized_synonym === '') {
      return FALSE;
    }

    $escaped = preg_quote($normalized_synonym, '/');
    // Allow flexible whitespace for phrase synonyms.
    $escaped = str_replace('\ ', '\s+', $escaped);
    $pattern = '/(?<![\p{L}\p{N}_])' . $escaped . '(?![\p{L}\p{N}_])/u';

    return (bool) preg_match($pattern, $input);
  }

  /**
   * Returns chips for an intent.
   *
   * @param string $intent_key
   *   The canonical intent key.
   *
   * @return array
   *   Array of chip definitions [{label, intent}], or empty array.
   */
  public function getChips(string $intent_key): array {
    $entry = $this->lookup($intent_key);
    return $entry['chips'] ?? [];
  }

  /**
   * Returns the clarifier for an intent, if defined.
   *
   * @param string $intent_key
   *   The canonical intent key.
   *
   * @return array|null
   *   Clarifier array with 'question' and 'options', or NULL.
   */
  public function getClarifier(string $intent_key): ?array {
    $entry = $this->lookup($intent_key);
    return $entry['clarifier'] ?? NULL;
  }

  /**
   * Returns the config version string.
   *
   * @return string
   *   Version from the YAML, or 'unknown'.
   */
  public function getVersion(): string {
    $data = $this->loadData();
    return $data['version'] ?? 'unknown';
  }

  /**
   * Returns all intent keys defined in the pack.
   *
   * @return array
   *   Array of intent key strings.
   */
  public function getAllKeys(): array {
    $data = $this->loadData();
    return array_keys($data['intents'] ?? []);
  }

  /**
   * Loads and caches the YAML data.
   *
   * @return array
   *   Parsed YAML data.
   */
  protected function loadData(): array {
    if ($this->data !== NULL) {
      return $this->data;
    }

    // Try cache first.
    if ($this->cache) {
      $cached = $this->cache->get(self::CACHE_KEY);
      if ($cached) {
        $this->data = $cached->data;
        return $this->data;
      }
    }

    // Parse from YAML file.
    $yaml_path = dirname(__DIR__, 2) . '/config/intents/top_intents.yml';
    if (!file_exists($yaml_path)) {
      $this->data = ['version' => 'missing', 'intents' => []];
      return $this->data;
    }

    $this->data = Yaml::parseFile($yaml_path) ?: ['version' => 'empty', 'intents' => []];

    // Cache for 1 hour.
    if ($this->cache) {
      $this->cache->set(self::CACHE_KEY, $this->data, time() + 3600);
    }

    return $this->data;
  }

  /**
   * Builds reverse synonym index: synonym → intent_key.
   *
   * Longer synonyms are checked first to prefer specific matches.
   *
   * @return array
   *   Map of lowercase synonym → intent_key.
   */
  protected function buildSynonymIndex(): array {
    if ($this->synonymIndex !== NULL) {
      return $this->synonymIndex;
    }

    $data = $this->loadData();
    $index = [];

    foreach ($data['intents'] ?? [] as $key => $entry) {
      foreach ($entry['synonyms'] ?? [] as $synonym) {
        $index[mb_strtolower(trim($synonym))] = $key;
      }
    }

    // Sort by synonym length descending so longer matches take priority.
    uksort($index, function ($a, $b) {
      return mb_strlen($b) - mb_strlen($a);
    });

    $this->synonymIndex = $index;
    return $this->synonymIndex;
  }

}
