<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #3 closure artifacts (`XDP-03`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowThreeGateTest extends TestCase {

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
   * Roadmap must retain row #3 and a dated XDP-03 disposition.
   */
  public function testRoadmapContainsRowThreeAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| Observability baseline (`IMP-OBS-01`) | Sentry/Langfuse credentials, redaction validation | Phase 1 -> prerequisite for Phase 2/3 optimization | SRE/Platform Engineer |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #3 disposition (2026-03-06)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream Phase 2/3',
      $roadmap
    );
    $this->assertStringContainsString(
      'blocked whenever unresolved dependency count is',
      $roadmap
    );
    $this->assertStringContainsString(
      'phase1-xdp03-observability-baseline-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-03 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp03AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #3 Observability Baseline Guardrail Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-03`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite', $currentState);
    $this->assertStringContainsString('`xdp-03-status=blocked`', $currentState);
    $this->assertStringContainsString('all prerequisites pass reports', $currentState);
    $this->assertStringContainsString('`xdp-03-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-03-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('phase1-xdp03-observability-baseline-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-162]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-03.
   */
  public function testRunbookContainsXdp03VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #3 observability baseline verification (`XDP-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-03-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-03-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-03-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('phase1-xdp03-observability-baseline-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-162]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-120 addendum plus CLAIM-162 section.
   */
  public function testEvidenceIndexContainsXdp03ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-120', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Cross-phase dependency row #3 (`XDP-03`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #3 Observability Baseline Guardrail (`XDP-03`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-162', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase1-xdp03-observability-baseline-dependency-gate.txt');

    $this->assertStringContainsString('xdp-03-status=closed', $artifact);
    $this->assertStringContainsString('xdp-03-workstream=IMP-OBS-01', $artifact);
    $this->assertStringContainsString('xdp-03-owner-role=SRE/Platform Engineer', $artifact);
    $this->assertStringContainsString('xdp-03-consumed-in=Phase 1 -> prerequisite for Phase 2/3 optimization', $artifact);
    $this->assertStringContainsString('dependency.sentry-langfuse-credentials=pass', $artifact);
    $this->assertStringContainsString('dependency.redaction-validation=pass', $artifact);
    $this->assertStringContainsString('xdp-03-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-03-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-03 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in source/tests/docs.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $telemetryCredentialGate = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/TelemetryCredentialGateTest.php');
    $impObsAcceptance = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/ImpObs01AcceptanceTest.php');
    $redactionContract = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/ObservabilityRedactionContractTest.php');
    $phaseOneRuntime = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('LANGFUSE_PUBLIC_KEY', $settings);
    $this->assertStringContainsString('LANGFUSE_SECRET_KEY', $settings);
    $this->assertStringContainsString('SENTRY_DSN', $settings);

    $this->assertStringContainsString('testRuntimeGatesArtifactShowsCredentialsPresentOnAllEnvironments', $telemetryCredentialGate);
    $this->assertStringContainsString('testSettingsPhpContainsLangfuseCredentialOverrideWiring', $telemetryCredentialGate);
    $this->assertStringContainsString('testSettingsPhpContainsSentryDsnOverrideWiring', $telemetryCredentialGate);

    $this->assertStringContainsString('testAllNinePiiTypesRedactedAcrossAllSentryFields', $impObsAcceptance);
    $this->assertStringContainsString('testSentryEventGetsEnvironmentTagsAndPiiScrubbed', $impObsAcceptance);

    $this->assertStringContainsString('testSentryBeforeSendRedactsAllNinePiiTypes', $redactionContract);
    $this->assertStringContainsString('testSentryBeforeSendRedactsExceptionPii', $redactionContract);
    $this->assertStringContainsString('testSentryBeforeSendRedactsExtraContextPii', $redactionContract);

    $this->assertStringContainsString('langfuse_public_key=present', $phaseOneRuntime);
    $this->assertStringContainsString('langfuse_secret_key=present', $phaseOneRuntime);
    $this->assertStringContainsString('raven_client_key=present', $phaseOneRuntime);

    $this->assertStringContainsString('Observability', $systemMap);
    $this->assertStringContainsString('Langfuse tracer/queue', $systemMap);
    $this->assertStringContainsString('Sentry options subscriber', $systemMap);
    $this->assertStringContainsString('Sentry tag + Langfuse error + 500 internal_error', $systemMap);
  }

}
