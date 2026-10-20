<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Lightweight typo correction using Levenshtein distance.
 *
 * Corrects misspellings of terms in the known vocabulary (topic tokens,
 * synonyms, and acronym expansions). An explicit misspelling dictionary
 * (config/routing/typos.yml) is consulted first; otherwise a token is only
 * corrected when:
 * - The token is at least 4 characters long
 * - Levenshtein distance is within conservative thresholds
 * - There is a single unambiguous best match (no ties)
 * - The corrected term exists in the known vocabulary.
 *
 * This is intentionally conservative to avoid false corrections.
 */
class TypoCorrector {

  /**
   * Minimum token length for correction.
   */
  const MIN_TOKEN_LENGTH = 4;

  /**
   * Maximum Levenshtein distance for short tokens (4-5 chars).
   */
  const MAX_DISTANCE_SHORT = 1;

  /**
   * Maximum Levenshtein distance for medium tokens (6-8 chars).
   */
  const MAX_DISTANCE_MEDIUM = 2;

  /**
   * Maximum Levenshtein distance for long tokens (9+ chars).
   */
  const MAX_DISTANCE_LONG = 3;

  /**
   * Known vocabulary: word => canonical form.
   *
   * @var array
   */
  protected $vocabulary = [];

  /**
   * Explicit typo dictionary: misspelling => canonical word.
   *
   * Loaded from config/routing/typos.yml. Consulted before Levenshtein
   * matching so known misspellings are corrected even when their edit
   * distance exceeds the conservative fuzzy thresholds.
   *
   * @var array
   */
  protected $dictionary = [];

  /**
   * Cache key for the vocabulary + dictionary bundle.
   */
  const CACHE_KEY = 'ilas_typo_corrector_vocab:v2';

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $cache;

  /**
   * The topic router (for vocabulary extraction).
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicRouter|null
   */
  protected $topicRouter;

  /**
   * The acronym expander (for vocabulary extraction).
   *
   * @var \Drupal\ilas_site_assistant\Service\AcronymExpander|null
   */
  protected $acronymExpander;

  /**
   * Constructs a TypoCorrector.
   *
   * @param object|null $cache
   *   Optional cache backend.
   * @param \Drupal\ilas_site_assistant\Service\TopicRouter|null $topic_router
   *   Optional topic router for vocabulary.
   * @param \Drupal\ilas_site_assistant\Service\AcronymExpander|null $acronym_expander
   *   Optional acronym expander for vocabulary.
   */
  public function __construct($cache = NULL, ?TopicRouter $topic_router = NULL, ?AcronymExpander $acronym_expander = NULL, ?ConfigFactoryInterface $config_factory = NULL) {
    $this->cache = $cache;
    $this->topicRouter = $topic_router;
    $this->acronymExpander = $acronym_expander;
    $this->configFactory = $config_factory;
    $this->buildVocabulary();
  }

  /**
   * Optional config factory for admin lexicon overrides.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|null
   */
  protected ?ConfigFactoryInterface $configFactory = NULL;

  /**
   * Corrects typos in the given text.
   *
   * @param string $text
   *   The input text (lowercase expected).
   *
   * @return array
   *   Array with keys:
   *   - 'text': The corrected text.
   *   - 'corrections': Array of ['original' => ..., 'corrected' => ..., 'distance' => ...].
   */
  public function correct(string $text): array {
    $corrections = [];
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $result_words = [];

    foreach ($words as $word) {
      // Strip punctuation for matching but preserve for reconstruction.
      $clean = preg_replace('/[^\w]/', '', $word);
      $clean_lower = strtolower($clean);

      // Skip if too short.
      if (strlen($clean_lower) < self::MIN_TOKEN_LENGTH) {
        $result_words[] = $word;
        continue;
      }

      // Skip if already in vocabulary (no correction needed).
      if (isset($this->vocabulary[$clean_lower])) {
        $result_words[] = $word;
        continue;
      }

      // Explicit dictionary hit: known misspelling regardless of distance.
      if (isset($this->dictionary[$clean_lower])) {
        $corrections[] = [
          'original' => $clean_lower,
          'corrected' => $this->dictionary[$clean_lower],
          'distance' => levenshtein($clean_lower, $this->dictionary[$clean_lower]),
          'source' => 'dictionary',
        ];
        $result_words[] = $this->dictionary[$clean_lower];
        continue;
      }

      // Skip common English words that are not typos.
      if ($this->isCommonWord($clean_lower)) {
        $result_words[] = $word;
        continue;
      }

      // Try to find best match.
      $match = $this->findBestMatch($clean_lower);
      if ($match) {
        $corrections[] = [
          'original' => $clean_lower,
          'corrected' => $match['word'],
          'distance' => $match['distance'],
        ];
        $result_words[] = $match['word'];
      }
      else {
        $result_words[] = $word;
      }
    }

    return [
      'text' => implode(' ', $result_words),
      'corrections' => $corrections,
    ];
  }

