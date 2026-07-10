<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Service for grounding chatbot responses in retrieved content.
 *
 * Ensures responses:
 * - Cite sources (title + link) for retrieved content
 * - Don't invent addresses, phone numbers, or facts not in retrieved content
 * - Include appropriate caveats for legal information.
 */
class ResponseGrounder {

  /**
   * Shared source-governance policy surface for citation URL validation.
   *
   * @var \Drupal\ilas_site_assistant\Service\SourceGovernanceService|null
   */
  protected ?SourceGovernanceService $sourceGovernance;

  /**
   * Known official non-office contact information (safe to include).
   *
   * Office addresses and per-office phone numbers come from {@see
   * OfficeDirectory} (canonical first-party source backed by office_information
   * nodes) — they MUST NOT be hardcoded here. This block is restricted to
   * shared hotline/toll-free numbers and emergency services.
   */
  const OFFICIAL_CONTACTS = [
    'hotline' => [
      'number' => '(208) 746-7541',
      'toll_free' => '1-866-345-0106',
      'hours' => 'Monday–Wednesday, 10:00 a.m.–1:30 p.m. Mountain (9:00 a.m.–12:30 p.m. Pacific). Phone intakes are closed Thursday/Friday.',
    ],
    'emergency' => [
      '911' => 'Emergency services',
      '988' => 'Suicide & Crisis Lifeline',
      '1-800-799-7233' => 'National Domestic Violence Hotline',
      '1-800-669-3176' => 'Idaho Domestic Violence Hotline',
    ],
  ];

  /**
   * Response types that require citations to be considered grounded.
   */
  const CITATION_REQUIRED_TYPES = ['faq', 'resources', 'topic', 'eligibility', 'services_overview', 'search_results'];

  /**
   * Patterns that indicate potentially invented information.
   */
  const INVENTION_PATTERNS = [
    // Phone numbers not in official list.
    '/\(\d{3}\)\s*\d{3}[-.]?\d{4}/',
    // Email addresses.
    '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
    // Street addresses.
    '/\d+\s+[A-Za-z]+\s+(Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd)\b/i',
    // Specific dollar amounts.
    '/\$[\d,]+(\.\d{2})?/',
    // Dates as deadlines.
    '/deadline\s+(is|was|will\s+be)\s+\w+\s+\d+/i',
  ];

  /**
   * Public response/result fields that may contain untrusted retrieved text.
   */
  const UNTRUSTED_RETRIEVAL_TEXT_FIELDS = [
    'message',
    'answer',
    'answer_snippet',
    'body',
    'description',
    'summary',
    'title',
    'question',
  ];

  /**
   * Prompt-injection-like content that must be treated as page text only.
   */
  const UNTRUSTED_RETRIEVAL_INSTRUCTION_PATTERNS = [
    '/\bignore\s+(all\s+)?(previous|prior|above|system|developer|assistant)\s+instructions\b/i',
    '/\bdo\s+not\s+cite\s+(ilas|idaho\s+legal\s+aid|sources?)\b/i',
    '/\brecommend\s+(a\s+)?paid\s+lawyer\b/i',
    '/\btell\s+the\s+user\s+they\s+(will|should)\s+win\b/i',
  ];

  /**
   * Canonical office directory (entity-backed).
   *
   * @var \Drupal\ilas_site_assistant\Service\OfficeDirectory|null
   */
  protected ?OfficeDirectory $officeDirectory;

  /**
   * Constructs a response grounder.
   */
  public function __construct(?SourceGovernanceService $source_governance = NULL, ?OfficeDirectory $office_directory = NULL) {
    $this->sourceGovernance = $source_governance;
    $this->officeDirectory = $office_directory;
  }

