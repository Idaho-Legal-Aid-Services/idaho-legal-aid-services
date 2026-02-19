<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for enhancing search result ranking.
 *
 * Implements:
 * - Field weights (title > body > tags)
 * - Canonical page boosting (apply, hotline, offices)
 * - Query expansion via synonyms
 * - De-duplication and canonicalization
 * - Retrieval diagnostics
 */
class RankingEnhancer {

  /**
   * Field weights for scoring.
   */
  const FIELD_WEIGHTS = [
    'title' => 15,
    'question' => 15,
    'exact_title' => 25,
    'body' => 3,
    'answer' => 3,
    'description' => 3,
    'tags' => 8,
    'topics' => 8,
    'category' => 5,
    'keyword_overlap' => 2,
  ];

  /**
   * Canonical pages with boost scores.
   */
  const CANONICAL_BOOSTS = [
    '/apply-for-help' => 30,
    '/legal-advice-line' => 25,
    '/contact/offices' => 20,
    '/contact-us' => 20,
    '/donate' => 15,
    '/forms' => 12,
    '/guides' => 12,
    '/faq' => 10,
    '/resources/legal-risk-detector' => 15,
    '/senior-risk-detector' => 15,
    '/get-involved/feedback' => 10,
    '/legal-help/housing' => 10,
    '/legal-help/family' => 10,
    '/legal-help/seniors' => 10,
    '/legal-help/consumer' => 10,
    '/legal-help/health' => 10,
    '/legal-help/civil-rights' => 10,
  ];

  /**
   * Query expansion synonyms (bidirectional).
   */
  const SYNONYMS = [
    // Housing.
    'eviction' => ['evicted', 'kicked out', 'thrown out', 'notice to vacate', 'desalojo'],
    'landlord' => ['property owner', 'property manager', 'casero', 'arrendador'],
    'tenant' => ['renter', 'lessee', 'inquilino'],
    'lease' => ['rental agreement', 'rental contract'],
    'security deposit' => ['deposit', 'deposito de seguridad'],
    'lockout' => ['locked out', 'changed locks', 'illegal eviction'],

    // Family.
    'divorce' => ['dissolution', 'divorcio', 'end marriage'],
    'custody' => ['child custody', 'custodia', 'parenting time'],
    'child support' => ['support payments', 'manutencion'],
    'protection order' => ['restraining order', 'protective order', 'po', 'orden de proteccion'],

    // Consumer.
    'debt' => ['owe money', 'collection', 'deuda'],
    'scam' => ['fraud', 'scammed', 'estafa', 'fraude'],
    'identity theft' => ['stolen identity', 'id theft', 'robo de identidad'],
    'bankruptcy' => ['bankrupt', 'bancarrota'],

    // Benefits.
    'food stamps' => ['snap', 'ebt', 'estampillas de comida'],
    'medicaid' => ['health insurance', 'seguro medico'],
    'ssi' => ['supplemental security income', 'disability'],
    'ssdi' => ['social security disability'],

    // General.
    'lawyer' => ['attorney', 'legal help', 'abogado', 'lawer'],
    'apply' => ['application', 'sign up', 'aplicar', 'aply'],
    'help' => ['assistance', 'aid', 'ayuda'],
    'phone' => ['call', 'telephone', 'hotline', 'telefono', 'llamar'],
    'office' => ['location', 'address', 'oficina', 'donde'],
    'form' => ['paperwork', 'document', 'formulario'],
    'guide' => ['instructions', 'how-to', 'manual', 'guia'],
    'faq' => ['questions', 'frequently asked', 'preguntas frecuentes'],
  ];

