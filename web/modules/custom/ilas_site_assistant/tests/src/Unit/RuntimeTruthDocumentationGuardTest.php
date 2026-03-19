<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the canonical override-aware runtime-truth documentation contract.
 */
#[Group('ilas_site_assistant')]
class RuntimeTruthDocumentationGuardTest extends TestCase {

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * The runbook records the canonical TOVR-08 verification flow.
   */
  public function testRunbookContainsTovr08RuntimeTruthSection(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### TOVR-08 override-aware runtime truth verification', $runbook);
    $this->assertStringContainsString('### AFRP-02 runtime truth expansion', $runbook);
    $this->assertStringContainsString('ddev drush ilas:runtime-truth', $runbook);
    $this->assertStringContainsString('terminus remote:drush "idaho-legal-aid-services.${ENV}" -- ilas:runtime-truth', $runbook);
    $this->assertStringContainsString('Stored-config-only habits to avoid', $runbook);
    $this->assertStringContainsString('config:get raven.settings', $runbook);
    $this->assertStringContainsString('config:get langfuse.settings', $runbook);
    $this->assertStringContainsString('assistant settings runtime truth', $runbook);
    $this->assertStringContainsString('assistant_page_suppressed=true', $runbook);
    $this->assertStringContainsString('assistant-route GA suppression', $runbook);
  }

  /**
   * The prompt-pack VC-RUNTIME commands use the helper instead of ad hoc evals.
   */
  public function testPromptPackValidationMatrixUsesRuntimeTruthHelper(): void {
    $promptPack = self::readFile('docs/aila/tooling-observability-vector-remediation-prompt-pack.md');

    preg_match('/^\| `VC-RUNTIME-LOCAL-SAFE` \| (?P<command>.+)$/m', $promptPack, $localMatch);
    preg_match('/^\| `VC-RUNTIME-PANTHEON-SAFE` \| (?P<command>.+)$/m', $promptPack, $pantheonMatch);

    $this->assertNotEmpty($localMatch['command'] ?? '');
    $this->assertNotEmpty($pantheonMatch['command'] ?? '');

    $this->assertStringContainsString('ddev drush ilas:runtime-truth', $localMatch['command']);
    $this->assertStringContainsString('gtag\\\\(', $localMatch['command']);
    $this->assertStringNotContainsString('php:eval', $localMatch['command']);
    $this->assertStringNotContainsString('config:get', $localMatch['command']);

    $this->assertStringContainsString('terminus remote:drush idaho-legal-aid-services.$ENV -- ilas:runtime-truth', $pantheonMatch['command']);
    $this->assertStringContainsString('gtag\\\\(', $pantheonMatch['command']);
    $this->assertStringNotContainsString('php:eval', $pantheonMatch['command']);
    $this->assertStringNotContainsString('config:get', $pantheonMatch['command']);
  }

  /**
   * Current-state, evidence, and roadmap all record the TOVR-08 disposition.
   */
  public function testTovr08DispositionIsRecordedAcrossCanonicalDocs(): void {
    $currentState = self::readFile('docs/aila/current-state.md');
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('TOVR-08', $currentState);
    $this->assertStringContainsString('AFRP-02', $currentState);
    $this->assertStringContainsString('ilas:runtime-truth', $currentState);
    $this->assertStringContainsString('storage-only and historical', $currentState);

    $this->assertStringContainsString('### CLAIM-213', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-214', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-215', $evidenceIndex);
    $this->assertStringContainsString('afrp-02-runtime-truth-remediation.txt', $evidenceIndex);
    $this->assertStringContainsString('docs/aila/runtime/tovr-08-runtime-truth-verification.txt', $evidenceIndex);

    $this->assertStringContainsString('### TOVR-08 override-aware runtime truth disposition', $roadmap);
    $this->assertStringContainsString('AFRP-02 runtime truth expansion', $roadmap);
    $this->assertStringContainsString('ilas:runtime-truth', $roadmap);
  }

}
