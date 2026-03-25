<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 1 deterministic dependency-degrade formalization artifacts.
 */
#[Group('ilas_site_assistant')]
class PhaseOneDeterministicDegradeContractTest extends TestCase {

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
   * current-state.md must formalize deterministic degrade behavior in 4B/4D.
   */
  public function testCurrentStateFormalizesDeterministicDependencyDegradeContracts(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### B) Conversation lifecycle', $currentState);
    $this->assertStringContainsString('### D) Retrieval', $currentState);
    $this->assertStringContainsString('Deterministic dependency degrade contract (Phase 1 Objective #2)', $currentState);
    $this->assertStringContainsString('Deterministic degrade outcomes (formalized)', $currentState);
    $this->assertStringContainsString('controlled `500 internal_error`', $currentState);
    $this->assertStringContainsString('fail closed or degrade explicitly', $currentState);
    $this->assertStringContainsString('generic Search API query exceptions still route to legacy retrieval', $currentState);
  }

  /**
   * runbook.md section 2 must include local deterministic degrade verification.
   */
  public function testRunbookContainsDeterministicDependencyDegradeVerificationSteps(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('## 2) Local verification (DDEV)', $runbook);
    $this->assertStringContainsString('### Deterministic dependency degrade contract verification (`P1-OBJ-02`)', $runbook);
    $this->assertStringContainsString('DependencyFailureDegradeContractTest.php', $runbook);
    $this->assertStringContainsString('LlmEnhancerHardeningTest.php', $runbook);
    $this->assertStringContainsString('llm.fallback_on_error=true', $runbook);
    $this->assertStringContainsString('controlled `500 internal_error`', $runbook);
    $this->assertStringContainsString('explicit degraded or', $runbook);
  }

  /**
   * Evidence addenda under claims 048/063/065 must reference contract tests.
   */
  public function testEvidenceIndexAddendaReferenceDeterministicDegradeContracts(): void {
    $evidence = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-048', $evidence);
    $this->assertStringContainsString('### CLAIM-063', $evidence);
    $this->assertStringContainsString('### CLAIM-065', $evidence);
    $this->assertStringContainsString('Addendum (2026-02-27): deterministic dependency degrade behavior is formalized', $evidence);
    $this->assertStringContainsString('Addendum (2026-02-27): deterministic FAQ dependency degrade contract is now', $evidence);
    $this->assertStringContainsString('Addendum (2026-02-27): deterministic resource dependency degrade contract is', $evidence);
    $this->assertStringContainsString('DependencyFailureDegradeContractTest.php', $evidence);
    $this->assertStringContainsString('PhaseOneDeterministicDegradeContractTest.php', $evidence);
  }

}