  /**
   * Finds the best vocabulary match for a misspelled token.
   *
   * @param string $token
   *   The misspelled token (lowercase).
   *
   * @return array|null
   *   ['word' => ..., 'distance' => ...] or NULL if no good match.
   */
  protected function findBestMatch(string $token): ?array {
    $len = strlen($token);
    $max_distance = $this->getMaxDistance($len);

    $best_word = NULL;
    $best_distance = $max_distance + 1;
    $tie = FALSE;

    foreach ($this->vocabulary as $candidate => $canonical) {
      $candidate_len = strlen($candidate);

      // Quick length filter.
      if (abs($len - $candidate_len) > $max_distance) {
        continue;
      }

      // Quick first-character heuristic: for distance 1, first char must
      // match or one of the first two chars must match.
      if ($max_distance <= 1) {
        if ($token[0] !== $candidate[0] && ($len < 2 || $candidate_len < 2 || $token[1] !== $candidate[0])) {
          // Allow transposition of first two chars.
          if ($len < 2 || $candidate_len < 2 || $token[0] !== $candidate[1]) {
            continue;
          }
        }
      }

      $distance = levenshtein($token, $candidate);

      if ($distance > 0 && $distance <= $max_distance) {
        if ($distance < $best_distance) {
          $best_distance = $distance;
          $best_word = $canonical;
          $tie = FALSE;
        }
        elseif ($distance === $best_distance && $canonical !== $best_word) {
          // Tie: different canonical forms at same distance.
          $tie = TRUE;
        }
      }
    }

    // Only return if unambiguous match.
    if ($best_word !== NULL && !$tie) {
      return ['word' => $best_word, 'distance' => $best_distance];
    }

    return NULL;
  }

  /**
   * Gets the maximum allowed Levenshtein distance for a token length.
   *
   * @param int $length
   *   Token length.
   *
   * @return int
   *   Maximum distance.
   */
  protected function getMaxDistance(int $length): int {
    if ($length >= 9) {
      return self::MAX_DISTANCE_LONG;
    }
    if ($length >= 6) {
      return self::MAX_DISTANCE_MEDIUM;
    }
    return self::MAX_DISTANCE_SHORT;
  }

