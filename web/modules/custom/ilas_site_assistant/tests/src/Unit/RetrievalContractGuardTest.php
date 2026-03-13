<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Architectural guard tests for the Drupal-primary retrieval contract (PHARD-06).
 *
 * Static analysis guards (following PhaseOneNoRetrievalArchitectureRedesignGuardTest
 * pattern): reads file contents and asserts structural invariants.
 *
 */
#[Group('ilas_site_assistant')]
final class RetrievalContractGuardTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from the repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * FaqIndex.php must import RetrievalContract.
   */
  public function testFaqIndexImportsRetrievalContract(): void {
    $contents = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php');

    $this->assertMatchesRegularExpression(
      '/use\s+.*RetrievalContract/',
      $contents,
      'FaqIndex.php must import RetrievalContract.',
    );
  }

  /**
   * ResourceFinder.php must import RetrievalContract.
   */
  public function testResourceFinderImportsRetrievalContract(): void {
    $contents = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php');

    $this->assertMatchesRegularExpression(
      '/use\s+.*RetrievalContract/',
      $contents,
      'ResourceFinder.php must import RetrievalContract.',
    );
  }

  /**
   * SourceGovernanceService.php must import RetrievalContract.
   */
  public function testSourceGovernanceImportsRetrievalContract(): void {
    $contents = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/SourceGovernanceService.php');

    $this->assertMatchesRegularExpression(
      '/use\s+.*RetrievalContract/',
      $contents,
      'SourceGovernanceService.php must import RetrievalContract.',
    );
  }

  /**
   * In FaqIndex.php, search() appears before supplementWithVectorResults().
   *
   * Ensures lexical search is always attempted first, vector only supplements.
   */
  public function testFaqIndexVectorSupplementFollowsLexical(): void {
    $contents = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FaqIndex.php');

    $search_pos = strpos($contents, 'public function search(');
    $supplement_pos = strpos($contents, 'function supplementWithVectorResults(');

    $this->assertNotFalse($search_pos, 'FaqIndex must have a search() method.');
    $this->assertNotFalse($supplement_pos, 'FaqIndex must have supplementWithVectorResults().');
    $this->assertLessThan(
      $supplement_pos,
      $search_pos,
      'search() must appear before supplementWithVectorResults() in FaqIndex.',
    );
  }

  /**
   * In ResourceFinder.php, findByTypeSearchApi() appears before supplementWithVectorResults().
   *
   * Ensures lexical search is always attempted first, vector only supplements.
   */
  public function testResourceFinderVectorSupplementFollowsLexical(): void {
    $contents = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/ResourceFinder.php');

    $search_pos = strpos($contents, 'function findByTypeSearchApi(');
    $supplement_pos = strpos($contents, 'function supplementWithVectorResults(');

    $this->assertNotFalse($search_pos, 'ResourceFinder must have findByTypeSearchApi().');
    $this->assertNotFalse($supplement_pos, 'ResourceFinder must have supplementWithVectorResults().');
    $this->assertLessThan(
      $supplement_pos,
      $search_pos,
      'findByTypeSearchApi() must appear before supplementWithVectorResults() in ResourceFinder.',
    );
  }

  /**
   * Active config YAML must contain a retrieval_contract block.
   */
  public function testConfigContainsRetrievalContractBlock(): void {
    $path = self::repoRoot() . '/config/ilas_site_assistant.settings.yml';
    self::assertFileExists($path);

    $config = Yaml::parseFile($path);
    $this->assertArrayHasKey(
      'retrieval_contract',
      $config,
      'Active config must contain a retrieval_contract block.',
    );

    $contract = $config['retrieval_contract'];
    $this->assertArrayHasKey('policy_version', $contract);
    $this->assertArrayHasKey('enforcement_mode', $contract);
    $this->assertArrayHasKey('approved_source_classes', $contract);
    $this->assertArrayHasKey('primary_source_classes', $contract);
    $this->assertArrayHasKey('supplement_source_classes', $contract);
  }

}
