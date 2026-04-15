<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P2-NDO-02: no broad platform migration outside Pantheon baseline.
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoNoBroadPlatformMigrationGuardTest extends TestCase {

  use DiagramAQualityGateAssertionsTrait;

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
   * Roadmap must contain dated P2-NDO-02 disposition.
   */
  public function testRoadmapContainsPhaseTwoNdo02Disposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 NDO #2 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-146', $roadmap);
    $this->assertStringContainsString('PhaseTwoNoBroadPlatformMigrationGuardTest.php', $roadmap);
    $this->assertStringContainsString('phase2-ndo2-no-broad-platform-migration.txt', $roadmap);
  }

  /**
   * Current-state must include dated P2-NDO-02 addendum.
   */
  public function testCurrentStateContainsPhaseTwoNdo02Addendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 NDO #2 No Broad Platform Migration Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('P2-NDO-02', $currentState);
    $this->assertStringContainsString('phase2-ndo2-no-broad-platform-migration.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-146]', $currentState);
  }

  /**
   * Evidence index must include CLAIM-146 for P2-NDO-02 closure.
   */
  public function testEvidenceIndexContainsClaim146(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-146', $evidenceIndex);
    $this->assertStringContainsString('P2-NDO-02', $evidenceIndex);
    $this->assertStringContainsString('CLAIM-115', $evidenceIndex);
    $this->assertStringContainsString('CLAIM-119', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoNoBroadPlatformMigrationGuardTest.php', $evidenceIndex);
  }

  /**
   * Runbook must include P2-NDO-02 verification bundle.
   */
  public function testRunbookContainsPhaseTwoNdo02VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 NDO #2 no broad platform migration verification (`P2-NDO-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('PhaseTwoNoBroadPlatformMigrationGuardTest.php', $runbook);
    $this->assertStringContainsString('phase2-ndo2-no-broad-platform-migration.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-146]', $runbook);
  }

  /**
   * Runtime artifact must contain required proof markers.
   */
  public function testRuntimeArtifactContainsPhaseTwoNdo02ProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-ndo2-no-broad-platform-migration.txt');

    $this->assertStringContainsString('VC-TOGGLE-CHECK', $artifact);
    $this->assertStringContainsString('guard-anchor-pantheon-yml=present', $artifact);
    $this->assertStringContainsString('guard-anchor-pantheon-upstream=present', $artifact);
    $this->assertStringContainsString('guard-anchor-settings-pantheon-include=present', $artifact);
    $this->assertStringContainsString('guard-anchor-system-map-diagram-a=present', $artifact);
    $this->assertStringContainsString('p2-ndo-02-status=closed', $artifact);
    $this->assertStringContainsString('p2-ndo-02-enforcement=guard-test+platform-baseline-anchors', $artifact);
    $this->assertStringContainsString('p2-ndo-02-scope=boundary-enforcement-artifacts-only', $artifact);
  }

  /**
   * Pantheon baseline anchors must remain present in baseline files.
   */
  public function testPantheonBaselineAnchorsRemainPresent(): void {
    $pantheon = self::readFile('pantheon.yml');
    $upstream = self::readFile('pantheon.upstream.yml');
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString('api_version: 1', $pantheon);

    $this->assertStringContainsString('web_docroot: true', $upstream);
    $this->assertStringContainsString('php_version: 8.3', $upstream);
    $this->assertStringContainsString('database:', $upstream);
    $this->assertStringContainsString('version: 10.6', $upstream);
    $this->assertStringContainsString('build_step: true', $upstream);
    $this->assertStringContainsString('protected_web_paths:', $upstream);

    $this->assertStringContainsString('include __DIR__ . "/settings.pantheon.php";', $settings);
    $this->assertStringContainsString('PANTHEON_ENVIRONMENT', $settings);
    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_ENABLED')", $settings);
    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['vector_search']['enabled'] = FALSE;", $settings);
  }

  /**
   * Diagram A anchors must remain present in the system map.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertCurrentDiagramAQualityGateAnchors($systemMap);
  }

  /**
   * VC-TOGGLE-CHECK alias must remain in implementation-prompt-pack.
   */
  public function testImplementationPromptPackRetainsVcToggleCheckAlias(): void {
    $promptPack = self::readFile('docs/aila/implementation-prompt-pack.md');

    $this->assertStringContainsString('| `VC-TOGGLE-CHECK` |', $promptPack);
    $this->assertStringContainsString(
      'llm.enabled|vector_search|rate_limit_per_minute|conversation_logging',
      $promptPack
    );
  }

}
