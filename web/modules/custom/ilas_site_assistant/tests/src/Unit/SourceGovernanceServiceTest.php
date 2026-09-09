<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
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

  /**
 *
 */
  #[DataProvider('allowedCitationUrlProvider')]
  public function testSanitizeCitationUrlAllowsApprovedUrls(string $url): void {
    $service = $this->buildService();

    $this->assertSame($url, $service->sanitizeCitationUrl($url));
  }

  /**
 *
 */
  #[DataProvider('disallowedCitationUrlProvider')]
  public function testSanitizeCitationUrlRejectsDisallowedUrls(string $url): void {
    $service = $this->buildService();

    $this->assertNull($service->sanitizeCitationUrl($url));
  }

  /**
   *
   */
  public static function allowedCitationUrlProvider(): array {
    return [
      'relative path' => ['/faq#housing'],
      'absolute ilas host' => ['https://idaholegalaid.org/guides/eviction'],
      'absolute www host' => ['https://www.idaholegalaid.org/forms'],
    ];
  }

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   * A review attestation newer than the last edit keeps old content fresh.
   *
   * PHP-9Z: content untouched since migration crossed max_age_days en masse;
   * the review date lets content ops attest currency without a fake edit.
   */
  public function testAnnotateResultUsesReviewedAtWhenNewerThanChanged(): void {
    $service = $this->buildService();
    $now = time();
    $reviewed_at = $now - (10 * 86400);

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (300 * 86400),
      'reviewed_at' => $reviewed_at,
    ], 'resource_lexical');

    $this->assertSame('fresh', $item['freshness']['status']);
    $this->assertSame(10, $item['freshness']['age_days']);
    $this->assertSame('reviewed', $item['freshness']['basis']);
    $this->assertSame($reviewed_at, $item['freshness']['effective_at']);
    $this->assertSame($reviewed_at, $item['freshness']['reviewed_at']);
    $this->assertSame($now - (300 * 86400), $item['freshness']['updated_at']);
    $this->assertSame([], $item['governance_flags']);
  }

  /**
   * An edit newer than the review is the effective timestamp.
   */
  public function testAnnotateResultUsesChangedWhenReviewedAtIsOlder(): void {
    $service = $this->buildService();
    $now = time();

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (10 * 86400),
      'reviewed_at' => $now - (300 * 86400),
    ], 'resource_lexical');

    $this->assertSame('fresh', $item['freshness']['status']);
    $this->assertSame('changed', $item['freshness']['basis']);
    $this->assertSame(10, $item['freshness']['age_days']);
  }

  /**
   * A review date alone (no changed timestamp) is sufficient.
   */
  public function testAnnotateResultReviewedAtOnlyIsSufficient(): void {
    $service = $this->buildService();
    $now = time();

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'reviewed_at' => $now - (20 * 86400),
    ], 'resource_lexical');

    $this->assertSame('fresh', $item['freshness']['status']);
    $this->assertSame('reviewed', $item['freshness']['basis']);
    $this->assertNull($item['freshness']['updated_at']);
    $this->assertNotContains('unknown_freshness', $item['governance_flags']);
  }

  /**
   * Both timestamps older than the window still yields stale.
   */
  public function testAnnotateResultStaleWhenReviewAndChangeBothOld(): void {
    $service = $this->buildService();
    $now = time();

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (400 * 86400),
      'reviewed_at' => $now - (200 * 86400),
    ], 'resource_lexical');

    $this->assertSame('stale', $item['freshness']['status']);
    $this->assertSame('reviewed', $item['freshness']['basis']);
    $this->assertContains('stale_source', $item['governance_flags']);
  }

  /**
   * reviewed_at accepts a Y-m-d string (the raw datetime field value).
   */
  public function testAnnotateResultReviewedAtAcceptsIsoDateString(): void {
    $service = $this->buildService();
    $now = time();
    $date = gmdate('Y-m-d', $now - (5 * 86400));

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (300 * 86400),
      'reviewed_at' => $date,
    ], 'resource_lexical');

    $this->assertSame('fresh', $item['freshness']['status']);
    $this->assertSame('reviewed', $item['freshness']['basis']);
    $this->assertSame($date, gmdate('Y-m-d', $item['freshness']['reviewed_at']));
  }

  /**
   * A future review date (typo) must not make an item permanently fresh.
   */
  public function testAnnotateResultIgnoresFutureReviewedAt(): void {
    $service = $this->buildService();
    $now = time();

    $item = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (300 * 86400),
      'reviewed_at' => gmdate('Y-m-d', $now + (30 * 86400)),
    ], 'resource_lexical');

    $this->assertSame('stale', $item['freshness']['status']);
    $this->assertSame('changed', $item['freshness']['basis']);
    $this->assertNull($item['freshness']['reviewed_at']);

    // Free-form strings are not parsed for reviewed_at either.
    $item = $service->annotateResult([
      'id' => 'resource_2',
      'source_url' => '/resources/form-2',
      'updated_at' => $now - (300 * 86400),
      'reviewed_at' => 'yesterday',
    ], 'resource_lexical');
    $this->assertSame('stale', $item['freshness']['status']);
    $this->assertNull($item['freshness']['reviewed_at']);
  }

  /**
   * Re-annotation (recordObservationBatch path) sees the echoed review date.
   */
  public function testReAnnotationPreservesReviewedAtViaFreshness(): void {
    $service = $this->buildService();
    $now = time();

    $first = $service->annotateResult([
      'id' => 'resource_1',
      'source_url' => '/resources/form-1',
      'updated_at' => $now - (300 * 86400),
      'reviewed_at' => $now - (10 * 86400),
    ], 'resource_lexical');
    unset($first['reviewed_at']);

    $second = $service->annotateResult($first, 'resource_lexical');

    $this->assertSame('fresh', $second['freshness']['status']);
    $this->assertSame('reviewed', $second['freshness']['basis']);
    $this->assertSame($first['freshness']['reviewed_at'], $second['freshness']['reviewed_at']);
  }

  /**
   * classifyFreshness matrix.
   */
  #[DataProvider('freshnessMatrixProvider')]
  public function testClassifyFreshnessMatrix(?int $updated_offset, ?int $reviewed_offset, string $status, string $basis, ?int $age): void {
    $now = 1_800_000_000;
    $updated_at = $updated_offset === NULL ? NULL : $now - ($updated_offset * 86400);
    $reviewed_at = $reviewed_offset === NULL ? NULL : $now - ($reviewed_offset * 86400);

    $result = SourceGovernanceService::classifyFreshness($updated_at, $reviewed_at, 180, $now);

    $this->assertSame($status, $result['status']);
    $this->assertSame($basis, $result['basis']);
    $this->assertSame($age, $result['age_days']);
  }

  /**
   * Data provider for the freshness matrix.
   */
  public static function freshnessMatrixProvider(): array {
    return [
      'nothing known' => [NULL, NULL, 'unknown', 'unknown', NULL],
      'changed only, fresh' => [10, NULL, 'fresh', 'changed', 10],
      'changed only, stale' => [190, NULL, 'stale', 'changed', 190],
      'reviewed only, fresh' => [NULL, 10, 'fresh', 'reviewed', 10],
      'review newer than change' => [300, 10, 'fresh', 'reviewed', 10],
      'change newer than review' => [10, 300, 'fresh', 'changed', 10],
      'both old' => [400, 200, 'stale', 'reviewed', 200],
      'exactly at boundary is fresh' => [180, NULL, 'fresh', 'changed', 180],
      'same instant prefers reviewed' => [50, 50, 'fresh', 'reviewed', 50],
    ];
  }

  /**
   * parseReviewDate anchors at noon UTC and rejects malformed/future values.
   */
  public function testParseReviewDate(): void {
    $now = gmmktime(0, 0, 0, 9, 9, 2026);

    $this->assertSame(gmmktime(12, 0, 0, 8, 1, 2026), SourceGovernanceService::parseReviewDate('2026-08-01', $now));
    $this->assertSame(gmmktime(12, 0, 0, 8, 1, 2026), SourceGovernanceService::parseReviewDate('2026-08-01T00:00:00', $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate('2026-13-01', $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate('2026-02-30', $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate('08/01/2026', $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate('', $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate(NULL, $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate(1_700_000_000, $now));
    $this->assertNull(SourceGovernanceService::parseReviewDate('2026-12-25', $now), 'future date ignored');
  }

  /**
   * resolveEntityReviewedAt reads the field from a fieldable entity.
   */
  public function testResolveEntityReviewedAtReadsFieldValue(): void {
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt(new \stdClass()));

    $node_without_field = $this->createStub(SourceGovernanceReviewableEntityStub::class);
    $node_without_field->method('hasField')->willReturn(FALSE);
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt($node_without_field));

    $empty_list = $this->createStub(FieldItemListInterface::class);
    $empty_list->method('isEmpty')->willReturn(TRUE);
    $node_empty = $this->createStub(SourceGovernanceReviewableEntityStub::class);
    $node_empty->method('hasField')->willReturn(TRUE);
    $node_empty->method('get')->willReturn($empty_list);
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt($node_empty));

    $node_valid = $this->buildNodeWithReviewDate('2026-08-01');
    $this->assertSame(gmmktime(12, 0, 0, 8, 1, 2026), SourceGovernanceService::resolveEntityReviewedAt($node_valid));

    $node_malformed = $this->buildNodeWithReviewDate('not-a-date');
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt($node_malformed));

    $node_future = $this->buildNodeWithReviewDate(gmdate('Y-m-d', time() + (60 * 86400)));
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt($node_future));

    $node_throwing = $this->createStub(SourceGovernanceReviewableEntityStub::class);
    $node_throwing->method('hasField')->willReturn(TRUE);
    $node_throwing->method('get')->willThrowException(new \RuntimeException('boom'));
    $this->assertNull(SourceGovernanceService::resolveEntityReviewedAt($node_throwing));
  }

  /**
   * buildEntityFreshness pairs changed and reviewed timestamps.
   */
  public function testBuildEntityFreshnessPairsChangedAndReviewed(): void {
    $node = $this->buildNodeWithReviewDate('2026-08-01', 1_750_000_000);
    $freshness = SourceGovernanceService::buildEntityFreshness($node);

    $this->assertSame(1_750_000_000, $freshness['updated_at']);
    $this->assertSame(gmmktime(12, 0, 0, 8, 1, 2026), $freshness['reviewed_at']);

    $plain = SourceGovernanceService::buildEntityFreshness(new \stdClass());
    $this->assertSame(['updated_at' => NULL, 'reviewed_at' => NULL], $plain);
  }

  /**
   * Builds a node stub exposing field_last_reviewed with the given value.
   */
  private function buildNodeWithReviewDate(string $value, int $changed = 1_700_000_000): SourceGovernanceReviewableEntityStub {
    $item = $this->createStub(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['value' => $value]);

    $list = $this->createStub(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('first')->willReturn($item);

    $node = $this->createStub(SourceGovernanceReviewableEntityStub::class);
    $node->method('hasField')->willReturnCallback(
      static fn(string $name): bool => $name === SourceGovernanceService::REVIEW_FIELD
    );
    $node->method('get')->willReturn($list);
    $node->method('getChangedTime')->willReturn($changed);

    return $node;
  }

  /**
   * Snapshot counts observations whose source has never been reviewed.
   */
  public function testObservationSnapshotCountsNeverReviewed(): void {
    $service = $this->buildService();
    $now = time();

    $service->recordObservationBatch([
      [
        'id' => 'faq_1',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#a',
        'updated_at' => $now - (200 * 86400),
      ],
      [
        'id' => 'faq_2',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#b',
        'updated_at' => $now - (200 * 86400),
        'reviewed_at' => $now - (5 * 86400),
      ],
      [
        'id' => 'resource_1',
        'source_class' => 'resource_vector',
        'source_url' => '/resources/x',
        'updated_at' => $now - (5 * 86400),
      ],
    ]);

    $snapshot = $service->getSnapshot();
    $this->assertSame(3, $snapshot['total']);
    $this->assertSame(1, $snapshot['stale']);
    $this->assertSame(2, $snapshot['never_reviewed']);
    $this->assertSame(1, $snapshot['by_source_class']['faq_lexical']['never_reviewed']);
    $this->assertSame(1, $snapshot['by_source_class']['faq_lexical']['stale']);
    $this->assertSame(1, $snapshot['by_source_class']['resource_vector']['never_reviewed']);
    $this->assertSame(2, $snapshot['by_retrieval_method']['search_api']['never_reviewed']);
  }

  /**
   * The stale-ratio alert carries the per-class breakdown content ops needs.
   */
  public function testStaleRatioAlertContextIncludesBreakdown(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->logicalAnd(
          $this->stringContains('never_reviewed @never_reviewed'),
          $this->stringContains('by class: @by_class')
        ),
        $this->callback(static function (array $context): bool {
          return ($context['@never_reviewed'] ?? NULL) === 2
            && ($context['@by_class'] ?? NULL) === 'faq_lexical=1/1 nr=1; resource_lexical=1/1 nr=1';
        })
      );

    $service = $this->buildService($logger, [
      'stale_ratio_alert_pct' => 10.0,
      'min_observations' => 2,
    ]);
    $now = time();

    $service->recordObservationBatch([
      [
        'id' => 'faq_1',
        'source_class' => 'faq_lexical',
        'source_url' => '/faq#a',
        'updated_at' => $now - (200 * 86400),
      ],
      [
        'id' => 'resource_1',
        'source_class' => 'resource_lexical',
        'source_url' => '/resources/x',
        'updated_at' => $now - (200 * 86400),
      ],
    ]);
  }

  /**
   * formatSourceClassBreakdown is compact, sorted, and tolerant.
   */
  public function testFormatSourceClassBreakdown(): void {
    $this->assertSame('none', SourceGovernanceService::formatSourceClassBreakdown([]));
    $this->assertSame(
      'faq_vector=6/8 nr=8; resource_lexical=17/23 nr=23',
      SourceGovernanceService::formatSourceClassBreakdown([
        'resource_lexical' => ['total' => 23, 'stale' => 17, 'never_reviewed' => 23],
        'faq_vector' => ['total' => 8, 'stale' => 6, 'never_reviewed' => 8],
        'junk' => 'not-an-array',
      ])
    );
  }

  /**
   * getMaxAgeDays reads the class policy with a safe fallback.
   */
  public function testGetMaxAgeDays(): void {
    $service = $this->buildService(NULL, [
      'source_classes' => ['resource_lexical' => ['max_age_days' => 365]],
    ]);
    $this->assertSame(365, $service->getMaxAgeDays('resource_lexical'));
    $this->assertSame(180, $service->getMaxAgeDays('faq_lexical'));
    $this->assertSame(180, $service->getMaxAgeDays('not_configured'));
  }

  /**
   *
   */
  public function testAnnotateResultFlagsMissingSourceUrl(): void {
    $service = $this->buildService();

    $result = $service->annotateResult([
      'id' => 'faq_3',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertFalse($result['provenance']['has_source_url']);
    $this->assertContains('missing_source_url', $result['governance_flags']);
  }

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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
      // Keep the warning-level alert path exercised with this 2-item batch:
      // below min_observations the alert downgrades to notice (PHP-4S).
      'min_observations' => 2,
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

  /**
   * Tests the stale-ratio alert downgrades to notice on thin samples.
   *
   * Below min_observations a high stale ratio is statistically meaningless
   * (e.g. 2/2 stale = 100% on a thin dev index), so the alert must log at
   * notice (not forwarded to Sentry) while the snapshot status still
   * degrades (PHP-4S).
   */
  public function testStaleRatioAlertDowngradesToNoticeBelowMinimumObservations(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('stale ratio'),
        $this->isArray()
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
    $snapshot = $service->getSnapshot();

    // Status still degrades independently of the alert-severity gate.
    $this->assertSame('degraded', $snapshot['status']);
    $this->assertSame(50.0, $snapshot['stale_ratio_pct']);
    $this->assertFalse($snapshot['min_observations_met']);

    // Cooldown applies to the notice-level alert too.
    $service->recordObservationBatch($batch);
  }

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   * =========================================================================
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   * =========================================================================
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   * =========================================================================
   */
  public function testEnforcementModeReadsFromConfig(): void {
    $service = $this->buildServiceWithEnforcementMode('soft');

    $result = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#test',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertSame('soft', $result['provenance']['enforcement_mode']);
  }

  /**
   *
   */
  public function testEnforcementModeDefaultsToAdvisoryWhenConfigMissing(): void {
    $service = $this->buildService();

    $this->assertSame('advisory', $service->getEnforcementMode());
  }

  /**
   *
   */
  public function testEnforcementModeRejectsInvalidValues(): void {
    $service = $this->buildServiceWithEnforcementMode('nuclear');

    $this->assertSame('advisory', $service->getEnforcementMode());
  }

  /**
   *
   */
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

/**
 * Fieldable, changed-tracking entity shape the freshness helpers duck-type.
 *
 * NodeInterface lives in a core module the pure runner does not autoload;
 * the helpers only call hasField(), get() and getChangedTime(), which these
 * two core interfaces provide.
 */
interface SourceGovernanceReviewableEntityStub extends FieldableEntityInterface, EntityChangedInterface {
}
