<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AFRP-20: Citation freshness enforcement contract tests.
 *
 * Proves that the enforceable freshness policy changes citation-required
 * response behavior when freshness is weak, and that non-citation-required
 * types remain unaffected.
 */
#[CoversClass(ResponseGrounder::class)]
#[Group('ilas_site_assistant')]
final class CitationFreshnessEnforcementTest extends TestCase {

  /**
   * Builds a SourceGovernanceService with freshness enforcement enabled.
   */
  private function buildGovernanceService(bool $enforcement_enabled = TRUE): SourceGovernanceService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($enforcement_enabled) {
        if ($key === 'source_governance.freshness_enforcement.enabled') {
          return $enforcement_enabled;
        }
        return NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturn(NULL);

    $logger = $this->createStub(LoggerInterface::class);

    return new SourceGovernanceService($configFactory, $state, $logger);
  }

  /**
   * Builds a ResponseGrounder with the given governance service.
   */
  private function buildGrounder(bool $enforcement_enabled = TRUE): ResponseGrounder {
    return new ResponseGrounder($this->buildGovernanceService($enforcement_enabled));
  }

  /**
   * Builds a result with a given freshness status and valid ILAS URL.
   */
  private function buildResult(string $freshness, string $title = 'Test'): array {
    return [
      'title' => $title,
      'url' => '/resources/' . strtolower($title),
      'type' => 'resource',
      'freshness' => ['status' => $freshness],
    ];
  }

  // -------------------------------------------------------------------
  // Test 1: All fresh — no enforcement, freshness_profile present.
  // -------------------------------------------------------------------

  /**
   * Citation-required type with all fresh citations gets no enforcement.
   */
  public function testAllFreshCitationsNoEnforcement(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('fresh', 'Guide1'),
      $this->buildResult('fresh', 'Guide2'),
    ];
    $response = ['message' => 'Info', 'type' => 'faq'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('freshness_profile', $grounded);
    $this->assertSame(2, $grounded['freshness_profile']['fresh']);
    $this->assertSame(0, $grounded['freshness_profile']['stale']);
    $this->assertSame(0, $grounded['freshness_profile']['unknown']);
    $this->assertArrayNotHasKey('_freshness_enforcement', $grounded);
    $this->assertArrayNotHasKey('_freshness_confidence_cap', $grounded);
  }

  // -------------------------------------------------------------------
  // Test 2: All stale — enforcement with confidence cap.
  // -------------------------------------------------------------------

  /**
   * Citation-required type with all stale citations triggers enforcement.
   */
  public function testAllStaleCitationsTriggersEnforcement(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('stale', 'OldGuide1'),
      $this->buildResult('stale', 'OldGuide2'),
      $this->buildResult('stale', 'OldGuide3'),
    ];
    $response = ['message' => 'Info', 'type' => 'resources'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertSame('all_non_fresh', $grounded['_freshness_enforcement']);
    $this->assertSame(0.5, $grounded['_freshness_confidence_cap']);
    $this->assertSame(0, $grounded['freshness_profile']['fresh']);
    $this->assertSame(3, $grounded['freshness_profile']['stale']);
    $this->assertSame(3, $grounded['freshness_profile']['total']);
  }

  // -------------------------------------------------------------------
  // Test 3: All unknown — treated as all non-fresh.
  // -------------------------------------------------------------------

  /**
   * Unknown freshness is treated as non-fresh (precautionary principle).
   */
  public function testAllUnknownFreshnessTreatedAsNonFresh(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('unknown', 'NoDate1'),
      $this->buildResult('unknown', 'NoDate2'),
    ];
    $response = ['message' => 'Info', 'type' => 'topic'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertSame('all_non_fresh', $grounded['_freshness_enforcement']);
    $this->assertSame(0.5, $grounded['_freshness_confidence_cap']);
    $this->assertSame(0, $grounded['freshness_profile']['fresh']);
    $this->assertSame(2, $grounded['freshness_profile']['unknown']);
  }

  // -------------------------------------------------------------------
  // Test 4: Mixed (1 fresh, 2 stale) — proportional cap.
  // -------------------------------------------------------------------

  /**
   * Mixed freshness applies proportional confidence cap.
   */
  public function testMixedFreshnessProportionalCap(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('fresh', 'FreshGuide'),
      $this->buildResult('stale', 'StaleGuide1'),
      $this->buildResult('stale', 'StaleGuide2'),
    ];
    $response = ['message' => 'Info', 'type' => 'resources'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertSame('partial_non_fresh', $grounded['_freshness_enforcement']);
    // non_fresh_ratio = 2/3 ≈ 0.667, cap = max(0.5, 1.0 - 0.333) = 0.667
    $expected_cap = max(0.5, 1.0 - ((2 / 3) * 0.5));
    $this->assertEqualsWithDelta($expected_cap, $grounded['_freshness_confidence_cap'], 0.001);
    $this->assertSame(1, $grounded['freshness_profile']['fresh']);
    $this->assertSame(2, $grounded['freshness_profile']['stale']);
    $this->assertSame(3, $grounded['freshness_profile']['total']);
  }

