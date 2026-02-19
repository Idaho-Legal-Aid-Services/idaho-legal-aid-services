<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Routes short/single-token queries to canonical topic landing pages.
 *
 * This service runs BEFORE generic fallback in the IntentRouter pipeline.
 * It handles the case where users type a bare topic word like "divorce",
 * "custody", or "eviction" which would otherwise fall through to
 * greeting/fallback because existing phrase detection requires multi-word
 * triggers.
 *
 * Matching hierarchy (highest to lowest confidence):
 *   1. Exact token match (0.88)
 *   2. Stem match (0.85)
 *   3. Synonym match (0.82)
 *   4. Short phrase match (0.85)
 *   5. Fuzzy Levenshtein match (0.70) - very conservative thresholds
 *
 * This class has NO Drupal service dependencies beyond cache, so it can be
 * used standalone in eval harnesses and unit tests.
 */
class TopicRouter {

  /**
   * Confidence scores by match type.
   */
  const CONFIDENCE_EXACT = 0.88;
  const CONFIDENCE_STEM = 0.85;
  const CONFIDENCE_PHRASE = 0.85;
  const CONFIDENCE_SYNONYM = 0.82;
  const CONFIDENCE_FUZZY = 0.70;

  /**
   * Maximum word count for messages this router handles.
   *
   * Beyond this, the message is complex enough for IntentRouter patterns.
   */
  const MAX_WORD_COUNT = 3;

  /**
   * Minimum token length for fuzzy matching.
   */
  const MIN_FUZZY_LENGTH = 5;

  /**
   * The loaded topic map.
   *
   * @var array
   */
  protected $topicMap;

  /**
   * Precomputed lookup indices.
   *
   * @var array
   */
  protected $tokenIndex;
  protected $stemIndex;
  protected $synonymIndex;
  protected $phraseIndex;
  protected $fuzzyTokens;

  /**
   * Topic-level alias index mapping aliases to canonical keys.
   *
   * @var array
   */
  protected $topicAliasIndex;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $cache;

  /**
   * Constructs a TopicRouter.
   *
   * @param object|null $cache
   *   Optional cache backend. NULL for standalone/test usage.
   */
  public function __construct($cache = NULL) {
    $this->cache = $cache;
    $this->loadTopicMap();
    $this->buildIndices();
  }

  /**
   * Routes a short message to a topic if possible.
   *
   * @param string $message
   *   The user's message (raw input).
   *
   * @return array|null
   *   Topic routing result with keys:
   *     - type: 'topic_routed'
   *     - topic: Topic key (e.g., 'family', 'housing')
   *     - service_area: Service area key
   *     - url: Canonical landing page URL
   *     - label: Human-readable label
   *     - confidence: Match confidence (0-1)
   *     - match_type: How the match was made (exact|stem|synonym|phrase|fuzzy)
   *     - matched_token: The token that matched
   *   Or NULL if no topic match.
   */
  public function route(string $message): ?array {
    $normalized = $this->normalize($message);

    // Guard: reject messages with too many raw words (before stop word
    // removal). TopicRouter is designed for short, 1-3 word queries.
    // A longer sentence belongs in IntentRouter even if most words are
    // stop words.
    $raw_words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (count($raw_words) > self::MAX_WORD_COUNT) {
      return NULL;
    }

    $words = $this->tokenize($normalized);
    $word_count = count($words);

    // No meaningful tokens after stop word removal.
    if ($word_count === 0) {
      return NULL;
    }

    // For single-token queries, try all match types.
    if ($word_count === 1) {
      $token = $words[0];
      return $this->matchSingleToken($token);
    }

    // For 2-3 word queries, try phrase match first, then per-token.
    $phrase_result = $this->matchPhrase($normalized);
    if ($phrase_result) {
      return $phrase_result;
    }

    // Try matching each token individually, return best match.
    $best = NULL;
    foreach ($words as $word) {
      // Skip very short words (likely stop words).
      if (strlen($word) < 3) {
        continue;
      }
      $result = $this->matchSingleToken($word);
      if ($result && (!$best || $result['confidence'] > $best['confidence'])) {
        $best = $result;
      }
    }

    // For multi-word queries, slightly reduce confidence since context is ambiguous.
    if ($best) {
      $best['confidence'] = max(0.65, $best['confidence'] - 0.05);
    }

    return $best;
  }