  /**
   * Builds the vocabulary from available sources.
   */
  protected function buildVocabulary(): void {
    // Check cache.
    if ($this->cache) {
      $cached = $this->cache->get(self::CACHE_KEY);
      if ($cached && is_array($cached->data) && isset($cached->data['vocabulary'])) {
        $this->vocabulary = $cached->data['vocabulary'];
        $this->dictionary = $cached->data['dictionary'] ?? [];
        return;
      }
    }

    $this->vocabulary = [];
    $this->dictionary = [];

    // Add core legal terms (always available, even without TopicRouter).
    $this->addCoreVocabulary();

    // Add terms from TopicRouter's topic map.
    // Only add canonical tokens, NOT synonyms (which may include typo
    // variations that should themselves be corrected, not preserved).
    if ($this->topicRouter) {
      $topic_map = $this->topicRouter->getTopicMap();
      foreach ($topic_map as $topic_key => $topic) {
        // Add canonical tokens only.
        foreach ($topic['tokens'] ?? [] as $token) {
          $lower = strtolower($token);
          $this->vocabulary[$lower] = $lower;
        }
      }
    }

    // Add acronym expansions (individual words from expansions).
    if ($this->acronymExpander) {
      foreach ($this->acronymExpander->getAcronymMap() as $acronym => $config) {
        $expansion_words = preg_split('/\s+/', $config['expansion']);
        foreach ($expansion_words as $word) {
          if (strlen($word) >= self::MIN_TOKEN_LENGTH) {
            $this->vocabulary[$word] = $word;
          }
        }
      }
    }

    // Load the explicit typo dictionary (config/routing/typos.yml).
    $this->loadDictionary();

    // Admin overrides (settings form / gap review) win over the shipped file.
    if ($this->configFactory !== NULL) {
      foreach (LexiconOverrides::typosFromConfig($this->configFactory) as $entry) {
        $this->vocabulary[$entry['canonical']] = $entry['canonical'];
        $this->dictionary[$entry['misspelling']] = $entry['canonical'];
      }
    }

    // Cache the vocabulary + dictionary bundle.
    if ($this->cache && !empty($this->vocabulary)) {
      $this->cache->set(self::CACHE_KEY, [
        'vocabulary' => $this->vocabulary,
        'dictionary' => $this->dictionary,
      ], time() + 3600);
    }
  }

  /**
   * Loads the explicit misspelling dictionary from config/routing/typos.yml.
   *
   * Entries whose canonical word is not in the vocabulary are added to the
   * vocabulary as well, so a dictionary target is never itself "corrected".
   */
  protected function loadDictionary(): void {
    $yaml_path = __DIR__ . '/../../config/routing/typos.yml';
    if (!file_exists($yaml_path)) {
      return;
    }
    $data = Yaml::decode((string) file_get_contents($yaml_path)) ?: [];
    foreach ($data['typos'] ?? [] as $canonical => $misspellings) {
      $canonical = strtolower(trim((string) $canonical));
      if ($canonical === '' || !is_array($misspellings)) {
        continue;
      }
      $this->vocabulary[$canonical] = $canonical;
      foreach ($misspellings as $misspelling) {
        $misspelling = strtolower(trim((string) $misspelling));
        if ($misspelling === '' || $misspelling === $canonical) {
          continue;
        }
        $this->dictionary[$misspelling] = $canonical;
      }
    }
  }

  /**
   * Returns the explicit typo dictionary (for testing/debugging).
   *
   * @return array
   *   Map of misspelling => canonical word.
   */
  public function getDictionary(): array {
    return $this->dictionary;
  }

  /**
   * Adds core legal/domain vocabulary terms.
   *
   * These are always available regardless of TopicRouter.
   */
  protected function addCoreVocabulary(): void {
    $terms = [
      // Family law.
      'divorce', 'custody', 'visitation', 'adoption', 'paternity',
      'guardianship', 'separation', 'alimony', 'family',
      'child', 'support', 'protection', 'restraining',
      // Housing.
      'eviction', 'landlord', 'tenant', 'rent', 'lease', 'apartment',
      'foreclosure', 'housing', 'mortgage', 'security', 'deposit',
      'habitability', 'sublease', 'inspection', 'renter',
      // Known words that sit within edit distance of housing terms and
      // must never be "corrected" toward them.
      'renew', 'renewal', 'renewing', 'residence',
      // Consumer.
      'bankruptcy', 'debt', 'collection', 'credit', 'garnishment',
      'repossession', 'consumer', 'fraud', 'scam', 'judgment',
      'predatory', 'lending', 'payday',
      // Benefits / health.
      'medicaid', 'medicare', 'benefits', 'disability', 'insurance',
      'healthcare', 'medical',
      // Employment.
      'employment', 'fired', 'terminated', 'wages', 'paycheck',
      'harassment', 'discrimination', 'retaliation', 'wrongful',
      // Civil rights.
      'discrimination', 'civil', 'rights',
      // Seniors.
      'senior', 'elderly', 'elder', 'nursing',
      // General legal.
      'lawyer', 'attorney', 'legal', 'court', 'hearing',
      'application', 'eligible', 'eligibility', 'qualify',
      'forms', 'paperwork', 'documents', 'complaint',
      'feedback', 'donation', 'donate', 'office', 'location',
      'hotline', 'advice', 'phone', 'assistance',
      'guide', 'guides', 'instructions', 'resources',
      'representation', 'address', 'represent', 'representing',
      'judgment', 'notice', 'petition', 'motion', 'affidavit',
      'statement', 'testimony', 'subpoena', 'summons', 'answer',
      // Safety.
      'violence', 'domestic', 'abusive', 'stalking', 'threatened',
      'identity', 'theft', 'deadline',
      // Veterans.
      'veteran', 'veterans', 'military',
      // Idaho.
      'idaho', 'boise', 'pocatello', 'nampa', 'lewiston',
    ];

    foreach ($terms as $term) {
      $this->vocabulary[$term] = $term;
    }
  }