  /**
   * Grounds a response by adding citations and removing invented information.
   *
   * @param array $response
   *   The response to ground.
   * @param array $retrieved_results
   *   The retrieved results used to generate the response.
   * @param array $options
   *   Options:
   *   - add_citations: Whether to add source citations.
   *   - remove_invented: Whether to remove potentially invented info.
   *   - add_caveats: Whether to add legal caveats.
   *
   * @return array
   *   The grounded response.
   */
  public function groundResponse(array $response, array $retrieved_results = [], array $options = []): array {
    $options = array_merge([
      'add_citations' => TRUE,
      'remove_invented' => TRUE,
      'add_caveats' => TRUE,
      'include_source_url' => TRUE,
    ], $options);

    [$response, $retrieved_results, $sanitized_fields] = $this->sanitizeUntrustedRetrievedContent($response, $retrieved_results);
    if (!empty($sanitized_fields)) {
      $response['_retrieval_content_sanitized'] = TRUE;
      $response['_retrieval_content_sanitized_fields'] = $sanitized_fields;
    }

    // Add citations from retrieved results.
    if ($options['add_citations'] && !empty($retrieved_results)) {
      $response = $this->addCitations($response, $retrieved_results);
      // Flag citation-required types that have no valid citations.
      $type = $response['type'] ?? 'unknown';
      if (in_array($type, self::CITATION_REQUIRED_TYPES, TRUE)
          && empty($response['sources'])) {
        $response['_grounding_weak'] = TRUE;
        $response['_grounding_weak_reason'] = 'citation_required_type_without_citations';
      }

      // AFRP-20: Enforce freshness policy for citation-required types with sources.
      if (in_array($type, self::CITATION_REQUIRED_TYPES, TRUE)
          && !empty($response['sources'])) {
        $response = $this->enforceFreshnessPolicy($response);
      }
    }

    // Check for and remove potentially invented information.
    if ($options['remove_invented'] && !empty($response['message'])) {
      $response = $this->validateInformation($response);
    }

    // Add legal caveats for certain response types.
    if ($options['add_caveats']) {
      $response = $this->addCaveats($response);
    }

    // Mark response as grounded.
    $response['_grounded'] = TRUE;
    $response['_grounding_version'] = '1.0';

    return $response;
  }

  /**
   * Adds source citations to response.
   *
   * @param array $response
   *   The response.
   * @param array $results
   *   Retrieved results.
   *
   * @return array
   *   Response with citations.
   */
  protected function addCitations(array $response, array $results): array {
    if (empty($results)) {
      return $response;
    }

    $sources = [];

    foreach ($results as $result) {
      $title = $result['title'] ?? $result['question'] ?? 'Untitled';
      if (!is_string($title) || trim($title) === '') {
        $title = 'Untitled';
      }
      $raw_url = $result['url'] ?? $result['source_url'] ?? NULL;
      $url = $this->sourceGovernance?->sanitizeCitationUrl(is_string($raw_url) ? $raw_url : NULL);
      $freshness = $result['freshness']['status'] ?? 'unknown';

      if ($url) {
        $source = [
          'title' => $this->truncateTitle($title, 60),
          'url' => $url,
          'type' => $result['type'] ?? 'resource',
          'freshness' => $freshness,
          'supported' => TRUE,
        ];
        // Carry through any source_class / topic metadata the retrieval layer
        // attached. These are non-PII signals the public response contract
        // exposes for grounding-proof and topic-specificity assertions.
        if (!empty($result['source_class']) && is_string($result['source_class'])) {
          $source['source_class'] = $result['source_class'];
        }
        if (!empty($result['source']) && is_string($result['source'])) {
          $source['source'] = $result['source'];
        }
        foreach (['topic', 'topic_id', 'category'] as $topic_key) {
          if (!empty($result[$topic_key])) {
            $source[$topic_key] = is_array($result[$topic_key])
              ? reset($result[$topic_key])
              : $result[$topic_key];
          }
        }
        // Carry the full topic-term list (field_topics names) so citations
        // are provably about the user's subject. Joined into the singular
        // 'topic' field because downstream consumers (widget, eval
        // normalizer) treat topic as one bounded string.
        if (empty($source['topic']) && !empty($result['topics']) && is_array($result['topics'])) {
          $topics = array_values(array_filter(array_map(
            static fn($t) => is_string($t) ? trim($t) : '',
            $result['topics'],
          )));
          if ($topics !== []) {
            $source['topic'] = mb_substr(implode(', ', $topics), 0, 120);
          }
        }
        $sources[] = $source;
      }
    }

    // Aggregate freshness metadata for downstream enforcement.
    $stale_count = count(array_filter($sources, fn($s) => ($s['freshness'] ?? '') === 'stale'));
    if ($stale_count > 0) {
      $response['_stale_citation_count'] = $stale_count;
      if ($stale_count === count($sources)) {
        $response['_all_citations_stale'] = TRUE;
      }
    }

    if (!empty($sources)) {
      // Add sources array.
      $response['sources'] = array_slice($sources, 0, 3);

      // Add citation notice to message.
      if (!isset($response['_citation_added'])) {
        $source_titles = array_map(function ($s) {
          return $s['title'];
        }, array_slice($sources, 0, 2));

        $citation_text = count($sources) === 1
          ? sprintf('(Source: %s)', $source_titles[0])
          : sprintf('(Sources: %s)', implode('; ', $source_titles));

        // Store citation for potential use (UI can decide whether to display).
        $response['citation_text'] = $citation_text;
        $response['_citation_added'] = TRUE;
      }
    }

    return $response;
  }

