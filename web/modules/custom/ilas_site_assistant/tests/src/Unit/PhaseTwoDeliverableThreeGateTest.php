<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards Phase 2 Deliverable #3 closure artifacts (`P2-DEL-03`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoDeliverableThreeGateTest extends TestCase {

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
   * Parses YAML from a repo-relative path.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML: {$relativePath}");
    return $parsed;
  }

  /**
   * Roadmap must include dated closure disposition for Phase 2 Deliverable #3.
   */
  public function testRoadmapContainsDeliverableThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Deliverable #3 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('`IMP-RAG-02`', $roadmap);
    $this->assertStringContainsString('checks.vector_index_hygiene', $roadmap);
    $this->assertStringContainsString('metrics.vector_index_hygiene', $roadmap);
    $this->assertStringContainsString('R-RAG-02', $roadmap);
    $this->assertStringContainsString('R-GOV-02', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-136', $roadmap);
  }

  /**
   * Current-state must include retrieval/cron updates and P2-DEL-03 addendum.
   */
  public function testCurrentStateContainsDeliverableThreeAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('Vector index hygiene + metadata standards + refresh monitoring (`P2-DEL-03`)', $currentState);
    $this->assertStringContainsString('vector-index hygiene refresh snapshots (`runScheduledRefresh()` with failure isolation)', $currentState);
    $this->assertStringContainsString(
      '### Phase 2 Deliverable #3 Vector Index Hygiene + Refresh Monitoring Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('checks.vector_index_hygiene', $currentState);
    $this->assertStringContainsString('metrics.vector_index_hygiene', $currentState);
    $this->assertStringContainsString('[^CLAIM-136]', $currentState);
  }

  /**
   * Runbook section 4 must include P2-DEL-03 verification bundle.
   */
  public function testRunbookContainsDeliverableThreeVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 vector index hygiene, metadata standards, and refresh monitoring verification (`P2-DEL-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-KERNEL', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('PhaseTwoDeliverableThreeGateTest', $runbook);
    $this->assertStringContainsString('VectorIndexHygieneServiceTest', $runbook);
    $this->assertStringContainsString('state:get ilas_site_assistant.vector_index_hygiene.snapshot', $runbook);
    $this->assertStringContainsString('state:delete ilas_site_assistant.vector_index_hygiene.last_alert', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('[^CLAIM-136]', $runbook);
  }

  /**
   * Evidence index must include deliverable closure claim.
   */
  public function testEvidenceIndexContainsDeliverableThreeClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString(
      '## Phase 2 Deliverable #3 Vector Index Hygiene + Refresh Monitoring (`P2-DEL-03`)',
      $evidenceIndex
    );
    $this->assertStringContainsString('### CLAIM-136', $evidenceIndex);
    $this->assertStringContainsString('VectorIndexHygieneService.php', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoDeliverableThreeGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('R-RAG-02', $evidenceIndex);
    $this->assertStringContainsString('R-GOV-02', $evidenceIndex);
  }

  /**
   * Diagram A must include vector hygiene node and monitoring edges.
   */
  public function testSystemMapContainsVectorHygieneNodeAndEdges(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('VHYG[Vector index hygiene', $systemMap);
    $this->assertStringContainsString('RET --> VHYG', $systemMap);
    $this->assertStringContainsString('VHYG --> DASH', $systemMap);
    $this->assertStringContainsString('VHYG --> ALT', $systemMap);
  }

  /**
   * Config/schema/services/module/controller anchors must be present.
   */
  public function testConfigSchemaServiceCronAndControllerAnchors(): void {
    $installConfig = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $activeConfig = self::readYaml('config/ilas_site_assistant.settings.yml');
    $schema = self::readYaml('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');
    $module = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.module');
    $controller = self::readFile('web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php');

    foreach ([$installConfig, $activeConfig] as $config) {
      $this->assertArrayHasKey('vector_index_hygiene', $config);
      $policy = $config['vector_index_hygiene'];

      $this->assertTrue($policy['enabled']);
      $this->assertSame('p2_del_03_v1', $policy['policy_version']);
      $this->assertSame('incremental', $policy['refresh_mode']);
      $this->assertSame(24, $policy['refresh_interval_hours']);
      $this->assertSame(45, $policy['overdue_grace_minutes']);
      $this->assertSame(60, $policy['max_items_per_run']);
      $this->assertSame(60, $policy['alert_cooldown_minutes']);

      $managed = $policy['managed_indexes'];
      $this->assertSame(['faq_vector', 'resource_vector'], array_keys($managed));
      $this->assertSame('faq_accordion_vector', $managed['faq_vector']['index_id']);
      $this->assertSame('assistant_resources_vector', $managed['resource_vector']['index_id']);

      foreach ($managed as $indexPolicy) {
        $this->assertSame('Content Operations Lead', $indexPolicy['owner_role']);
        $this->assertSame('pinecone_vector', $indexPolicy['expected_server_id']);
        $this->assertSame('cosine_similarity', $indexPolicy['expected_metric']);
        $this->assertSame(3072, $indexPolicy['expected_dimensions']);
      }
    }

    $schemaMapping = $schema['ilas_site_assistant.settings']['mapping']['vector_index_hygiene']['mapping'] ?? [];
    $this->assertArrayHasKey('managed_indexes', $schemaMapping);
    $this->assertArrayHasKey('refresh_interval_hours', $schemaMapping);
    $this->assertArrayHasKey('overdue_grace_minutes', $schemaMapping);
    $this->assertArrayHasKey('max_items_per_run', $schemaMapping);
    $this->assertArrayHasKey('alert_cooldown_minutes', $schemaMapping);
    $managedMapping = $schemaMapping['managed_indexes']['mapping'] ?? [];
    $this->assertSame(['faq_vector', 'resource_vector'], array_keys($managedMapping));

    $this->assertStringContainsString('ilas_site_assistant.vector_index_hygiene:', $services);
    $this->assertStringContainsString('VectorIndexHygieneService', $services);
    $this->assertStringContainsString("'@entity_type.manager'", $services);

    $this->assertStringContainsString("hasService('ilas_site_assistant.vector_index_hygiene')", $module);
    $this->assertStringContainsString("service('ilas_site_assistant.vector_index_hygiene')->runScheduledRefresh()", $module);
    $this->assertStringContainsString('Vector index hygiene refresh failed', $module);

    $this->assertStringContainsString('VectorIndexHygieneService $vector_index_hygiene = NULL', $controller);
    $this->assertStringContainsString('ilas_site_assistant.vector_index_hygiene', $controller);
    $this->assertStringContainsString("\$checks['vector_index_hygiene']", $controller);
    $this->assertStringContainsString("\$response['metrics']['vector_index_hygiene']", $controller);
    $this->assertStringContainsString("\$response['thresholds']['vector_index_hygiene']", $controller);
  }

  /**
   * Backlog and risk linkage must move to active mitigation posture.
   */
  public function testBacklogAndRiskRegisterMoveToActiveMitigation(): void {
    $backlog = self::readFile('docs/aila/backlog.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString(
      'Active mitigation (IMP-RAG-02 / P2-DEL-03, 2026-03-04)',
      $backlog
    );

    $this->assertStringContainsString('| R-RAG-02 |', $riskRegister);
    $this->assertStringContainsString('| R-GOV-02 |', $riskRegister);
    $this->assertStringContainsString('metrics.vector_index_hygiene.totals', $riskRegister);
    $this->assertStringContainsString('drift_fields', $riskRegister);
    $this->assertStringContainsString('metrics.source_governance', $riskRegister);
    $this->assertStringContainsString('| Active mitigation |', $riskRegister);
  }

  /**
   * Scope boundary language must remain continuous across closure docs.
   */
  public function testScopeBoundaryTextContinuity(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $currentState = self::readFile('docs/aila/current-state.md');
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);

    $this->assertStringContainsString('no live production LLM', $currentState);
    $this->assertStringContainsString('enablement through Phase 2', $currentState);
    $this->assertStringContainsString('no broad platform migration outside', $currentState);
    $this->assertStringContainsString('current Pantheon baseline', $currentState);

    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('no broad platform migration outside the current Pantheon baseline', $runbook);
  }

}
