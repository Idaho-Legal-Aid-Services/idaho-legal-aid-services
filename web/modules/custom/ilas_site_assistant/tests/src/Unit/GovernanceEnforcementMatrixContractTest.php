<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\RetrievalContract;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests validating the governance enforcement matrix.
 *
 * Proves that the GOVERNANCE_ENFORCEMENT_MATRIX in RetrievalContract
 * accurately reflects the behavioral contract of the governance system:
 * - HARD signals throw or nullify.
 * - SOFT signals change response content or metadata.
 * - ADVISORY signals do not change response content.
 */
#[Group('ilas_site_assistant')]
final class GovernanceEnforcementMatrixContractTest extends TestCase {

  /**
   * The enforcement matrix must define a level for every entry.
   */
  public function testEnforcementMatrixEntriesHaveRequiredKeys(): void {
    foreach (RetrievalContract::GOVERNANCE_ENFORCEMENT_MATRIX as $signal => $entry) {
      $this->assertArrayHasKey('level', $entry, "Signal '$signal' must define 'level'.");
      $this->assertArrayHasKey('action', $entry, "Signal '$signal' must define 'action'.");
      $this->assertArrayHasKey('rationale', $entry, "Signal '$signal' must define 'rationale'.");
      $this->assertContains(
        $entry['level'],
        [RetrievalContract::ENFORCEMENT_HARD, RetrievalContract::ENFORCEMENT_SOFT, RetrievalContract::ENFORCEMENT_ADVISORY],
        "Signal '$signal' has invalid enforcement level '{$entry['level']}'.",
      );
    }
  }

  /**
   * HARD: unapproved source class throws InvalidArgumentException.
   */
  public function testHardEnforcementUnapprovedSourceClassThrows(): void {
    $this->assertSame(
      RetrievalContract::ENFORCEMENT_HARD,
      RetrievalContract::getEnforcementLevel('unapproved_source_class'),
    );

    $this->expectException(\InvalidArgumentException::class);
    RetrievalContract::assertApprovedSourceClass('fabricated_class');
  }

  /**
   * HARD: unsafe citation URL is nullified.
   */
  public function testHardEnforcementUnsafeCitationUrlNullified(): void {
    $this->assertSame(
      RetrievalContract::ENFORCEMENT_HARD,
      RetrievalContract::getEnforcementLevel('unsafe_citation_url'),
    );

    $service = $this->buildGovernanceService();
    $this->assertNull($service->sanitizeCitationUrl('javascript:alert(1)'));
    $this->assertNull($service->sanitizeCitationUrl('http://evil.com/page'));
    $this->assertNull($service->sanitizeCitationUrl('data:text/html,<script>'));
  }

  /**
   * HARD: approved source classes do NOT throw.
   */
  public function testHardEnforcementApprovedSourceClassesPass(): void {
    foreach (RetrievalContract::APPROVED_SOURCE_CLASSES as $class) {
      RetrievalContract::assertApprovedSourceClass($class);
    }
    // If we reach here without exception, all approved classes pass.
    $this->addToAssertionCount(count(RetrievalContract::APPROVED_SOURCE_CLASSES));
  }

  /**
   * HARD: safe citation URLs are preserved.
   */
  public function testHardEnforcementSafeCitationUrlsPreserved(): void {
    $service = $this->buildGovernanceService();
    $this->assertSame('/faq#housing', $service->sanitizeCitationUrl('/faq#housing'));
    $this->assertSame(
      'https://idaholegalaid.org/guides',
      $service->sanitizeCitationUrl('https://idaholegalaid.org/guides'),
    );
  }

  /**
   * ADVISORY: per-item governance flags do not remove items from results.
   */
  public function testAdvisorySignalsDoNotBlockContent(): void {
    $service = $this->buildGovernanceService();

    // Stale item — should still be in results with flag but not suppressed.
    $stale_item = $service->annotateResult([
      'id' => 'faq_1',
      'source_url' => '/faq#old-topic',
      'updated_at' => time() - (365 * 86400),
    ], 'faq_lexical');

    $this->assertContains('stale_source', $stale_item['governance_flags']);
    $this->assertSame('faq_1', $stale_item['id'], 'Stale items must still be present in results.');
    $this->assertSame('stale', $stale_item['freshness']['status']);

    // Unknown freshness — should still be in results.
    $unknown_item = $service->annotateResult([
      'id' => 'faq_2',
      'source_url' => '/faq#no-date',
    ], 'faq_lexical');

    $this->assertContains('unknown_freshness', $unknown_item['governance_flags']);
    $this->assertSame('faq_2', $unknown_item['id'], 'Unknown-freshness items must still be present in results.');

    // Missing source URL — should still be in results.
    $missing_url_item = $service->annotateResult([
      'id' => 'faq_3',
      'updated_at' => time(),
    ], 'faq_lexical');

    $this->assertContains('missing_source_url', $missing_url_item['governance_flags']);
    $this->assertSame('faq_3', $missing_url_item['id'], 'Missing-URL items must still be present in results.');
  }

  /**
   * The matrix must cover all governance flags that annotateResult() can produce.
   */
  public function testEnforcementMatrixCoversAllAnnotateResultFlags(): void {
    $possible_flags = ['stale_source', 'unknown_freshness', 'missing_source_url', 'invalid_source_url'];
    $matrix_keys = array_keys(RetrievalContract::GOVERNANCE_ENFORCEMENT_MATRIX);

    foreach ($possible_flags as $flag) {
      $this->assertContains(
        $flag,
        $matrix_keys,
        "Governance flag '$flag' from annotateResult() must have an enforcement matrix entry.",
      );
    }
  }

  /**
   * The matrix must cover response-level signals from the controller pipeline.
   */
  public function testEnforcementMatrixCoversResponseLevelSignals(): void {
    $response_signals = [
      'requires_review',
      'all_citations_stale',
      'grounding_weak',
      'health_degraded',
    ];
    $matrix_keys = array_keys(RetrievalContract::GOVERNANCE_ENFORCEMENT_MATRIX);

    foreach ($response_signals as $signal) {
      $this->assertContains(
        $signal,
        $matrix_keys,
        "Response-level signal '$signal' must have an enforcement matrix entry.",
      );
    }
  }

  /**
   * getEnforcementLevel() throws on unknown signals.
   */
  public function testGetEnforcementLevelThrowsOnUnknownSignal(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown governance signal "nonexistent_signal"');
    RetrievalContract::getEnforcementLevel('nonexistent_signal');
  }

  /**
   * Builds a minimal SourceGovernanceService for testing.
   */
  private function buildGovernanceService(): SourceGovernanceService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturn(NULL);

    $logger = $this->createStub(LoggerInterface::class);

    return new SourceGovernanceService($configFactory, $state, $logger);
  }

}
