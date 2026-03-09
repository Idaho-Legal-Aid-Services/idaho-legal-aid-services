<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards cross-phase dependency row #1 closure artifacts (`XDP-01`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowOneGateTest extends TestCase {

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
   * Roadmap must retain row #1 and a dated XDP-01 disposition.
   */
  public function testRoadmapContainsRowOneAndDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| CSRF hardening (`IMP-SEC-01`) | Authenticated test matrix and route enforcement verification | Phase 0 -> prerequisite for Phases 1-3 | Security Engineer + Drupal Lead |',
      $roadmap
    );
    $this->assertStringContainsString(
      '### Cross-phase dependency row #1 disposition (2026-03-06)',
      $roadmap
    );
    $this->assertStringContainsString(
      'downstream dependency work is',
      $roadmap
    );
    $this->assertStringContainsString(
      'unresolved dependency count is non-zero',
      $roadmap
    );
    $this->assertStringContainsString(
      'phase0-xdp01-csrf-hardening-dependency-gate.txt',
      $roadmap
    );
  }

  /**
   * Current-state must include XDP-01 addendum and unresolved dependency logic.
   */
  public function testCurrentStateContainsXdp01AddendumAndStatusRules(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Cross-Phase Dependency Row #1 CSRF Guardrail Disposition (2026-03-06)',
      $currentState
    );
    $this->assertStringContainsString('`XDP-01`', $currentState);
    $this->assertStringContainsString('any unresolved prerequisite reports `xdp-01-status=blocked`', $currentState);
    $this->assertStringContainsString('pass reports `xdp-01-status=closed`', $currentState);
    $this->assertStringContainsString('xdp-01-unresolved-dependency-count', $currentState);
    $this->assertStringContainsString('phase0-xdp01-csrf-hardening-dependency-gate.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-160]', $currentState);
  }

  /**
   * Runbook must include verification bundle for XDP-01.
   */
  public function testRunbookContainsXdp01VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Cross-phase dependency row #1 CSRF hardening verification (`XDP-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('any missing prerequisite => `xdp-01-status=blocked`', $runbook);
    $this->assertStringContainsString('all prerequisites present => `xdp-01-status=closed`', $runbook);
    $this->assertStringContainsString('xdp-01-unresolved-dependency-count=0', $runbook);
    $this->assertStringContainsString('phase0-xdp01-csrf-hardening-dependency-gate.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-160]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-123 addendum plus CLAIM-160 section.
   */
  public function testEvidenceIndexContainsXdp01ClaimAndAddendum(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-123', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-06): Cross-phase dependency row #1 (`XDP-01`)', $evidenceIndex);
    $this->assertStringContainsString(
      '## Cross-Phase Dependency Row #1 CSRF Hardening Guardrail (`XDP-01`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-160', $evidenceIndex);
    $this->assertStringContainsString('CrossPhaseDependencyRowOneGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime proof must contain deterministic markers and no unresolved items.
   */
  public function testRuntimeArtifactContainsClosedStatusAndMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase0-xdp01-csrf-hardening-dependency-gate.txt');

    $this->assertStringContainsString('xdp-01-status=closed', $artifact);
    $this->assertStringContainsString('xdp-01-workstream=IMP-SEC-01', $artifact);
    $this->assertStringContainsString('xdp-01-owner-role=Security Engineer + Drupal Lead', $artifact);
    $this->assertStringContainsString('xdp-01-consumed-in=Phase 0 -> prerequisite for Phases 1-3', $artifact);
    $this->assertStringContainsString('dependency.authenticated-test-matrix=pass', $artifact);
    $this->assertStringContainsString('dependency.route-enforcement-verification=pass', $artifact);
    $this->assertStringContainsString('xdp-01-unresolved-dependencies=none', $artifact);

    $matches = [];
    $didMatch = preg_match('/xdp-01-unresolved-dependency-count=(\d+)/', $artifact, $matches);
    $this->assertSame(1, $didMatch, 'Runtime artifact missing unresolved dependency count marker.');
    $this->assertSame('0', $matches[1], 'XDP-01 must remain blocked unless unresolved dependency count is zero.');
  }

  /**
   * Prerequisite anchors must stay present in source and tests.
   */
  public function testPrerequisiteAnchorsRemainPresent(): void {
    $routing = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml');
    $strictCsrfCheck = self::readFile('web/modules/custom/ilas_site_assistant/src/Access/StrictCsrfRequestHeaderAccessCheck.php');
    $apiController = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');
    $csrfMatrixTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Unit/CsrfAuthMatrixTest.php');
    $functionalTest = self::readFile('web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantApiFunctionalTest.php');
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('ilas_site_assistant.api.message:', $routing);
    $this->assertStringContainsString("_ilas_strict_csrf_token: 'TRUE'", $routing);
    $this->assertStringContainsString('ilas_site_assistant.api.track:', $routing);
    $this->assertStringContainsString('Enforces strict CSRF header validation for assistant write endpoints', $strictCsrfCheck);
    $this->assertStringContainsString('Hybrid browser proof: same-origin Origin/Referer first', $apiController);
    $this->assertStringContainsString('private function evaluateTrackWriteProof(Request $request): array', $apiController);

    $this->assertStringContainsString('testAuthenticatedWithInvalidTokenIsForbiddenAndLogged', $csrfMatrixTest);
    $this->assertStringContainsString('testAnonymousWithValidTokenIsAllowed', $csrfMatrixTest);
    $this->assertStringContainsString('testAnonymousMessageEndpointAllowsValidCsrfToken', $functionalTest);
    $this->assertStringContainsString('testTrackEndpointRejectsCrossOriginOriginHeader', $functionalTest);
    $this->assertStringContainsString('testTrackEndpointAllowsSameOriginRefererHeader', $functionalTest);

    $this->assertStringContainsString('POST /assistant/api/message + CSRF', $systemMap);
    $this->assertStringContainsString('POST /assistant/api/track + Origin/Referer or bootstrap-token recovery', $systemMap);
  }

}
