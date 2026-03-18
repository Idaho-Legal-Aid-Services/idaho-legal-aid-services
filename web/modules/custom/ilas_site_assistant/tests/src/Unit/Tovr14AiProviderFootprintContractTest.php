<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Locks the TOVR-14 minimal AI/provider footprint contract.
 */
#[Group('ilas_site_assistant')]
final class Tovr14AiProviderFootprintContractTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repo file with existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Reads a YAML file with existence checks.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML file: {$relativePath}");
    return $parsed;
  }

  /**
   * Enabled AI/provider modules are reduced to the current vector minimum.
   */
  public function testCoreExtensionKeepsOnlyRequiredAiProviderModulesEnabled(): void {
    $coreExtension = self::readYaml('config/core.extension.yml');
    $modules = $coreExtension['module'] ?? [];

    foreach (['ai', 'ai_search', 'ai_vdb_provider_pinecone', 'gemini_provider', 'key'] as $module) {
      $this->assertArrayHasKey($module, $modules);
    }

    foreach (['ai_provider_google_vertex', 'ai_seo', 'metatag_ai'] as $module) {
      $this->assertArrayNotHasKey($module, $modules);
    }
  }

  /**
   * Removed module-owned config and the dormant Vertex key export stay absent.
   */
  public function testRemovedModuleConfigAndVertexKeyExportStayAbsent(): void {
    $removedPaths = [
      'config/ai_provider_google_vertex.settings.yml',
      'config/ai_seo.settings.yml',
      'config/ai_seo.report_type.full.yml',
      'config/ai_seo.report_type.headings_and_structure.yml',
      'config/ai_seo.report_type.link_analysis.yml',
      'config/ai_seo.report_type.natural_language.yml',
      'config/ai_seo.report_type.schema_org_markup.yml',
      'config/ai_seo.report_type.topic_authority.yml',
      'config/system.action.generate_metatag_action.yml',
      'config/key.key.vertex_sa_credentials.yml',
    ];

    foreach ($removedPaths as $path) {
      $this->assertFileDoesNotExist(self::repoRoot() . '/' . $path);
    }
  }

  /**
   * Canonical docs and the runtime report record the TOVR-14 disposition.
   */
  public function testCanonicalDocsRecordTovr14Disposition(): void {
    $report = self::readFile('docs/aila/runtime/tovr-14-ai-provider-footprint-rationalization.txt');
    $currentState = self::readFile('docs/aila/current-state.md');
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $runbook = self::readFile('docs/aila/runbook.md');
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');
    $riskRegister = self::readFile('docs/aila/risk-register.md');

    $this->assertStringContainsString('TOVR-14', $report);
    $this->assertStringContainsString('Hosted post-change status: `Unverified (no deploy performed in TOVR-14)`', $report);
    $this->assertStringContainsString('ai_provider_google_vertex', $report);
    $this->assertStringContainsString('metatag_ai', $report);
    $this->assertStringContainsString('gemini_provider', $report);

    $this->assertStringContainsString('TOVR-14', $currentState);
    $this->assertStringContainsString('minimum proven vector stack', $currentState);
    $this->assertStringContainsString('### TOVR-14 AI/provider footprint rationalization disposition', $roadmap);
    $this->assertStringContainsString('No exported', $runbook);
    $this->assertStringContainsString('Drupal config should now carry Vertex credential material.', $runbook);
    $this->assertStringContainsString('## TOVR-14 AI Provider Footprint Rationalization', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-239', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-242', $evidenceIndex);
    $this->assertStringContainsString('R-SEC-04', $riskRegister);
    $this->assertStringContainsString('runtime-only `ILAS_VERTEX_SA_JSON`', $riskRegister);
  }

}