  /**
   * Matches a single token against all indices.
   *
   * @param string $token
   *   Normalized single token.
   *
   * @return array|null
   *   Match result or NULL.
   */
  protected function matchSingleToken(string $token): ?array {
    // 1. Exact token match.
    if (isset($this->tokenIndex[$token])) {
      $topic_key = $this->tokenIndex[$token];
      return $this->buildResult($topic_key, self::CONFIDENCE_EXACT, 'exact', $token);
    }

    // 2. Stem match.
    $stem_result = $this->matchStem($token);
    if ($stem_result) {
      return $stem_result;
    }

    // 3. Synonym match.
    if (isset($this->synonymIndex[$token])) {
      $topic_key = $this->synonymIndex[$token];
      return $this->buildResult($topic_key, self::CONFIDENCE_SYNONYM, 'synonym', $token);
    }

    // 4. Fuzzy match (very conservative).
    $fuzzy_result = $this->matchFuzzy($token);
    if ($fuzzy_result) {
      return $fuzzy_result;
    }

    return NULL;
  }

  /**
   * Matches a token against stem patterns.
   *
   * @param string $token
   *   Normalized token.
   *
   * @return array|null
   *   Match result or NULL.
   */
  protected function matchStem(string $token): ?array {
    foreach ($this->stemIndex as $stem => $topic_key) {
      if (strlen($token) > strlen($stem) && strpos($token, $stem) === 0) {
        return $this->buildResult($topic_key, self::CONFIDENCE_STEM, 'stem', $token);
      }
    }
    return NULL;
  }

  /**
   * Matches a normalized phrase against phrase patterns.
   *
   * @param string $normalized
   *   Normalized message.
   *
   * @return array|null
   *   Match result or NULL.
   */
  protected function matchPhrase(string $normalized): ?array {
    foreach ($this->phraseIndex as $phrase => $topic_key) {
      if (strpos($normalized, $phrase) !== FALSE) {
        return $this->buildResult($topic_key, self::CONFIDENCE_PHRASE, 'phrase', $phrase);
      }
    }
    return NULL;
  }

  /**
   * Fuzzy matches a token using Levenshtein distance.
   *
   * Very conservative: max distance 1 for 5-7 char tokens, 2 for 8+ chars.
   * Tokens under 5 characters are not fuzzy-matched to avoid false positives.
   *
   * @param string $token
   *   Normalized token.
   *
   * @return array|null
   *   Match result or NULL.
   */
  protected function matchFuzzy(string $token): ?array {
    $len = strlen($token);
    if ($len < self::MIN_FUZZY_LENGTH) {
      return NULL;
    }

    $max_distance = $len >= 8 ? 2 : 1;
    $best_distance = $max_distance + 1;
    $best_topic = NULL;
    $best_match = NULL;

    foreach ($this->fuzzyTokens as $candidate => $topic_key) {
      // Only fuzzy-match against tokens of similar length.
      $candidate_len = strlen($candidate);
      if (abs($len - $candidate_len) > $max_distance) {
        continue;
      }

      $distance = levenshtein($token, $candidate);
      if ($distance > 0 && $distance <= $max_distance && $distance < $best_distance) {
        $best_distance = $distance;
        $best_topic = $topic_key;
        $best_match = $candidate;
      }
    }

    if ($best_topic !== NULL) {
      // Scale confidence down by distance.
      $confidence = self::CONFIDENCE_FUZZY - ($best_distance - 1) * 0.05;
      return $this->buildResult($best_topic, $confidence, 'fuzzy', $best_match);
    }

    return NULL;
  }

