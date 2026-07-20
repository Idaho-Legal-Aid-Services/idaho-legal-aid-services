<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Serialization\Yaml;

/**
 * Expands acronyms and abbreviations to their canonical terms.
 *
 * Loaded from config/routing/acronyms.yml, this service runs early in the
 * extraction pipeline (before synonym mapping) to normalize acronyms like
 * "DV" -> "domestic violence", "POA" -> "power of attorney", etc.
 *
 * Only expands on whole-word boundaries to avoid false positives.
 */
class AcronymExpander {

  /**
   * Acronym map: lowercase key => ['expansion' => ..., 'intent' => ...].
   *
   * @var array
   */
  protected $acronyms = [];

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $cache;

  /**
   * Constructs an AcronymExpander.
   *
   * @param object|null $cache
   *   Optional cache backend. NULL for standalone/test usage.
   */
  public function __construct($cache = NULL) {
    $this->cache = $cache;
    $this->loadAcronyms();
  }

  /**
   * Expands acronyms in the given text.
   *
   * @param string $text
   *   The input text (will be processed case-insensitively).
   *
   * @return array
   *   Array with keys:
   *   - 'text': The text with acronyms replaced by expansions.
   *   - 'expansions': Array of ['acronym' => ..., 'expansion' => ..., 'intent' => ...].
   */
  public function expand(string $text): array {
    $expansions = [];
    $text_lower = strtolower($text);

    foreach ($this->acronyms as $acronym_lower => $config) {
      // Match whole words only using word boundaries.
      // For acronyms with periods/spaces (like "pro se"), use appropriate pattern.
      $escaped = preg_quote($acronym_lower, '/');
      $pattern = '/\b' . $escaped . '\b/i';

      if (preg_match($pattern, $text_lower)) {
        $expansion = $config['expansion'];
        $expansions[] = [
          'acronym' => $acronym_lower,
          'expansion' => $expansion,
          'intent' => $config['intent'] ?? NULL,
        ];
        // Replace in the lowercase working copy.
        $text_lower = preg_replace($pattern, $expansion, $text_lower);
      }
    }

    return [
      'text' => $text_lower,
      'expansions' => $expansions,
    ];
  }

  /**
   * Checks if a token is a known acronym.
   *
   * @param string $token
   *   The token to check.
   *
   * @return bool
   *   TRUE if the token is a known acronym.
   */
  public function isAcronym(string $token): bool {
    return isset($this->acronyms[strtolower(trim($token))]);
  }

  /**
   * Gets the expansion for a specific acronym.
   *
   * @param string $acronym
   *   The acronym to look up.
   *
   * @return string|null
   *   The expansion or NULL if not found.
   */
  public function getExpansion(string $acronym): ?string {
    $key = strtolower(trim($acronym));
    return $this->acronyms[$key]['expansion'] ?? NULL;
  }

  /**
   * Gets the intent hint for a specific acronym.
   *
   * @param string $acronym
   *   The acronym to look up.
   *
   * @return string|null
   *   The intent hint or NULL.
   */
  public function getIntentHint(string $acronym): ?string {
    $key = strtolower(trim($acronym));
    return $this->acronyms[$key]['intent'] ?? NULL;
  }

  /**
   * Returns the full acronym map (for testing/debugging).
   *
   * @return array
   *   The acronym map.
   */
  public function getAcronymMap(): array {
    return $this->acronyms;
  }

  /**
   * Loads the acronym map from YAML config.
   */
  protected function loadAcronyms(): void {
    // Check cache first.
    if ($this->cache) {
      $cached = $this->cache->get('ilas_acronym_expander_map');
      if ($cached) {
        $this->acronyms = $cached->data;
        return;
      }
    }

    $yaml_path = __DIR__ . '/../../config/routing/acronyms.yml';
    if (!file_exists($yaml_path)) {
      $this->acronyms = [];
      return;
    }

    $yaml_content = file_get_contents($yaml_path);

    $raw = Yaml::decode($yaml_content) ?: [];

    // Build normalized map: lowercase key => config.
    $this->acronyms = [];
    foreach ($raw as $key => $config) {
      if (is_array($config) && isset($config['expansion'])) {
        $this->acronyms[strtolower($key)] = [
          'expansion' => strtolower($config['expansion']),
          'intent' => $config['intent'] ?? NULL,
        ];
      }
    }

    // Cache the parsed map.
    if ($this->cache && !empty($this->acronyms)) {
      $this->cache->set('ilas_acronym_expander_map', $this->acronyms, time() + 3600);
    }
  }

}
