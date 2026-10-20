<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Measures how much FAQ/accordion content backs each routed sub-topic.
 *
 * Routing (topic_map.yml) can send a question to a sub-topic that has no
 * content behind it; the assistant then answers with a canned pack sentence
 * or a grounding refusal. This analyzer makes that gap measurable for every
 * sub-topic at once instead of one topic at a time by hand.
 *
 * It is deliberately coarse: a document "covers" a sub-topic when one of the
 * sub-topic's terms appears as a whole word/phrase. Heading matches (FAQ
 * question, accordion title) are strong evidence; body-only matches are weak.
 */
final class KbCoverageAnalyzer {

  public const STATUS_OK = 'ok';
  public const STATUS_THIN = 'thin';
  public const STATUS_NONE = 'none';

  /**
   * Terms shorter than this match too much unrelated text to be evidence.
   */
  private const MIN_TERM_LENGTH = 4;

  /**
   * Builds one coverage row per topic_map sub-topic.
   *
   * @param array $topic_map
   *   Parsed config/routing/topic_map.yml.
   * @param array $documents
   *   Published content rows: ['bundle' => 'faq_item'|'accordion_item',
   *   'heading' => string, 'body' => string].
   * @param string[] $pack_keys
   *   Top Intents pack keys (TopIntentsPack::getAllKeys()).
   * @param array $stubs
   *   kb-stub entries not yet imported: ['question' => string, ...].
   *
   * @return array<int, array<string, mixed>>
   *   Rows with area, topic, canonical_key, pack_intent, has_pack,
   *   faq_heading, accordion_heading, body_only, stubs_pending, status.
   */
  public static function analyze(array $topic_map, array $documents, array $pack_keys, array $stubs = []): array {
    $documents = array_map(static fn(array $doc): array => [
      'bundle' => (string) ($doc['bundle'] ?? ''),
      'heading' => self::normalize((string) ($doc['heading'] ?? '')),
      'body' => self::normalize(strip_tags((string) ($doc['body'] ?? ''))),
    ], array_filter($documents, 'is_array'));
    $stub_questions = array_map(
      static fn(array $stub): string => self::normalize((string) ($stub['question'] ?? '')),
      array_filter($stubs, 'is_array'),
    );

    $rows = [];
    foreach ($topic_map as $area_key => $area) {
      if (!is_array($area) || empty($area['topics']) || !is_array($area['topics'])) {
        continue;
      }
      foreach ($area['topics'] as $topic_key => $topic) {
        if (!is_array($topic)) {
          continue;
        }
        $canonical_key = (string) ($topic['canonical_key'] ?? ($area_key . '_' . $topic_key));
        $pack_intent = (string) ($topic['pack_intent'] ?? ('topic_' . $canonical_key));
        $pattern = self::buildPattern(self::termsFor((string) $topic_key, $topic));

        $row = [
          'area' => (string) ($area['label'] ?? $area_key),
          'topic' => (string) ($topic['term_name'] ?? $topic_key),
          'canonical_key' => $canonical_key,
          'pack_intent' => $pack_intent,
          'has_pack' => in_array($pack_intent, $pack_keys, TRUE),
          'faq_heading' => 0,
          'accordion_heading' => 0,
          'body_only' => 0,
          'stubs_pending' => 0,
        ];

        if ($pattern !== NULL) {
          foreach ($documents as $doc) {
            if (preg_match($pattern, $doc['heading'])) {
              $row[$doc['bundle'] === 'faq_item' ? 'faq_heading' : 'accordion_heading']++;
            }
            elseif (preg_match($pattern, $doc['body'])) {
              $row['body_only']++;
            }
          }
          foreach ($stub_questions as $question) {
            if (preg_match($pattern, $question)) {
              $row['stubs_pending']++;
            }
          }
        }

        $headings = $row['faq_heading'] + $row['accordion_heading'];
        $row['status'] = $headings > 0
          ? self::STATUS_OK
          : ($row['body_only'] > 0 ? self::STATUS_THIN : self::STATUS_NONE);
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * Collects the searchable terms of a sub-topic.
   *
   * @return string[]
   *   Normalized, de-duplicated terms of usable length.
   */
  public static function termsFor(string $topic_key, array $topic): array {
    $terms = array_merge(
      [str_replace('_', ' ', $topic_key), (string) ($topic['term_name'] ?? '')],
      is_array($topic['aliases'] ?? NULL) ? $topic['aliases'] : [],
    );
    $terms = array_map(static fn($term): string => self::normalize((string) $term), $terms);
    $terms = array_filter($terms, static fn(string $term): bool => mb_strlen($term) >= self::MIN_TERM_LENGTH);
    return array_values(array_unique($terms));
  }

  /**
   * Builds one whole-word alternation regex for a sub-topic's terms.
   */
  private static function buildPattern(array $terms): ?string {
    if ($terms === []) {
      return NULL;
    }
    // Longest first so multi-word phrases win over their own substrings.
    usort($terms, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    $quoted = array_map(static fn(string $term): string => preg_quote($term, '/'), $terms);
    return '/(?<![\p{L}\p{N}])(?:' . implode('|', $quoted) . ')(?![\p{L}\p{N}])/u';
  }

  /**
   * Lowercases and collapses whitespace.
   */
  private static function normalize(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))));
  }

}