  /**
   * Builds a routing result array.
   *
   * @param string $topic_key
   *   The topic key (e.g., 'family', 'housing').
   * @param float $confidence
   *   Match confidence.
   * @param string $match_type
   *   How the match was made.
   * @param string $matched_token
   *   The token/phrase that matched.
   *
   * @return array
   *   Routing result.
   */
  protected function buildResult(string $topic_key, float $confidence, string $match_type, string $matched_token): array {
    $topic = $this->topicMap[$topic_key];
    return [
      'type' => 'topic_routed',
      'topic' => $topic_key,
      'service_area' => $topic['service_area'],
      'url' => $topic['url'],
      'label' => $topic['label'],
      'confidence' => $confidence,
      'match_type' => $match_type,
      'matched_token' => $matched_token,
    ];
  }

  /**
   * Normalizes a message for matching.
   *
   * @param string $message
   *   Raw message.
   *
   * @return string
   *   Normalized lowercase string with punctuation removed.
   */
  protected function normalize(string $message): string {
    $message = strtolower(trim($message));
    // Remove punctuation except hyphens (for compound words).
    $message = preg_replace('/[^\w\s\-]/u', '', $message);
    // Normalize whitespace.
    $message = preg_replace('/\s+/', ' ', $message);
    return $message;
  }

  /**
   * Tokenizes a normalized message into words.
   *
   * @param string $normalized
   *   Normalized message.
   *
   * @return array
   *   Array of tokens, with stop words removed.
   */
  protected function tokenize(string $normalized): array {
    $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

    // Remove stop words for matching (but keep for phrase matching).
    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'you', 'we', 'they', 'my', 'your', 'me', 'can',
      'do', 'does', 'did', 'have', 'has', 'am', 'be', 'been', 'what',
      'how', 'where', 'when', 'why', 'this', 'that', 'it', 'about',
      'need', 'help', 'want', 'looking', 'get', 'find',
      'por', 'el', 'la', 'los', 'las', 'un', 'una', 'con', 'de', 'en',
    ];

