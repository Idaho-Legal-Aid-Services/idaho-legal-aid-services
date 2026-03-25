<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ObservabilityProofTaxonomy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for AFRP-12 observability proof taxonomy.
 *
 * Validates that the proof taxonomy constants, probe commands, and
 * documentation are consistent and prevent proof-level inflation.
 */
#[Group('ilas_site_assistant')]
class ObservabilityProofTaxonomyTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file after asserting it exists.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Tests that the taxonomy class exists with all required constants.
   */
  public function testTaxonomyClassExists(): void {
    $this->assertTrue(class_exists(ObservabilityProofTaxonomy::class));
    $this->assertNotEmpty(ObservabilityProofTaxonomy::LEVELS_ORDERED);
    $this->assertNotEmpty(ObservabilityProofTaxonomy::TOOL_MAX_PROOF);
    $this->assertNotEmpty(ObservabilityProofTaxonomy::CLAIM_MIN_PROOF);
  }

  /**
   * Tests that LEVELS_ORDERED contains exactly 7 levels, L0 first, L6 last.
   */
  public function testLevelsOrderedIsComplete(): void {
    $levels = ObservabilityProofTaxonomy::LEVELS_ORDERED;
    $this->assertCount(7, $levels, 'Taxonomy must define exactly 7 proof levels (L0-L6)');
    $this->assertSame(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, $levels[0], 'L0:Unverified must be first (weakest)');
    $this->assertSame(ObservabilityProofTaxonomy::LEVEL_L6_OWNERSHIP, $levels[6], 'L6:Ownership must be last (strongest)');
  }

  /**
   * Tests that tool max proof ceilings match their true capabilities.
   */
  public function testToolMaxProofCeilings(): void {
    $map = ObservabilityProofTaxonomy::TOOL_MAX_PROOF;

    // Sentry probe: L1 (no account-side API exists).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
      $map[ObservabilityProofTaxonomy::TOOL_SENTRY_PROBE],
      'Sentry probe ceiling must be L1:Transport (no account-side lookup)'
    );

    // Langfuse direct: L3 (HTTP 207 proves acceptance, not visibility).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE,
      $map[ObservabilityProofTaxonomy::TOOL_LANGFUSE_PROBE_DIRECT],
      'Langfuse direct probe ceiling must be L3:PayloadAcceptance'
    );

    // Langfuse queued: L2 (enqueue proves queue drain, not delivery).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L2_QUEUE_DRAIN,
      $map[ObservabilityProofTaxonomy::TOOL_LANGFUSE_PROBE_QUEUED],
      'Langfuse queued probe ceiling must be L2:QueueDrain'
    );

    // Langfuse lookup: L4 (proves account-side trace exists).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L4_ACCOUNT_SIDE,
      $map[ObservabilityProofTaxonomy::TOOL_LANGFUSE_LOOKUP],
      'Langfuse lookup ceiling must be L4:AccountSide'
    );

    // Langfuse status: L1 (config and queue health, not delivery proof).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
      $map[ObservabilityProofTaxonomy::TOOL_LANGFUSE_STATUS],
      'Langfuse status ceiling must be L1:Transport'
    );

    // Langfuse diagnose: L0 (no probe sent).
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      $map[ObservabilityProofTaxonomy::TOOL_LANGFUSE_DIAGNOSE],
      'Langfuse diagnose ceiling must be L0:Unverified'
    );
  }

  /**
   * Tests that "operational" claims require L4 (account-side) minimum.
   */
  public function testOperationalClaimRequiresAccountSide(): void {
    $min = ObservabilityProofTaxonomy::CLAIM_MIN_PROOF['operational'];
    $this->assertTrue(
      ObservabilityProofTaxonomy::meetsMinimum($min, ObservabilityProofTaxonomy::LEVEL_L4_ACCOUNT_SIDE),
      '"operational" claim minimum must be >= L4:AccountSide'
    );
  }

  /**
   * Tests that "fully_operationalized" claims require L6 (ownership).
   */
  public function testFullyOperationalizedRequiresOwnership(): void {
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L6_OWNERSHIP,
      ObservabilityProofTaxonomy::CLAIM_MIN_PROOF['fully_operationalized'],
      '"fully_operationalized" claim must require L6:Ownership'
    );
  }

  /**
   * Tests that meetsMinimum rejects weaker levels.
   */
  public function testMeetsMinimumRejectsWeaker(): void {
    $this->assertFalse(
      ObservabilityProofTaxonomy::meetsMinimum(
        ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
        ObservabilityProofTaxonomy::LEVEL_L4_ACCOUNT_SIDE,
      ),
      'L1:Transport must NOT meet L4:AccountSide requirement'
    );
  }

  /**
   * Tests that meetsMinimum accepts stronger levels.
   */
  public function testMeetsMinimumAcceptsStronger(): void {
    $this->assertTrue(
      ObservabilityProofTaxonomy::meetsMinimum(
        ObservabilityProofTaxonomy::LEVEL_L4_ACCOUNT_SIDE,
        ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
      ),
      'L4:AccountSide must meet L1:Transport requirement'
    );
  }

  /**
   * Tests that L0:Unverified is the default (first in LEVELS_ORDERED).
   */
  public function testUnverifiedIsDefault(): void {
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      ObservabilityProofTaxonomy::LEVELS_ORDERED[0],
      'L0:Unverified must be the first (default) level'
    );
  }

  /**
   * Tests that SentryProbeCommands references the L1 proof level constant.
   */
  public function testSentryProbeEmitsProofLevel(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Commands/SentryProbeCommands.php'
    );

    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT',
      $source,
      'SentryProbeCommands must reference LEVEL_L1_TRANSPORT for proof-level output'
    );
    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED',
      $source,
      'SentryProbeCommands must reference LEVEL_L0_UNVERIFIED for error paths'
    );
  }

  /**
   * Tests that LangfuseProbeCommands references proof level constants.
   */
  public function testLangfuseProbeEmitsProofLevel(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Commands/LangfuseProbeCommands.php'
    );

    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE',
      $source,
      'LangfuseProbeCommands must reference LEVEL_L3_PAYLOAD_ACCEPTANCE for direct probe'
    );
    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L2_QUEUE_DRAIN',
      $source,
      'LangfuseProbeCommands must reference LEVEL_L2_QUEUE_DRAIN for queued probe'
    );
    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED',
      $source,
      'LangfuseProbeCommands must reference LEVEL_L0_UNVERIFIED for diagnose/error paths'
    );
  }

  /**
   * Tests that evidence-index.md contains AFRP-12 claims.
   */
  public function testEvidenceIndexContainsAfrp12(): void {
    $source = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('AFRP-12', $source, 'evidence-index.md must reference AFRP-12');
    $this->assertStringContainsString('CLAIM-266', $source, 'evidence-index.md must contain CLAIM-266');
    $this->assertStringContainsString('CLAIM-267', $source, 'evidence-index.md must contain CLAIM-267');
    $this->assertStringContainsString('CLAIM-268', $source, 'evidence-index.md must contain CLAIM-268');
    $this->assertStringContainsString('ObservabilityProofTaxonomy', $source, 'evidence-index.md must reference ObservabilityProofTaxonomy');
  }

  /**
   * Tests that runbook.md references the proof taxonomy.
   */
  public function testRunbookReferencesProofTaxonomy(): void {
    $source = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('AFRP-12', $source, 'runbook.md must reference AFRP-12');
    $this->assertStringContainsString('ObservabilityProofTaxonomy', $source, 'runbook.md must reference ObservabilityProofTaxonomy');
    $this->assertStringContainsString('L0:Unverified', $source, 'runbook.md must define L0:Unverified');
    $this->assertStringContainsString('L4:AccountSide', $source, 'runbook.md must define L4:AccountSide');
    $this->assertStringContainsString('L6:Ownership', $source, 'runbook.md must define L6:Ownership');
  }

  /**
   * Tests that current-state.md references proof levels.
   */
  public function testCurrentStateReferencesProofLevels(): void {
    $source = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('AFRP-12 proof taxonomy', $source, 'current-state.md must reference AFRP-12 proof taxonomy');
    $this->assertStringContainsString('L1:Transport', $source, 'current-state.md must reference L1:Transport for Sentry');
    $this->assertStringContainsString('L3:PayloadAcceptance', $source, 'current-state.md must reference L3:PayloadAcceptance for Langfuse');
  }

  /**
   * Tests that the runtime artifact exists and contains required sections.
   */
  public function testRuntimeArtifactExists(): void {
    $source = self::readFile('docs/aila/runtime/afrp-12-observability-proof-standard.txt');

    $this->assertStringContainsString('AFRP-12', $source, 'Runtime artifact must reference AFRP-12');
    $this->assertStringContainsString('Prior observability-proof standard', $source, 'Runtime artifact must document prior standard');
    $this->assertStringContainsString('Post-change observability-proof taxonomy', $source, 'Runtime artifact must document post-change taxonomy');
    $this->assertStringContainsString('Proof-strength matrix by tool', $source, 'Runtime artifact must include proof-strength matrix');
    $this->assertStringContainsString('Still-unverified surfaces', $source, 'Runtime artifact must document still-unverified surfaces');
    $this->assertStringContainsString('L0:Unverified', $source, 'Runtime artifact must define L0');
    $this->assertStringContainsString('L6:Ownership', $source, 'Runtime artifact must define L6');
  }

  // -- AFRP-16: Runtime diagnostics constants ---------------------------

  /**
   * Tests that runtime-diagnostics tool ceiling is L0 (read-only, no probes).
   */
  public function testRuntimeDiagnosticsToolCeiling(): void {
    $map = ObservabilityProofTaxonomy::TOOL_MAX_PROOF;

    $this->assertArrayHasKey(
      ObservabilityProofTaxonomy::TOOL_RUNTIME_DIAGNOSTICS,
      $map,
      'TOOL_MAX_PROOF must include runtime-diagnostics tool'
    );
    $this->assertSame(
      ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      $map[ObservabilityProofTaxonomy::TOOL_RUNTIME_DIAGNOSTICS],
      'Runtime diagnostics ceiling must be L0:Unverified (no probes sent)'
    );
  }

  /**
   * Tests that FACT_CATEGORIES constant exists and is non-empty.
   */
  public function testFactCategoriesConstantExists(): void {
    $this->assertNotEmpty(
      ObservabilityProofTaxonomy::FACT_CATEGORIES,
      'FACT_CATEGORIES must be non-empty'
    );
    $this->assertContains('toggle', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('credential', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('index', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('server', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('integration', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('url', ObservabilityProofTaxonomy::FACT_CATEGORIES);
    $this->assertContains('slo', ObservabilityProofTaxonomy::FACT_CATEGORIES);
  }

  /**
   * Tests that all four assertion constants exist.
   */
  public function testAssertionConstantsExist(): void {
    $this->assertSame('pass', ObservabilityProofTaxonomy::ASSERTION_PASS);
    $this->assertSame('fail', ObservabilityProofTaxonomy::ASSERTION_FAIL);
    $this->assertSame('degraded', ObservabilityProofTaxonomy::ASSERTION_DEGRADED);
    $this->assertSame('skipped', ObservabilityProofTaxonomy::ASSERTION_SKIPPED);
  }

  /**
   * Tests that RuntimeDiagnosticsCommands references the proof level constant.
   */
  public function testRuntimeDiagnosticsCommandReferencesProofLevel(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Commands/RuntimeDiagnosticsCommands.php'
    );

    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED',
      $source,
      'RuntimeDiagnosticsCommands must reference LEVEL_L0_UNVERIFIED for proof-level output'
    );
    $this->assertStringContainsString(
      'ObservabilityProofTaxonomy::ASSERTION_FAIL',
      $source,
      'RuntimeDiagnosticsCommands must reference ASSERTION_FAIL for exit code logic'
    );
  }

}