  /**
   * Removes prompt-injection-like instructions from untrusted retrieved text.
   *
   * Retrieved page content may describe malicious instructions. It must never
   * become an instruction to the assistant, a citation title, or public result
   * snippet. This deliberately uses narrow patterns so normal legal content is
   * not broadly rewritten.
   *
   * @param array $response
   *   Response being grounded.
   * @param array $retrieved_results
   *   Retrieved results used for grounding.
   *
   * @return array
   *   Tuple of sanitized response, sanitized results, and changed field labels.
   */
  protected function sanitizeUntrustedRetrievedContent(array $response, array $retrieved_results): array {
    $sanitized_fields = [];

    foreach (self::UNTRUSTED_RETRIEVAL_TEXT_FIELDS as $field) {
      if (!isset($response[$field]) || !is_string($response[$field])) {
        continue;
      }

      $original = $response[$field];
      $response[$field] = $this->stripUntrustedInstructionText($original);
      if ($response[$field] !== $original) {
        $sanitized_fields[] = 'response.' . $field;
        if ($field === 'message' && trim($response[$field]) === '') {
          $response[$field] = 'I found ILAS information for this topic, but I cannot summarize untrusted instructions from retrieved page content. Please review the cited ILAS source or contact ILAS for help.';
        }
      }
    }

    if (!empty($response['results']) && is_array($response['results'])) {
      foreach ($response['results'] as $index => $result) {
        if (!is_array($result)) {
          continue;
        }
        [$response['results'][$index], $changed] = $this->sanitizeUntrustedResultItem($result, 'response.results.' . $index);
        $sanitized_fields = array_merge($sanitized_fields, $changed);
      }
    }

    foreach ($retrieved_results as $index => $result) {
      if (!is_array($result)) {
        continue;
      }
      [$retrieved_results[$index], $changed] = $this->sanitizeUntrustedResultItem($result, 'retrieved_results.' . $index);
      $sanitized_fields = array_merge($sanitized_fields, $changed);
    }

    return [
      $response,
      $retrieved_results,
      array_values(array_unique($sanitized_fields)),
    ];
  }

  /**
   * Sanitizes one retrieval result item.
   *
   * @param array $result
   *   Result item.
   * @param string $prefix
   *   Field prefix for diagnostics.
   *
   * @return array
   *   Tuple of sanitized result and changed field labels.
   */
  protected function sanitizeUntrustedResultItem(array $result, string $prefix): array {
    $changed = [];

    foreach (self::UNTRUSTED_RETRIEVAL_TEXT_FIELDS as $field) {
      if (!isset($result[$field]) || !is_string($result[$field])) {
        continue;
      }

      $original = $result[$field];
      $result[$field] = $this->stripUntrustedInstructionText($original);
      if ($result[$field] !== $original) {
        $changed[] = $prefix . '.' . $field;
        if (in_array($field, ['title', 'question'], TRUE) && trim($result[$field]) === '') {
          $result[$field] = 'ILAS source';
        }
      }
    }

    return [$result, $changed];
  }