    return array_values(array_filter($words, function ($word) use ($stop_words) {
      return !in_array($word, $stop_words);
    }));
  }

  /**
   * Loads the topic map from YAML config.
   */
  protected function loadTopicMap(): void {
    // Check cache first.
    if ($this->cache) {
      $cached = $this->cache->get('ilas_topic_router_map');
      if ($cached) {
        $this->topicMap = $cached->data;
        return;
      }
    }

    $yaml_path = __DIR__ . '/../../config/routing/topic_map.yml';
    if (!file_exists($yaml_path)) {
      $this->topicMap = [];
      return;
    }

    $yaml_content = file_get_contents($yaml_path);
    // Use Symfony YAML parser if available, otherwise basic parsing.
    if (class_exists('\Symfony\Component\Yaml\Yaml')) {
      $this->topicMap = \Symfony\Component\Yaml\Yaml::parse($yaml_content) ?: [];
    }
    else {
      // Fallback: use Drupal's Yaml parser.
      if (class_exists('\Drupal\Component\Serialization\Yaml')) {
        $this->topicMap = \Drupal\Component\Serialization\Yaml::decode($yaml_content) ?: [];
      }
      else {
        $this->topicMap = [];
      }
    }

    // Cache the parsed map.
    if ($this->cache && !empty($this->topicMap)) {
      $this->cache->set('ilas_topic_router_map', $this->topicMap, time() + 3600);
    }
  }

  /**
   * Builds precomputed lookup indices from the topic map.
   */
  protected function buildIndices(): void {
    $this->tokenIndex = [];
    $this->stemIndex = [];
    $this->synonymIndex = [];
    $this->phraseIndex = [];
    $this->fuzzyTokens = [];
    $this->topicAliasIndex = [];

    foreach ($this->topicMap as $topic_key => $topic) {
      // Index exact tokens.
      foreach ($topic['tokens'] ?? [] as $token) {
        $normalized = strtolower($token);
        $this->tokenIndex[$normalized] = $topic_key;
        // Also add to fuzzy candidates.
        $this->fuzzyTokens[$normalized] = $topic_key;
      }

      // Index stems.
      foreach ($topic['stems'] ?? [] as $stem) {
        $this->stemIndex[strtolower($stem)] = $topic_key;
      }

      // Index synonyms.
      foreach ($topic['synonyms'] ?? [] as $synonym) {
        $normalized = strtolower($synonym);
        $this->synonymIndex[$normalized] = $topic_key;
        // Also add to fuzzy candidates.
        $this->fuzzyTokens[$normalized] = $topic_key;
      }

      // Index phrases.
      foreach ($topic['phrases'] ?? [] as $phrase) {
        $this->phraseIndex[strtolower($phrase)] = $topic_key;
      }

      // Index topic-level aliases for canonical key resolution.
      foreach ($topic['topics'] ?? [] as $sub_key => $sub_topic) {
        $entry = [
          'service_area' => $topic_key,
          'topic' => $sub_key,
          'canonical_key' => $sub_topic['canonical_key'],
          'term_uuid' => $sub_topic['term_uuid'] ?? NULL,
          'term_name' => $sub_topic['term_name'] ?? NULL,
        ];
        // Index the sub-key itself (e.g., "divorce", "eviction").
        $this->topicAliasIndex[strtolower($sub_key)] = $entry;
        // Index the term_name.
        if (!empty($sub_topic['term_name'])) {
          $this->topicAliasIndex[strtolower($sub_topic['term_name'])] = $entry;
        }
        // Index each alias.
        foreach ($sub_topic['aliases'] ?? [] as $alias) {
          $this->topicAliasIndex[strtolower($alias)] = $entry;
        }
      }
    }
  }

  /**
   * Returns the loaded topic map (for debugging/testing).
   *
   * @return array
   *   The topic map.
   */
  public function getTopicMap(): array {
    return $this->topicMap;
  }

  /**
   * Returns all registered topics with their URLs.
   *
   * @return array
   *   Associative array of topic_key => ['url' => ..., 'label' => ...].
   */
  public function getTopicUrls(): array {
    $urls = [];
    foreach ($this->topicMap as $key => $topic) {
      $urls[$key] = [
        'url' => $topic['url'],
        'label' => $topic['label'],
      ];
    }
    return $urls;
  }

  /**
   * Resolves a message to a canonical topic key.
   *
   * Looks up the normalized message (and its individual tokens) against
   * the topic-level alias index built from topic_map.yml `topics:` entries.
   *
   * @param string $message
   *   The user's message (raw input).
   *
   * @return array|null
   *   Topic resolution result with keys:
   *     - service_area: Service area key (e.g., 'family')
   *     - topic: Topic sub-key (e.g., 'divorce')
   *     - canonical_key: Stable identifier (e.g., 'family_divorce')
   *     - term_uuid: UUID of the topics taxonomy term (or NULL)
   *     - term_name: Exact Drupal term name (or NULL)
   *   Or NULL if no topic-level match.
   */
  public function resolveTopicKey(string $message): ?array {
    $normalized = $this->normalize($message);

    // 1. Try full normalized message as a phrase match.
    if (isset($this->topicAliasIndex[$normalized])) {
      return $this->topicAliasIndex[$normalized];
    }

    // 2. Try individual tokens (after stop word removal).
    $words = $this->tokenize($normalized);
    foreach ($words as $word) {
      if (isset($this->topicAliasIndex[$word])) {
        return $this->topicAliasIndex[$word];
      }
    }

    return NULL;
  }

  /**
   * Returns all canonical topic keys with their metadata.
   *
   * @return array
   *   Flat array of canonical key entries, each with:
   *     - canonical_key, service_area, topic, term_uuid, term_name.
   */
  public function getAllCanonicalKeys(): array {
    $keys = [];
    foreach ($this->topicMap as $topic_key => $topic) {
      foreach ($topic['topics'] ?? [] as $sub_key => $sub_topic) {
        $keys[] = [
          'service_area' => $topic_key,
          'topic' => $sub_key,
          'canonical_key' => $sub_topic['canonical_key'],
          'term_uuid' => $sub_topic['term_uuid'] ?? NULL,
          'term_name' => $sub_topic['term_name'] ?? NULL,
        ];
      }
    }
    return $keys;
  }

  /**
   * Returns the topic alias index (for debugging/testing).
   *
   * @return array
   *   The topic alias index.
   */
  public function getTopicAliasIndex(): array {
    return $this->topicAliasIndex;
  }

}
