<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Deterministic disambiguation service for vague and confusable intents.
 */
class Disambiguator {

  /**
   * Confidence delta threshold for triggering disambiguation.
   */
  const DELTA_THRESHOLD = 0.12;

  /**
   * Minimum confidence to consider an intent as a candidate.
   */
  const MIN_CANDIDATE_CONFIDENCE = 0.40;

  /**
   * The disambiguation catalog loader.
   *
   * @var \Drupal\ilas_site_assistant\Service\DisambiguationPack
   */
  protected $pack;

  /**
   * Configured family definitions.
   *
   * @var array
   */
  protected $families = [];

  /**
   * Topic-only lexicon keyed by normalized topic phrase.
   *
   * @var array
   */
  protected $topicOnlyTriggers = [];

  /**
   * Known confusable pairs keyed by pair key.
   *
   * @var array
   */
  protected $confusablePairs = [];

  /**
   * Topic modifier tokens.
   *
   * @var string[]
   */
  protected $topicModifiers = [];

  /**
   * Topic filler tokens.
   *
   * @var string[]
   */
  protected $topicFillerWords = [];

  /**
   * Topic lead regex patterns.
   *
   * @var string[]
   */
  protected $topicLeadPatterns = [];

  /**
   * Constructs a Disambiguator.
   */
  public function __construct(?DisambiguationPack $pack = NULL) {
    $this->pack = $pack ?? new DisambiguationPack();
    $this->loadCatalog();
  }

  /**
   * Checks if disambiguation is needed and returns clarification if so.
   *
   * Order:
   * 1. Exact family alias match
   * 2. Short family match from token sets / lead patterns
   * 3. Topic-only / topic-enriched clarification
   * 4. Confidence-delta and known-pair fallbacks
   *
   * @param string $message
   *   The user's message.
   * @param array $scored_intents
   *   Scored intents sorted by confidence descending.
   * @param array $context
   *   Optional extraction context.
   *
   * @return array|null
   *   Disambiguation payload or NULL.
   */
  public function check(string $message, array $scored_intents, array $context = []): ?array {
    $lookup = $this->buildLookupContext($message, $context);

    $familyResult = $this->checkFamilyMatch($lookup);
    if ($familyResult) {
      return $familyResult;
    }

    $topicResult = $this->checkTopicOnly($lookup);
    if ($topicResult) {
      return $topicResult;
    }

    if (count($scored_intents) >= 2) {
      $deltaResult = $this->checkConfidenceDelta($scored_intents, $message);
      if ($deltaResult) {
        return $deltaResult;
      }
    }

    if (count($scored_intents) >= 2) {
      $pairResult = $this->checkKnownConfusablePair($scored_intents, $message);
      if ($pairResult) {
        return $pairResult;
      }
    }

    return NULL;
  }

  /**
   * Returns the configured pair keys.
   */
  public function getConfusablePairs(): array {
    return array_keys($this->confusablePairs);
  }

  /**
   * Returns all exact vague-query aliases for test/debug usage.
   */
  public function getVagueQueries(): array {
    $queries = [];
    foreach ($this->families as $family) {
      foreach (($family['exact_aliases'] ?? []) as $alias) {
        $queries[] = $this->normalizeForLookup((string) $alias);
      }
    }

    return array_values(array_unique(array_filter($queries)));
  }

  /**
   * Returns the configured topic trigger phrases.
   */
  public function getTopicTriggers(): array {
    return array_keys($this->topicOnlyTriggers);
  }

  /**
   * Returns the configured family keys.
   */
  public function getFamilies(): array {
    return array_keys($this->families);
  }

  /**
   * Loads the YAML catalog into local structures.
   */
  protected function loadCatalog(): void {
    $this->families = $this->pack->getFamilies();
    $topicLexicon = $this->pack->getTopicLexicon();
    $this->topicOnlyTriggers = is_array($topicLexicon['topics'] ?? NULL) ? $topicLexicon['topics'] : [];
    $this->confusablePairs = $this->pack->getConfusablePairs();
    $this->topicModifiers = $this->normalizeStringList($topicLexicon['modifiers'] ?? []);
    $this->topicFillerWords = $this->normalizeStringList($topicLexicon['filler_words'] ?? []);
    $this->topicLeadPatterns = array_values(array_filter(array_map(
      static fn($pattern): string => is_string($pattern) ? $pattern : '',
      $topicLexicon['lead_patterns'] ?? []
    )));
  }