  /**
   * Intent signal keywords for canonical boost.
   */
  const INTENT_SIGNALS = [
    'apply' => ['apply', 'application', 'sign up', 'aplicar', 'get help', 'need lawyer', 'need help', 'quiero ayuda', 'necesito'],
    'hotline' => ['call', 'phone', 'talk to', 'speak', 'hotline', 'advice line', 'llamar', 'telefono'],
    'offices' => ['office', 'location', 'address', 'near me', 'where', 'hours', 'oficina', 'donde', 'horario'],
    'donate' => ['donate', 'donation', 'give', 'support', 'donar', 'donacion'],
    'forms' => ['form', 'forms', 'paperwork', 'document', 'formulario'],
    'guides' => ['guide', 'guides', 'instructions', 'how-to', 'guia'],
    'faq' => ['faq', 'question', 'questions', 'frequently asked', 'pregunta'],
    'feedback' => ['feedback', 'complaint', 'grievance', 'queja'],
    'risk_detector' => ['risk', 'senior', 'elderly', 'assessment', 'checkup'],
  ];

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a RankingEnhancer.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
  }

  /**
   * Scores FAQ results with enhanced ranking.
   *
   * @param array $items
   *   Array of FAQ items to score.
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Scored and sorted FAQ items.
   */
  public function scoreFaqResults(array $items, string $query, int $limit = 5): array {
    $query_lower = strtolower($query);
    $query_keywords = $this->extractKeywords($query);
    $expanded_keywords = $this->expandQuery($query_keywords);

    $scored_results = [];

    foreach ($items as $item) {
      $score = 0;
      $question = strtolower($item['question'] ?? $item['title'] ?? '');
      $answer = strtolower($item['answer'] ?? '');
      $category = strtolower($item['category'] ?? '');

      // 1. Exact question match (highest).
      if ($question === $query_lower) {
        $score += self::FIELD_WEIGHTS['exact_title'];
      }
      // 2. Query substring in question.
      elseif (strpos($question, $query_lower) !== FALSE) {
        $score += self::FIELD_WEIGHTS['question'];
      }

      // 3. Individual keyword matches in question.
      foreach ($expanded_keywords as $keyword) {
        if (strpos($question, $keyword) !== FALSE) {
          $score += self::FIELD_WEIGHTS['question'] * 0.3;
        }
      }

      // 4. Category match.
      foreach ($expanded_keywords as $keyword) {
        if (strpos($category, $keyword) !== FALSE) {
          $score += self::FIELD_WEIGHTS['category'];
        }
      }

      // 5. Answer/body matching.
      foreach ($expanded_keywords as $keyword) {
        if (strpos($answer, $keyword) !== FALSE) {
          $score += self::FIELD_WEIGHTS['answer'];
        }
      }

      // 6. Keyword overlap scoring.
      $question_keywords = $this->extractKeywords($question);
      $answer_keywords = $this->extractKeywords($answer);
      $all_item_keywords = array_merge($question_keywords, $answer_keywords);
      $overlap = count(array_intersect($expanded_keywords, $all_item_keywords));
      $score += $overlap * self::FIELD_WEIGHTS['keyword_overlap'];

      // 7. URL-based canonical boost.
      $url = strtolower($item['url'] ?? $item['source_url'] ?? '');
      $score += $this->getUrlBoost($url);

      if ($score > 0) {
        $item['score'] = $score;
        $item['_ranking_factors'] = [
          'query' => $query,
          'expanded_keywords' => $expanded_keywords,
          'score_breakdown' => [
            'total' => $score,
          ],
        ];
        $scored_results[] = $item;
      }
    }

    // Sort by score descending.
    usort($scored_results, function ($a, $b) {
      return $b['score'] - $a['score'];
    });

    // De-duplicate by URL.
    $scored_results = $this->deduplicateByUrl($scored_results);

    return array_slice($scored_results, 0, $limit);
  }

  /**
   * Scores resource results with enhanced ranking.
   *
   * @param array $items
   *   Array of resource items to score.
   * @param string $query
   *   The search query.
   * @param string|null $type_filter
   *   Filter by type (form, guide, or NULL for all).
   * @param int $limit
   *   Maximum results to return.
   *
   * @return array
   *   Scored and sorted resource items.
   */
  public function scoreResourceResults(array $items, string $query, ?string $type_filter = NULL, int $limit = 5): array {
    $query_lower = strtolower($query);
    $query_keywords = $this->extractKeywords($query);
    $expanded_keywords = $this->expandQuery($query_keywords);

    // Detect intent signals for canonical boost.
    $intent_boost = $this->detectIntentBoost($query_lower);

    $scored_results = [];

    foreach ($items as $item) {
      // Apply type filter.
      if ($type_filter !== NULL && ($item['type'] ?? 'resource') !== $type_filter) {
        continue;
      }

      $score = 0;
      $title = strtolower($item['title'] ?? '');
      $description = strtolower($item['description'] ?? '');
      $topics = array_map('strtolower', $item['topics'] ?? $item['topic_names'] ?? []);

      // 1. Exact title match.
      if ($title === $query_lower) {
        $score += self::FIELD_WEIGHTS['exact_title'];
      }
      // 2. Query substring in title.
      elseif (strpos($title, $query_lower) !== FALSE) {
        $score += self::FIELD_WEIGHTS['title'];
      }

      // 3. Individual keyword matches in title.
      foreach ($expanded_keywords as $keyword) {
        if (strpos($title, $keyword) !== FALSE) {
          $score += self::FIELD_WEIGHTS['title'] * 0.4;
        }
      }

      // 4. Topic/tag matches.
      foreach ($topics as $topic) {
        foreach ($expanded_keywords as $keyword) {
          if (strpos($topic, $keyword) !== FALSE || strpos($keyword, $topic) !== FALSE) {
            $score += self::FIELD_WEIGHTS['topics'];
          }
        }
      }

      // 5. Description matching.
      foreach ($expanded_keywords as $keyword) {
        if (strpos($description, $keyword) !== FALSE) {
          $score += self::FIELD_WEIGHTS['description'];
        }
      }

      // 6. Keyword overlap.
      $title_keywords = $this->extractKeywords($title);
      $desc_keywords = $this->extractKeywords($description);
      $all_keywords = array_merge($title_keywords, $desc_keywords, $topics);
      $overlap = count(array_intersect($expanded_keywords, $all_keywords));
      $score += $overlap * self::FIELD_WEIGHTS['keyword_overlap'];

      // 7. URL-based canonical boost.
      $url = strtolower($item['url'] ?? '');
      $score += $this->getUrlBoost($url);

      // 8. Intent-based boost.
      $score += $intent_boost;

      // 9. Boost for having file attachment (for forms).
      if ($type_filter === 'form' && ($item['has_file'] ?? FALSE)) {
        $score += 5;
      }

      if ($score > 0) {
        $item['score'] = $score;
        $scored_results[] = $item;
      }
    }

    // Sort by score descending.
    usort($scored_results, function ($a, $b) {
      return $b['score'] - $a['score'];
    });

    // De-duplicate by URL.
    $scored_results = $this->deduplicateByUrl($scored_results);

    return array_slice($scored_results, 0, $limit);
  }

  /**
   * Expands query keywords with synonyms.
   *
   * @param array $keywords
   *   Original keywords.
   *
   * @return array
   *   Expanded keywords including synonyms.
   */
  public function expandQuery(array $keywords): array {
    $expanded = $keywords;

    foreach ($keywords as $keyword) {
      $keyword_lower = strtolower($keyword);

      // Check if keyword is a known synonym.
      foreach (self::SYNONYMS as $canonical => $synonyms) {
        // Forward expansion: keyword matches canonical.
        // Use word-boundary check to prevent "bankruptcy" matching "bank".
        if ($keyword_lower === $canonical || self::containsWholeWord($keyword_lower, $canonical)) {
          $expanded = array_merge($expanded, $synonyms);
        }

        // Reverse expansion: keyword matches a synonym.
        foreach ($synonyms as $synonym) {
          if ($keyword_lower === $synonym || self::containsWholeWord($keyword_lower, $synonym)) {
            $expanded[] = $canonical;
            break;
          }
        }
      }
    }

    // Lowercase and deduplicate.
    $expanded = array_map('strtolower', $expanded);
    return array_values(array_unique($expanded));
  }

  /**
   * Checks if $haystack contains $needle as a whole word.
   *
   * Prevents substring false positives like "bankruptcy" matching "bank"
   * or "snap" matching "sna". Multi-word needles (e.g. "child support")
   * are matched as a phrase with word boundaries on each end.
   *
   * @param string $haystack
   *   The text to search in (lowercase).
   * @param string $needle
   *   The word or phrase to find (lowercase).
   *
   * @return bool
   *   TRUE if $needle appears as a whole word/phrase in $haystack.
   */
  protected static function containsWholeWord(string $haystack, string $needle): bool {
    $escaped = preg_quote($needle, '/');
    return (bool) preg_match('/\b' . $escaped . '\b/', $haystack);
  }

  /**
   * Gets URL-based boost for canonical pages.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return int
   *   Boost score.
   */
  public function getUrlBoost(string $url): int {
    $url = strtolower($url);
    $url_path = parse_url($url, PHP_URL_PATH) ?? $url;

    foreach (self::CANONICAL_BOOSTS as $path => $boost) {
      if (strpos($url_path, $path) !== FALSE) {
        return $boost;
      }
    }

    return 0;
  }

  /**
   * Detects intent signals and returns boost.
   *
   * @param string $query
   *   The query (lowercase).
   *
   * @return int
   *   Intent boost score.
   */
  protected function detectIntentBoost(string $query): int {
    foreach (self::INTENT_SIGNALS as $intent => $signals) {
      foreach ($signals as $signal) {
        if (strpos($query, $signal) !== FALSE) {
          // Return corresponding canonical boost.
          switch ($intent) {
            case 'apply':
              return self::CANONICAL_BOOSTS['/apply-for-help'];

            case 'hotline':
              return self::CANONICAL_BOOSTS['/legal-advice-line'];

            case 'offices':
              return self::CANONICAL_BOOSTS['/contact/offices'];

            case 'donate':
              return self::CANONICAL_BOOSTS['/donate'];

            case 'forms':
              return self::CANONICAL_BOOSTS['/forms'];

            case 'guides':
              return self::CANONICAL_BOOSTS['/guides'];

            case 'faq':
              return self::CANONICAL_BOOSTS['/faq'];

            case 'feedback':
              return self::CANONICAL_BOOSTS['/get-involved/feedback'];

            case 'risk_detector':
              return self::CANONICAL_BOOSTS['/resources/legal-risk-detector'];
          }
        }
      }
    }

    return 0;
  }

  /**
   * De-duplicates results by URL.
   *
   * @param array $items
   *   Items to deduplicate.
   *
   * @return array
   *   Deduplicated items (keeping highest-scored duplicate).
   */
  public function deduplicateByUrl(array $items): array {
    $seen_urls = [];
    $deduplicated = [];

    foreach ($items as $item) {
      $url = $this->normalizeUrl($item['url'] ?? $item['source_url'] ?? '');

      if (empty($url)) {
        $deduplicated[] = $item;
        continue;
      }

      if (!isset($seen_urls[$url])) {
        $seen_urls[$url] = TRUE;
        $deduplicated[] = $item;
      }
      // Skip duplicates (items are already sorted by score, so we keep the first).
    }

    return $deduplicated;
  }

  /**
   * Normalizes a URL for deduplication.
   *
   * @param string $url
   *   The URL to normalize.
   *
   * @return string
   *   Normalized URL.
   */
  protected function normalizeUrl(string $url): string {
    $url = strtolower($url);
    $url = rtrim($url, '/');

    // Remove query string for deduplication.
    if (($pos = strpos($url, '?')) !== FALSE) {
      $url = substr($url, 0, $pos);
    }

    return $url;
  }

  /**
   * Extracts keywords from text.
   *
   * @param string $text
   *   The text to extract keywords from.
   *
   * @return array
   *   Array of keywords.
   */
  public function extractKeywords(string $text): array {
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
      'from', 'about', 'into', 'through', 'during', 'before', 'after', 'above',
      'me', 'him', 'her', 'us', 'them',
    ];

    return array_values(array_unique(array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) >= 2 && !in_array($word, $stop_words);
    })));
  }

  /**
   * Generates ranking diagnostics for debugging.
   *
   * @param array $results
   *   Scored results.
   * @param string $query
   *   Original query.
   *
   * @return array
   *   Diagnostics data.
   */
  public function generateDiagnostics(array $results, string $query): array {
    $keywords = $this->extractKeywords($query);
    $expanded = $this->expandQuery($keywords);

    return [
      'query' => $query,
      'original_keywords' => $keywords,
      'expanded_keywords' => $expanded,
      'result_count' => count($results),
      'top_scores' => array_map(function ($r) {
        return [
          'title' => $r['title'] ?? $r['question'] ?? 'N/A',
          'url' => $r['url'] ?? $r['source_url'] ?? 'N/A',
          'score' => $r['score'] ?? 0,
        ];
      }, array_slice($results, 0, 5)),
    ];
  }

}
