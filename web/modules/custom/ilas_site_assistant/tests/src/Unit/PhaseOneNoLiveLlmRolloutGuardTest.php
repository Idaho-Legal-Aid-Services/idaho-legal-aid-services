<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the staged, runtime-toggle rollout posture for request-time LLM.
 */
#[Group('ilas_site_assistant')]
final class PhaseOneNoLiveLlmRolloutGuardTest extends TestCase {

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

  public function testDocsDescribeRuntimeToggleControlledCohereRollout(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');
    $currentState = self::readFile('docs/aila/current-state.md');
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('Cohere-first request-time transition addendum (2026-04-15)', $roadmap);
    $this->assertStringContainsString('runtime toggle + runtime secret (`ILAS_LLM_ENABLED` + `ILAS_COHERE_API_KEY`)', $roadmap);
    $this->assertStringContainsString('2026-04-15 Cohere-first transition note', $currentState);
    $this->assertStringContainsString('ILAS_LLM_ENABLED` + `ILAS_COHERE_API_KEY`', $runbook);
  }

  public function testSettingsPhpKeepsLiveVectorDisabledAndLlmRuntimeControlled(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['vector_search']['enabled'] = FALSE;", $settings);
    $this->assertStringContainsString("_ilas_get_secret('ILAS_COHERE_API_KEY')", $settings);
    $this->assertStringContainsString("_ilas_get_secret('ILAS_LLM_ENABLED')", $settings);
    $this->assertStringNotContainsString("\$config['ilas_site_assistant.settings']['llm.enabled'] = FALSE;", $settings);
  }

  public function testAssistantSettingsFormKeepsLiveLlmGuardrails(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringContainsString("'#disabled' => \$is_live_environment", $form);
    $this->assertStringContainsString("setErrorByName(\n        'llm_enabled',", $form);
    $this->assertStringContainsString("if (\$this->isLiveEnvironment()) {\n      \$llm_enabled = FALSE;", $form);
    $this->assertStringContainsString('ILAS_COHERE_API_KEY', $form);
    $this->assertStringContainsString('ILAS_LLM_ENABLED', $form);
  }

}
