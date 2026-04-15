<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards that live production enablement remains runtime-controlled.
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoNoLiveLlmProductionEnablementGuardTest extends TestCase {

  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path);
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    return $contents;
  }

  public function testRoadmapAndRunbookStateLiveEnablementIsRuntimeOnly(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('Live rollout remains runtime-toggle controlled', $roadmap);
    $this->assertStringContainsString('request-time LLM verification', $runbook);
    $this->assertStringContainsString('live vector search remains hard-disabled', $runbook);
  }

  public function testRuntimeGuardAnchorsRemainPresent(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $gate = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_ENABLED')", $settings);
    $this->assertStringContainsString('runtime-only. Set <code>ILAS_LLM_ENABLED</code>', $form);
    $this->assertStringContainsString('protected function isLlmEffectivelyEnabled(): bool', $gate);
    $this->assertStringContainsString('DECISION_FALLBACK_LLM', $gate);
  }

}
