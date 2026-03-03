<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards IMP-REL-01 and IMP-REL-02 documentation artifacts.
 *
 * Ensures evidence-index, current-state, runbook, and risk-register contain
 * the required formalization entries for failure-mode contracts and
 * replay/idempotency coverage.
 */
#[Group('ilas_site_assistant')]
class ImpRel01Rel02DocumentationGuardTest extends TestCase {

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
   * evidence-index.md must contain IMP-REL-01 and IMP-REL-02 addenda.
   */
  public function testEvidenceIndexContainsImpRelAddenda(): void {
    $evidence = self::readFile('docs/aila/evidence-index.md');

    // IMP-REL-01 addendum under CLAIM-048.
    $this->assertStringContainsString(
      'Addendum (2026-03-02): IMP-REL-01 tests verify the controller catch-all',
      $evidence,
      'CLAIM-048 must have IMP-REL-01 addendum'
    );
    $this->assertStringContainsString(
      'IntegrationFailureContractTest.php',
      $evidence,
      'CLAIM-048 addendum must reference IntegrationFailureContractTest'
    );

    // IMP-REL-02 addendum under CLAIM-035.
    $this->assertStringContainsString(
      'Addendum (2026-03-02): IMP-REL-02 contract tests verify resolveCorrelationId',
      $evidence,
      'CLAIM-035 must have IMP-REL-02 addendum'
    );
    $this->assertStringContainsString(
      'IdempotencyReplayContractTest.php',
      $evidence,
      'CLAIM-035 addendum must reference IdempotencyReplayContractTest'
    );

    // IMP-REL-02 addendum under CLAIM-046.
    $this->assertStringContainsString(
      'Addendum (2026-03-02): IMP-REL-02 tests verify conversation cache key determinism',
      $evidence,
      'CLAIM-046 must have IMP-REL-02 addendum'
    );
  }

  /**
   * current-state.md must formalize IMP-REL-01 and IMP-REL-02 contracts.
   */
  public function testCurrentStateFormalizesImpRelContracts(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      'Integration failure contract formalization (IMP-REL-01)',
      $currentState,
      'Section 4B must contain IMP-REL-01 formalization row'
    );
    $this->assertStringContainsString(
      'Idempotency and replay correctness (IMP-REL-02)',
      $currentState,
      'Section 4B must contain IMP-REL-02 formalization row'
    );
  }

  /**
   * runbook.md must contain verification commands for both test suites.
   */
  public function testRunbookContainsVerificationCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      'IntegrationFailureContractTest.php',
      $runbook,
      'Runbook must reference IntegrationFailureContractTest'
    );
    $this->assertStringContainsString(
      'IdempotencyReplayContractTest.php',
      $runbook,
      'Runbook must reference IdempotencyReplayContractTest'
    );
    $this->assertStringContainsString(
      'Integration failure contract verification (`IMP-REL-01`)',
      $runbook,
      'Runbook must have IMP-REL-01 verification subsection'
    );
    $this->assertStringContainsString(
      'Idempotency and replay verification (`IMP-REL-02`)',
      $runbook,
      'Runbook must have IMP-REL-02 verification subsection'
    );
  }

  /**
   * risk-register.md R-REL-01 and R-REL-03 must show Mitigated status.
   */
  public function testRiskRegisterShowsMitigated(): void {
    $register = self::readFile('docs/aila/risk-register.md');

    // Find the R-REL-01 row and verify it contains "Mitigated".
    $this->assertMatchesRegularExpression(
      '/R-REL-01.*Mitigated/s',
      $register,
      'R-REL-01 must have Mitigated status'
    );

    // Find the R-REL-03 row and verify it contains "Mitigated".
    $this->assertMatchesRegularExpression(
      '/R-REL-03.*Mitigated/s',
      $register,
      'R-REL-03 must have Mitigated status'
    );

    // Verify IMP-REL-01 is referenced in R-REL-01 controls.
    $this->assertStringContainsString(
      'IMP-REL-01 adds consolidated failure matrix',
      $register,
      'R-REL-01 must reference IMP-REL-01 controls'
    );

    // Verify IMP-REL-02 is referenced in R-REL-03 controls.
    $this->assertStringContainsString(
      'IMP-REL-02 adds idempotency/replay contract tests',
      $register,
      'R-REL-03 must reference IMP-REL-02 controls'
    );
  }

}
