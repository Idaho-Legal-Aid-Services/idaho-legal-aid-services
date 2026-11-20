<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the helper that decides whether a pull request may be merged.
 *
 * On 2026-11-20 git:finish merged a pull request whose SEO job had been
 * cancelled at its time limit: `gh pr checks --watch` printed the job as
 * "fail" and exited 0. checks_not_passed() reads every check's outcome
 * instead, and finish.sh refuses to merge when it prints anything.
 */
#[Group('ilas_site_assistant')]
final class PullRequestChecksGuardTest extends TestCase {

  /**
   * Runs checks_not_passed on the given `gh pr view` JSON.
   *
   * @param array<int, array<string, string>>|null $rollup
   *   The statusCheckRollup entries, or NULL for unreadable output.
   *
   * @return string[]
   *   The lines the helper printed.
   */
  private function notPassed(?array $rollup): array {
    $root = dirname(__DIR__, 7);
    $json = $rollup === NULL ? 'not json' : (string) json_encode(['statusCheckRollup' => $rollup]);
    $command = 'source ' . escapeshellarg($root . '/scripts/git/common.sh') . ' && printf %s ' . escapeshellarg($json) . ' | checks_not_passed';
    $lines = [];
    $status = 0;
    exec('bash -c ' . escapeshellarg($command) . ' 2>&1', $lines, $status);
    $this->assertSame(0, $status, implode("\n", $lines));

    return $lines;
  }

  /**
   * Success, skipped and neutral checks let a pull request through.
   */
  public function testPassedSkippedAndNeutralChecksPrintNothing(): void {
    $this->assertSame([], $this->notPassed([
      ['name' => 'Basic Quality Gate', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
      ['name' => 'Hosted Manual Assistant Gate', 'status' => 'COMPLETED', 'conclusion' => 'SKIPPED'],
      ['name' => 'CodeQL', 'status' => 'COMPLETED', 'conclusion' => 'NEUTRAL'],
      ['context' => 'external/status', 'state' => 'SUCCESS'],
    ]));
  }

  /**
   * A cancelled job is not a pass, whatever gh's exit status says.
   */
  public function testCancelledTimedOutFailedAndRunningChecksAreListed(): void {
    $this->assertSame(
      [
        'SEO Functional + Kernel Tests (DDEV): cancelled',
        'Slow job: timed_out',
        'Broken job: failure',
        'Still going: in_progress',
        'Not started: queued',
        'external/status: pending',
      ],
      $this->notPassed([
        ['name' => 'Basic Quality Gate', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
        ['name' => 'SEO Functional + Kernel Tests (DDEV)', 'status' => 'COMPLETED', 'conclusion' => 'CANCELLED'],
        ['name' => 'Slow job', 'status' => 'COMPLETED', 'conclusion' => 'TIMED_OUT'],
        ['name' => 'Broken job', 'status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
        ['name' => 'Still going', 'status' => 'IN_PROGRESS', 'conclusion' => ''],
        ['name' => 'Not started', 'status' => 'QUEUED'],
        ['context' => 'external/status', 'state' => 'PENDING'],
      ]),
    );
  }

  /**
   * Output that cannot be read is treated as not passed.
   */
  public function testUnreadableOutputIsNotAPass(): void {
    $this->assertSame(['(could not read the checks of the pull request)'], $this->notPassed(NULL));
  }

  /**
   * The finish command uses the helper before it merges.
   */
  public function testFinishRefusesToMergeWhenAnythingIsListed(): void {
    $finish = (string) file_get_contents(dirname(__DIR__, 7) . '/scripts/git/finish.sh');
    $guard = strpos($finish, 'gh pr view "$pr_number" --json statusCheckRollup | checks_not_passed');
    $merge = strpos($finish, 'gh pr merge "$pr_number"');

    $this->assertNotFalse($guard);
    $this->assertNotFalse($merge);
    $this->assertLessThan($merge, $guard);
  }

}
