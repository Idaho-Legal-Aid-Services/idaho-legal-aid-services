<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for immutable gap-item identity boundaries.
 */
#[Group('ilas_site_assistant_governance')]
final class GapItemIdentityBuilderTest extends TestCase {

  /**
   * Different selection branches must not collapse together.
   */
  public function testDifferentSelectionBranchesProduceDifferentIdentity(): void {
    $builder = new GapItemIdentityBuilder();

    $forms_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
    ], []);
    $guides_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'guides',
      'active_selection_key' => 'guides_family',
    ], []);

    $this->assertSame('selection:forms_family', $forms_identity['identity_context_key']);
    $this->assertSame('selection:guides_family', $guides_identity['identity_context_key']);
    $this->assertNotSame($forms_identity['cluster_hash'], $guides_identity['cluster_hash']);
  }

  /**
   * Selection identity remains stable when later enrichment adds topic context.
   */
  public function testSelectionIdentityIgnoresLaterTopicEnrichment(): void {
    $builder = new GapItemIdentityBuilder();

    $baseline_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
    ], []);
    $enriched_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
      'active_selection_key' => 'forms_family',
      'topic_id' => 22,
      'service_area_id' => 7,
    ], [
      'topic_id' => 22,
      'service_area_id' => 7,
    ]);

    $this->assertSame($baseline_identity['identity_context_key'], $enriched_identity['identity_context_key']);
    $this->assertSame($baseline_identity['cluster_hash'], $enriched_identity['cluster_hash']);
  }

  /**
   * Route identities must split across different routed intents.
   */
  public function testDifferentRouteIntentsProduceDifferentIdentity(): void {
    $builder = new GapItemIdentityBuilder();

    $forms_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
    ], []);
    $guides_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'guides',
    ], []);

    $this->assertSame('route:forms', $forms_identity['identity_context_key']);
    $this->assertSame('route:guides', $guides_identity['identity_context_key']);
    $this->assertNotSame($forms_identity['cluster_hash'], $guides_identity['cluster_hash']);
  }

  /**
   * Route identities must split across different topic or service-area context.
   */
  public function testRouteIdentityIncludesTopicAndServiceArea(): void {
    $builder = new GapItemIdentityBuilder();

    $topic_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
    ], [
      'topic_id' => 22,
      'service_area_id' => 7,
    ]);
    $different_topic_identity = $builder->buildFromRuntimeContext('query-hash', 'en', [
      'intent_type' => 'forms',
    ], [
      'topic_id' => 23,
      'service_area_id' => 7,
    ]);

    $this->assertSame('route:forms|topic:22|area:7', $topic_identity['identity_context_key']);
    $this->assertSame('route:forms|topic:23|area:7', $different_topic_identity['identity_context_key']);
    $this->assertNotSame($topic_identity['cluster_hash'], $different_topic_identity['cluster_hash']);
  }

}
