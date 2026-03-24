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
 * Enforcement rationale: Governance flags never suppress retrieval results.
 * In a legal aid context, hiding content (even stale content) is more harmful
 * than showing it — vulnerable users may lose access to critical resources.
 *
 * Enforcement operates in three tiers:
 * - HARD: structural/security boundaries that throw or nullify (source class,
 *   citation URL sanitization).
 * - SOFT: signals that change response metadata or caveats without hiding
 *   content (requires_review, all_citations_stale, grounding_weak).
 * - ADVISORY: flags for operator monitoring only, no behavioral effect
 *   (per-item stale_source, unknown_freshness, missing_source_url, health
 *   degradation).
 *
 * @see GOVERNANCE_ENFORCEMENT_MATRIX for the exhaustive signal-to-action map.
 */
final class RetrievalContract {

  /**
   * Enforcement level: structural/security boundary (throws or nullifies).
   */
  public const ENFORCEMENT_HARD = 'hard';

  /**
   * Enforcement level: modifies response metadata/caveats without hiding content.
   */
  public const ENFORCEMENT_SOFT = 'soft';

  /**
   * Enforcement level: operator-visible flag only, no behavioral change.
   */
  public const ENFORCEMENT_ADVISORY = 'advisory';

  /**
   * Exhaustive governance signal-to-enforcement map.
   *
   * Every governance signal the pipeline can produce maps to exactly one
   * enforcement level. Tests validate that the matrix is exhaustive and
   * that each level's behavioral contract holds.
   */
  public const GOVERNANCE_ENFORCEMENT_MATRIX = [
    'unapproved_source_class' => [
      'level' => self::ENFORCEMENT_HARD,
      'action' => 'InvalidArgumentException thrown by assertApprovedSourceClass()',
      'rationale' => 'Structural integrity: only approved source classes enter the pipeline.',
    ],
    'unsafe_citation_url' => [
      'level' => self::ENFORCEMENT_HARD,
      'action' => 'Citation URL nullified by sanitizeCitationUrl()',
      'rationale' => 'Security: prevent XSS/redirect via citation URLs.',
    ],
    'requires_review' => [
      'level' => self::ENFORCEMENT_SOFT,
      'action' => 'Response message replaced with safe fallback in enforcePostGenerationSafety()',
      'rationale' => 'Legal safety: legal-advice patterns must not reach users.',
    ],
    'all_citations_stale' => [
      'level' => self::ENFORCEMENT_SOFT,
      'action' => 'freshness_caveat added to response body in enforcePostGenerationSafety()',
      'rationale' => 'Transparency: users know when cited sources may be outdated.',
    ],
    'grounding_weak' => [
      'level' => self::ENFORCEMENT_SOFT,
      'action' => 'Confidence capped at 0.3; grounding refusal if confidence <= 0.5 in assembleContractFields()',
      'rationale' => 'Accuracy: answerable types without citations should not appear high-confidence.',
    ],
    'stale_source' => [
      'level' => self::ENFORCEMENT_ADVISORY,
      'action' => 'Flag in governance_flags; feeds aggregate _all_citations_stale signal.',
      'rationale' => 'Legal aid: stale content is better than no content for vulnerable users.',
    ],
    'unknown_freshness' => [
      'level' => self::ENFORCEMENT_ADVISORY,
      'action' => 'Flag in governance_flags; contributes to health snapshot.',
      'rationale' => 'Operational awareness without user-facing impact.',
    ],
    'missing_source_url' => [
      'level' => self::ENFORCEMENT_ADVISORY,
      'action' => 'Flag in governance_flags; content served but citation URL excluded.',
      'rationale' => 'Missing URL prevents citation but not content delivery.',
    ],
    'invalid_source_url' => [
      'level' => self::ENFORCEMENT_ADVISORY,
      'action' => 'Flag in governance_flags; URL already nullified by unsafe_citation_url HARD enforcement.',
      'rationale' => 'Informational flag marking items whose URLs were sanitized away.',
    ],
    'health_degraded' => [
      'level' => self::ENFORCEMENT_ADVISORY,
      'action' => 'Status propagated to /health endpoint and response governance metadata.',
      'rationale' => 'Operator awareness; response behavior unchanged for end users.',
    ],
  ];

  /**
   * Returns the enforcement level for a governance signal.
   *
   * @param string $signal
   *   Governance signal name (e.g. 'stale_source', 'requires_review').
   *
   * @return string
   *   One of ENFORCEMENT_HARD, ENFORCEMENT_SOFT, or ENFORCEMENT_ADVISORY.
   *
   * @throws \InvalidArgumentException
   *   When the signal is not in the enforcement matrix.
   */
  public static function getEnforcementLevel(string $signal): string {
    if (!isset(self::GOVERNANCE_ENFORCEMENT_MATRIX[$signal])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown governance signal "%s". Known: [%s].',
        $signal,
        implode(', ', array_keys(self::GOVERNANCE_ENFORCEMENT_MATRIX)),
      ));
    }
    return self::GOVERNANCE_ENFORCEMENT_MATRIX[$signal]['level'];
  }

  /**
   * Source priority tiers (lower = higher priority).
   */
  public const SOURCE_PRIORITY_LEXICAL = 1;
  public const SOURCE_PRIORITY_VECTOR = 2;
  public const SOURCE_PRIORITY_LEGACY = 3;

  /**
   * Retrieval method: Search API index query.
   */
  public const RETRIEVAL_METHOD_SEARCH_API = 'search_api';

  /**
   * Retrieval method: direct entity query (legacy fallback).
   */
  public const RETRIEVAL_METHOD_LEGACY = 'entity_query';

  /**
   * Retrieval method: external vector API (e.g. Pinecone).
   */
  public const RETRIEVAL_METHOD_VECTOR = 'vector_api';

  /**
   * Retrieval methods that do not use a Search API index.
   *
   * Results from these methods must not claim Search API provenance.
   */
  public const NON_INDEX_RETRIEVAL_METHODS = [
    'entity_query',
  ];

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
