<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Phase 0 architectural boundary enforcement.
 *
 * P0-NDO-03 requires "No broad architectural refactor beyond minimal seam
 * prep." This test guards boundary text and seam anchors without locking exact
 * architecture shape, so additive seam prep remains possible.
 */
#[Group('ilas_site_assistant')]
class ArchitectureBoundaryGuardTest extends TestCase {

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
   * Phase 0 roadmap guardrail text must remain intact.
   */
  public function testRoadmapRetainsPhaseZeroNoBroadRefactorBoundary(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### What we will NOT do', $roadmap);
    $this->assertStringContainsString('1. No live LLM enablement.', $roadmap);
    $this->assertStringContainsString('2. No major UI redesign.', $roadmap);
    $this->assertStringContainsString('3. No broad architectural refactor beyond minimal seam prep.', $roadmap);
  }

  /**
   * Backlog/risk artifacts must preserve seam-extraction framing.
   */
  public function testBacklogAndRiskPreserveSeamExtractionLanguage(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $risk = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString(
      '| Maintainability & Testing | Pipeline seam extraction | Incrementally extract interfaces around policy/routing/retrieval/response composition for safer changes. |',
      $backlog,
    );
    $this->assertStringContainsString(
      'Extract seams and interfaces around policy/routing/retrieval/response composition.',
      $risk,
    );
  }

  /**
   * Runbook section 4 must include reproducible boundary verification commands.
   */
  public function testRunbookContainsArchitecturalBoundaryVerificationSteps(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Architectural boundary verification (`P0-NDO-03`)',
      $runbook,
    );
    $this->assertStringContainsString(
      'No broad architectural refactor beyond minimal seam prep',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/roadmap.md docs/aila/backlog.md docs/aila/risk-register.md',
      $runbook,
    );
    $this->assertStringContainsString(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/artifacts/services-inventory.tsv',
      $runbook,
    );
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
      'ilas_site_assistant.pre_routing_decision_engine',
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
   * Router/controller sources must not reintroduce split pre-routing logic.
   */
  public function testPreRoutingAuthorityDoesNotDriftBackIntoRouterOrController(): void {
    $router = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/IntentRouter.php');
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');

    $this->assertStringNotContainsString('skip_pre_routing_urgency', $router);
    $this->assertStringNotContainsString('urgentSafetyTriggers', $router);
    $this->assertStringNotContainsString('checkUrgentSafety', $router);
    $this->assertStringNotContainsString('buildHighRiskIntent', $router);
    $this->assertStringNotContainsString("\$extraction['high_risk']", $router);
    $this->assertStringNotContainsString("\$extraction['out_of_scope']", $router);

    $this->assertStringNotContainsString('skip_pre_routing_urgency', $controller);
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
   * Diagram B deterministic pipeline anchors must remain documented.
   */
  public function testSystemMapRetainsDeterministicPipelineAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart TD', $systemMap);

    $requiredAnchors = [
      'Flood checks',
      'PreRoutingDecisionEngine',
      'SafetyClassifier',
      'OutOfScopeClassifier',
      'PolicyFilter fallback checks',
      'LlmEnhancer call',
      'Queue worker on cron',
    ];

    foreach ($requiredAnchors as $anchor) {
      $this->assertStringContainsString(
        $anchor,
        $systemMap,
        "Diagram B must retain pipeline anchor: {$anchor}",
      );
    }
  }

}
