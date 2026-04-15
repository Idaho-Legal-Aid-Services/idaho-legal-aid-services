<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Exit criterion #3 closure artifacts (`P2-EXT-03`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoExitCriteriaThreeGateTest extends TestCase {

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
   * Roadmap must contain dated closure for Phase 2 Exit criterion #3.
   */
  public function testRoadmapContainsPhaseTwoExitThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Exit #3 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Live LLM remains disabled pending Phase 3 readiness review.', $roadmap);
    $this->assertStringContainsString('phase2-exit3-live-llm-disabled-phase3-readiness.txt', $roadmap);
    $this->assertStringContainsString('CLAIM-142', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
  }

  /**
   * Current-state must include dated Phase 2 Exit #3 addendum.
   */
  public function testCurrentStateContainsPhaseTwoExitThreeAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Exit #3 Live LLM Disabled Pending Phase 3 Readiness Review Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $currentState);
    $this->assertStringContainsString('phase2-exit3-live-llm-disabled-phase3-readiness.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-142]', $currentState);
  }

  /**
   * Runbook section 3 must include reproducible P2-EXT-03 verification bundle.
   */
  public function testRunbookContainsPhaseTwoExitThreeVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 exit #3 live LLM disabled pending Phase 3 readiness review verification (`P2-EXT-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('P2-EXT-03 is not closed.', $runbook);
    $this->assertStringContainsString('phase2-exit3-live-llm-disabled-phase3-readiness.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-142]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-119 addendum and CLAIM-142 closure section.
   */
  public function testEvidenceIndexContainsPhaseTwoExitThreeClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-119', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-04): Phase 2 Exit #3 (`P2-EXT-03`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 2 Exit #3 Live LLM Disabled Pending Phase 3 Readiness Review (`P2-EXT-03`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-142', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoExitCriteriaThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required verification markers.
   */
  public function testRuntimeArtifactContainsPhaseTwoExitThreeProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-exit3-live-llm-disabled-phase3-readiness.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $artifact);
    $this->assertStringContainsString('llm.enabled: false', $artifact);
    $this->assertStringContainsString('vector_search.enabled: false', $artifact);
    $this->assertStringContainsString('phase2-ext-03-status=closed', $artifact);
  }

  /**
   * Runtime guard anchors must remain present in code paths.
   */
  public function testRuntimeGuardAnchorsRemainPresent(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $gate = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_ENABLED')", $settings);
    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['llm']['enabled'] = TRUE;", $settings);
    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['vector_search']['enabled'] = FALSE;", $settings);
    $this->assertStringContainsString('Live enablement is runtime-only. Set <code>ILAS_LLM_ENABLED</code>', $form);
    $this->assertStringContainsString('$llm_enabled = FALSE;', $form);
    $this->assertStringContainsString('public function isEnabled(): bool', $enhancer);
    $this->assertStringContainsString('protected function isLiveEnvironment(): bool', $gate);
    $this->assertStringContainsString('protected function isLlmEffectivelyEnabled(): bool', $gate);
  }

}
