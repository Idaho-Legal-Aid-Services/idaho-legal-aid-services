<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards Phase 2 Objective #3 closure artifacts (`P2-OBJ-03`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoObjectiveThreeGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 2 Objective #3.
   */
  public function testRoadmapContainsPhaseTwoObjectiveThreeDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('## Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity', $roadmap);
    $this->assertStringContainsString(
      '3. Enforce governance around source freshness and provenance.',
      $roadmap
    );
    $this->assertStringContainsString('### Phase 2 Objective #3 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('soft-governance enforcement mode', $roadmap);
    $this->assertStringContainsString('`faq_lexical`, `faq_vector`, `resource_lexical`, `resource_vector`', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('No broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-133', $roadmap);
  }

  /**
   * Current-state must include retrieval governance row and objective addendum.
   */
  public function testCurrentStateContainsObjectiveThreeGovernanceAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('Source freshness + provenance governance', $currentState);
    $this->assertStringContainsString('`provenance`, `freshness`, `governance_flags`', $currentState);
    $this->assertStringContainsString(
      '### Phase 2 Objective #3 Source Freshness + Provenance Governance Disposition (2026-03-03)',
      $currentState
    );
    $this->assertStringContainsString('GOVERNANCE_ENFORCEMENT_MATRIX', $currentState);
    $this->assertStringContainsString('[^CLAIM-133]', $currentState);
  }

  /**
   * Runbook section 4 must provide reproducible P2-OBJ-03 verification steps.
   */
  public function testRunbookContainsObjectiveThreeVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 source freshness + provenance governance verification (`P2-OBJ-03`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-DRUPAL-UNIT', $runbook);
    $this->assertStringContainsString('SourceGovernanceServiceTest.php', $runbook);
    $this->assertStringContainsString('PhaseTwoObjectiveThreeGateTest.php', $runbook);
    $this->assertStringContainsString('no stale-result filtering or ranking penalties are introduced', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('state:get ilas_site_assistant.source_governance.snapshot', $runbook);
    $this->assertStringContainsString('state:delete ilas_site_assistant.source_governance.last_alert', $runbook);
    $this->assertStringContainsString('terminus remote:drush "idaho-legal-aid-services.${ENV}" -- state:get ilas_site_assistant.source_governance.snapshot', $runbook);
  }

  /**
   * Evidence index must include objective closure claim and addenda.
   */
  public function testEvidenceIndexContainsObjectiveThreeClaimsAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-067', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-122', $evidenceIndex);
    $this->assertStringContainsString('Phase 2 Objective #3 (`P2-OBJ-03`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-133', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Objective #3 Source Freshness + Provenance Governance (`P2-OBJ-03`)', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoObjectiveThreeGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('Follow-on addendum (2026-03-03): balanced ratio+sample degrade thresholds', $evidenceIndex);
  }

  /**
   * Diagram A governance anchors must remain present for objective context.
   */
  public function testSystemMapRetainsDiagramAGovernanceAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('SGOV[Source governance', $systemMap);
    $this->assertStringContainsString('RET --> SGOV', $systemMap);
    $this->assertStringContainsString('SGOV --> DASH', $systemMap);
    $this->assertStringContainsString('SGOV --> ALT', $systemMap);
  }

  /**
   * Config/schema/services must include source governance anchors.
   */
  public function testSourceGovernanceConfigSchemaAndServiceAnchors(): void {
    $installConfig = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $activeConfig = self::readYaml('config/ilas_site_assistant.settings.yml');
    $schema = self::readYaml('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');

    foreach ([$installConfig, $activeConfig] as $config) {
      $this->assertArrayHasKey('source_governance', $config);
      $this->assertTrue($config['source_governance']['enabled']);
      $this->assertSame('p2_obj_03_v1', $config['source_governance']['policy_version']);
      $this->assertSame(24, $config['source_governance']['observation_window_hours']);
      $this->assertSame(18.0, (float) $config['source_governance']['stale_ratio_alert_pct']);
      $this->assertSame(20, $config['source_governance']['min_observations']);
      $this->assertSame(22.0, (float) $config['source_governance']['unknown_ratio_degrade_pct']);
      $this->assertSame(9.0, (float) $config['source_governance']['missing_source_url_ratio_degrade_pct']);
      $this->assertSame(60, $config['source_governance']['alert_cooldown_minutes']);

      $classes = $config['source_governance']['source_classes'];
      $this->assertSame(['faq_lexical', 'faq_vector', 'resource_lexical', 'resource_vector'], array_keys($classes));

      foreach ($classes as $classPolicy) {
        $this->assertSame('Content Operations Lead', $classPolicy['owner_role']);
        $this->assertSame(180, $classPolicy['max_age_days']);
        $this->assertTrue($classPolicy['require_source_url']);
      }
    }

    $schemaMapping = $schema['ilas_site_assistant.settings']['mapping']['source_governance']['mapping'] ?? [];
    $this->assertArrayHasKey('source_classes', $schemaMapping);
    $this->assertArrayHasKey('min_observations', $schemaMapping);
    $this->assertArrayHasKey('unknown_ratio_degrade_pct', $schemaMapping);
    $this->assertArrayHasKey('missing_source_url_ratio_degrade_pct', $schemaMapping);
    $classMapping = $schemaMapping['source_classes']['mapping'] ?? [];
    $this->assertSame(['faq_lexical', 'faq_vector', 'resource_lexical', 'resource_vector'], array_keys($classMapping));

    $this->assertStringContainsString('ilas_site_assistant.source_governance:', $services);
    $this->assertStringContainsString("'@ilas_site_assistant.source_governance'", $services);
    $this->assertStringContainsString('SourceGovernanceService', self::readFile('web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php'));
  }

}