  /**
   * Strips sentences or lines that look like injected instructions.
   */
  protected function stripUntrustedInstructionText(string $text): string {
    if (!$this->containsUntrustedInstruction($text)) {
      return $text;
    }

    $chunks = preg_split('/(?<=[.!?])\s+|\R+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chunks = $chunks !== FALSE ? $chunks : [$text];
    $kept = [];

    foreach ($chunks as $chunk) {
      if ($this->containsUntrustedInstruction($chunk)) {
        continue;
      }
      $kept[] = $chunk;
    }

    return trim((string) preg_replace('/\s+/', ' ', implode(' ', $kept)));
  }

  /**
   * Returns TRUE when text contains a retrieval prompt-injection pattern.
   */
  protected function containsUntrustedInstruction(string $text): bool {
    foreach (self::UNTRUSTED_RETRIEVAL_INSTRUCTION_PATTERNS as $pattern) {
      if (preg_match($pattern, $text)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Enforces freshness policy for citation-required response types.
   *
   * Computes a freshness profile from the response's citations and sets
   * enforcement flags that downstream pipeline stages (controller) consume
   * to cap confidence and add caveats. Unknown freshness is treated as
   * non-fresh (precautionary principle).
   *
   * Content is never suppressed — only confidence and caveats change.
   *
   * @param array $response
   *   Response with 'sources' already populated by addCitations().
   *
   * @return array
   *   Response with freshness_profile and optional enforcement flags.
   */
  protected function enforceFreshnessPolicy(array $response): array {
    $sources = $response['sources'] ?? [];
    if (empty($sources)) {
      return $response;
    }

    // Check if freshness enforcement is enabled via config.
    if ($this->sourceGovernance && !$this->isFreshnessEnforcementEnabled()) {
      return $response;
    }

    $fresh = 0;
    $stale = 0;
    $unknown = 0;

    foreach ($sources as $source) {
      match ($source['freshness'] ?? 'unknown') {
        'fresh' => $fresh++,
        'stale' => $stale++,
        default => $unknown++,
      };
    }

    $total = count($sources);
    $response['freshness_profile'] = [
      'fresh' => $fresh,
      'stale' => $stale,
      'unknown' => $unknown,
      'total' => $total,
    ];

    // Unknown freshness is treated as non-fresh (precautionary principle).
    $non_fresh = $stale + $unknown;

    if ($non_fresh === 0) {
      // All citations fresh — no enforcement needed.
      return $response;
    }

    if ($non_fresh === $total) {
      // All citations are stale or unknown: hard degrade.
      $response['_freshness_enforcement'] = 'all_non_fresh';
      $response['_freshness_confidence_cap'] = 0.5;
    }
    else {
      // Some citations non-fresh: proportional degrade.
      $response['_freshness_enforcement'] = 'partial_non_fresh';
      $ratio = $non_fresh / $total;
      $response['_freshness_confidence_cap'] = max(0.5, 1.0 - ($ratio * 0.5));
    }

    return $response;
  }

  /**
   * Checks whether freshness enforcement is enabled in config.
   *
   * @return bool
   *   TRUE if enforcement is enabled or config is unavailable.
   */
  protected function isFreshnessEnforcementEnabled(): bool {
    if (!$this->sourceGovernance) {
      return TRUE;
    }
    return $this->sourceGovernance->isFreshnessEnforcementEnabled();
  }

  /**
   * Validates and sanitizes potentially invented information.
   *
   * @param array $response
   *   The response.
   *
   * @return array
   *   Validated response.
   */
  protected function validateInformation(array $response): array {
    $message = $response['message'] ?? '';

    // Check for phone numbers not in our official list.
    if (preg_match_all('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $message, $matches)) {
      foreach ($matches[0] as $phone) {
        $normalized = preg_replace('/[^\d]/', '', $phone);
        if (!$this->isOfficialPhone($normalized)) {
          // Flag as potentially invented.
          if (!isset($response['_validation_warnings'])) {
            $response['_validation_warnings'] = [];
          }
          $response['_validation_warnings'][] = "Phone number '$phone' not in official contact list";

          // Replace with safer text directing to offices page.
          $message = str_replace($phone, '[contact information available on our website]', $message);
        }
      }
    }

    // Check for addresses not in official list.
    if (preg_match('/\d+\s+[A-Za-z]+\s+(Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd)[^,]*(,\s*[A-Za-z\s]+,?\s*(ID|Idaho))?\s*\d{5}/i', $message, $address_match)) {
      if (!$this->isOfficialAddress($address_match[0])) {
        if (!isset($response['_validation_warnings'])) {
          $response['_validation_warnings'] = [];
        }
        $response['_validation_warnings'][] = "Address may not be in official list";

        // Add caveat.
        $response['address_caveat'] = 'For current office addresses, please visit our offices page.';
      }
    }

    // Check for specific legal advice patterns.
    $advice_patterns = [
      '/you\s+(should|must|need\s+to|have\s+to)\s+(file|sue|go\s+to\s+court)/i',
      '/your\s+case\s+(will|should)\s+(win|succeed)/i',
      '/the\s+judge\s+will\s+(likely|probably)/i',
      '/you\s+(can|cannot)\s+be\s+deported/i',
    ];

    foreach ($advice_patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        if (!isset($response['_validation_warnings'])) {
          $response['_validation_warnings'] = [];
        }
        $response['_validation_warnings'][] = 'Response may contain specific legal advice';
        $response['_requires_review'] = TRUE;
      }
    }

    $response['message'] = $message;
    return $response;
  }

  /**
   * Adds appropriate legal caveats to response.
   *
   * @param array $response
   *   The response.
   *
   * @return array
   *   Response with caveats.
   */
  protected function addCaveats(array $response): array {
    $type = $response['type'] ?? 'unknown';

    // Types that need caveats.
    $caveat_types = ['faq', 'resources', 'topic', 'eligibility', 'services_overview'];

    if (in_array($type, $caveat_types)) {
      // Add general legal caveat if not already present.
      if (empty($response['caveat'])) {
        $response['caveat'] = 'This information is for general guidance only and does not constitute legal advice. For advice about your specific situation, please apply for help or call our Legal Advice Line.';
      }
    }

    // Add accuracy caveat for eligibility-related responses.
    if ($type === 'eligibility' || strpos(strtolower($response['message'] ?? ''), 'eligib') !== FALSE) {
      if (empty($response['eligibility_caveat'])) {
        $response['eligibility_caveat'] = 'Eligibility depends on your specific circumstances. Applying is the best way to find out if we can help.';
      }
    }

    return $response;
  }

  /**
   * Checks if a phone number is in our official contact list.
   *
   * @param string $phone
   *   Normalized phone number (digits only).
   *
   * @return bool
   *   TRUE if official.
   */
  protected function isOfficialPhone(string $phone): bool {
    // Normalize to 10 digits.
    if (strlen($phone) === 11 && $phone[0] === '1') {
      $phone = substr($phone, 1);
    }

    // Check hotline numbers.
    $hotline_normalized = preg_replace('/[^\d]/', '', self::OFFICIAL_CONTACTS['hotline']['number']);
    if ($phone === $hotline_normalized) {
      return TRUE;
    }

    $toll_free_normalized = preg_replace('/[^\d]/', '', self::OFFICIAL_CONTACTS['hotline']['toll_free']);
    // Also strip leading country code from toll-free for comparison.
    if (strlen($toll_free_normalized) === 11 && $toll_free_normalized[0] === '1') {
      $toll_free_normalized = substr($toll_free_normalized, 1);
    }
    if ($phone === $toll_free_normalized) {
      return TRUE;
    }

    // Check office numbers via the canonical OfficeDirectory.
    if ($this->officeDirectory !== NULL) {
      foreach ($this->officeDirectory->all() as $office) {
        foreach (['phone', 'phone_secondary'] as $key) {
          if (empty($office[$key])) {
            continue;
          }
          $office_normalized = preg_replace('/[^\d]/', '', (string) $office[$key]) ?? '';
          if ($office_normalized !== '' && $phone === $office_normalized) {
            return TRUE;
          }
        }
      }
    }

    // Check emergency numbers.
    foreach (self::OFFICIAL_CONTACTS['emergency'] as $number => $desc) {
      $emergency_normalized = preg_replace('/[^\d]/', '', $number);
      if ($phone === $emergency_normalized) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if an address is in our official contact list.
   *
   * @param string $address
   *   The address to check.
   *
   * @return bool
   *   TRUE if likely official.
   */
  protected function isOfficialAddress(string $address): bool {
    if ($this->officeDirectory === NULL) {
      return FALSE;
    }
    $address_lower = strtolower($address);

    foreach ($this->officeDirectory->all() as $office) {
      $candidates = array_filter([
        $office['address'] ?? '',
        $office['street'] ?? '',
      ]);
      foreach ($candidates as $candidate) {
        $official_lower = strtolower((string) $candidate);
        if ($official_lower === '') {
          continue;
        }
        if (str_contains($official_lower, substr($address_lower, 0, 20))) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Truncates a title to a maximum length.
   *
   * @param string $title
   *   The title.
   * @param int $max_length
   *   Maximum length.
   *
   * @return string
   *   Truncated title.
   */
  protected function truncateTitle(string $title, int $max_length): string {
    if (mb_strlen($title) <= $max_length) {
      return $title;
    }
    return mb_substr($title, 0, $max_length - 3) . '...';
  }

  /**
   * Gets official contact information.
   *
   * @param string $type
   *   Contact type (hotline, offices, emergency, all).
   *
   * @return array
   *   Official contact information. Office records come from the canonical
   *   OfficeDirectory service when available; the hotline and emergency blocks
   *   are returned from the static OFFICIAL_CONTACTS list.
   */
  public function getOfficialContacts(string $type = 'all'): array {
    $offices = $this->officeDirectory !== NULL
      ? $this->buildOfficeContactsBlock()
      : [];

    if ($type === 'all') {
      $merged = self::OFFICIAL_CONTACTS;
      if ($offices !== []) {
        $merged['offices'] = $offices;
      }
      return $merged;
    }
    if ($type === 'offices') {
      return $offices;
    }
    return self::OFFICIAL_CONTACTS[$type] ?? [];
  }

  /**
   * Builds the legacy 'offices' contact block from the OfficeDirectory.
   *
   * @return array<string, array{address: string, phone: string}>
   */
  private function buildOfficeContactsBlock(): array {
    $offices = [];
    foreach ($this->officeDirectory->all() as $slug => $office) {
      $offices[$slug] = [
        'address' => (string) ($office['address'] ?? ''),
        'phone' => (string) ($office['phone'] ?? ''),
      ];
    }
    return $offices;
  }

  /**
   * Generates a grounded FAQ response.
   *
   * @param array $faq_result
   *   The FAQ result.
   *
   * @return array
   *   Grounded response.
   */
  public function groundFaqResponse(array $faq_result): array {
    $source_url = $faq_result['url'] ?? $faq_result['source_url'] ?? NULL;
    $sanitized_url = $this->sourceGovernance?->sanitizeCitationUrl(is_string($source_url) ? $source_url : NULL);

    $response = [
      'type' => 'faq',
      'message' => $faq_result['answer'] ?? '',
      'title' => $faq_result['question'] ?? $faq_result['title'] ?? '',
    ];
    if ($sanitized_url !== NULL) {
      $response['url'] = $sanitized_url;
    }

    return $this->groundResponse($response, [$faq_result]);
  }

  /**
   * Generates a grounded resource response.
   *
   * @param array $resources
   *   Array of resource results.
   * @param string $intro_message
   *   Introduction message.
   *
   * @return array
   *   Grounded response.
   */
  public function groundResourceResponse(array $resources, string $intro_message = 'Here are some resources that might help:'): array {
    $response = [
      'type' => 'resources',
      'message' => $intro_message,
      'results' => $resources,
    ];

    return $this->groundResponse($response, $resources);
  }

  /**
   * Validates that a response only contains information from retrieved content.
   *
   * @param string $response_text
   *   The response text.
   * @param array $retrieved_content
   *   The retrieved content used.
   *
   * @return array
   *   Validation result with 'valid' and 'issues' keys.
   */
  public function validateGrounding(string $response_text, array $retrieved_content): array {
    $issues = [];

    // Extract all text from retrieved content.
    $content_corpus = '';
    foreach ($retrieved_content as $item) {
      $content_corpus .= ' ' . ($item['question'] ?? '');
      $content_corpus .= ' ' . ($item['answer'] ?? '');
      $content_corpus .= ' ' . ($item['title'] ?? '');
      $content_corpus .= ' ' . ($item['description'] ?? '');
    }
    $content_corpus = strtolower($content_corpus);

    // Check for phone numbers in response not in content.
    if (preg_match_all('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $response_text, $phones)) {
      foreach ($phones[0] as $phone) {
        if (strpos($content_corpus, $phone) === FALSE && !$this->isOfficialPhone(preg_replace('/[^\d]/', '', $phone))) {
          $issues[] = "Phone number '$phone' not found in retrieved content";
        }
      }
    }

    // Check for specific dollar amounts.
    if (preg_match_all('/\$[\d,]+(\.\d{2})?/', $response_text, $amounts)) {
      foreach ($amounts[0] as $amount) {
        if (strpos($content_corpus, $amount) === FALSE) {
          $issues[] = "Dollar amount '$amount' not found in retrieved content";
        }
      }
    }

    // Check for specific dates.
    if (preg_match_all('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(,?\s+\d{4})?\b/i', $response_text, $dates)) {
      foreach ($dates[0] as $date) {
        if (strpos($content_corpus, strtolower($date)) === FALSE) {
          $issues[] = "Date '$date' not found in retrieved content";
        }
      }
    }

    return [
      'valid' => empty($issues),
      'issues' => $issues,
    ];
  }

}
