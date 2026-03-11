<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Formalizes the Drupal-primary retrieval contract.
 *
 * Defines architectural invariants for the retrieval pipeline:
 * - Source priority tiers (lexical before vector).
 * - Approved source classes (exhaustive allowlist).
 * - Merge constraints (lexical priority boost, minimum preservation).
 *
 * This is a pure-value contract class with constants and static validators.
 * No Drupal service dependencies. Runtime config (operator tuning) lives in
 * RetrievalConfigurationService; this class defines compile-time invariants.
 *
 * Advisory enforcement rationale: Governance flags inform operators but never
 * suppress results. In a legal aid context, hiding content (even stale content)
 * is more harmful than showing it — vulnerable users may lose access to critical
 * resources. The enforcement point is source class validation (throws on
 * unapproved), not freshness blocking.
 */
final class RetrievalContract {

  /**
   * Source priority tiers (lower = higher priority).
   */
  public const SOURCE_PRIORITY_LEXICAL = 1;
  public const SOURCE_PRIORITY_VECTOR = 2;
  public const SOURCE_PRIORITY_LEGACY = 3;

  /**
   * Approved source classes (exhaustive).
   */
  public const APPROVED_SOURCE_CLASSES = [
    'faq_lexical',
    'faq_vector',
    'resource_lexical',
    'resource_vector',
  ];

  /**
   * Primary (Drupal-authoritative) source classes.
   */
  public const PRIMARY_SOURCE_CLASSES = [
    'faq_lexical',
    'resource_lexical',
  ];

  /**
   * Supplement-only source classes (vector enrichment).
   */
  public const SUPPLEMENT_SOURCE_CLASSES = [
    'faq_vector',
    'resource_vector',
  ];

  /**
   * Tie-break boost for lexical items during duplicate comparison.
   *
   * Applied to comparison score only — stored score is not modified.
   * Assumes BM25 scores where 5 points is a meaningful tie-break.
   */
  public const LEXICAL_PRIORITY_BOOST = 5;

  /**
   * Minimum lexical results preserved in merge output.
   *
   * If the input contained lexical results but the merge output has fewer
   * than this count, the lowest-scoring vector result is replaced.
   */
  public const MIN_LEXICAL_PRESERVED = 1;

  /**
   * Policy version identifier for provenance tracking.
   */
  public const POLICY_VERSION = 'phard_06_v1';

  /**
   * Asserts that a source class is in the approved allowlist.
   *
   * @param string $source_class
   *   The source class to validate.
   *
   * @throws \InvalidArgumentException
   *   When the source class is not approved.
   */
  public static function assertApprovedSourceClass(string $source_class): void {
    if (!in_array($source_class, self::APPROVED_SOURCE_CLASSES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Unapproved retrieval source class "%s". Approved: [%s].',
        $source_class,
        implode(', ', self::APPROVED_SOURCE_CLASSES),
      ));
    }
  }

  /**
   * Checks if a source class is a primary (lexical) source.
   *
   * @param string $source_class
   *   The source class to check.
   *
   * @return bool
   *   TRUE if the source class is primary.
   */
  public static function isPrimarySource(string $source_class): bool {
    return in_array($source_class, self::PRIMARY_SOURCE_CLASSES, TRUE);
  }

  /**
   * Checks if a source class is a supplement (vector) source.
   *
   * @param string $source_class
   *   The source class to check.
   *
   * @return bool
   *   TRUE if the source class is a supplement source.
   */
  public static function isSupplementSource(string $source_class): bool {
    return in_array($source_class, self::SUPPLEMENT_SOURCE_CLASSES, TRUE);
  }

}
