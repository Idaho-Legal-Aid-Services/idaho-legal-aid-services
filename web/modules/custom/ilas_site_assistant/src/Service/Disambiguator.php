<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Deterministic disambiguation service for confusable intent pairs.
 *
 * This service handles cases where:
 * 1. User query is vague (single word or phrase that maps to multiple intents)
 * 2. Top-2 scored intents have confidence delta below threshold
 * 3. Known confusable pairs are both triggered
 *
 * All logic is deterministic - no LLM calls. Returns clarifying questions
 * with quick-reply options mapped to canonical actions.
 */
class Disambiguator {

  /**
   * Confidence delta threshold for triggering disambiguation.
   *
   * If the gap between top-2 intents is below this, we ask to clarify.
   */
  const DELTA_THRESHOLD = 0.12;

  /**
   * Minimum confidence to consider an intent as a candidate.
   */
  const MIN_CANDIDATE_CONFIDENCE = 0.40;

  /**
   * Known confusable intent pairs with their clarification templates.
   *
   * Keys are sorted intent pairs (alphabetical order).
   * This ensures consistent lookup regardless of which intent is "first".
   *
   * @var array
   */
  protected $confusablePairs;

  /**
   * Vague queries that always need clarification.
   *
   * Keyed by normalized query text.
   *
   * @var array
   */
  protected $vagueQueries;

  /**
   * Single-word topic queries that need action clarification.
   *
   * @var array
   */
  protected $topicOnlyTriggers;

  /**
   * Urgency/politeness modifier words to strip before topic lookup.
   *
   * @var string[]
   */
  protected static $topicModifiers = [
    'now', 'right', 'asap', 'please', 'urgent', 'quickly',
    'immediately', 'today', 'soon', 'fast',
    // Spanish.
    'ahora', 'urgente', 'rapido', 'hoy', 'pronto', 'por', 'favor',
  ];

  /**
   * Constructs a Disambiguator.
   */
  public function __construct() {
    $this->initializeConfusablePairs();
    $this->initializeVagueQueries();
    $this->initializeTopicTriggers();
  }

  /**
   * Initializes known confusable intent pairs.
   */
  protected function initializeConfusablePairs(): void {
    $this->confusablePairs = [
      // Apply vs Services - user wants help but unclear if applying or learning.
      'apply_for_help:services_overview' => [
        'question' => 'Are you looking for information about our services or ready to apply?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Learn about services', 'intent' => 'services_overview'],
        ],
      ],

      // Apply vs Eligibility - check qualify vs start application.
      'apply_for_help:eligibility' => [
        'question' => 'Would you like to check if you qualify or start an application?',
        'options' => [
          ['label' => 'Check eligibility', 'intent' => 'eligibility'],
          ['label' => 'Start application', 'intent' => 'apply_for_help'],
        ],
      ],

      // Forms vs Guides - different resource types for same topic.
      'forms_finder:guides_finder' => [
        'question' => 'What type of resource do you need?',
        'options' => [
          ['label' => 'Court forms to fill out', 'intent' => 'forms_finder'],
          ['label' => 'Step-by-step guide', 'intent' => 'guides_finder'],
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ],
      ],

      // Hotline vs Offices - contact method confusion.
      'legal_advice_line:offices_contact' => [
        'question' => 'How would you like to reach us?',
        'options' => [
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find office location', 'intent' => 'offices_contact'],
        ],
      ],

      // Donations vs Feedback - both are "support" actions.
      'donations:feedback' => [
        'question' => 'What would you like to do?',
        'options' => [
          ['label' => 'Make a donation', 'intent' => 'donations'],
          ['label' => 'Volunteer or give feedback', 'intent' => 'feedback'],
        ],
      ],

      // FAQ vs Guides - both provide information.
      'faq:guides_finder' => [
        'question' => 'What would be most helpful?',
        'options' => [
          ['label' => 'Quick answer to a question', 'intent' => 'faq'],
          ['label' => 'Detailed how-to guide', 'intent' => 'guides_finder'],
        ],
      ],

      // FAQ vs Services - general info vs specific services.
      'faq:services_overview' => [
        'question' => 'What are you looking for?',
        'options' => [
          ['label' => 'Answers to common questions', 'intent' => 'faq'],
          ['label' => 'Overview of our services', 'intent' => 'services_overview'],
        ],
      ],

