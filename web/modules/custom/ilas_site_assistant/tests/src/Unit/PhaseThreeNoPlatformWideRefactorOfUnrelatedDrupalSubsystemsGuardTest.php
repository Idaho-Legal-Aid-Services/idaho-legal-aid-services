<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P3-NDO-02 boundary: no platform-wide refactor of unrelated subsystems.
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest extends TestCase {

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
   * Roadmap must contain dated P3-NDO-02 disposition.
   */
  public function testRoadmapContainsPhaseThreeNdo02Disposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 NDO #2 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
    $this->assertStringContainsString('boundary enforcement only', $roadmap);
    $this->assertStringContainsString('CLAIM-159', $roadmap);
    $this->assertStringContainsString('PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php', $roadmap);
    $this->assertStringContainsString('phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt', $roadmap);
  }

  /**
   * Current-state must include dated P3-NDO-02 addendum.
   */
  public function testCurrentStateContainsPhaseThreeNdo02Addendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 NDO #2 No Platform-Wide Refactor of Unrelated Drupal Subsystems Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-NDO-02`', $currentState);
    $this->assertStringContainsString('PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php', $currentState);
    $this->assertStringContainsString('phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-159]', $currentState);
  }

  /**
   * Evidence index must include CLAIM-010 addendum and CLAIM-159 boundary section.
   */
  public function testEvidenceIndexContainsClaim010AddendumAndClaim159Section(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-010', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Phase 3 NDO #2 (`P3-NDO-02`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 NDO #2 No Platform-Wide Refactor of Unrelated Drupal Subsystems Boundary (`P3-NDO-02`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-159', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php', $evidenceIndex);
  }

  /**
   * Runbook must include P3-NDO-02 verification bundle.
   */
  public function testRunbookContainsPhaseThreeNdo02VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 NDO #2 no platform-wide refactor of unrelated Drupal subsystems verification (`P3-NDO-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('services_inventory_rows=${SERVICE_ROWS}', $runbook);
    $this->assertStringContainsString('PhaseThreeNoPlatformWideRefactorOfUnrelatedDrupalSubsystemsGuardTest.php', $runbook);
    $this->assertStringContainsString('phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-159]', $runbook);
  }

  /**
   * Runtime artifact must contain required proof markers.
   */
  public function testRuntimeArtifactContainsPhaseThreeNdo02ProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-ndo2-no-platform-wide-refactor-of-unrelated-drupal-subsystems.txt');

    $this->assertStringContainsString('VC-TOGGLE-CHECK', $artifact);
    $this->assertStringContainsString('guard-anchor-module-info=present', $artifact);
    $this->assertStringContainsString('guard-anchor-core-seam-services=present', $artifact);
    $this->assertStringContainsString('guard-anchor-services-inventory-bounded=present', $artifact);
    $this->assertStringContainsString('guard-anchor-system-map-diagram-a=present', $artifact);
    $this->assertStringContainsString('p3-ndo-02-status=closed', $artifact);
    $this->assertStringContainsString('p3-ndo-02-enforcement=guard-test+module-scope-anchors', $artifact);
    $this->assertStringContainsString('p3-ndo-02-scope=boundary-enforcement-artifacts-only', $artifact);
    $this->assertStringContainsString('p3-ndo-02-claim-010=present', $artifact);
    $this->assertStringContainsString('p3-ndo-02-claim-159=present', $artifact);
    $this->assertStringContainsString('no-net-new-assistant-channels=true', $artifact);
    $this->assertStringContainsString('no-third-party-model-expansion=true', $artifact);
    $this->assertStringContainsString('no-platform-wide-refactor-of-unrelated-drupal-subsystems=true', $artifact);
  }

  /**
   * Module scope anchor metadata must remain present.
   */
  public function testModuleScopeAnchorsRemainPresent(): void {
    $moduleInfo = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.info.yml');

    $this->assertStringContainsString("name: 'ILAS Site Assistant'", $moduleInfo);
    $this->assertStringContainsString('core_version_requirement: ^10 || ^11', $moduleInfo);
    $this->assertStringContainsString('drupal:search_api', $moduleInfo);
    $this->assertStringContainsString('drupal:paragraphs', $moduleInfo);
  }

  /**
   * Core seam service anchors must remain declared.
   */
  public function testCoreSeamServicesRemainDeclared(): void {
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');

    $requiredServiceIds = [
      'ilas_site_assistant.policy_filter',
      'ilas_site_assistant.intent_router',
      'ilas_site_assistant.faq_index',
      'ilas_site_assistant.resource_finder',
      'ilas_site_assistant.response_grounder',
      'ilas_site_assistant.safety_classifier',
      'ilas_site_assistant.llm_enhancer',
    ];

    foreach ($requiredServiceIds as $serviceId) {
      $this->assertStringContainsString(
        $serviceId . ':',
        $services,
        "Required seam service missing from services.yml: {$serviceId}",
      );
    }
  }

  /**
   * Service inventory continuity guard must stay within bounded thresholds.
   */
  public function testServicesInventoryRowCountWithinBoundaryGuardBounds(): void {
    $path = self::repoRoot() . '/docs/aila/artifacts/services-inventory.tsv';
    $this->assertFileExists($path);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $this->assertIsArray($lines);
    $this->assertNotEmpty($lines);

    // Header + service rows. Bound values detect collapse without exact lock.
    $serviceRows = max(0, count($lines) - 1);
    $this->assertGreaterThanOrEqual(
      30,
      $serviceRows,
      "Service inventory row count too low ({$serviceRows}); possible broad architecture collapse.",
    );
    $this->assertLessThanOrEqual(
      80,
      $serviceRows,
      "Service inventory row count too high ({$serviceRows}); review for broad architectural churn.",
    );
  }

  /**
   * Diagram A continuity anchors must remain present for scope boundary context.
   */
  public function testSystemMapRetainsDiagramAAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('Drupal 11 / ilas_site_assistant', $systemMap);
    $this->assertStringContainsString('External Integrations', $systemMap);
    $this->assertStringContainsString('CI[External CI runner', $systemMap);
    $this->assertStringContainsString('PF[Promptfoo harness]', $systemMap);
  }

}
