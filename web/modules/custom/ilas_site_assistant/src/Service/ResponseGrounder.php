<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Service for grounding chatbot responses in retrieved content.
 *
 * Ensures responses:
 * - Cite sources (title + link) for retrieved content
 * - Don't invent addresses, phone numbers, or facts not in retrieved content
 * - Include appropriate caveats for legal information
 */
class ResponseGrounder {

  /**
   * Known official contact information (safe to include).
   */
  const OFFICIAL_CONTACTS = [
    'hotline' => [
      'number' => '(208) 746-7541',
      'toll_free' => '1-866-345-0106',
      'hours' => 'Monday–Wednesday, 10:00 a.m.–1:30 p.m. Mountain (9:00 a.m.–12:30 p.m. Pacific). Phone intakes are closed Thursday/Friday.',
    ],
    'offices' => [
      'boise' => [
        'address' => '310 N 5th Street, Boise, ID 83702',
        'phone' => '(208) 345-0106',
      ],
      'pocatello' => [
        'address' => '201 N 8th Ave, Suite 100, Pocatello, ID 83201',
        'phone' => '(208) 233-0079',
      ],
      'twin_falls' => [
        'address' => '496 Shoup Ave W, Twin Falls, ID 83301',
        'phone' => '(208) 734-7024',
      ],
      'lewiston' => [
        'address' => '1424 Main Street, Lewiston, ID 83501',
        'phone' => '(208) 746-7541',
      ],
      'idaho_falls' => [
        'address' => '482 Constitution Way, Suite 101, Idaho Falls, ID 83402',
        'phone' => '(208) 524-3660',
      ],
    ],
    'emergency' => [
      '911' => 'Emergency services',
      '988' => 'Suicide & Crisis Lifeline',
      '1-800-799-7233' => 'National Domestic Violence Hotline',
      '1-800-669-3176' => 'Idaho Domestic Violence Hotline',
    ],
  ];

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

    // Add citations from retrieved results.
    if ($options['add_citations'] && !empty($retrieved_results)) {
      $response = $this->addCitations($response, $retrieved_results);
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
      $url = $result['url'] ?? $result['source_url'] ?? NULL;

      if ($url) {
        $sources[] = [
          'title' => $this->truncateTitle($title, 60),
          'url' => $url,
          'type' => $result['type'] ?? 'resource',
        ];
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

    // Check office numbers.
    foreach (self::OFFICIAL_CONTACTS['offices'] as $office) {
      $office_normalized = preg_replace('/[^\d]/', '', $office['phone']);
      if ($phone === $office_normalized) {
        return TRUE;
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
    $address_lower = strtolower($address);

    foreach (self::OFFICIAL_CONTACTS['offices'] as $office) {
      $official_lower = strtolower($office['address']);
      // Check for significant overlap.
      if (strpos($official_lower, substr($address_lower, 0, 20)) !== FALSE) {
        return TRUE;
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
   *   Contact type (hotline, offices, emergency).
   *
   * @return array
   *   Official contact information.
   */
  public function getOfficialContacts(string $type = 'all'): array {
    if ($type === 'all') {
      return self::OFFICIAL_CONTACTS;
    }
    return self::OFFICIAL_CONTACTS[$type] ?? [];
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
    $response = [
      'type' => 'faq',
      'message' => $faq_result['answer'] ?? '',
      'title' => $faq_result['question'] ?? $faq_result['title'] ?? '',
      'url' => $faq_result['url'] ?? $faq_result['source_url'] ?? '',
    ];

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