  /**
   * Checks if a word is a common English word that should not be corrected.
   *
   * This prevents false positives like "case" -> "care", "file" -> "fire",
   * "need" -> "deed", etc.
   *
   * @param string $word
   *   The word to check (lowercase).
   *
   * @return bool
   *   TRUE if the word is common and should be skipped.
   */
  protected function isCommonWord(string $word): bool {
    static $common_words = NULL;
    if ($common_words === NULL) {
      $common_words = array_flip([
        // Common verbs.
        'case', 'call', 'come', 'give', 'goes', 'gone', 'good', 'keep',
        'know', 'last', 'live', 'long', 'look', 'made', 'make', 'many',
        'much', 'must', 'name', 'next', 'only', 'open', 'part', 'plan',
        'play', 'read', 'said', 'same', 'show', 'side', 'sign', 'take',
        'tell', 'time', 'turn', 'used', 'want', 'well', 'went', 'will',
        'word', 'work', 'year', 'also', 'back', 'been', 'best', 'both',
        'came', 'date', 'days', 'each', 'even', 'ever', 'fact', 'feel',
        'find', 'four', 'free', 'full', 'gave', 'gets', 'half', 'hand',
        'hard', 'head', 'held', 'here', 'high', 'home', 'hope', 'hour',
        'idea', 'john', 'kind', 'knew', 'left', 'less', 'life', 'like',
        'line', 'list', 'lose', 'lost', 'love', 'main', 'mean', 'meet',
        'mind', 'miss', 'more', 'move', 'near', 'nice', 'note', 'once',
        'paid', 'past', 'pick', 'real', 'rest', 'risk', 'role', 'room',
        'rule', 'runs', 'safe', 'says', 'seen', 'self', 'send', 'sent',
        'sets', 'shut', 'size', 'sort', 'stay', 'step', 'stop', 'such',
        'sure', 'talk', 'test', 'text', 'them', 'then', 'told', 'took',
        'town', 'tree', 'true', 'type', 'upon', 'very', 'view', 'vote',
        'wait', 'walk', 'week', 'wide', 'wife', 'wish', 'area', 'body',
        'book', 'care', 'city', 'dark', 'deal', 'deep', 'done', 'door',
        'down', 'draw', 'drop', 'easy', 'else', 'eyes', 'face', 'fall',
        'fast', 'fill', 'fine', 'five', 'food', 'foot', 'form', 'girl',
        'grew', 'grow', 'hair', 'heat', 'help', 'here', 'hill', 'hold',
        'hole', 'huge', 'hung', 'hurt', 'iron', 'item', 'jack', 'join',
        'jump', 'just', 'king', 'land', 'late', 'lead', 'lift', 'load',
        'lock', 'mark', 'mass', 'mile', 'mine', 'moon', 'neck', 'news',
        'none', 'nose', 'okay', 'ones', 'page', 'pair', 'park', 'pass',
        'path', 'pile', 'plus', 'pool', 'poor', 'pull', 'push', 'race',
        'rain', 'rate', 'rich', 'ride', 'ring', 'rise', 'road', 'rock',
        'rose', 'sale', 'save', 'seat', 'seek', 'seem', 'sell', 'ship',
        'shop', 'shot', 'snow', 'soft', 'soil', 'sold', 'song', 'soon',
        'star', 'suit', 'tall', 'task', 'team', 'till', 'tone', 'tool',
        'tied', 'tiny', 'trip', 'unit', 'vast', 'wage', 'warm', 'wash',
        'wave', 'wear', 'whom', 'wild', 'wind', 'wine', 'wire', 'wise',
        'wood', 'wore', 'wrap', 'zero', 'zone',
        // Common 5+ letter words that may be close to legal terms.
        'doing', 'going', 'thing', 'think', 'those', 'under', 'never',
        'still', 'every', 'being', 'place', 'state', 'since', 'house',
        'might', 'right', 'night', 'small', 'large', 'point', 'world',
        'water', 'power', 'money', 'board', 'class', 'clear', 'close',
        'bring', 'green', 'begin', 'early', 'level', 'often', 'order',
        'paper', 'serve', 'south', 'speak', 'spend', 'stand', 'start',
        'table', 'today', 'total', 'tried', 'value', 'voice', 'watch',
        'white', 'whole', 'women', 'wrote', 'young', 'above', 'along',
        'below', 'break', 'carry', 'cause', 'check', 'claim', 'cover',
        'death', 'drive', 'enter', 'equal', 'exist', 'field', 'final',
        'floor', 'force', 'given', 'glass', 'great', 'group', 'heart',
        'heavy', 'horse', 'human', 'image', 'issue', 'known', 'labor',
        'later', 'laugh', 'learn', 'leave', 'local', 'lower', 'lucky',
        'lunch', 'major', 'match', 'maybe', 'model', 'month', 'moral',
        'mouth', 'movie', 'music', 'north', 'occur', 'offer', 'often',
        'owner', 'party', 'peace', 'phone', 'photo', 'piece', 'plant',
        'press', 'price', 'prime', 'prove', 'quick', 'quiet', 'quite',
        'radio', 'raise', 'range', 'reach', 'ready', 'refer', 'reply',
        'river', 'round', 'scene', 'score', 'sense', 'seven', 'shall',
        'shape', 'share', 'short', 'shout', 'sight', 'sleep', 'smile',
        'solid', 'solve', 'sorry', 'sound', 'space', 'stage', 'stick',
        'stock', 'stone', 'store', 'story', 'stuff', 'style', 'sugar',
        'taken', 'teach', 'teeth', 'thank', 'there', 'thick', 'third',
        'those', 'threw', 'throw', 'tight', 'tired', 'title', 'touch',
        'tower', 'track', 'trade', 'train', 'treat', 'trial', 'trust',
        'twice', 'union', 'video', 'visit', 'waste', 'worth', 'would',
        'wrong', 'years',
        // Prevent "file" -> "fire" type corrections.
        'file', 'files', 'filing', 'filed',
        'need', 'needs', 'deed', 'feed',
        'send', 'gave', 'from', 'into', 'over', 'also', 'able',
      ]);
    }
    return isset($common_words[$word]);
  }

  /**
   * Returns the vocabulary size (for testing/debugging).
   *
   * @return int
   *   Number of vocabulary entries.
   */
  public function getVocabularySize(): int {
    return count($this->vocabulary);
  }

  /**
   * Returns the vocabulary (for testing/debugging).
   *
   * @return array
   *   The vocabulary map.
   */
  public function getVocabulary(): array {
    return $this->vocabulary;
  }

  /**
   * Clears the vocabulary cache.
   */
  public function clearCache(): void {
    if ($this->cache) {
      $this->cache->delete('ilas_typo_corrector_vocab');
    }
    $this->buildVocabulary();
  }

}
