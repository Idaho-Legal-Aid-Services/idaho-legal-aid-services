<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\FallbackGate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Deliverable #1 closure artifacts (`P2-DEL-01`).
 *
 * Locks the response contract expansion: `confidence`, `citations[]`,
 * `decision_reason` on all 200-response paths, request-id normalization
 * verification, and Langfuse span citation field fix.
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoDeliverableOneGateTest extends TestCase {

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
   * Roadmap must include dated disposition for Phase 2 Deliverable #1.
   */
  public function testRoadmapContainsDeliverableOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '### Phase 2 Deliverable #1 disposition (2026-03-03)',
      $roadmap
    );
    $this->assertStringContainsString('`confidence`', $roadmap);
    $this->assertStringContainsString('`citations[]`', $roadmap);
    $this->assertStringContainsString('`decision_reason`', $roadmap);
    $this->assertStringContainsString('request-id normalization', $roadmap);
    $this->assertStringContainsString('CLAIM-134', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('No broad platform migration outside current Pantheon baseline', $roadmap);
  }

  /**
   * Current-state must include contract expansion addendum.
   */
  public function testCurrentStateContainsContractExpansionAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('`confidence`', $currentState);
    $this->assertStringContainsString('`citations[]`', $currentState);
    $this->assertStringContainsString('`decision_reason`', $currentState);
    $this->assertStringContainsString('Response contract expansion (`P2-DEL-01`)', $currentState);
    $this->assertStringContainsString(
      '### Phase 2 Deliverable #1 Response Contract Expansion Disposition',
      $currentState
    );
    $this->assertStringContainsString('[^CLAIM-134]', $currentState);
  }

  /**
   * Runbook section 4 must include P2-DEL-01 verification bundle.
   */
  public function testRunbookContainsDeliverableOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 response contract expansion verification (`P2-DEL-01`)',
      $runbook
    );
    $this->assertStringContainsString('PhaseTwoDeliverableOneGateTest', $runbook);
    $this->assertStringContainsString('assembleContractFields', $runbook);
    $this->assertStringContainsString('confidence', $runbook);
    $this->assertStringContainsString('citations', $runbook);
    $this->assertStringContainsString('decision_reason', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
  }

  /**
   * Evidence index must include CLAIM-134.
   */
  public function testEvidenceIndexContainsDeliverableOneClaims(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-134', $evidenceIndex);
    $this->assertStringContainsString('Phase 2 Deliverable #1 (`P2-DEL-01`)', $evidenceIndex);
    $this->assertStringContainsString('`confidence`', $evidenceIndex);
    $this->assertStringContainsString('`citations[]`', $evidenceIndex);
    $this->assertStringContainsString('`decision_reason`', $evidenceIndex);
    $this->assertStringContainsString('assembleContractFields', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoDeliverableOneGateTest.php', $evidenceIndex);
  }

  /**
   * Controller must contain assembleContractFields method and contract fields.
   */
  public function testControllerContainsContractExpansionFields(): void {
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');

    // Method must exist.
    $this->assertStringContainsString(
      'private function assembleContractFields(array $response, ?array $gate_decision, string $path_type): array',
      $controller
    );

    // Contract fields must be set in the method.
    $this->assertStringContainsString("['confidence']", $controller);
    $this->assertStringContainsString("['citations']", $controller);
    $this->assertStringContainsString("['decision_reason']", $controller);
    $this->assertStringContainsString('normalizeContractConfidence', $controller);
    $this->assertStringContainsString('normalizeContractCitations', $controller);
    $this->assertStringContainsString('normalizeContractDecisionReason', $controller);
    $this->assertStringContainsString('max(0.0, min(1.0, $confidence))', $controller);
    $this->assertStringContainsString("['source_url']", $controller);

    // Must be called at all five 200-response paths.
    $this->assertSame(
      5,
      substr_count($controller, '$this->assembleContractFields('),
      'assembleContractFields must be called at exactly 5 response paths'
    );

    // Path type arguments must cover all expected types.
    $this->assertStringContainsString("'safety'", $controller);
    $this->assertStringContainsString("'oos'", $controller);
    $this->assertStringContainsString("'policy'", $controller);
    $this->assertStringContainsString("'normal'", $controller);
  }

  /**
   * FallbackGate reason code descriptions must be complete for all constants.
   */
  public function testFallbackGateReasonCodeDescriptionsComplete(): void {
    $descriptions = FallbackGate::getReasonCodeDescriptions();

    $expected_constants = [
      FallbackGate::REASON_HIGH_CONF_INTENT,
      FallbackGate::REASON_HIGH_CONF_RETRIEVAL,
      FallbackGate::REASON_LOW_INTENT_CONF,
      FallbackGate::REASON_LOW_RETRIEVAL_SCORE,
      FallbackGate::REASON_AMBIGUOUS_MULTI_INTENT,
      FallbackGate::REASON_SAFETY_URGENT,
      FallbackGate::REASON_OUT_OF_SCOPE,
      FallbackGate::REASON_POLICY_VIOLATION,
      FallbackGate::REASON_NO_RESULTS,
      FallbackGate::REASON_LARGE_SCORE_GAP,
      FallbackGate::REASON_BORDERLINE_CONF,
      FallbackGate::REASON_GREETING,
      FallbackGate::REASON_LLM_DISABLED,
    ];

    $this->assertCount(
      13,
      $expected_constants,
      'Expected exactly 13 REASON_* constants'
    );

    foreach ($expected_constants as $code) {
      $this->assertArrayHasKey(
        $code,
        $descriptions,
        "Missing description for reason code: {$code}"
      );
      $this->assertNotEmpty(
        $descriptions[$code],
        "Empty description for reason code: {$code}"
      );
    }

    $this->assertCount(
      count($expected_constants),
      $descriptions,
      'getReasonCodeDescriptions() must have exactly one entry per REASON_* constant'
    );
  }

  /**
   * Langfuse span must use 'sources' field (not 'citations') for grounding.
   */
  public function testLangfuseSpanUsesCorrectCitationField(): void {
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');

    // The grounding span must check $response['sources'], not $response['citations'],
    // because ResponseGrounder produces 'sources' and assembleContractFields
    // populates 'citations' later.
    $this->assertStringContainsString(
      "'citations_added' => !empty(\$response['sources'])",
      $controller,
      'Langfuse grounding span must use sources field, not citations'
    );

    // Must NOT contain the buggy pre-fix pattern.
    $this->assertStringNotContainsString(
      "'citations_added' => !empty(\$response['citations'])",
      $controller,
      'Langfuse grounding span must not use citations field (bug fix)'
    );
  }

}
