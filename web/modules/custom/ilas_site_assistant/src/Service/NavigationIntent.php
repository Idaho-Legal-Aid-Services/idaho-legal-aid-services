<?php

namespace Drupal\ilas_site_assistant\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Detects navigation-style queries and matches them to canonical pages.
 *
 * Navigation queries are "take me to the forms page" or "where do I find
 * office locations" -- the user wants a specific page, not an answer.
 *
 * This service:
 *  1. Detects navigation phrasing ("show me", "where do I find", etc.)
 *  2. Matches the residual query against a page index (titles, slugs, aliases)
 *  3. Returns a ranked list of candidate pages with scores.
 *
 * The page index is loaded from config/routing/navigation_pages.yml and can be
 * extended without code changes.
 *
 * This class has NO Drupal service dependencies and can be used standalone
 * in eval harnesses, Drush commands, or unit tests.
 */
class NavigationIntent {

  /**
   * Navigation trigger patterns (regex).
   *
   * Phrases that signal the user wants to *find a page* rather than get info.
   *
   * @var string[]
   */
  protected const NAV_PATTERNS = [
    // English.
    '/\b(where\s*(do|can|would)\s*i\s*(find|go|look|see|get\s*to))\b/i',
    '/\b(where\s*(do|can)\s*i\s*(apply|donate|file|submit|start|access))\b/i',
    '/\b(where\s*(are|is)\s*(the|your|you))\b/i',
    '/\bwhere\s+is\b.+\b(info|page|section|area)\b/i',
    '/\b(show\s*me|take\s*me\s*to|bring\s*me\s*to|go\s*to|navigate\s*to)\b/i',
    '/\b(link\s*(to|for)|direct\s*link|url\s*(for|to))\b/i',
    '/\b(page\s*(for|about|on|where)|website\s*page)\b/i',
    '/\b(find\s*(the|your)\s*page|find\s*(the|your)\s*(section|area))\b/i',
    '/\b(looking\s*for\s*(the|your|a)\s*(page|section|area|site|website))\b/i',
    '/\b(how\s*do\s*i\s*(get\s*to|access|navigate|reach)\s*(the|your)?)\b/i',
    '/\b(i\s*need\s*the\s*(page|link|url|site|section))\b/i',
    '/\b(can\s*you\s*(show|direct|point|send)\s*me\s*to)\b/i',
    // "X page" pattern (e.g., "forms page", "donation page", "FAQ page")
    '/\b\w+\s+page\b/i',
    // Spanish.
    '/\b(donde\s*(encuentro|esta|puedo\s*(encontrar|ver|ir)))\b/i',
    '/\b(llevame\s*a|muestrame|pagina\s*(de|para|sobre))\b/i',
    '/\b(enlace\s*(a|de|para))\b/i',
  ];

  /**
   * Page index: list of navigable pages.
   *
   * @var array
   */
  protected $pageIndex;

  /**
   * Constructs a NavigationIntent.
   *
   * @param array $page_index
   *   Optional pre-built page index. If empty, loads defaults.
   */
  public function __construct(array $page_index = []) {
    $this->pageIndex = $page_index ?: self::getDefaultPageIndex();
  }

  /**
   * Creates an instance from a YAML file path.
   *
   * @param string $yaml_path
   *   Path to navigation_pages.yml.
   *
   * @return static
   */
  public static function fromYaml(string $yaml_path): self {
    if (!file_exists($yaml_path)) {
      return new self();
    }
    $data = Yaml::parseFile($yaml_path);
    if (!is_array($data)) {
      return new self();
    }
    return new self($data);
  }