  // -------------------------------------------------------------------
  // Test 5: Non-citation-required type — no enforcement.
  // -------------------------------------------------------------------

  /**
   * Non-citation-required types get no freshness enforcement or profile.
   */
  public function testNonCitationRequiredTypeNoEnforcement(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('stale', 'StaleNav'),
    ];
    // 'navigation' is NOT in CITATION_REQUIRED_TYPES.
    $response = ['message' => 'Info', 'type' => 'navigation'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertArrayNotHasKey('freshness_profile', $grounded);
    $this->assertArrayNotHasKey('_freshness_enforcement', $grounded);
    $this->assertArrayNotHasKey('_freshness_confidence_cap', $grounded);
  }

  // -------------------------------------------------------------------
  // Test 6: Citation-required + no sources — handled by grounding_weak.
  // -------------------------------------------------------------------

  /**
   * No valid sources triggers _grounding_weak, not freshness enforcement.
   */
  public function testNoSourcesTriggersGroundingWeakNotFreshnessEnforcement(): void {
    $grounder = $this->buildGrounder();
    // Results with no valid URLs — addCitations() will exclude them.
    $results = [
      ['title' => 'No URL', 'type' => 'resource'],
    ];
    $response = ['message' => 'Info', 'type' => 'faq'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertTrue($grounded['_grounding_weak'] ?? FALSE);
    $this->assertArrayNotHasKey('freshness_profile', $grounded);
    $this->assertArrayNotHasKey('_freshness_enforcement', $grounded);
  }

  // -------------------------------------------------------------------
  // Test 7: Freshness caveat idempotency with _all_citations_stale.
  // -------------------------------------------------------------------

  /**
   * When _all_citations_stale and _freshness_enforcement both fire,
   * the freshness_profile is still computed and enforcement flags set.
   */
  public function testFreshnessEnforcementCoexistsWithAllCitationsStale(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('stale', 'Stale1'),
      $this->buildResult('stale', 'Stale2'),
    ];
    $response = ['message' => 'Info', 'type' => 'faq'];
    $grounded = $grounder->groundResponse($response, $results);

    // Both signals should be present.
    $this->assertTrue($grounded['_all_citations_stale'] ?? FALSE);
    $this->assertSame('all_non_fresh', $grounded['_freshness_enforcement']);
    $this->assertSame(0.5, $grounded['_freshness_confidence_cap']);
    $this->assertArrayHasKey('freshness_profile', $grounded);
  }

  // -------------------------------------------------------------------
  // Test 8: Config toggle disabled — no enforcement.
  // -------------------------------------------------------------------

  /**
   * When freshness_enforcement.enabled is false, no enforcement applies.
   */
  public function testConfigToggleDisabledNoEnforcement(): void {
    $grounder = $this->buildGrounder(enforcement_enabled: FALSE);
    $results = [
      $this->buildResult('stale', 'Stale1'),
      $this->buildResult('stale', 'Stale2'),
    ];
    $response = ['message' => 'Info', 'type' => 'faq'];
    $grounded = $grounder->groundResponse($response, $results);

    $this->assertArrayNotHasKey('freshness_profile', $grounded);
    $this->assertArrayNotHasKey('_freshness_enforcement', $grounded);
    $this->assertArrayNotHasKey('_freshness_confidence_cap', $grounded);
    // _all_citations_stale still fires (existing Check 3, independent).
    $this->assertTrue($grounded['_all_citations_stale'] ?? FALSE);
  }

  // -------------------------------------------------------------------
  // Test 9: freshness_profile is visible (not an internal flag).
  // -------------------------------------------------------------------

  /**
   * freshness_profile is a user-facing field, not prefixed with underscore.
   */
  public function testFreshnessProfileIsUserFacing(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('fresh', 'Guide1'),
    ];
    $response = ['message' => 'Info', 'type' => 'eligibility'];
    $grounded = $grounder->groundResponse($response, $results);

    // freshness_profile has no underscore prefix — it is user-facing.
    $this->assertArrayHasKey('freshness_profile', $grounded);
    $this->assertArrayNotHasKey('_freshness_profile', $grounded);
    $this->assertSame(1, $grounded['freshness_profile']['total']);
  }

  // -------------------------------------------------------------------
  // Test 10: Internal flags use underscore prefix convention.
  // -------------------------------------------------------------------

  /**
   * _freshness_enforcement and _freshness_confidence_cap are internal.
   */
  public function testInternalFlagsUseUnderscorePrefix(): void {
    $grounder = $this->buildGrounder();
    $results = [
      $this->buildResult('stale', 'Stale1'),
    ];
    $response = ['message' => 'Info', 'type' => 'faq'];
    $grounded = $grounder->groundResponse($response, $results);

    // Internal flags have underscore prefix — will be stripped by controller.
    $this->assertArrayHasKey('_freshness_enforcement', $grounded);
    $this->assertArrayHasKey('_freshness_confidence_cap', $grounded);
    // These should start with underscore.
    $this->assertStringStartsWith('_', '_freshness_enforcement');
    $this->assertStringStartsWith('_', '_freshness_confidence_cap');
  }

}