      // Apply vs Hotline - how to get help.
      'apply_for_help:legal_advice_line' => [
        'question' => 'How would you like to get help?',
        'options' => [
          ['label' => 'Apply online', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
        ],
      ],

      // Forms vs Apply - sometimes users want forms, sometimes full representation.
      'apply_for_help:forms_finder' => [
        'question' => 'What kind of help do you need?',
        'options' => [
          ['label' => 'Find court forms', 'intent' => 'forms_finder'],
          ['label' => 'Apply for a lawyer', 'intent' => 'apply_for_help'],
        ],
      ],

      // Guides vs Apply - self-help vs representation.
      'apply_for_help:guides_finder' => [
        'question' => 'Would you prefer self-help resources or legal representation?',
        'options' => [
          ['label' => 'Self-help guide', 'intent' => 'guides_finder'],
          ['label' => 'Apply for a lawyer', 'intent' => 'apply_for_help'],
        ],
      ],
    ];
  }

  /**
   * Initializes vague queries that always need clarification.
   */
  protected function initializeVagueQueries(): void {
    $this->vagueQueries = [
      // English vague queries.
      'help' => [
        'question' => 'How can I help you today?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Find office location', 'intent' => 'offices_contact'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
      'can you help' => [
        'question' => 'How can I help you today?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
      'can you help me' => [
        'question' => 'How can I help you today?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
      'i need help' => [
        'question' => 'What kind of help do you need?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
      'i need some help' => [
        'question' => 'What kind of help do you need?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
      'where can i get help' => [
        'question' => 'What kind of help are you looking for?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Find office location', 'intent' => 'offices_contact'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
        ],
      ],
      'forms' => [
        'question' => 'What type of forms do you need?',
        'options' => [
          ['label' => 'Family/Divorce forms', 'intent' => 'forms_finder', 'topic' => 'family'],
          ['label' => 'Housing/Eviction forms', 'intent' => 'forms_finder', 'topic' => 'housing'],
          ['label' => 'Other forms', 'intent' => 'forms_finder'],
        ],
      ],
      'phone' => [
        'question' => 'What phone number do you need?',
        'options' => [
          ['label' => 'Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Office phone number', 'intent' => 'offices_contact'],
        ],
      ],
      'information' => [
        'question' => 'What information are you looking for?',
        'options' => [
          ['label' => 'About our services', 'intent' => 'services_overview'],
          ['label' => 'Frequently asked questions', 'intent' => 'faq'],
          ['label' => 'Self-help guides', 'intent' => 'guides_finder'],
        ],
      ],
      'i want to apply' => [
        'question' => 'What would you like to apply for?',
        'options' => [
          ['label' => 'Legal help from ILAS', 'intent' => 'apply_for_help'],
          ['label' => 'Learn about eligibility first', 'intent' => 'eligibility'],
        ],
      ],
      'contact' => [
        'question' => 'How would you like to contact us?',
        'options' => [
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find office location', 'intent' => 'offices_contact'],
          ['label' => 'Apply online', 'intent' => 'apply_for_help'],
        ],
      ],
      'contact information' => [
        'question' => 'What contact information do you need?',
        'options' => [
          ['label' => 'Office locations & hours', 'intent' => 'offices_contact'],
          ['label' => 'Legal Advice Line', 'intent' => 'legal_advice_line'],
        ],
      ],
      'what do you do' => [
        'question' => 'What would you like to know?',
        'options' => [
          ['label' => 'What services we offer', 'intent' => 'services_overview'],
          ['label' => 'How to apply for help', 'intent' => 'apply_for_help'],
          ['label' => 'Frequently asked questions', 'intent' => 'faq'],
        ],
      ],
      'what can you do' => [
        'question' => 'What would you like to know?',
        'options' => [
          ['label' => 'What services we offer', 'intent' => 'services_overview'],
          ['label' => 'How to apply for help', 'intent' => 'apply_for_help'],
          ['label' => 'Frequently asked questions', 'intent' => 'faq'],
        ],
      ],
      'what do you offer' => [
        'question' => 'What would you like to know?',
        'options' => [
          ['label' => 'What services we offer', 'intent' => 'services_overview'],
          ['label' => 'How to apply for help', 'intent' => 'apply_for_help'],
        ],
      ],

      // Spanish vague queries.
      'ayuda' => [
        'question' => 'Como puedo ayudarle hoy?',
        'options' => [
          ['label' => 'Aplicar para ayuda legal', 'intent' => 'apply_for_help'],
          ['label' => 'Encontrar oficina', 'intent' => 'offices_contact'],
          ['label' => 'Encontrar formularios', 'intent' => 'forms_finder'],
          ['label' => 'Leer una guia', 'intent' => 'guides_finder'],
        ],
      ],
      'formularios' => [
        'question' => 'Que tipo de formularios necesita?',
        'options' => [
          ['label' => 'Formularios de familia/divorcio', 'intent' => 'forms_finder', 'topic' => 'family'],
          ['label' => 'Formularios de vivienda/desalojo', 'intent' => 'forms_finder', 'topic' => 'housing'],
          ['label' => 'Otros formularios', 'intent' => 'forms_finder'],
        ],
      ],
    ];
  }

  /**
   * Initializes topic-only triggers (single topic words without action).
   */
  protected function initializeTopicTriggers(): void {
    $this->topicOnlyTriggers = [
      // Family law topics.
      'divorce' => ['area' => 'family', 'label' => 'divorce'],
      'custody' => ['area' => 'family', 'label' => 'custody'],
      'child support' => ['area' => 'family', 'label' => 'child support'],
      'visitation' => ['area' => 'family', 'label' => 'visitation'],
      'adoption' => ['area' => 'family', 'label' => 'adoption'],
      'paternity' => ['area' => 'family', 'label' => 'paternity'],
      'divorcio' => ['area' => 'family', 'label' => 'divorcio'],
      'custodia' => ['area' => 'family', 'label' => 'custodia'],

      // Housing topics.
      'eviction' => ['area' => 'housing', 'label' => 'eviction'],
      'landlord' => ['area' => 'housing', 'label' => 'landlord issues'],
      'tenant' => ['area' => 'housing', 'label' => 'tenant rights'],
      'rent' => ['area' => 'housing', 'label' => 'rent issues'],
      'foreclosure' => ['area' => 'housing', 'label' => 'foreclosure'],
      'desalojo' => ['area' => 'housing', 'label' => 'desalojo'],

      // Consumer topics.
      'debt' => ['area' => 'consumer', 'label' => 'debt'],
      'bankruptcy' => ['area' => 'consumer', 'label' => 'bankruptcy'],
      'scam' => ['area' => 'consumer', 'label' => 'scam'],
      'fraud' => ['area' => 'consumer', 'label' => 'fraud'],
      'collection' => ['area' => 'consumer', 'label' => 'debt collection'],

      // Benefits topics.
      'medicaid' => ['area' => 'benefits', 'label' => 'Medicaid'],
      'medicare' => ['area' => 'benefits', 'label' => 'Medicare'],
      'food stamps' => ['area' => 'benefits', 'label' => 'food stamps'],
      'snap' => ['area' => 'benefits', 'label' => 'SNAP benefits'],
      'ssi' => ['area' => 'benefits', 'label' => 'SSI'],
      'ssdi' => ['area' => 'benefits', 'label' => 'SSDI'],

      // Senior topics.
      'guardianship' => ['area' => 'seniors', 'label' => 'guardianship'],
      'elder abuse' => ['area' => 'seniors', 'label' => 'elder abuse'],
    ];
  }

  /**
   * Checks if disambiguation is needed and returns clarification if so.
   *
   * This is the main entry point. It checks:
   * 1. Vague query matches (exact normalized string)
   * 2. Topic-only queries (single topic word without action)
   * 3. Confidence delta between top-2 intents
   * 4. Known confusable pairs
   *
   * @param string $message
   *   The user's message.
   * @param array $scored_intents
   *   Array of intents with 'intent' and 'confidence' keys, sorted by
   *   confidence descending.
   * @param array $context
   *   Optional context array with extraction data.
   *
   * @return array|null
   *   Disambiguation result with 'type' => 'disambiguation', or NULL if
   *   no disambiguation needed.
   */
  public function check(string $message, array $scored_intents, array $context = []): ?array {
    // Step 1: Check for vague queries (highest priority for clarification).
    $vague_result = $this->checkVagueQuery($message);
    if ($vague_result) {
      return $vague_result;
    }

    // Step 2: Check for topic-only queries.
    $topic_result = $this->checkTopicOnly($message);
    if ($topic_result) {
      return $topic_result;
    }

    // Step 3: Check confidence delta between top-2 intents.
    if (count($scored_intents) >= 2) {
      $delta_result = $this->checkConfidenceDelta($scored_intents, $message);
      if ($delta_result) {
        return $delta_result;
      }
    }

    // Step 4: Check for known confusable pair triggers even with larger delta.
    if (count($scored_intents) >= 2) {
      $pair_result = $this->checkKnownConfusablePair($scored_intents, $message);
      if ($pair_result) {
        return $pair_result;
      }
    }

    return NULL;
  }

  /**
   * Checks if the message matches a known vague query.
   *
   * @param string $message
   *   The user's message.
   *
   * @return array|null
   *   Disambiguation result or NULL.
   */
  protected function checkVagueQuery(string $message): ?array {
    $normalized = $this->normalizeForLookup($message);

    if (isset($this->vagueQueries[$normalized])) {
      $template = $this->vagueQueries[$normalized];
      return [
        'type' => 'disambiguation',
        'reason' => 'vague_query',
        'matched_query' => $normalized,
        'confidence' => 0.3,
        'question' => $template['question'],
        'options' => $this->canonicalizeOptions($template['options']),
      ];
    }

    return NULL;
  }

  /**
   * Checks if the message is a single topic word without action.
   *
   * @param string $message
   *   The user's message.
   *
   * @return array|null
   *   Disambiguation result or NULL.
   */
  protected function checkTopicOnly(string $message): ?array {
    $normalized = $this->normalizeForLookup($message);
    $word_count = str_word_count($normalized);

    // Only check messages with 1-3 words (increased from 2 to handle
    // "custody right now", "divorce please", etc.).
    if ($word_count > 3) {
      return NULL;
    }

    // Exact match first.
    if (isset($this->topicOnlyTriggers[$normalized])) {
      return $this->buildTopicOnlyResult($normalized);
    }

    // Strip urgency/politeness modifiers and retry.
    $stripped = $this->stripTopicModifiers($normalized);
    if ($stripped !== $normalized && $stripped !== '' && isset($this->topicOnlyTriggers[$stripped])) {
      return $this->buildTopicOnlyResult($stripped);
    }

    return NULL;
  }

  /**
   * Builds a topic-only disambiguation result.
   *
   * @param string $key
   *   The topicOnlyTriggers key that matched.
   *
   * @return array
   *   Disambiguation result.
   */
  protected function buildTopicOnlyResult(string $key): array {
    $topic = $this->topicOnlyTriggers[$key];
    $area_label = ucfirst($topic['label']);

    return [
      'type' => 'disambiguation',
      'reason' => 'topic_without_action',
      'topic' => $topic['area'],
      'confidence' => 0.4,
      'question' => "I can help with {$area_label}. What would you like to do?",
      'options' => $this->canonicalizeOptions([
        ['label' => "Find {$area_label} forms", 'intent' => 'forms_finder', 'topic' => $topic['area']],
        ['label' => "Read {$area_label} guide", 'intent' => 'guides_finder', 'topic' => $topic['area']],
        ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
      ]),
    ];
  }

  /**
   * Strips urgency/politeness modifiers from a normalized message.
   *
   * @param string $normalized
   *   The normalized message.
   *
   * @return string
   *   Message with modifier words removed.
   */
  protected function stripTopicModifiers(string $normalized): string {
    $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $filtered = array_filter($words, function ($word) {
      return !in_array($word, self::$topicModifiers);
    });
    return implode(' ', $filtered);
  }

  /**
   * Checks if top-2 intents have a small confidence delta.
   *
   * @param array $scored_intents
   *   Scored intents sorted by confidence descending.
   * @param string $message
   *   The user's message.
   *
   * @return array|null
   *   Disambiguation result or NULL.
   */
  protected function checkConfidenceDelta(array $scored_intents, string $message): ?array {
    $first = $scored_intents[0];
    $second = $scored_intents[1];

    // Both must meet minimum confidence threshold.
    if ($first['confidence'] < self::MIN_CANDIDATE_CONFIDENCE ||
        $second['confidence'] < self::MIN_CANDIDATE_CONFIDENCE) {
      return NULL;
    }

    $delta = $first['confidence'] - $second['confidence'];

    // If delta is too large, no disambiguation needed.
    if ($delta >= self::DELTA_THRESHOLD) {
      return NULL;
    }

    // Check if this is a known confusable pair.
    $pair_key = $this->makePairKey($first['intent'], $second['intent']);

    if (isset($this->confusablePairs[$pair_key])) {
      $template = $this->confusablePairs[$pair_key];
      return [
        'type' => 'disambiguation',
        'reason' => 'close_confidence_known_pair',
        'competing_intents' => [$first, $second],
        'delta' => $delta,
        'confidence' => $first['confidence'],
        'question' => $template['question'],
        'options' => $this->canonicalizeOptions($template['options']),
      ];
    }

    // Unknown pair but still close - use generic clarification.
    return [
      'type' => 'disambiguation',
      'reason' => 'close_confidence_unknown_pair',
      'competing_intents' => [$first, $second],
      'delta' => $delta,
      'confidence' => $first['confidence'],
      'question' => 'I want to make sure I help you with the right thing. What are you looking for?',
      'options' => $this->canonicalizeOptions([
        ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ['label' => 'Find forms', 'intent' => 'forms_finder'],
        ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ['label' => 'Call advice line', 'intent' => 'legal_advice_line'],
      ]),
    ];
  }

  /**
   * Checks if top-2 intents are a known confusable pair (even with larger delta).
   *
   * Some pairs are inherently confusable even if one scores higher. This catches
   * cases like "legal help" which might score apply_for_help highly but could
   * also mean services_overview.
   *
   * @param array $scored_intents
   *   Scored intents sorted by confidence descending.
   * @param string $message
   *   The user's message.
   *
   * @return array|null
   *   Disambiguation result or NULL.
   */
  protected function checkKnownConfusablePair(array $scored_intents, string $message): ?array {
    $first = $scored_intents[0];
    $second = $scored_intents[1];

    // Both must meet minimum confidence.
    if ($second['confidence'] < self::MIN_CANDIDATE_CONFIDENCE) {
      return NULL;
    }

    // Allow slightly larger delta for known confusable pairs (up to 0.15).
    $delta = $first['confidence'] - $second['confidence'];
    if ($delta >= 0.15) {
      return NULL;
    }

    // High confidence first intent means we're fairly sure - don't disambiguate
    // unless delta is very small.
    if ($first['confidence'] >= 0.85 && $delta >= 0.08) {
      return NULL;
    }

    $pair_key = $this->makePairKey($first['intent'], $second['intent']);

    if (isset($this->confusablePairs[$pair_key])) {
      $template = $this->confusablePairs[$pair_key];
      return [
        'type' => 'disambiguation',
        'reason' => 'known_confusable_pair',
        'competing_intents' => [$first, $second],
        'delta' => $delta,
        'confidence' => $first['confidence'],
        'question' => $template['question'],
        'options' => $this->canonicalizeOptions($template['options']),
      ];
    }

    return NULL;
  }

  /**
   * Makes a sorted pair key from two intents.
   *
   * @param string $intent1
   *   First intent.
   * @param string $intent2
   *   Second intent.
   *
   * @return string
   *   Sorted pair key like "apply_for_help:services_overview".
   */
  protected function makePairKey(string $intent1, string $intent2): string {
    $pair = [$intent1, $intent2];
    sort($pair);
    return implode(':', $pair);
  }

  /**
   * Normalizes message for vague query lookup.
   *
   * @param string $message
   *   Raw message.
   *
   * @return string
   *   Normalized lowercase string.
   */
  protected function normalizeForLookup(string $message): string {
    $message = strtolower(trim($message));
    // Remove trailing punctuation.
    $message = preg_replace('/[?.!,]+$/', '', $message);
    // Normalize whitespace.
    $message = preg_replace('/\s+/', ' ', $message);
    return $message;
  }

  /**
   * Ensures disambiguation options always expose canonical 'intent' keys.
   *
   * The canonical key is 'intent'; 'value' is deprecated. This method
   * normalizes any remaining legacy 'value' entries for backwards compat.
   *
   * @param array $options
   *   Raw option rows.
   *
   * @return array
   *   Normalized options.
   */
  protected function canonicalizeOptions(array $options): array {
    $normalized = [];
    foreach ($options as $option) {
      if (!is_array($option)) {
        continue;
      }

      $intent = (string) ($option['intent'] ?? $option['value'] ?? '');
      if ($intent === '') {
        continue;
      }

      if (!isset($option['intent']) || $option['intent'] === '') {
        $option['intent'] = $intent;
      }

      $normalized[] = $option;
    }

    return $normalized;
  }

  /**
   * Gets the list of known confusable pairs (for testing/debugging).
   *
   * @return array
   *   Array of pair keys.
   */
  public function getConfusablePairs(): array {
    return array_keys($this->confusablePairs);
  }

  /**
   * Gets the list of vague queries (for testing/debugging).
   *
   * @return array
   *   Array of vague query strings.
   */
  public function getVagueQueries(): array {
    return array_keys($this->vagueQueries);
  }

  /**
   * Gets the list of topic-only triggers (for testing/debugging).
   *
   * @return array
   *   Array of topic trigger strings.
   */
  public function getTopicTriggers(): array {
    return array_keys($this->topicOnlyTriggers);
  }

}