  /**
   * Detects whether a message is a navigation query.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if navigation phrasing is detected.
   */
  public function isNavigationQuery(string $message): bool {
    foreach (self::NAV_PATTERNS as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts the search residual after stripping navigation phrasing.
   *
   * "where do I find the forms page" → "the forms page"
   * "show me office locations"       → "office locations"
   *
   * @param string $message
   *   The user message.
   *
   * @return string
   *   The stripped residual (lowercased, trimmed).
   */
  public function extractResidual(string $message): string {
    $residual = $message;

    // Strip navigation trigger phrases.
    $strip_patterns = [
      '/\b(where\s*(do|can|would)\s*i\s*(find|go|look|see|get\s*to|apply|donate|file|submit|start|access))\b/i',
      '/\b(where\s*(are|is)\s*(the|your|you))\b/i',
      '/\b(where\s*is\s+)\b/i',
      '/\b(show\s*me|take\s*me\s*to|bring\s*me\s*to|go\s*to|navigate\s*to)\b/i',
      '/\b(link\s*(to|for)|direct\s*link|url\s*(for|to))\b/i',
      '/\b(page\s*(for|about|on|where)|website\s*page)\b/i',
      '/\b(find\s*(the|your)\s*page|find\s*(the|your)\s*(section|area))\b/i',
      '/\b(looking\s*for\s*(the|your|a)\s*(page|section|area|site|website))\b/i',
      '/\b(how\s*do\s*i\s*(get\s*to|access|navigate|reach)\s*(the|your)?)\b/i',
      '/\b(i\s*need\s*the\s*(page|link|url|site|section))\b/i',
      '/\b(can\s*you\s*(show|direct|point|send)\s*me\s*to)\b/i',
      '/\b(donde\s*(encuentro|esta|puedo\s*(encontrar|ver|ir)))\b/i',
      '/\b(llevame\s*a|muestrame|pagina\s*(de|para|sobre))\b/i',
      '/\b(enlace\s*(a|de|para))\b/i',
    ];

    foreach ($strip_patterns as $pattern) {
      $residual = preg_replace($pattern, '', $residual);
    }

    // Remove leftover stop words and noise.
    $residual = preg_replace('/\b(the|your|a|an|for|about|on|to|page|section|website|site)\b/i', '', $residual);
    $residual = preg_replace('/[?.!,]+/', '', $residual);
    $residual = preg_replace('/\s+/', ' ', $residual);

    return strtolower(trim($residual));
  }

  /**
   * Matches a message against the page index.
   *
   * Returns ranked candidate pages sorted by relevance score.
   *
   * @param string $message
   *   The user message (raw).
   * @param int $limit
   *   Max results to return.
   *
   * @return array
   *   Array of matches: [{page_key, url, label, score, match_type}].
   */
  public function match(string $message, int $limit = 5): array {
    $residual = $this->extractResidual($message);
    $message_lower = strtolower($message);
    $candidates = [];

    foreach ($this->pageIndex as $page_key => $page) {
      $best_score = 0.0;
      $match_type = 'none';

      // 1. Exact slug match (highest priority).
      $slug = $page['slug'] ?? '';
      if ($slug && strpos($message_lower, $slug) !== FALSE) {
        $best_score = 0.95;
        $match_type = 'slug_exact';
      }

      // 2. Title match.
      $title = strtolower($page['label'] ?? '');
      if ($title) {
        // Exact title in residual.
        if ($residual === $title || strpos($residual, $title) !== FALSE) {
          $score = 0.92;
          if ($score > $best_score) {
            $best_score = $score;
            $match_type = 'title_exact';
          }
        }
        // Title words overlap.
        else {
          $title_words = array_filter(explode(' ', $title), fn($w) => strlen($w) >= 3);
          $residual_words = array_filter(explode(' ', $residual), fn($w) => strlen($w) >= 3);
          if (!empty($title_words) && !empty($residual_words)) {
            $overlap = count(array_intersect($residual_words, $title_words));
            if ($overlap > 0) {
              $score = 0.60 + min(0.30, $overlap * 0.15);
              if ($score > $best_score) {
                $best_score = $score;
                $match_type = 'title_partial';
              }
            }
          }
        }
      }

      // 3. Alias match.
      $aliases = $page['aliases'] ?? [];
      foreach ($aliases as $alias) {
        $alias_lower = strtolower($alias);
        if ($alias_lower === $residual || strpos($residual, $alias_lower) !== FALSE || strpos($message_lower, $alias_lower) !== FALSE) {
          $score = 0.90;
          if ($score > $best_score) {
            $best_score = $score;
            $match_type = 'alias_exact';
          }
        }
      }

      // 4. Keyword match. Whole-word only: substring matching made the
      // keyword 'form' hit inside "information", routing informational
      // queries to the Forms page.
      $keywords = $page['keywords'] ?? [];
      $kw_hits = 0;
      foreach ($keywords as $keyword) {
        $kw_lower = strtolower($keyword);
        $kw_pattern = '/\b' . preg_quote($kw_lower, '/') . '\b/u';
        if (preg_match($kw_pattern, $residual) || preg_match($kw_pattern, $message_lower)) {
          $kw_hits++;
        }
      }
      if ($kw_hits > 0) {
        $score = 0.50 + min(0.35, $kw_hits * 0.12);
        if ($score > $best_score) {
          $best_score = $score;
          $match_type = 'keyword';
        }
      }

      // 5. URL path segment match.
      $url = strtolower($page['url'] ?? '');
      if ($url && $residual) {
        $url_segments = array_filter(explode('/', $url), fn($s) => strlen($s) >= 3);
        $residual_tokens = explode(' ', $residual);
        foreach ($url_segments as $seg) {
          $seg_clean = str_replace('-', ' ', $seg);
          foreach ($residual_tokens as $tok) {
            if (strlen($tok) >= 3 && (strpos($seg_clean, $tok) !== FALSE || strpos($tok, $seg_clean) !== FALSE)) {
              $score = 0.70;
              if ($score > $best_score) {
                $best_score = $score;
                $match_type = 'url_segment';
              }
            }
          }
        }
      }

      // 6. Fuzzy match on slug/label/aliases (for typos like "froms", "giudes").
      if ($best_score < 0.60) {
        $fuzzy_targets = array_merge(
          [$slug, $title],
          array_map('strtolower', $aliases)
        );
        $residual_tokens = array_filter(explode(' ', $residual), fn($t) => strlen($t) >= 3);
        foreach ($residual_tokens as $tok) {
          foreach ($fuzzy_targets as $target) {
            if (!$target || strlen($target) < 3) {
              continue;
            }
            // For single-word targets, use Levenshtein.
            $target_words = explode(' ', $target);
            foreach ($target_words as $tw) {
              if (strlen($tw) < 3 || strlen($tok) < 3) {
                continue;
              }
              $max_dist = strlen($tw) >= 8 ? 2 : (strlen($tw) >= 5 ? 1 : 0);
              if ($max_dist > 0) {
                $dist = levenshtein($tok, $tw);
                if ($dist > 0 && $dist <= $max_dist) {
                  $score = 0.72 - ($dist * 0.05);
                  if ($score > $best_score) {
                    $best_score = $score;
                    $match_type = 'fuzzy';
                  }
                }
              }
            }
          }
        }
      }

      if ($best_score > 0.40) {
        $candidates[] = [
          'page_key' => $page_key,
          'url' => $page['url'] ?? '',
          'label' => $page['label'] ?? ucfirst(str_replace('_', ' ', $page_key)),
          'score' => round($best_score, 4),
          'match_type' => $match_type,
        ];
      }
    }

    // Sort by score descending.
    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($candidates, 0, $limit);
  }

  /**
   * Full navigation detection + matching pipeline.
   *
   * @param string $message
   *   The user message.
   *
   * @return array|null
   *   Navigation result or NULL if not a navigation query.
   *   Result keys: is_navigation, matches, top_match, confidence.
   */
  public function detect(string $message): ?array {
    // First check: does it have navigation phrasing?
    $has_nav_phrasing = $this->isNavigationQuery($message);

    if (!$has_nav_phrasing) {
      // Without explicit navigation phrasing, we only match if the message
      // is very short (1-3 words) AND has a high-confidence match (slug/title
      // exact). This avoids triggering on "I need help with my eviction".
      $word_count = str_word_count($message);
      if ($word_count > 3) {
        return NULL;
      }
      $matches = $this->match($message);
      if (empty($matches) || $matches[0]['score'] < 0.85) {
        return NULL;
      }
    }
    else {
      $matches = $this->match($message);
    }

    // If we have nav phrasing but no matches, still return navigation
    // intent so the fallback can try retrieval.
    if (empty($matches)) {
      return [
        'is_navigation' => TRUE,
        'has_nav_phrasing' => TRUE,
        'matches' => [],
        'top_match' => NULL,
        'confidence' => 0.40,
        'residual' => $this->extractResidual($message),
      ];
    }

    $top = $matches[0];

    // Boost confidence if we also have navigation phrasing.
    $confidence = $top['score'];
    if ($has_nav_phrasing) {
      $confidence = min(1.0, $confidence + 0.05);
    }

    return [
      'is_navigation' => TRUE,
      'has_nav_phrasing' => $has_nav_phrasing,
      'matches' => $matches,
      'top_match' => $top,
      'confidence' => round($confidence, 4),
      'residual' => $this->extractResidual($message),
    ];
  }

  /**
   * Returns the default page index.
   *
   * Covers the canonical pages on the ILAS site.
   *
   * @return array
   */
  public static function getDefaultPageIndex(): array {
    return [
      'apply' => [
        'url' => '/apply-for-help',
        'label' => 'Apply for Help',
        'slug' => 'apply',
        'aliases' => ['application', 'apply for help', 'intake', 'get started', 'sign up', 'aplicar'],
        'keywords' => ['apply', 'application', 'intake', 'started', 'sign up', 'aplicar', 'solicitud'],
      ],
      'hotline' => [
        'url' => '/Legal-Advice-Line',
        'label' => 'Legal Advice Line',
        'slug' => 'hotline',
        'aliases' => ['hotline', 'advice line', 'legal advice line', 'phone line', 'helpline', 'linea de ayuda'],
        'keywords' => ['hotline', 'advice', 'phone', 'call', 'helpline', 'linea', 'llamar', 'telefono'],
      ],
      'offices' => [
        'url' => '/contact/offices',
        'label' => 'Office Locations',
        'slug' => 'offices',
        'aliases' => ['offices', 'office locations', 'locations', 'contact', 'contact us', 'oficinas', 'direccion'],
        'keywords' => ['office', 'offices', 'location', 'locations', 'address', 'contact', 'visit', 'located', 'oficina', 'direccion', 'horario'],
      ],
      'forms' => [
        'url' => '/forms',
        'label' => 'Forms',
        'slug' => 'forms',
        'aliases' => ['forms', 'legal forms', 'court forms', 'paperwork', 'documents', 'formularios', 'froms'],
        'keywords' => ['form', 'forms', 'paperwork', 'document', 'documents', 'download', 'pdf', 'court papers', 'formulario', 'formularios'],
      ],
      'guides' => [
        'url' => '/guides',
        'label' => 'Guides',
        'slug' => 'guides',
        'aliases' => ['guides', 'self-help', 'self help', 'how-to', 'how to guides', 'guias', 'giudes', 'giude'],
        'keywords' => ['guide', 'guides', 'how-to', 'instructions', 'self-help', 'step by step', 'manual', 'guia', 'guias'],
      ],
      'faq' => [
        'url' => '/faq',
        'label' => 'FAQ',
        'slug' => 'faq',
        'aliases' => ['faq', 'faqs', 'frequently asked questions', 'common questions', 'preguntas frecuentes'],
        'keywords' => ['faq', 'faqs', 'question', 'questions', 'frequently', 'answers', 'preguntas'],
      ],
      'donate' => [
        'url' => '/donate',
        'label' => 'Donate',
        'slug' => 'donate',
        'aliases' => ['donate', 'donations', 'contribute', 'donar', 'donacion', 'donatoin', 'dontae'],
        'keywords' => ['donate', 'donation', 'contribute', 'gift', 'donar', 'donacion'],
      ],
      'feedback' => [
        'url' => '/get-involved/feedback',
        'label' => 'Feedback',
        'slug' => 'feedback',
        'aliases' => ['feedback', 'feedback form', 'site feedback', 'service feedback', 'grievance', 'queja'],
        'keywords' => ['feedback', 'grievance', 'review', 'queja', 'feedback form'],
      ],
      'resources' => [
        'url' => '/what-we-do/resources',
        'label' => 'Resources',
        'slug' => 'resources',
        'aliases' => ['resources', 'legal resources', 'resource library', 'recursos'],
        'keywords' => ['resource', 'resources', 'library', 'download', 'printable', 'recursos'],
      ],
      'services' => [
        'url' => '/services',
        'label' => 'Our Services',
        'slug' => 'services',
        'aliases' => ['services', 'our services', 'what we do', 'what you do', 'practice areas', 'servicios'],
        'keywords' => ['services', 'service', 'help', 'practice', 'areas', 'servicios', 'que hacemos'],
      ],
      'senior_risk_detector' => [
        'url' => '/resources/legal-risk-detector',
        'label' => 'Legal Risk Detector',
        'slug' => 'risk detector',
        'aliases' => ['risk detector', 'legal risk detector', 'senior risk', 'legal checkup', 'risk assessment', 'evaluacion de riesgo'],
        'keywords' => ['risk', 'detector', 'assessment', 'quiz', 'checkup', 'senior', 'elderly', 'evaluacion', 'riesgo'],
      ],
      // Service area pages.
      'housing' => [
        'url' => '/legal-help/housing',
        'label' => 'Housing',
        'slug' => 'housing',
        'aliases' => ['housing', 'housing help', 'housing page', 'eviction help', 'landlord tenant', 'vivienda'],
        'keywords' => ['housing', 'eviction', 'landlord', 'tenant', 'rent', 'lease', 'vivienda', 'desalojo'],
      ],
      'family' => [
        'url' => '/legal-help/family',
        'label' => 'Family Law',
        'slug' => 'family',
        'aliases' => ['family', 'family law', 'family help', 'divorce page', 'custody page', 'familia'],
        'keywords' => ['family', 'divorce', 'custody', 'child support', 'adoption', 'familia', 'divorcio', 'custodia'],
      ],
      'seniors' => [
        'url' => '/legal-help/seniors',
        'label' => 'Seniors',
        'slug' => 'seniors',
        'aliases' => ['seniors', 'senior help', 'elder law', 'elderly', 'ancianos'],
        'keywords' => ['senior', 'seniors', 'elderly', 'elder', 'older', 'aging', 'ancianos'],
      ],
      'health' => [
        'url' => '/legal-help/health',
        'label' => 'Health & Benefits',
        'slug' => 'health',
        'aliases' => ['health', 'benefits', 'health benefits', 'medicaid', 'salud', 'beneficios'],
        'keywords' => ['health', 'benefits', 'medicaid', 'medicare', 'insurance', 'disability', 'snap', 'salud', 'beneficios'],
      ],
      'consumer' => [
        'url' => '/legal-help/consumer',
        'label' => 'Consumer',
        'slug' => 'consumer',
        'aliases' => ['consumer', 'consumer help', 'debt help', 'scam help', 'consumidor'],
        'keywords' => ['consumer', 'debt', 'bankruptcy', 'scam', 'fraud', 'credit', 'consumidor', 'deuda'],
      ],
      'civil_rights' => [
        'url' => '/legal-help/civil-rights',
        'label' => 'Civil Rights',
        'slug' => 'civil rights',
        'aliases' => ['civil rights', 'discrimination', 'employment rights', 'derechos civiles'],
        'keywords' => ['civil', 'rights', 'discrimination', 'employment', 'harassment', 'derechos', 'discriminacion'],
      ],
    ];
  }

  /**
   * Returns the page index.
   *
   * @return array
   */
  public function getPageIndex(): array {
    return $this->pageIndex;
  }

}
