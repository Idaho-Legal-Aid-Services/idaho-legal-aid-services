<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dependency gates for Phase 1 observability readiness.
 *
 * These tests lock the artifacts used to unblock roadmap dependency work
 * for IMP-OBS-01 and IMP-TST-01 in Pantheon/local workflows without
 * enabling live telemetry.
 */
#[Group('ilas_site_assistant')]
class PhaseOneObservabilityDependencyGateTest extends TestCase {

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
   * Roadmap dependency matrix must retain IMP-OBS-01 and IMP-TST-01 owners.
   */
  public function testRoadmapDependencyRowsContainExpectedOwnerRoles(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| Observability baseline (`IMP-OBS-01`) | Sentry/Langfuse credentials, redaction validation | Phase 1 -> prerequisite for Phase 2/3 optimization | SRE/Platform Engineer |',
      $roadmap,
    );
    $this->assertStringContainsString(
      '| CI quality gate (`IMP-TST-01`) | CI owner/platform decisions | Phase 1 -> prerequisite for all subsequent release gates | QA/Automation Engineer + TPM |',
      $roadmap,
    );
  }

  /**
   * Backlog must reflect external CI and runtime-override observability model.
   */
  public function testBacklogReflectsExternalCiAndRuntimeObservabilityChecks(): void {
    $backlog = self::readFile('docs/aila/backlog.md');

    $this->assertStringContainsString('runtime override checks (`raven_client_key` presence + module enabled)', $backlog);
    $this->assertStringContainsString('Live sample rate remains policy-capped (initial 0.10)', $backlog);
    $this->assertStringContainsString('external CI runners using repo scripts (`scripts/ci/*`)', $backlog);
    $this->assertStringContainsString('Promptfoo threshold failures block `master`/`main`/`release/*` and are advisory elsewhere.', $backlog);
  }

  /**
   * Runbook must contain Phase 1 dependency verification commands.
   */
  public function testRunbookContainsPhaseOneDependencyGateVerificationSteps(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 1 observability dependency gate verification', $runbook);
    $this->assertStringContainsString("pml --status=enabled --type=module --no-core --format=list | rg '^raven$'", $runbook);
    $this->assertStringContainsString('php:eval "', $runbook);
    $this->assertStringContainsString("\\Drupal::config('ilas_site_assistant.settings')", $runbook);
    $this->assertStringContainsString('langfuse_enabled=', $runbook);
    $this->assertStringContainsString('langfuse_public_key=', $runbook);
    $this->assertStringContainsString('langfuse_secret_key=', $runbook);
    $this->assertStringContainsString('raven_client_key=', $runbook);
    $this->assertStringContainsString('terminus env:view "idaho-legal-aid-services.${ENV}" --print', $runbook);
    $this->assertStringContainsString('export ILAS_ASSISTANT_URL=', $runbook);
    $this->assertStringContainsString('npm run eval:promptfoo', $runbook);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh --env dev --mode auto', $runbook);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE=15', $runbook);
    $this->assertStringContainsString('ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR=120', $runbook);
    $this->assertStringContainsString('target_env_mismatch', $runbook);
    $this->assertStringContainsString('`master`, `main`, and `release/*` branches are blocking for threshold failures.', $runbook);
    $this->assertStringContainsString('Expected readiness result', $runbook);
    $this->assertStringNotContainsString('config:get raven.settings -y', $runbook);
    $this->assertStringNotContainsString('config:get langfuse.settings -y', $runbook);
    $this->assertStringNotContainsString('find .github -maxdepth 3 -type f', $runbook);
  }

  /**
   * Current state must keep historical known unknowns and add dated addendum.
   */
  public function testCurrentStateRetainsKnownUnknownsAndAddsDatedDisposition(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    // Preserve historical baseline in section 8 table.
    $this->assertStringContainsString('## 8) Known unknowns', $currentState);
    $this->assertStringContainsString('Long-run cron cadence and queue drain timing under load', $currentState);
    $this->assertStringContainsString('Promptfoo CI ownership outside this repository', $currentState);

    // Addendum-style P0-EXT-03 resolution entry.
    $this->assertStringContainsString(
      '### Phase 0 Exit #3 Dependency Disposition (2026-02-27)',
      $currentState,
    );
    $this->assertStringContainsString('CLAIM-120 dependency is unblocked via readiness gates', $currentState);
    $this->assertStringContainsString('CLAIM-122 dependency is unblocked via Pantheon/local gate ownership', $currentState);
  }

  /**
   * Evidence index addendum must reference Pantheon/local dependency gates.
   */
  public function testEvidenceIndexAddendumReferencesPantheonLocalGates(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-02-27)', $evidenceIndex);
    $this->assertStringContainsString(
      '`docs/aila/runbook.md` (Phase 1 observability dependency gate verification)',
      $evidenceIndex,
    );
    $this->assertStringContainsString('scripts/ci/derive-assistant-url.sh', $evidenceIndex);
    $this->assertStringContainsString('scripts/ci/run-promptfoo-gate.sh', $evidenceIndex);
    $this->assertStringContainsString(
      'web/modules/custom/ilas_site_assistant/tests/src/Unit/PhaseOneObservabilityDependencyGateTest.php',
      $evidenceIndex,
    );
    $this->assertStringContainsString('docs/aila/runtime/phase1-observability-gates.txt', $evidenceIndex);
    $this->assertStringNotContainsString('.github/workflows/aila-quality-gate.yml', $evidenceIndex);
  }

  /**
   * External CI helper scripts must exist for promptfoo gate automation.
   */
  public function testExternalCiScriptsExist(): void {
    $deriveScript = self::repoRoot() . '/scripts/ci/derive-assistant-url.sh';
    $gateScript = self::repoRoot() . '/scripts/ci/run-promptfoo-gate.sh';

    $this->assertFileExists($deriveScript);
    $this->assertFileExists($gateScript);
  }

}
