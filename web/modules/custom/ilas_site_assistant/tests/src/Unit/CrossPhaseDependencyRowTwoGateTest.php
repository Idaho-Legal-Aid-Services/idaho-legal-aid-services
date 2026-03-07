<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #2 closure artifacts (`XDP-02`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowTwoGateTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Roadmap must retain row #2 and a dated XDP-02 disposition.
   */
  public function testRoadmapContainsRowTwoAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| Config parity (`IMP-CONF-01`) | Schema mapping + env drift checks | Phase 0 -> prerequisite for Phase 2 retrieval tuning | Drupal Lead |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #2 disposition (2026-03-06)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream retrieval-tuning work is',
      $roadmap
    );
    $this->assertStringContainsString(
      'blocked whenever unresolved dependency count is non-zero',
      $roadmap
    );
    $this->assertStringContainsString(
      'phase0-xdp02-config-parity-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-02 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp02AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #2 Config Parity Guardrail Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-02`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite', $currentState);
    $this->assertStringContainsString('`xdp-02-status=blocked`', $currentState);
    $this->assertStringContainsString('pass reports', $currentState);
    $this->assertStringContainsString('`xdp-02-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-02-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('phase0-xdp02-config-parity-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-161]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-02.
   */
  public function testRunbookContainsXdp02VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #2 config parity verification (`XDP-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-02-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-02-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-02-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('phase0-xdp02-config-parity-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-161]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-124 addendum plus CLAIM-161 section.
   */
  public function testEvidenceIndexContainsXdp02ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-124', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Cross-phase dependency row #2 (`XDP-02`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #2 Config Parity Guardrail (`XDP-02`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-161', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase0-xdp02-config-parity-dependency-gate.txt');

    $this->assertStringContainsString('xdp-02-status=closed', $artifact);
    $this->assertStringContainsString('xdp-02-workstream=IMP-CONF-01', $artifact);
    $this->assertStringContainsString('xdp-02-owner-role=Drupal Lead', $artifact);
    $this->assertStringContainsString('xdp-02-consumed-in=Phase 0 -> prerequisite for Phase 2 retrieval tuning', $artifact);
    $this->assertStringContainsString('dependency.schema-mapping=pass', $artifact);
    $this->assertStringContainsString('dependency.env-drift-checks=pass', $artifact);
    $this->assertStringContainsString('xdp-02-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-02-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-02 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in source/tests/docs.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $schema = self::readFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $vectorSearchSchemaTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/VectorSearchConfigSchemaTest.php');
    $configDriftTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/ConfigCompletenessDriftTest.php');
    $runbook = self::readFile('docs/aila/runbook.md');
    $phaseTwoEntryTwoRuntime = self::readFile('docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('vector_search:', $schema);
    $this->assertStringContainsString('fallback_gate:', $schema);

    $this->assertStringContainsString('testSchemaCoversAllInstallDefaultKeys', $vectorSearchSchemaTest);
    $this->assertStringContainsString('testActiveVectorSearchValuesMatchInstallDefaults', $vectorSearchSchemaTest);
    $this->assertStringContainsString('testActiveConfigContainsAllInstallTopLevelKeys', $configDriftTest);
    $this->assertStringContainsString('testSchemaCoversAllInstallTopLevelKeys', $configDriftTest);

    $this->assertStringContainsString('### Config parity + drift checks (`IMP-CONF-01`)', $runbook);
    $this->assertStringContainsString('vector-search-drift-report.txt', $runbook);
    $this->assertStringContainsString('for ENV in dev test live; do', $runbook);

    $this->assertStringContainsString('Config Parity + Retrieval Tuning Stability Verification', $phaseTwoEntryTwoRuntime);
    $this->assertStringContainsString('vector_search', $phaseTwoEntryTwoRuntime);
    $this->assertStringContainsString('fallback_gate', $phaseTwoEntryTwoRuntime);

    $this->assertStringContainsString('Search API + optional vector', $systemMap);
    $this->assertStringContainsString('drift + overdue monitoring', $systemMap);
  }

}
