<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 1 Entry criteria #1 blocker disposition artifacts.
 */
#[Group('ilas_site_assistant')]
class PhaseOneEntryCriteriaOneGateTest extends TestCase {

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
   * Roadmap must preserve P1 entry criteria #1 and explicit blocker disposition.
   */
  public function testRoadmapContainsEntryCriteriaAndBlockerDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      'Phase 0 CSRF and config-parity blockers are resolved or have approved mitigations.',
      $roadmap,
    );
    $this->assertStringContainsString('### Phase 1 Entry #1 blocker disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('B-01 is resolved for `/assistant/api/message` strict CSRF enforcement', $roadmap);
    $this->assertStringContainsString('/assistant/api/track` uses approved mitigation (same-origin Origin/Referer + flood limits)', $roadmap);
    $this->assertStringContainsString('B-02 is resolved via `vector_search` schema/export parity', $roadmap);
  }

  /**
   * Current-state must reflect resolved blocker posture and approved mitigation.
   */
  public function testCurrentStateReflectsResolvedCsrfAndConfigParityBlockers(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('Message endpoint enforces strict CSRF', $currentState);
    $this->assertStringContainsString('Track endpoint uses approved mitigation', $currentState);
    $this->assertStringContainsString('Config schema coverage | Schema covers all install-default blocks including `vector_search`', $currentState);
    $this->assertStringNotContainsString('schema gap noted', $currentState);
    $this->assertStringNotContainsString('Known config-model gap', $currentState);
  }

  /**
   * Evidence index must capture superseded blocker claims and current closures.
   */
  public function testEvidenceIndexCapturesSupersededAndResolvedClaims(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-012', $evidenceIndex);
    $this->assertStringContainsString('/assistant/api/message` is a POST route with dual CSRF enforcement', $evidenceIndex);
    $this->assertStringContainsString('/assistant/api/track` is a POST route with approved origin/referer mitigation', $evidenceIndex);

    $this->assertStringContainsString('### CLAIM-095', $evidenceIndex);
    $this->assertStringContainsString('SUPERSEDED by CLAIM-124', $evidenceIndex);

    $this->assertStringContainsString('### CLAIM-123', $evidenceIndex);
    $this->assertStringContainsString('approved mitigation model for `/assistant/api/track`', $evidenceIndex);

    $this->assertStringContainsString('### CLAIM-124', $evidenceIndex);
    $this->assertStringContainsString('Config completeness drift test enforces install-vs-active-vs-schema parity', $evidenceIndex);
  }

  /**
   * Runbook section 2 must verify message CSRF and track mitigation checks.
   */
  public function testRunbookSectionTwoHasCsrfAndTrackMitigationVerification(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Route + endpoint schema verification (synthetic)', $runbook);
    $this->assertStringContainsString('message: missing token -> 403', $runbook);
    $this->assertStringContainsString('track request (same-origin, no CSRF required)', $runbook);
    $this->assertStringContainsString('track request (cross-origin Origin) -> 403', $runbook);
    $this->assertStringNotContainsString('track: missing token -> 403', $runbook);
  }

  /**
   * System map must document track endpoint mitigation in Diagram A edge labels.
   */
  public function testSystemMapDocumentsTrackOriginMitigation(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('POST /assistant/api/message + CSRF', $systemMap);
    $this->assertStringContainsString('POST /assistant/api/track + Origin/Referer guard', $systemMap);
  }

}
