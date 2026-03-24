<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\RetrievalContract;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SourceGovernanceService.
 */
#[CoversClass(SourceGovernanceService::class)]
#[Group('ilas_site_assistant')]
final class SourceGovernanceServiceTest extends TestCase {

  /**
   * In-memory state store.
   *
   * @var array
   */
  private array $stateStore = [];

  /**
   * Builds a mock state service backed by in-memory storage.
   */
  private function buildState(): StateInterface {
    $state = $this->createMock(StateInterface::class);

    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStore[$key] ?? $default;
      });

    $state->method('set')
      ->willReturnCallback(function (string $key, $value): void {
        $this->stateStore[$key] = $value;
      });

    $state->method('delete')
      ->willReturnCallback(function (string $key): void {
        unset($this->stateStore[$key]);
      });

    return $state;
  }

  /**
   * Builds a config factory for source governance policy.
   */
  private function buildConfigFactory(array $policyOverrides = []): ConfigFactoryInterface {
    $defaultPolicy = [
      'enabled' => TRUE,
      'policy_version' => 'p2_obj_03_v1',
      'observation_window_hours' => 24,
      'stale_ratio_alert_pct' => 18.0,
      'min_observations' => 20,
      'unknown_ratio_degrade_pct' => 22.0,
      'missing_source_url_ratio_degrade_pct' => 9.0,
      'alert_cooldown_minutes' => 60,
      'source_classes' => [
        'faq_lexical' => [
          'provenance_label' => 'search_api.index.faq_accordion',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'faq_vector' => [
          'provenance_label' => 'search_api.index.faq_accordion_vector',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'resource_lexical' => [
          'provenance_label' => 'search_api.index.assistant_resources',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'resource_vector' => [
          'provenance_label' => 'search_api.index.assistant_resources_vector',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
      ],
    ];

    $policy = array_replace_recursive($defaultPolicy, $policyOverrides);

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($policy) {
        return $key === 'source_governance' ? $policy : NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Builds the service under test.
   */
  private function buildService(?LoggerInterface $logger = NULL, array $policyOverrides = []): SourceGovernanceService {
    $this->stateStore = [];
    $configFactory = $this->buildConfigFactory($policyOverrides);
    $state = $this->buildState();

    if (!$logger) {
      $logger = $this->createStub(LoggerInterface::class);
    }

    return new SourceGovernanceService($configFactory, $state, $logger);
  }

  #[DataProvider('allowedCitationUrlProvider')]
  public function testSanitizeCitationUrlAllowsApprovedUrls(string $url): void {
    $service = $this->buildService();

    $this->assertSame($url, $service->sanitizeCitationUrl($url));
  }

  #[DataProvider('disallowedCitationUrlProvider')]
  public function testSanitizeCitationUrlRejectsDisallowedUrls(string $url): void {
    $service = $this->buildService();

    $this->assertNull($service->sanitizeCitationUrl($url));
  }

  public static function allowedCitationUrlProvider(): array {
    return [
      'relative path' => ['/faq#housing'],
      'absolute ilas host' => ['https://idaholegalaid.org/guides/eviction'],
      'absolute www host' => ['https://www.idaholegalaid.org/forms'],
    ];
  }

  public static function disallowedCitationUrlProvider(): array {
    return [
      'javascript' => ['javascript:alert(1)'],
      'data' => ['data:text/html;base64,PHNjcmlwdA=='],
      'off-domain' => ['https://attacker.example.com/phish'],
      'malformed' => ['not a valid url'],
      'protocol-relative' => ['//attacker.example.com/phish'],
      'fragment' => ['#faq'],
      'http ilas' => ['http://idaholegalaid.org/page'],
      'http www ilas' => ['http://www.idaholegalaid.org/page'],
    ];
  }

  public function testAnnotateResultClassifiesFreshStaleAndUnknown(): void {
    $service = $this->buildService();
    $now = time();

    $fresh = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#housing',
      'updated_at' => $now - (10 * 86400),
    ], 'faq_lexical');

    $stale = $service->annotateResult([
      'id' => 'faq_2',
      'source_url' => '/faq#eviction',
      'updated_at' => $now - (190 * 86400),
    ], 'faq_lexical');

    $unknown = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
    ], 'resource_lexical');

    $this->assertSame('fresh', $fresh['freshness']['status']);
    $this->assertSame(10, $fresh['freshness']['age_days']);
    $this->assertSame([], $fresh['governance_flags']);

    $this->assertSame('stale', $stale['freshness']['status']);
    $this->assertContains('stale_source', $stale['governance_flags']);

    $this->assertSame('unknown', $unknown['freshness']['status']);
    $this->assertContains('unknown_freshness', $unknown['governance_flags']);
  }

  public function testAnnotateResultFlagsMissingSourceUrl(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_3',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertFalse($result['provenance']['has_source_url']);
    $this->assertContains('missing_source_url', $result['governance_flags']);
  }

  public function testAnnotateResultFlagsInvalidSourceUrl(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_invalid',
      'source_url' => 'https://attacker.example.com/phish',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertTrue($result['provenance']['has_source_url']);
    $this->assertFalse($result['provenance']['source_url_allowed']);
    $this->assertContains('invalid_source_url', $result['governance_flags']);
    $this->assertNotContains('missing_source_url', $result['governance_flags']);
  }

  public function testObservationSnapshotAggregatesBySourceClass(): void {
    $service = $this->buildService();
    $now = time();

    $service->recordObservationBatch([
      [
        'id' => 'faq_1',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic1',
        'updated_at' => $now - (3 * 86400),
      ],
      [
        'id' => 'faq_2',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic2',
        'updated_at' => $now - (200 * 86400),
      ],
      [
        'id' => 'resource_1',
        'source_class' => 'resource_vector',
        'url' => '/resources/guide-1',
      ],
    ]);

    $snapshot = $service->getSnapshot();

    $this->assertSame(3, $snapshot['total']);
    $this->assertSame(1, $snapshot['stale']);
    $this->assertSame(1, $snapshot['unknown']);
    $this->assertSame(0, $snapshot['missing_source_url']);

    $this->assertSame(2, $snapshot['by_source_class']['faq_lexical']['total']);
    $this->assertSame(1, $snapshot['by_source_class']['faq_lexical']['stale']);
    $this->assertSame(1, $snapshot['by_source_class']['resource_vector']['total']);
    $this->assertSame(1, $snapshot['by_source_class']['resource_vector']['unknown']);
    $this->assertArrayHasKey('unknown_ratio_pct', $snapshot);
    $this->assertArrayHasKey('missing_source_url_ratio_pct', $snapshot);
    $this->assertArrayHasKey('min_observations', $snapshot);
    $this->assertArrayHasKey('min_observations_met', $snapshot);
  }

  public function testDegradedThresholdAndAlertCooldownBehavior(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('stale ratio'),
        $this->isType('array')
      );

    $service = $this->buildService($logger, [
      'stale_ratio_alert_pct' => 10.0,
      'alert_cooldown_minutes' => 60,
    ]);

    $batch = [
      [
        'id' => 'faq_1',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic1',
        'updated_at' => time() - (220 * 86400),
      ],
      [
        'id' => 'faq_2',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic2',
        'updated_at' => time() - (2 * 86400),
      ],
    ];

    $service->recordObservationBatch($batch);
    $firstSnapshot = $service->getSnapshot();
    $this->assertSame('degraded', $firstSnapshot['status']);
    $this->assertSame(50.0, $firstSnapshot['stale_ratio_pct']);

    // Second call should not emit a second warning because of cooldown.
    $service->recordObservationBatch($batch);
    $secondSnapshot = $service->getSnapshot();
    $this->assertSame('degraded', $secondSnapshot['status']);
    $this->assertIsInt($secondSnapshot['cooldown_seconds_remaining']);
    $this->assertGreaterThanOrEqual(0, $secondSnapshot['cooldown_seconds_remaining']);
    $this->assertArrayHasKey('last_alert_at', $secondSnapshot);
    $this->assertArrayHasKey('next_alert_eligible_at', $secondSnapshot);
  }

  public function testUnknownMissingBelowMinimumObservationsDoesNotDegrade(): void {
    $service = $this->buildService();
    $now = time();
    $batch = [];

    // 10 observations (<20): 3 unknown + 1 missing URL should not degrade.
    for ($i = 1; $i <= 10; $i++) {
      $item = [
        'id' => 'faq_' . $i,
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic-' . $i,
        'updated_at' => $now - (2 * 86400),
      ];
      if ($i <= 3) {
        unset($item['updated_at']);
      }
      if ($i === 4) {
        unset($item['source_url']);
      }
      $batch[] = $item;
    }

    $service->recordObservationBatch($batch);
    $snapshot = $service->getSnapshot();

    $this->assertSame(10, $snapshot['total']);
    $this->assertFalse($snapshot['min_observations_met']);
    $this->assertSame(30.0, $snapshot['unknown_ratio_pct']);
    $this->assertSame(10.0, $snapshot['missing_source_url_ratio_pct']);
    $this->assertSame('healthy', $snapshot['status']);
  }

  public function testUnknownRatioDegradesWhenMinimumObservationsMet(): void {
    $service = $this->buildService();
    $now = time();
    $batch = [];

    // 20 observations: 6 unknown => 30% unknown ratio (>=25%) => degraded.
    for ($i = 1; $i <= 20; $i++) {
      $item = [
        'id' => 'resource_' . $i,
        'source_class' => 'resource_lexical',
        'source_url' => '/resources/item-' . $i,
        'updated_at' => $now - (2 * 86400),
      ];
      if ($i <= 6) {
        unset($item['updated_at']);
      }
      $batch[] = $item;
    }

    $service->recordObservationBatch($batch);
    $snapshot = $service->getSnapshot();

    $this->assertTrue($snapshot['min_observations_met']);
    $this->assertSame(30.0, $snapshot['unknown_ratio_pct']);
    $this->assertSame('degraded', $snapshot['status']);
  }

  public function testMissingSourceUrlRatioDegradesWhenMinimumObservationsMet(): void {
    $service = $this->buildService();
    $now = time();
    $batch = [];

    // 20 observations: 2 missing source URL => 10% missing ratio (>=10%).
    for ($i = 1; $i <= 20; $i++) {
      $item = [
        'id' => 'faq_' . $i,
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic-' . $i,
        'updated_at' => $now - (2 * 86400),
      ];
      if ($i <= 2) {
        unset($item['source_url']);
      }
      $batch[] = $item;
    }

    $service->recordObservationBatch($batch);
    $snapshot = $service->getSnapshot();

    $this->assertTrue($snapshot['min_observations_met']);
    $this->assertSame(10.0, $snapshot['missing_source_url_ratio_pct']);
    $this->assertSame('degraded', $snapshot['status']);
  }

  public function testStaleRatioStillDegradesIndependentOfMinimumSampleGate(): void {
    $service = $this->buildService();
    $batch = [
      [
        'id' => 'faq_1',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic1',
        'updated_at' => time() - (250 * 86400),
      ],
      [
        'id' => 'faq_2',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#topic2',
        'updated_at' => time() - (2 * 86400),
      ],
    ];

    $service->recordObservationBatch($batch);
    $snapshot = $service->getSnapshot();

    $this->assertFalse($snapshot['min_observations_met']);
    $this->assertSame(50.0, $snapshot['stale_ratio_pct']);
    $this->assertSame('degraded', $snapshot['status']);
  }

  // =========================================================================
  // Retrieval contract tests (PHARD-06)
  // =========================================================================

  public function testAnnotateResultRejectsUnapprovedSourceClass(): void {
    $service = $this->buildService();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/external_scraper/');

    $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#test',
      'updated_at' => time(),
    ], 'external_scraper');
  }

  public function testAnnotateResultAcceptsAllApprovedSourceClasses(): void {
    $service = $this->buildService();

    foreach (RetrievalContract::APPROVED_SOURCE_CLASSES as $source_class) {
      $result = $service->annotateResult([
        'id' => 'test_1',
        'source_url' => '/test',
        'updated_at' => time(),
      ], $source_class);

      $this->assertSame($source_class, $result['source_class'], "Source class {$source_class} should be accepted.");
    }
  }

  public function testAnnotateResultIncludesContractVersion(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#test',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertArrayHasKey('retrieval_contract_version', $result['provenance']);
    $this->assertSame(RetrievalContract::POLICY_VERSION, $result['provenance']['retrieval_contract_version']);
  }

  public function testAnnotateResultIncludesEnforcementMode(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#test',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertArrayHasKey('enforcement_mode', $result['provenance']);
    $this->assertSame('advisory', $result['provenance']['enforcement_mode']);
  }

  // =========================================================================
  // Retrieval method truthfulness tests (AFRP-08)
  // =========================================================================

  public function testAnnotateResultLegacyFaqGetsEntityQueryProvenanceLabel(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#housing',
      'updated_at' => time(),
    ], 'faq_lexical', 'entity_query');

    $this->assertSame('faq_lexical', $result['source_class']);
    $this->assertSame('paragraph.entity_query', $result['provenance']['provenance_label']);
    $this->assertSame('entity_query', $result['provenance']['retrieval_method']);
  }

  public function testAnnotateResultLegacyResourceGetsEntityQueryProvenanceLabel(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => time(),
    ], 'resource_lexical', 'entity_query');

    $this->assertSame('resource_lexical', $result['source_class']);
    $this->assertSame('node.entity_query', $result['provenance']['provenance_label']);
    $this->assertSame('entity_query', $result['provenance']['retrieval_method']);
  }

  public function testAnnotateResultSearchApiPreservesConfiguredProvenanceLabel(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#housing',
      'updated_at' => time(),
    ], 'faq_lexical', 'search_api');

    $this->assertSame('search_api.index.faq_accordion', $result['provenance']['provenance_label']);
    $this->assertSame('search_api', $result['provenance']['retrieval_method']);
  }

  public function testAnnotateResultDefaultRetrievalMethodIsSearchApi(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#housing',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertSame('search_api', $result['provenance']['retrieval_method']);
    $this->assertSame('search_api.index.faq_accordion', $result['provenance']['provenance_label']);
  }

  public function testAnnotateBatchPropagatesRetrievalMethod(): void {
    $service = $this->buildService();

    $items = $service->annotateBatch([
      ['id' => 'faq_1', 'source_url' => '/faq#a', 'updated_at' => time()],
      ['id' => 'faq_2', 'source_url' => '/faq#b', 'updated_at' => time()],
    ], 'faq_lexical', 'entity_query');

    foreach ($items as $item) {
      $this->assertSame('entity_query', $item['provenance']['retrieval_method']);
      $this->assertSame('paragraph.entity_query', $item['provenance']['provenance_label']);
    }
  }

  public function testLegacyAndSearchApiProvenanceLabelsAreDifferent(): void {
    $service = $this->buildService();

    $search_api_result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#topic',
      'updated_at' => time(),
    ], 'faq_lexical', 'search_api');

    $legacy_result = $service->annotateResult([
      'id' => 'faq_2',
      'source_url' => '/faq#topic2',
      'updated_at' => time(),
    ], 'faq_lexical', 'entity_query');

    $this->assertNotSame(
      $search_api_result['provenance']['provenance_label'],
      $legacy_result['provenance']['provenance_label'],
      'Legacy results must have a different provenance label from Search API results.',
    );

    $this->assertSame(
      $search_api_result['source_class'],
      $legacy_result['source_class'],
      'Source class should be identical regardless of retrieval method.',
    );
  }

  public function testObservationSnapshotTracksByRetrievalMethod(): void {
    $service = $this->buildService();
    $now = time();

    $search_api_item = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#topic1',
      'updated_at' => $now - (3 * 86400),
    ], 'faq_lexical', 'search_api');

    $legacy_item = $service->annotateResult([
      'id' => 'faq_2',
      'source_url' => '/faq#topic2',
      'updated_at' => $now - (2 * 86400),
    ], 'faq_lexical', 'entity_query');

    $service->recordObservationBatch([$search_api_item, $legacy_item]);
    $snapshot = $service->getSnapshot();

    $this->assertArrayHasKey('by_retrieval_method', $snapshot);
    $this->assertSame(1, $snapshot['by_retrieval_method']['search_api']['total']);
    $this->assertSame(1, $snapshot['by_retrieval_method']['entity_query']['total']);
  }

  // =========================================================================
  // AFRP-10: Config-driven enforcement mode + governance summary
  // =========================================================================

  public function testEnforcementModeReadsFromConfig(): void {
    $service = $this->buildServiceWithEnforcementMode('soft');

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#test',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertSame('soft', $result['provenance']['enforcement_mode']);
  }

  public function testEnforcementModeDefaultsToAdvisoryWhenConfigMissing(): void {
    $service = $this->buildService();

    $this->assertSame('advisory', $service->getEnforcementMode());
  }

  public function testEnforcementModeRejectsInvalidValues(): void {
    $service = $this->buildServiceWithEnforcementMode('nuclear');

    $this->assertSame('advisory', $service->getEnforcementMode());
  }

  public function testGovernanceSummaryShape(): void {
    $service = $this->buildService();

    $summary = $service->getGovernanceSummary();

    $this->assertArrayHasKey('enforcement_mode', $summary);
    $this->assertArrayHasKey('policy_version', $summary);
    $this->assertArrayHasKey('status', $summary);
    $this->assertSame('advisory', $summary['enforcement_mode']);
    $this->assertSame(RetrievalContract::POLICY_VERSION, $summary['policy_version']);
    $this->assertContains($summary['status'], ['healthy', 'degraded', 'unknown']);
  }

  /**
   * Builds a service with a specific retrieval_contract.enforcement_mode.
   */
  private function buildServiceWithEnforcementMode(string $mode): SourceGovernanceService {
    $this->stateStore = [];

    $defaultPolicy = [
      'enabled' => TRUE,
      'policy_version' => 'p2_obj_03_v1',
      'observation_window_hours' => 24,
      'stale_ratio_alert_pct' => 18.0,
      'min_observations' => 20,
      'unknown_ratio_degrade_pct' => 22.0,
      'missing_source_url_ratio_degrade_pct' => 9.0,
      'alert_cooldown_minutes' => 60,
      'source_classes' => [
        'faq_lexical' => [
          'provenance_label' => 'search_api.index.faq_accordion',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'faq_vector' => [
          'provenance_label' => 'search_api.index.faq_accordion_vector',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'resource_lexical' => [
          'provenance_label' => 'search_api.index.assistant_resources',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
        'resource_vector' => [
          'provenance_label' => 'search_api.index.assistant_resources_vector',
          'owner_role' => 'Content Operations Lead',
          'max_age_days' => 180,
          'require_source_url' => TRUE,
        ],
      ],
    ];

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($defaultPolicy, $mode) {
        if ($key === 'source_governance') {
          return $defaultPolicy;
        }
        if ($key === 'retrieval_contract.enforcement_mode') {
          return $mode;
        }
        return NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $state = $this->buildState();

    return new SourceGovernanceService($configFactory, $state, $this->createStub(LoggerInterface::class));
  }

}
