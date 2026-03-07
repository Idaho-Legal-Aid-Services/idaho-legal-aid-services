<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P3-NDO-01 boundary: no net-new channels or provider expansion.
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest extends TestCase {

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
   * Roadmap must contain dated P3-NDO-01 disposition.
   */
  public function testRoadmapContainsPhaseThreeNdo01Disposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 NDO #1 disposition (2026-03-06)', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion beyond audited providers', $roadmap);
    $this->assertStringContainsString('PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php', $roadmap);
    $this->assertStringContainsString('phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt', $roadmap);
    $this->assertStringContainsString('CLAIM-158', $roadmap);
  }

  /**
   * Current-state must include dated P3-NDO-01 addendum.
   */
  public function testCurrentStateContainsPhaseThreeNdo01Addendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 NDO #1 No Net-New Assistant Channels + No Third-Party Model Expansion Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`P3-NDO-01`', $currentState);
    $this->assertStringContainsString('PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php', $currentState);
    $this->assertStringContainsString('phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-158]', $currentState);
  }

  /**
   * Evidence index must include CLAIM-158 and CLAIM-073/074 addenda.
   */
  public function testEvidenceIndexContainsClaim158AndProviderAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-073', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Phase 3 NDO #1 (`P3-NDO-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-074', $evidenceIndex);
    $this->assertStringContainsString(
      '## Phase 3 NDO #1 No Net-New Assistant Channels + No Third-Party Model Expansion Boundary (`P3-NDO-01`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-158', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php', $evidenceIndex);
  }

  /**
   * Runbook must include P3-NDO-01 verification bundle.
   */
  public function testRunbookContainsPhaseThreeNdo01VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 NDO #1 no net-new assistant channels or third-party model expansion verification (`P3-NDO-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('Audited-provider allowlist continuity anchors', $runbook);
    $this->assertStringContainsString('PhaseThreeNoNetNewAssistantChannelsOrModelExpansionGuardTest.php', $runbook);
    $this->assertStringContainsString('phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-158]', $runbook);
  }

  /**
   * Runtime artifact must contain required proof markers.
   */
  public function testRuntimeArtifactContainsPhaseThreeNdo01ProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-ndo1-no-net-new-assistant-channels-or-third-party-model-expansion.txt');

    $this->assertStringContainsString('VC-TOGGLE-CHECK', $artifact);
    $this->assertStringContainsString('guard-anchor-assistant-route-page=present', $artifact);
    $this->assertStringContainsString('guard-anchor-assistant-route-api=present', $artifact);
    $this->assertStringContainsString('guard-anchor-provider-allowlist-service=present', $artifact);
    $this->assertStringContainsString('guard-anchor-provider-allowlist-form=present', $artifact);
    $this->assertStringContainsString('guard-anchor-provider-allowlist-schema=present', $artifact);
    $this->assertStringContainsString('guard-anchor-provider-allowlist-install-default=present', $artifact);
    $this->assertStringContainsString('guard-anchor-system-map-diagram-a=present', $artifact);
    $this->assertStringContainsString('p3-ndo-01-status=closed', $artifact);
    $this->assertStringContainsString('p3-ndo-01-claim-073=present', $artifact);
    $this->assertStringContainsString('p3-ndo-01-claim-074=present', $artifact);
    $this->assertStringContainsString('p3-ndo-01-enforcement=guard-test+channel-provider-anchors', $artifact);
    $this->assertStringContainsString('p3-ndo-01-scope=boundary-enforcement-artifacts-only', $artifact);
    $this->assertStringContainsString('no-net-new-assistant-channels=true', $artifact);
    $this->assertStringContainsString('no-third-party-model-expansion=true', $artifact);
    $this->assertStringContainsString('no-unrelated-drupal-platform-refactor=true', $artifact);
  }

  /**
   * Assistant channel anchor routes and documented surfaces must remain present.
   */
  public function testAssistantChannelAnchorsRemainPresent(): void {
    $routing = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml');
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString("path: '/assistant'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/message'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/session/bootstrap'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/suggest'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/faq'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/health'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/metrics'", $routing);
    $this->assertStringContainsString("path: '/assistant/api/track'", $routing);

    $this->assertStringContainsString('floating global widget and dedicated `/assistant` page mode', $currentState);
    $this->assertStringContainsString('Sends JSON to `/assistant/api/message` and `/assistant/api/track`', $currentState);
  }

  /**
   * Provider allowlist anchors must remain limited to audited Gemini/Vertex.
   */
  public function testProviderAllowlistAnchorsRemainPresent(): void {
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $schema = self::readFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $install = self::readFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');

    $this->assertStringContainsString('const GEMINI_API_ENDPOINT =', $enhancer);
    $this->assertStringContainsString('const VERTEX_AI_ENDPOINT =', $enhancer);
    $this->assertStringContainsString('if ($provider === \'gemini_api\') {', $enhancer);
    $this->assertStringContainsString('if ($provider === \'vertex_ai\') {', $enhancer);
    $this->assertStringContainsString("'x-goog-api-key' => \$apiKey", $enhancer);
    $this->assertStringContainsString("'Authorization' => 'Bearer ' . \$accessToken", $enhancer);

    $this->assertStringContainsString("'gemini_api' => \$this->t('Gemini API (API Key)')", $form);
    $this->assertStringContainsString("'vertex_ai' => \$this->t('Vertex AI (Google Cloud)')", $form);

    $this->assertStringContainsString('LLM provider (gemini_api or vertex_ai)', $schema);
    $this->assertStringContainsString("provider: 'gemini_api'  # 'gemini_api' or 'vertex_ai'", $install);
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