  /**
   * Checks vague disambiguation families.
   */
  protected function checkFamilyMatch(array $lookup): ?array {
    foreach ($this->families as $familyKey => $family) {
      if ($this->matchesExactAlias($lookup, $family)) {
        return $this->buildFamilyResult($familyKey, $family, $lookup, 'exact_alias');
      }
    }

    foreach ($this->families as $familyKey => $family) {
      if ($this->matchesShortFamily($lookup, $family)) {
        return $this->buildFamilyResult($familyKey, $family, $lookup, 'token_family');
      }
    }

    return NULL;
  }

  /**
   * Checks whether the message matches a family's exact alias list.
   */
  protected function matchesExactAlias(array $lookup, array $family): bool {
    $aliases = $family['exact_aliases'] ?? [];
    if (!is_array($aliases) || $aliases === []) {
      return FALSE;
    }

    foreach ($aliases as $alias) {
      $normalizedAlias = $this->normalizeForLookup((string) $alias);
      if ($normalizedAlias === '') {
        continue;
      }
      if ($normalizedAlias === $lookup['original_normalized']) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks whether a short-query family should trigger.
   */
  protected function matchesShortFamily(array $lookup, array $family): bool {
    if ($this->familyWouldInterceptSpecificQuery($lookup, $family)) {
      return FALSE;
    }

    if ($this->matchesFamilyPatterns($lookup, $family)) {
      return TRUE;
    }

    $allTokens = $family['all_tokens'] ?? [];
    $anyTokens = $family['any_tokens'] ?? [];
    if (!is_array($allTokens) && !is_array($anyTokens)) {
      return FALSE;
    }

    $effectiveCount = $this->effectiveTokenCount($lookup, $family);
    $maxTokenCount = (int) ($family['max_token_count'] ?? 0);
    if ($maxTokenCount > 0 && $effectiveCount > $maxTokenCount) {
      return FALSE;
    }

    if (is_array($allTokens) && $allTokens !== [] && !$this->allTermsMatch($lookup, $allTokens)) {
      return FALSE;
    }

    if (is_array($anyTokens) && $anyTokens !== [] && !$this->anyTermsMatch($lookup, $anyTokens)) {
      return FALSE;
    }

    return (is_array($allTokens) && $allTokens !== []) || (is_array($anyTokens) && $anyTokens !== []);
  }

  /**
   * Builds a vague-family result.
   */
  protected function buildFamilyResult(string $familyKey, array $family, array $lookup, string $matchType): array {
    return [
      'type' => 'disambiguation',
      'reason' => (string) ($family['reason'] ?? 'vague_query'),
      'family' => (string) ($family['stable_family'] ?? $familyKey),
      'family_variant' => $familyKey,
      'match_type' => $matchType,
      'matched_query' => $lookup['original_normalized'],
      'confidence' => 0.3,
      'question' => (string) ($family['question'] ?? 'What are you looking for?'),
      'options' => $this->canonicalizeOptions(is_array($family['options'] ?? NULL) ? $family['options'] : []),
    ];
  }

  /**
   * Checks if the message is a topic without an action.
   */
  protected function checkTopicOnly(array $lookup): ?array {
    $genericHelpTopic = $this->extractTopicFromGenericHelp($lookup['original_normalized']);
    if ($genericHelpTopic !== NULL && isset($this->topicOnlyTriggers[$genericHelpTopic])) {
      return $this->buildTopicOnlyResult($genericHelpTopic);
    }

    $wordCount = count($lookup['original_tokens']);
    if ($wordCount > 3) {
      return NULL;
    }

    $normalized = $lookup['original_normalized'];
    if (isset($this->topicOnlyTriggers[$normalized])) {
      return $this->buildTopicOnlyResult($normalized);
    }

    $stripped = $this->stripConfiguredWords($normalized, $this->topicModifiers);
    if ($stripped !== $normalized && $stripped !== '' && isset($this->topicOnlyTriggers[$stripped])) {
      return $this->buildTopicOnlyResult($stripped);
    }

    return NULL;
  }

  /**
   * Builds a topic-only disambiguation result.
   */
  protected function buildTopicOnlyResult(string $key): array {
    $topic = $this->topicOnlyTriggers[$key];
    $areaLabel = ucfirst((string) ($topic['label'] ?? $key));
    $area = (string) ($topic['area'] ?? '');
    $forms_intent = $this->resolveTopicOnlyResourceIntent('forms', $topic);
    $guides_intent = $this->resolveTopicOnlyResourceIntent('guides', $topic);

    return [
      'type' => 'disambiguation',
      'reason' => 'topic_without_action',
      'family' => 'topic_without_action',
      'topic' => $area,
      'confidence' => 0.4,
      'question' => "I can help with {$areaLabel}. What would you like to do?",
      'options' => $this->canonicalizeOptions([
        ['label' => "Find {$areaLabel} forms", 'intent' => $forms_intent, 'topic' => $area],
        ['label' => "Read {$areaLabel} guide", 'intent' => $guides_intent, 'topic' => $area],
        ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
      ]),
    ];
  }

  /**
   * Resolves the most specific structured action for a topic-only option.
   */
  protected function resolveTopicOnlyResourceIntent(string $resourceKind, array $topic): string {
    $configured_intent = trim((string) ($topic[$resourceKind . '_intent'] ?? ''));
    if ($configured_intent !== '') {
      return $configured_intent;
    }

    $topic_intent = trim((string) ($topic['topic_intent'] ?? ''));
    if ($topic_intent !== '') {
      return $resourceKind . '_' . $topic_intent;
    }

    $area = trim((string) ($topic['area'] ?? ''));
    if ($area !== '') {
      return $resourceKind . '_' . $area;
    }

    return $resourceKind === 'guides' ? 'guides_finder' : 'forms_finder';
  }

  /**
   * Extracts a bare topic from short "help with X" phrasing.
   */
  protected function extractTopicFromGenericHelp(string $normalized): ?string {
    foreach ($this->topicLeadPatterns as $pattern) {
      if (!preg_match($pattern, $normalized)) {
        continue;
      }

      $candidate = preg_replace($pattern, '', $normalized, 1);
      $candidate = is_string($candidate) ? $candidate : '';
      if ($candidate === '') {
        return NULL;
      }

      $candidateWords = $this->tokenizeLookup($candidate);
      if (count($candidateWords) > 4) {
        return NULL;
      }

      $candidate = $this->stripConfiguredWords($candidate, $this->topicModifiers);
      $candidate = $this->stripConfiguredWords($candidate, $this->topicFillerWords);
      if ($candidate === '') {
        return NULL;
      }

      return $candidate;
    }

    return NULL;
  }

  /**
   * Checks if top-2 intents have a small confidence delta.
   */
  protected function checkConfidenceDelta(array $scoredIntents, string $message): ?array {
    $first = $scoredIntents[0];
    $second = $scoredIntents[1];

    if ($first['confidence'] < self::MIN_CANDIDATE_CONFIDENCE || $second['confidence'] < self::MIN_CANDIDATE_CONFIDENCE) {
      return NULL;
    }

    $delta = $first['confidence'] - $second['confidence'];
    if ($delta >= self::DELTA_THRESHOLD) {
      return NULL;
    }

    $pairKey = $this->makePairKey((string) $first['intent'], (string) $second['intent']);
    if (isset($this->confusablePairs[$pairKey])) {
      $template = $this->confusablePairs[$pairKey];
      return [
        'type' => 'disambiguation',
        'reason' => 'close_confidence_known_pair',
        'family' => 'confusable_pair',
        'pair_key' => $pairKey,
        'competing_intents' => [$first, $second],
        'delta' => $delta,
        'confidence' => $first['confidence'],
        'question' => (string) ($template['question'] ?? 'What are you looking for?'),
        'options' => $this->canonicalizeOptions(is_array($template['options'] ?? NULL) ? $template['options'] : []),
      ];
    }

    return [
      'type' => 'disambiguation',
      'reason' => 'close_confidence_unknown_pair',
      'family' => 'unknown_pair',
      'pair_key' => $pairKey,
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
   * Checks if top-2 intents are a known confusable pair.
   */
  protected function checkKnownConfusablePair(array $scoredIntents, string $message): ?array {
    $first = $scoredIntents[0];
    $second = $scoredIntents[1];

    if ($second['confidence'] < self::MIN_CANDIDATE_CONFIDENCE) {
      return NULL;
    }

    $delta = $first['confidence'] - $second['confidence'];
    if ($delta >= 0.15) {
      return NULL;
    }

    if ($first['confidence'] >= 0.85 && $delta >= 0.08) {
      return NULL;
    }

    $pairKey = $this->makePairKey((string) $first['intent'], (string) $second['intent']);
    if (!isset($this->confusablePairs[$pairKey])) {
      return NULL;
    }

    $template = $this->confusablePairs[$pairKey];
    return [
      'type' => 'disambiguation',
      'reason' => 'known_confusable_pair',
      'family' => 'confusable_pair',
      'pair_key' => $pairKey,
      'competing_intents' => [$first, $second],
      'delta' => $delta,
      'confidence' => $first['confidence'],
      'question' => (string) ($template['question'] ?? 'What are you looking for?'),
      'options' => $this->canonicalizeOptions(is_array($template['options'] ?? NULL) ? $template['options'] : []),
    ];
  }

  /**
   * Builds a stable sorted pair key.
   */
  protected function makePairKey(string $intent1, string $intent2): string {
    $pair = [$intent1, $intent2];
    sort($pair);
    return implode(':', $pair);
  }

  /**
   * Builds normalized lookup context from raw message and extraction data.
   */
  protected function buildLookupContext(string $message, array $context): array {
    $extraction = is_array($context['extraction'] ?? NULL) ? $context['extraction'] : [];
    $originalNormalized = $this->normalizeForLookup($message);
    $extractionNormalized = $this->normalizeForLookup((string) ($extraction['normalized'] ?? ''));
    $originalTokens = $this->tokenizeLookup($originalNormalized);
    $extractionTokens = $this->tokenizeLookup($extractionNormalized);

    return [
      'original_normalized' => $originalNormalized,
      'extraction_normalized' => $extractionNormalized,
      'original_tokens' => $originalTokens,
      'extraction_tokens' => $extractionTokens,
      'combined_tokens' => array_values(array_unique(array_merge($originalTokens, $extractionTokens))),
    ];
  }

  /**
   * Normalizes a message for lookup.
   */
  protected function normalizeForLookup(string $message): string {
    $normalized = mb_strtolower(trim($message));
    $normalized = str_replace('_', ' ', $normalized);
    $normalized = preg_replace('/[?.!,]+$/u', '', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);
    return trim((string) $normalized);
  }

  /**
   * Tokenizes a normalized lookup string.
   *
   * Keeps apostrophes inside tokens but strips punctuation around them.
   */
  protected function tokenizeLookup(string $normalized): array {
    if ($normalized === '') {
      return [];
    }

    $pieces = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($pieces)) {
      return [];
    }

    $tokens = [];
    foreach ($pieces as $piece) {
      $token = preg_replace("/^[^\\p{L}\\p{N}']+|[^\\p{L}\\p{N}']+$/u", '', $piece);
      if (is_string($token) && $token !== '') {
        $tokens[] = $token;
      }
    }

    return $tokens;
  }

  /**
   * Normalizes a string list.
   */
  protected function normalizeStringList(array $items): array {
    $normalized = [];
    foreach ($items as $item) {
      if (!is_string($item)) {
        continue;
      }
      $value = $this->normalizeForLookup($item);
      if ($value !== '') {
        $normalized[] = $value;
      }
    }
    return array_values(array_unique($normalized));
  }

  /**
   * Returns the effective token count after removing family stop tokens.
   */
  protected function effectiveTokenCount(array $lookup, array $family): int {
    $stopTokens = $this->normalizeStringList(is_array($family['stop_tokens'] ?? NULL) ? $family['stop_tokens'] : []);
    if ($stopTokens === []) {
      return count($lookup['original_tokens']);
    }

    $filtered = array_filter($lookup['original_tokens'], function (string $token) use ($stopTokens): bool {
      return !in_array($token, $stopTokens, TRUE);
    });

    return count($filtered);
  }

  /**
   * Returns TRUE when a family would intercept a more specific query.
   */
  protected function familyWouldInterceptSpecificQuery(array $lookup, array $family): bool {
    $negativeTerms = is_array($family['negative_tokens'] ?? NULL) ? $family['negative_tokens'] : [];
    if ($negativeTerms !== [] && $this->anyTermsMatch($lookup, $negativeTerms)) {
      return TRUE;
    }

    $negativePatterns = $family['negative_patterns'] ?? [];
    if (is_array($negativePatterns)) {
      foreach ($negativePatterns as $pattern) {
        if (!is_string($pattern) || $pattern === '') {
          continue;
        }
        if (preg_match($pattern, $lookup['original_normalized']) || preg_match($pattern, $lookup['extraction_normalized'])) {
          return TRUE;
        }
      }
    }

    return !empty($family['disallow_topics']) && $this->containsKnownTopic($lookup);
  }

  /**
   * Returns TRUE when any configured lead pattern matches.
   */
  protected function matchesFamilyPatterns(array $lookup, array $family): bool {
    $patterns = $family['lead_patterns'] ?? [];
    if (!is_array($patterns) || $patterns === []) {
      return FALSE;
    }

    foreach ($patterns as $pattern) {
      if (!is_string($pattern) || $pattern === '') {
        continue;
      }
      if (preg_match($pattern, $lookup['original_normalized'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when all supplied terms match.
   */
  protected function allTermsMatch(array $lookup, array $terms): bool {
    foreach ($terms as $term) {
      if (!$this->lookupContainsTerm($lookup, (string) $term)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns TRUE when any supplied term matches.
   */
  protected function anyTermsMatch(array $lookup, array $terms): bool {
    foreach ($terms as $term) {
      if ($this->lookupContainsTerm($lookup, (string) $term)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when the lookup contains a term or phrase.
   */
  protected function lookupContainsTerm(array $lookup, string $term): bool {
    $normalizedTerm = $this->normalizeForLookup($term);
    if ($normalizedTerm === '') {
      return FALSE;
    }

    if (str_contains($normalizedTerm, ' ')) {
      return $this->containsPhrase($lookup['original_normalized'], $normalizedTerm);
    }

    return in_array($normalizedTerm, $lookup['original_tokens'], TRUE);
  }

  /**
   * Returns TRUE when a phrase appears with boundaries.
   */
  protected function containsPhrase(string $normalizedText, string $normalizedPhrase): bool {
    if ($normalizedText === '' || $normalizedPhrase === '') {
      return FALSE;
    }

    $escaped = preg_quote($normalizedPhrase, '/');
    $escaped = str_replace('\ ', '\s+', $escaped);
    return (bool) preg_match('/(?<![\p{L}\p{N}_])' . $escaped . '(?![\p{L}\p{N}_])/u', $normalizedText);
  }

  /**
   * Returns TRUE when the lookup contains any configured topic phrase.
   */
  protected function containsKnownTopic(array $lookup): bool {
    foreach (array_keys($this->topicOnlyTriggers) as $topic) {
      if ($this->containsPhrase($lookup['original_normalized'], $topic) || $this->containsPhrase($lookup['extraction_normalized'], $topic)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Removes configured single-word fillers from a normalized string.
   */
  protected function stripConfiguredWords(string $normalized, array $removals): string {
    $removalSet = array_flip($this->normalizeStringList($removals));
    if ($removalSet === []) {
      return $normalized;
    }

    $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) {
      return $normalized;
    }

    $filtered = array_filter($words, function (string $word) use ($removalSet): bool {
      return !isset($removalSet[$word]);
    });

    return implode(' ', $filtered);
  }

  /**
   * Ensures disambiguation options always expose canonical intent keys.
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

      $option['intent'] = $intent;
      unset($option['value']);
      $normalized[] = $option;
    }

    return $normalized;
  }

}
