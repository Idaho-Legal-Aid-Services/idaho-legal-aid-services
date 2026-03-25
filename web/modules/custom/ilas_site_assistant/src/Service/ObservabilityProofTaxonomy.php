<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Observability proof-level taxonomy constants (AFRP-12).
 *
 * Defines the proof-strength levels that separate transport reachability from
 * trustworthy signal coverage. Each level is strictly stronger than the one
 * below it. Contract tests validate that probe commands, report claims, and
 * documentation all reference this taxonomy consistently.
 *
 * Design: constants-only frozen class, no constructor, no DI. Matches the
 * LangfusePayloadContract pattern.
 */
final class ObservabilityProofTaxonomy {

  // -- Proof levels (monotonically increasing strength) --------------------

  /**
   * L0: No probe or check executed; default for unproven claims.
   */
  const LEVEL_L0_UNVERIFIED = 'L0:Unverified';

  /**
   * L1: HTTP connection or SDK call succeeded (e.g., exit code 0).
   */
  const LEVEL_L1_TRANSPORT = 'L1:Transport';

  /**
   * L2: Queue item dequeued and processed by worker.
   */
  const LEVEL_L2_QUEUE_DRAIN = 'L2:QueueDrain';

  /**
   * L3: Remote API accepted the payload (e.g., HTTP 207 partial success).
   */
  const LEVEL_L3_PAYLOAD_ACCEPTANCE = 'L3:PayloadAcceptance';

  /**
   * L4: Trace/event findable in SaaS dashboard or API.
   */
  const LEVEL_L4_ACCOUNT_SIDE = 'L4:AccountSide';

  /**
   * L5: Alerts route to a channel and fire on threshold.
   */
  const LEVEL_L5_ALERTABILITY = 'L5:Alertability';

  /**
   * L6: Named responder, review cadence, triage SLA documented.
   */
  const LEVEL_L6_OWNERSHIP = 'L6:Ownership';

  /**
   * Ordered proof levels from weakest to strongest.
   */
  const LEVELS_ORDERED = [
    self::LEVEL_L0_UNVERIFIED,
    self::LEVEL_L1_TRANSPORT,
    self::LEVEL_L2_QUEUE_DRAIN,
    self::LEVEL_L3_PAYLOAD_ACCEPTANCE,
    self::LEVEL_L4_ACCOUNT_SIDE,
    self::LEVEL_L5_ALERTABILITY,
    self::LEVEL_L6_OWNERSHIP,
  ];

  // -- Tool identifiers ---------------------------------------------------

  const TOOL_SENTRY_PROBE = 'sentry-probe';
  const TOOL_LANGFUSE_PROBE_DIRECT = 'langfuse-probe-direct';
  const TOOL_LANGFUSE_PROBE_QUEUED = 'langfuse-probe-queued';
  const TOOL_LANGFUSE_LOOKUP = 'langfuse-lookup';
  const TOOL_LANGFUSE_STATUS = 'langfuse-status';
  const TOOL_LANGFUSE_DIAGNOSE = 'langfuse-diagnose';
  const TOOL_RUNTIME_DIAGNOSTICS = 'runtime-diagnostics';

  /**
   * Maximum proof level each tool can achieve.
   *
   * A tool MUST NOT claim a higher level than its ceiling. Contract tests
   * enforce that probe output references only its declared ceiling or below.
   */
  const TOOL_MAX_PROOF = [
    self::TOOL_SENTRY_PROBE => self::LEVEL_L1_TRANSPORT,
    self::TOOL_LANGFUSE_PROBE_DIRECT => self::LEVEL_L3_PAYLOAD_ACCEPTANCE,
    self::TOOL_LANGFUSE_PROBE_QUEUED => self::LEVEL_L2_QUEUE_DRAIN,
    self::TOOL_LANGFUSE_LOOKUP => self::LEVEL_L4_ACCOUNT_SIDE,
    self::TOOL_LANGFUSE_STATUS => self::LEVEL_L1_TRANSPORT,
    self::TOOL_LANGFUSE_DIAGNOSE => self::LEVEL_L0_UNVERIFIED,
    self::TOOL_RUNTIME_DIAGNOSTICS => self::LEVEL_L0_UNVERIFIED,
  ];

  /**
   * Minimum proof level required for each class of report claim.
   *
   * A report MUST NOT assert a claim unless evidence at or above the minimum
   * level exists. "Unverified" must be used when the minimum is not met.
   */
  const CLAIM_MIN_PROOF = [
    'transport_healthy' => self::LEVEL_L1_TRANSPORT,
    'queue_healthy' => self::LEVEL_L2_QUEUE_DRAIN,
    'signal_trusted' => self::LEVEL_L4_ACCOUNT_SIDE,
    'operational' => self::LEVEL_L4_ACCOUNT_SIDE,
    'fully_operationalized' => self::LEVEL_L6_OWNERSHIP,
  ];

  /**
   * Diagnostic fact categories for runtime-diagnostics matrix rows.
   */
  const FACT_CATEGORIES = [
    'toggle',
    'credential',
    'index',
    'server',
    'integration',
    'url',
    'slo',
  ];

  const ASSERTION_PASS = 'pass';
  const ASSERTION_FAIL = 'fail';
  const ASSERTION_DEGRADED = 'degraded';
  const ASSERTION_SKIPPED = 'skipped';

  /**
   * Returns the zero-based index of a proof level in LEVELS_ORDERED.
   *
   * @param string $level
   *   A LEVEL_L* constant value.
   *
   * @return int
   *   The index (0 = weakest), or -1 if not found.
   */
  public static function levelIndex(string $level): int {
    $index = array_search($level, self::LEVELS_ORDERED, TRUE);
    return $index === FALSE ? -1 : $index;
  }

  /**
   * Tests whether an achieved proof level meets a required minimum.
   *
   * @param string $achieved
   *   The proof level that was actually demonstrated.
   * @param string $required
   *   The minimum proof level required for the claim.
   *
   * @return bool
   *   TRUE if achieved >= required in the LEVELS_ORDERED ordering.
   */
  public static function meetsMinimum(string $achieved, string $required): bool {
    return self::levelIndex($achieved) >= self::levelIndex($required);
  }

  /**
   * Returns a human-readable label for a proof level.
   *
   * @param string $level
   *   A LEVEL_L* constant value.
   *
   * @return string
   *   Human-readable description.
   */
  public static function proofStrengthLabel(string $level): string {
    return match ($level) {
      self::LEVEL_L0_UNVERIFIED => 'Unverified (no probe executed)',
      self::LEVEL_L1_TRANSPORT => 'Transport reachability (HTTP/SDK call succeeded)',
      self::LEVEL_L2_QUEUE_DRAIN => 'Queue drain (item dequeued and processed)',
      self::LEVEL_L3_PAYLOAD_ACCEPTANCE => 'Payload acceptance (remote API accepted payload)',
      self::LEVEL_L4_ACCOUNT_SIDE => 'Account-side visibility (trace/event findable in SaaS)',
      self::LEVEL_L5_ALERTABILITY => 'Alertability (alerts route and fire)',
      self::LEVEL_L6_OWNERSHIP => 'Ownership (named responder, review cadence, triage SLA)',
      default => 'Unknown proof level',
    };
  }

}
