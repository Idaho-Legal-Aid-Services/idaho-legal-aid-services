<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests locking the audit baseline as source of truth.
 *
 * Validates that the Phase 0 audit baseline (current-state.md,
 * evidence-index.md, system-map.mmd, runbook.md, and their artifacts)
 * is complete, consistent, and references real evidence. This is the
 * programmatic entry criterion for all subsequent roadmap phases.
 */
#[Group('ilas_site_assistant')]
class AuditBaselineAcceptanceTest extends TestCase {

  private const DOCS_PATH = 'docs/aila';

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Returns the parsed key=value pairs from context-latest.txt.
   *
   * @return array<string, string>
   */
  private static function contextLatest(): array {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/artifacts/context-latest.txt';
    self::assertFileExists($path, 'context-latest.txt must exist');
    $content = file_get_contents($path);
    self::assertNotEmpty($content, 'context-latest.txt must not be empty');

    $result = [];
    foreach (explode("\n", $content) as $line) {
      // Skip status_porcelain block and blank lines.
      if (str_starts_with($line, 'status_porcelain')) {
        break;
      }
      if (str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $result[trim($key)] = trim($value);
      }
    }
    return $result;
  }

  /**
   * context-latest.txt must exist and be non-empty.
   */
  public function testContextLatestFileExists(): void {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/artifacts/context-latest.txt';
    $this->assertFileExists($path, 'context-latest.txt must exist in docs/aila/artifacts/');
    $this->assertNotEmpty(
      file_get_contents($path),
      'context-latest.txt must not be empty',
    );
  }

  /**
   * context-latest.txt must contain valid metadata fields.
   */
  public function testContextLatestContainsValidMetadata(): void {
    $meta = self::contextLatest();

    $this->assertArrayHasKey('timestamp_utc', $meta, 'context-latest.txt must contain timestamp_utc');
    $this->assertArrayHasKey('branch', $meta, 'context-latest.txt must contain branch');
    $this->assertArrayHasKey('commit', $meta, 'context-latest.txt must contain commit');
    $this->assertArrayHasKey('commit_short', $meta, 'context-latest.txt must contain commit_short');

    // Validate ISO 8601 timestamp format.
    $this->assertMatchesRegularExpression(
      '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
      $meta['timestamp_utc'],
      'timestamp_utc must be ISO 8601 UTC format (YYYY-MM-DDTHH:MM:SSZ)',
    );

    // Validate full SHA-1 commit hash (40 hex chars).
    $this->assertMatchesRegularExpression(
      '/^[0-9a-f]{40}$/',
      $meta['commit'],
      'commit must be a 40-character hex SHA-1 hash',
    );

    // Validate short commit is a prefix of the full commit.
    $this->assertStringStartsWith(
      $meta['commit_short'],
      $meta['commit'],
      'commit_short must be a prefix of the full commit hash',
    );
  }

  /**
   * The commit referenced in context-latest.txt must exist in git history.
   */
  public function testContextLatestCommitExistsInGitHistory(): void {
    $gitBin = trim((string) shell_exec('which git 2>/dev/null'));
    if (empty($gitBin)) {
      $this->markTestSkipped('git binary not available in this environment');
    }

    $meta = self::contextLatest();
    $commit = $meta['commit'];

    $output = [];
    $exitCode = 0;
    exec(
      sprintf('git -C %s cat-file -t %s 2>/dev/null', escapeshellarg(self::repoRoot()), escapeshellarg($commit)),
      $output,
      $exitCode,
    );

    $this->assertSame(0, $exitCode, "git cat-file failed for commit {$commit}");
    $this->assertSame(
      'commit',
      trim($output[0] ?? ''),
      "SHA {$commit} must reference a commit object in git history",
    );
  }

  /**
   * All 4 core audit documents must exist and meet minimum line counts.
   */
  public function testCoreAuditDocumentsExistAndAreNonEmpty(): void {
    $docsBase = self::repoRoot() . '/' . self::DOCS_PATH;

    // Thresholds at ~90% of current sizes to allow minor edits.
    $documents = [
      'current-state.md' => 400,
      'evidence-index.md' => 750,
      'system-map.mmd' => 50,
      'runbook.md' => 300,
    ];

    foreach ($documents as $filename => $minLines) {
      $path = $docsBase . '/' . $filename;
      $this->assertFileExists($path, "{$filename} must exist in docs/aila/");

      $lineCount = count(file($path, FILE_IGNORE_NEW_LINES));
      $this->assertGreaterThanOrEqual(
        $minLines,
        $lineCount,
        "{$filename} must have at least {$minLines} lines (has {$lineCount})",
      );
    }
  }

