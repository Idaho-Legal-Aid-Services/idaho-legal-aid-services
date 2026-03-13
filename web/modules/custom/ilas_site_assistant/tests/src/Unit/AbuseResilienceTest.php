<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\InputNormalizer;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Data-driven abuse resilience test suite.
 *
 * Loads abuse_test_cases.json and verifies that the InputNormalizer +
 * SafetyClassifier pipeline correctly classifies adversarial inputs.
 *
 * Covers findings:
 * - F-08: Obfuscation bypass (interstitial punctuation, spaced letters)
 * - F-09: Hyphenation bypass
 * - F-10: Informational dampener
 * - F-11: Bilingual injection (mixed EN/ES, obfuscated Spanish)
 */
#[Group('ilas_site_assistant')]
class AbuseResilienceTest extends TestCase {

  /**
   * The safety classifier instance.
   */
  protected SafetyClassifier $classifier;

  /**
   * Loaded test cases from fixture file.
   */
  protected static array $testCases = [];

  /**
   * Per-category pass/fail counters.
   *
   * @var array<string, array{pass: int, fail: int}>
   */
  protected static array $categoryStats = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // SafetyClassifier requires a config factory; provide a minimal stub.
    $config = $this->createStub(\Drupal\Core\Config\ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $this->classifier = new SafetyClassifier($configFactory);
  }

  /**
   * Loads fixture data once for the entire test class.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();

    // __DIR__ = tests/src/Unit; go up 2 to tests/, then into fixtures/.
    $fixture_path = dirname(__DIR__, 2) . '/fixtures/abuse_test_cases.json';
    $json = file_get_contents($fixture_path);
    self::$testCases = json_decode($json, TRUE) ?? [];

    // Initialize category stats.
    foreach (array_keys(self::$testCases) as $category) {
      self::$categoryStats[$category] = ['pass' => 0, 'fail' => 0, 'total' => count(self::$testCases[$category])];
    }
  }

  /**
   * Reports per-category pass rates after all tests complete.
   */
  public static function tearDownAfterClass(): void {
    parent::tearDownAfterClass();

    fwrite(STDERR, "\n--- Abuse Resilience Per-Category Results ---\n");
    foreach (self::$categoryStats as $category => $stats) {
      $total = $stats['pass'] + $stats['fail'];
      $pct = $total > 0 ? round(100 * $stats['pass'] / $total, 1) : 0;
      fwrite(STDERR, sprintf(
        "  %-30s %d/%d passed (%.1f%%)\n",
        $category,
        $stats['pass'],
        $total,
        $pct
      ));
    }
    fwrite(STDERR, "---------------------------------------------\n");
  }

  /**
   * Data provider returning all test cases across all categories.
   */
  public static function abuseTestCaseProvider(): array {
    $fixture_path = dirname(__DIR__, 2) . '/fixtures/abuse_test_cases.json';
    $json = file_get_contents($fixture_path);
    $all = json_decode($json, TRUE);

    $cases = [];
    foreach ($all as $category => $entries) {
      foreach ($entries as $i => $entry) {
        $label = sprintf('%s/%d: %s', $category, $i, $entry['description']);
        $cases[$label] = [
          $category,
          $entry['input'],
          $entry['expected_class'],
          $entry['expected_safe'],
          $entry['description'],
        ];
      }
    }

    return $cases;
  }

  /**
   * Tests each abuse case through normalize → classify pipeline.
   */
  #[DataProvider('abuseTestCaseProvider')]
  public function testAbuseCase(
    string $category,
    string $input,
    string $expected_class,
    bool $expected_safe,
    string $description,
  ): void {
    // Phase 1: Normalize input (strips obfuscation).
    $normalized = InputNormalizer::normalize($input);

    // Phase 2: Classify the normalized input.
    $result = $this->classifier->classify($normalized);

    // Track stats for reporting.
    $passed = TRUE;

    // Assert safety flag.
    $this->assertEquals(
      $expected_safe,
      $result['is_safe'],
      sprintf(
        "[%s] is_safe mismatch for: \"%s\" (normalized: \"%s\") — got class=%s, expected_safe=%s, actual_safe=%s",
        $category,
        $input,
        $normalized,
        $result['class'],
        $expected_safe ? 'true' : 'false',
        $result['is_safe'] ? 'true' : 'false'
      )
    );

    // Assert classification class.
    $this->assertEquals(
      $expected_class,
      $result['class'],
      sprintf(
        "[%s] class mismatch for: \"%s\" (normalized: \"%s\") — expected=%s, got=%s",
        $category,
        $input,
        $normalized,
        $expected_class,
        $result['class']
      )
    );

    // Record pass/fail.
    if (isset(self::$categoryStats[$category])) {
      self::$categoryStats[$category]['pass']++;
    }
  }

}
