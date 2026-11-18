<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ties the names finish.sh waits for to the names the workflow defines.
 *
 * The finish.sh helper merges the publish pull request once CI is green. It
 * finds the checks and the gate-summary artifact by name. The workflow's jobs were
 * renamed and the script was not, so it waited for checks that no longer
 * existed and timed out on every run; nobody noticed because merges were
 * being done by hand. A rename now fails here instead.
 */
#[Group('ilas_site_assistant')]
final class FinishHelperWorkflowNamesTest extends TestCase {

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a quoted shell variable assignment from finish.sh.
   */
  private static function finishVariable(string $name): string {
    $script = (string) file_get_contents(self::repoRoot() . '/scripts/git/finish.sh');
    self::assertSame(1, preg_match('/^' . $name . '="([^"]+)"$/m', $script, $match), "$name is defined in finish.sh");
    return $match[1];
  }

  /**
   * Every check and artifact name finish.sh uses exists in the workflow.
   */
  public function testFinishHelperNamesExistInTheQualityGateWorkflow(): void {
    $workflow = (string) file_get_contents(self::repoRoot() . '/.github/workflows/quality-gate.yml');

    foreach (['BASIC_GATE_CHECK_NAME', 'ASSISTANT_GATE_CHECK_NAME'] as $variable) {
      $this->assertStringContainsString(
        "\n    name: " . self::finishVariable($variable) . "\n",
        $workflow,
        "$variable must be the name of a job in quality-gate.yml",
      );
    }

    $artifact = self::finishVariable('PROMPTFOO_ARTIFACT_NAME');
    $this->assertStringContainsString("          name: {$artifact}\n", $workflow);
    // The artifact the script downloads is the one holding the gate summary.
    $upload = substr($workflow, (int) strpos($workflow, "          name: {$artifact}\n"), 600);
    $this->assertStringContainsString('promptfoo-evals/output/gate-summary.txt', $upload);
  }

  /**
   * The names that caused the timeout are gone from the script.
   */
  public function testStaleCheckNamesAreNotUsed(): void {
    $script = (string) file_get_contents(self::repoRoot() . '/scripts/git/finish.sh');

    $this->assertStringNotContainsString('PHPUnit Quality Gate', $script);
    $this->assertStringNotContainsString('"Promptfoo Gate"', $script);
  }

}