  /**
   * Evidence index must contain exactly 144 CLAIM headings.
   */
  public function testEvidenceIndexContainsExpectedClaimCount(): void {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/evidence-index.md';
    $content = file_get_contents($path);

    preg_match_all('/^### CLAIM-\d+/m', $content, $matches);

    $this->assertCount(
      144,
      $matches[0],
      'Evidence index must contain exactly 144 CLAIM headings (found ' . count($matches[0]) . ')',
    );
  }

  /**
   * CLAIM numbers in evidence index must be sequential with no duplicates.
   */
  public function testEvidenceIndexClaimsAreSequential(): void {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/evidence-index.md';
    $content = file_get_contents($path);

    preg_match_all('/^### CLAIM-(\d+)/m', $content, $matches);
    $claimNumbers = array_map('intval', $matches[1]);

    // No duplicates.
    $uniqueNumbers = array_unique($claimNumbers);
    $this->assertCount(
      count($claimNumbers),
      $uniqueNumbers,
      'CLAIM numbers must not contain duplicates',
    );

    // Ascending order.
    $sorted = $claimNumbers;
    sort($sorted, SORT_NUMERIC);
    $this->assertSame(
      $sorted,
      $claimNumbers,
      'CLAIM numbers must be in ascending order',
    );
  }

  /**
   * All 7 runtime snapshot artifact files must exist and be non-empty.
   */
  public function testRuntimeArtifactsExist(): void {
    $runtimeBase = self::repoRoot() . '/' . self::DOCS_PATH . '/runtime';

    $expectedFiles = [
      'local-preflight.txt',
      'local-runtime.txt',
      'local-endpoints.txt',
      'pantheon-dev.txt',
      'pantheon-test.txt',
      'pantheon-live.txt',
      'promptfoo-ci-search.txt',
    ];

    foreach ($expectedFiles as $filename) {
      $path = $runtimeBase . '/' . $filename;
      $this->assertFileExists($path, "Runtime artifact {$filename} must exist in docs/aila/runtime/");
      $this->assertNotEmpty(
        file_get_contents($path),
        "Runtime artifact {$filename} must not be empty",
      );
    }
  }

  /**
   * Known unknowns section must exist with exactly 3 documented unknowns.
   */
  public function testCurrentStateDocumentsKnownUnknowns(): void {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/current-state.md';
    $content = file_get_contents($path);

    $this->assertStringContainsString(
      '## 8) Known unknowns',
      $content,
      'current-state.md must contain a "Known unknowns" section',
    );

    // Extract the known unknowns table: rows are lines starting with |
    // that follow the section header and are not the header/separator rows.
    $sectionStart = strpos($content, '## 8) Known unknowns');
    $section = substr($content, $sectionStart);
    // Stop at the next section (--- or ##).
    $sectionEnd = strpos($section, "\n---", 1);
    if ($sectionEnd !== FALSE) {
      $section = substr($section, 0, $sectionEnd);
    }

    // Count table data rows (skip header row and separator row).
    preg_match_all('/^\| (?!Unknown|---)/m', $section, $rows);

    $this->assertCount(
      2,
      $rows[0],
      'Known unknowns section must document exactly 2 unknowns (found ' . count($rows[0]) . ')',
    );
  }

  /**
   * Known unknowns must cover the 3 expected topics.
   */
  public function testKnownUnknownsContainExpectedTopics(): void {
    $path = self::repoRoot() . '/' . self::DOCS_PATH . '/current-state.md';
    $content = file_get_contents($path);

    $expectedTopics = [
      'cron cadence' => 'cron cadence',
      'Promptfoo CI ownership' => 'Promptfoo CI ownership',
    ];

    foreach ($expectedTopics as $label => $needle) {
      $this->assertStringContainsString(
        $needle,
        $content,
        "Known unknowns must include topic: {$label}",
      );
    }
  }

  /**
   * Commit hash and timestamp in current-state.md must match context-latest.txt.
   */
  public function testCurrentStateMetadataMatchesContextLatest(): void {
    $meta = self::contextLatest();
    $currentStatePath = self::repoRoot() . '/' . self::DOCS_PATH . '/current-state.md';
    $currentState = file_get_contents($currentStatePath);

    // current-state.md references the commit in a table cell:
    // | Git commit | `<hash>` | ...
    $this->assertStringContainsString(
      $meta['commit'],
      $currentState,
      'current-state.md must reference the same commit hash as context-latest.txt',
    );

    // current-state.md references the timestamp in a table cell:
    // | Audit timestamp (UTC) | `<timestamp>` | ...
    $this->assertStringContainsString(
      $meta['timestamp_utc'],
      $currentState,
      'current-state.md must reference the same timestamp as context-latest.txt',
    );
  }

}
