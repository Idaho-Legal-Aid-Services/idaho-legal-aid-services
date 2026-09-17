<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Admin-editable lexicon overrides (typos and topic aliases).
 *
 * Stored in ilas_site_assistant.settings:lexicon_overrides so staff can add a
 * misspelling or a phrase-to-topic mapping from the settings form or the gap
 * review screen without a code deploy. TypoCorrector and TopicRouter merge
 * these at load time; durable entries should still be promoted into
 * config/routing/typos.yml and topic_map.yml with fixtures during the weekly
 * review so CI covers them.
 */
final class LexiconOverrides {

  public const CONFIG_NAME = 'ilas_site_assistant.settings';
  public const CONFIG_KEY = 'lexicon_overrides';
  public const SEPARATOR = '=>';
  public const MAX_PHRASE_LENGTH = 80;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly ?TypoCorrector $typoCorrector = NULL,
    private readonly ?TopIntentsPack $topIntentsPack = NULL,
  ) {}

  /**
   * Reads typo overrides from config: [['misspelling' => ..., 'canonical' => ...]].
   */
  public static function typosFromConfig(ConfigFactoryInterface $config_factory): array {
    $raw = $config_factory->get(self::CONFIG_NAME)->get(self::CONFIG_KEY . '.typos');
    return self::normalizeTypoEntries(is_array($raw) ? $raw : []);
  }

  /**
   * Reads topic alias overrides from config: [['phrase' => ..., 'topic_intent' => ...]].
   */
  public static function topicAliasesFromConfig(ConfigFactoryInterface $config_factory): array {
    $raw = $config_factory->get(self::CONFIG_NAME)->get(self::CONFIG_KEY . '.topic_aliases');
    return self::normalizeAliasEntries(is_array($raw) ? $raw : []);
  }

  /**
   * Returns the configured typo overrides.
   */
  public function getTypos(): array {
    return self::typosFromConfig($this->configFactory);
  }

  /**
   * Returns the configured topic alias overrides.
   */
  public function getTopicAliases(): array {
    return self::topicAliasesFromConfig($this->configFactory);
  }

  /**
   * Parses "misspelling => canonical" lines.
   *
   * @return array
   *   ['entries' => [...], 'errors' => [line => message]].
   */
  public static function parseTypoLines(string $text): array {
    $entries = [];
    $errors = [];
    foreach (self::lines($text) as $number => $line) {
      $parts = self::splitLine($line);
      if ($parts === NULL) {
        $errors[$number] = sprintf('Line %d must look like "misspelling => canonical".', $number);
        continue;
      }
      [$misspelling, $canonical] = $parts;
      if (!preg_match('/^\p{L}[\p{L}\p{N}\'-]*$/u', $misspelling) || !preg_match('/^\p{L}[\p{L}\p{N}\'-]*$/u', $canonical)) {
        $errors[$number] = sprintf('Line %d: typo entries must be single words.', $number);
        continue;
      }
      if ($misspelling === $canonical) {
        $errors[$number] = sprintf('Line %d: a word cannot be its own correction.', $number);
        continue;
      }
      $entries[] = ['misspelling' => $misspelling, 'canonical' => $canonical];
    }
    return ['entries' => self::normalizeTypoEntries($entries), 'errors' => $errors];
  }

  /**
   * Parses "phrase => topic_intent" lines.
   *
   * @return array
   *   ['entries' => [...], 'errors' => [line => message]].
   */
  public static function parseAliasLines(string $text): array {
    $entries = [];
    $errors = [];
    foreach (self::lines($text) as $number => $line) {
      $parts = self::splitLine($line);
      if ($parts === NULL) {
        $errors[$number] = sprintf('Line %d must look like "phrase => topic_intent".', $number);
        continue;
      }
      [$phrase, $intent] = $parts;
      if (mb_strlen($phrase) > self::MAX_PHRASE_LENGTH || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\'-]*$/u', $phrase)) {
        $errors[$number] = sprintf('Line %d: phrases are plain words (max %d characters).', $number, self::MAX_PHRASE_LENGTH);
        continue;
      }
      if (!preg_match('/^topic_[a-z0-9_]+$/', $intent)) {
        $errors[$number] = sprintf('Line %d: the target must be a topic_* pack intent.', $number);
        continue;
      }
      $entries[] = ['phrase' => $phrase, 'topic_intent' => $intent];
    }
    return ['entries' => self::normalizeAliasEntries($entries), 'errors' => $errors];
  }

  /**
   * Formats typo entries back into editable lines.
   */
  public static function formatTypoLines(array $entries): string {
    $lines = [];
    foreach (self::normalizeTypoEntries($entries) as $entry) {
      $lines[] = $entry['misspelling'] . ' ' . self::SEPARATOR . ' ' . $entry['canonical'];
    }
    return implode("\n", $lines);
  }

  /**
   * Formats alias entries back into editable lines.
   */
  public static function formatAliasLines(array $entries): string {
    $lines = [];
    foreach (self::normalizeAliasEntries($entries) as $entry) {
      $lines[] = $entry['phrase'] . ' ' . self::SEPARATOR . ' ' . $entry['topic_intent'];
    }
    return implode("\n", $lines);
  }

  /**
   * Validates typo entries against the corrector vocabulary.
   *
   * @return string[]
   *   Error messages (empty when valid).
   */
  public function validateTypos(array $entries): array {
    $errors = [];
    if ($this->typoCorrector === NULL) {
      return $errors;
    }
    $vocabulary = $this->typoCorrector->getVocabulary();
    foreach (self::normalizeTypoEntries($entries) as $entry) {
      if (!isset($vocabulary[$entry['canonical']])) {
        $errors[] = sprintf('"%s" is not a known vocabulary word; corrections must target a word the assistant already recognises.', $entry['canonical']);
      }
      if (isset($vocabulary[$entry['misspelling']])) {
        $errors[] = sprintf('"%s" is already a real vocabulary word and cannot be treated as a misspelling.', $entry['misspelling']);
      }
    }
    return $errors;
  }

  /**
   * Validates alias entries against the Top Intents pack.
   *
   * @return string[]
   *   Error messages (empty when valid).
   */
  public function validateAliases(array $entries): array {
    $errors = [];
    if ($this->topIntentsPack === NULL) {
      return $errors;
    }
    foreach (self::normalizeAliasEntries($entries) as $entry) {
      if ($this->topIntentsPack->lookup($entry['topic_intent']) === NULL) {
        $errors[] = sprintf('"%s" is not a Top Intents pack entry.', $entry['topic_intent']);
      }
    }
    return $errors;
  }

  /**
   * Returns selectable topic_* pack intents keyed by intent with labels.
   */
  public function topicIntentOptions(): array {
    $options = [];
    if ($this->topIntentsPack === NULL) {
      return $options;
    }
    foreach ($this->topIntentsPack->getAllKeys() as $key) {
      if (!str_starts_with($key, 'topic_')) {
        continue;
      }
      $entry = $this->topIntentsPack->lookup($key) ?? [];
      $options[$key] = (string) ($entry['label'] ?? $key) . ' (' . $key . ')';
    }
    asort($options);
    return $options;
  }

  /**
   * Persists both lists and invalidates the routing caches.
   */
  public function save(array $typos, array $aliases): void {
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set(self::CONFIG_KEY, [
        'typos' => self::normalizeTypoEntries($typos),
        'topic_aliases' => self::normalizeAliasEntries($aliases),
      ])
      ->save();
    $this->invalidateCaches();
  }

  /**
   * Adds one topic alias (no-op when it already exists).
   *
   * @return bool
   *   TRUE when the alias was added.
   */
  public function addTopicAlias(string $phrase, string $topic_intent): bool {
    $aliases = $this->getTopicAliases();
    $candidate = self::normalizeAliasEntries([['phrase' => $phrase, 'topic_intent' => $topic_intent]]);
    if ($candidate === []) {
      return FALSE;
    }
    foreach ($aliases as $existing) {
      if ($existing['phrase'] === $candidate[0]['phrase']) {
        return FALSE;
      }
    }
    $aliases[] = $candidate[0];
    $this->save($this->getTypos(), $aliases);
    return TRUE;
  }

  /**
   * Drops the cached vocabulary/topic map so overrides apply on next request.
   */
  public function invalidateCaches(): void {
    $this->cache->delete(TypoCorrector::CACHE_KEY);
    $this->cache->delete(TopicRouter::CACHE_KEY);
  }

  /**
   * Normalizes typo entries: lowercase, trimmed, unique by misspelling.
   */
  public static function normalizeTypoEntries(array $entries): array {
    $normalized = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $misspelling = mb_strtolower(trim((string) ($entry['misspelling'] ?? '')));
      $canonical = mb_strtolower(trim((string) ($entry['canonical'] ?? '')));
      if ($misspelling === '' || $canonical === '' || $misspelling === $canonical) {
        continue;
      }
      $normalized[$misspelling] = ['misspelling' => $misspelling, 'canonical' => $canonical];
    }
    return array_values($normalized);
  }

  /**
   * Normalizes alias entries: lowercase, single-spaced, unique by phrase.
   */
  public static function normalizeAliasEntries(array $entries): array {
    $normalized = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $phrase = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($entry['phrase'] ?? ''))));
      $intent = trim((string) ($entry['topic_intent'] ?? ''));
      if ($phrase === '' || $intent === '' || mb_strlen($phrase) > self::MAX_PHRASE_LENGTH) {
        continue;
      }
      $normalized[$phrase] = ['phrase' => $phrase, 'topic_intent' => $intent];
    }
    return array_values($normalized);
  }

  /**
   * Splits text into non-empty, non-comment lines keyed by line number.
   */
  private static function lines(string $text): array {
    $lines = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $lines[$index + 1] = $line;
    }
    return $lines;
  }

  /**
   * Splits "left => right" into two trimmed lowercase parts.
   */
  private static function splitLine(string $line): ?array {
    $parts = explode(self::SEPARATOR, $line, 2);
    if (count($parts) !== 2) {
      return NULL;
    }
    $left = mb_strtolower(trim($parts[0]));
    $right = trim($parts[1]);
    if ($left === '' || $right === '') {
      return NULL;
    }
    return [$left, $right];
  }

}
