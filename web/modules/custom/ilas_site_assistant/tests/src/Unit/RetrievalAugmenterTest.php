<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\RetrievalAugmenter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the retrieve-first RetrievalAugmenter service.
 */
#[Group('ilas_site_assistant')]
final class RetrievalAugmenterTest extends TestCase {

  /**
   * Default retrieve_first settings used by most cases.
   */
  private const DEFAULT_SETTINGS = [
    'enabled' => TRUE,
    'max_results' => 3,
    'intent_types' => [
      'navigation',
      'topic',
      'service_area',
      'disambiguation',
      'apply_cta',
      'services',
      'escalation',
      'high_risk',
      'office_location',
    ],
  ];

  /**
   * Builds an augmenter with the given settings and resource results.
   */
  private function buildAugmenter(
    ?array $settings = self::DEFAULT_SETTINGS,
    array $resource_results = [],
    ?\Throwable $resource_exception = NULL,
  ): RetrievalAugmenter {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('retrieve_first')->willReturn($settings);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ilas_site_assistant.settings')->willReturn($config);

    $faq_index = $this->createMock(FaqIndex::class);

    $resource_finder = $this->createMock(ResourceFinder::class);
    if ($resource_exception !== NULL) {
      $resource_finder->method('findResources')->willThrowException($resource_exception);
    }
    else {
      $resource_finder->method('findResources')->willReturn($resource_results);
    }

    return new RetrievalAugmenter($config_factory, $faq_index, $resource_finder, new NullLogger());
  }

  /**
   * Builds a resource-style result item.
   */
  private static function resourceItem(string $id, string $url, string $title = 'Resource'): array {
    return [
      'id' => $id,
      'title' => $title,
      'url' => $url,
      'type' => 'resource',
      'source' => 'document_media',
      'source_class' => 'resource_lexical',
      'score' => 100,
    ];
  }

  /**
   * Augmentation is skipped when the kill-switch is off.
   */
  public function testAppliesFalseWhenDisabled(): void {
    $augmenter = $this->buildAugmenter(['enabled' => FALSE] + self::DEFAULT_SETTINGS);
    $this->assertFalse($augmenter->applies(['type' => 'navigation']));
  }

  /**
   * Missing retrieve_first config disables augmentation.
   */
  public function testAppliesFalseWhenConfigMissing(): void {
    $augmenter = $this->buildAugmenter(NULL);
    $this->assertFalse($augmenter->applies(['type' => 'navigation']));
  }

  /**
   * Responses that already carry results are never re-augmented.
   */
  public function testAppliesFalseWhenResultsAlreadyPresent(): void {
    $augmenter = $this->buildAugmenter();
    $this->assertFalse($augmenter->applies([
      'type' => 'navigation',
      'results' => [self::resourceItem('1', 'https://example.org/a')],
    ]));
  }

  /**
   * Retrieval-backed response types stay out of scope.
   */
  public function testAppliesFalseForUnlistedType(): void {
    $augmenter = $this->buildAugmenter();
    $this->assertFalse($augmenter->applies(['type' => 'faq']));
    $this->assertFalse($augmenter->applies(['type' => 'resources']));
    $this->assertFalse($augmenter->applies([]));
  }

  /**
   * Configured template-only types are eligible.
   */
  public function testAppliesTrueForConfiguredTemplateTypes(): void {
    $augmenter = $this->buildAugmenter();
    foreach (['navigation', 'topic', 'service_area', 'apply_cta', 'escalation'] as $type) {
      $this->assertTrue($augmenter->applies(['type' => $type]), "Type {$type} must be eligible");
    }
  }

  /**
   * Augmentation attaches results without replacing the message.
   */
  public function testAugmentAttachesResultsWithoutTouchingMessage(): void {
    $augmenter = $this->buildAugmenter(
      self::DEFAULT_SETTINGS,
      [self::resourceItem('r1', 'https://idaholegalaid.org/legal-help/health', 'SSI Benefits Guide')],
    );
    $response = [
      'type' => 'navigation',
      'message' => "Here's our health legal help page.",
    ];
    $augmented = $augmenter->augment($response, ['type' => 'navigation'], 'I need help with SSI benefits.');

    $this->assertSame("Here's our health legal help page.", $augmented['message']);
    $this->assertTrue($augmented['retrieval_supplemented']);
    $this->assertCount(1, $augmented['results']);
    $this->assertSame('SSI Benefits Guide', $augmented['results'][0]['title']);
  }

  /**
   * FAQ and resource results merge deduplicated and capped.
   */
  public function testAugmentMergesFaqAndResourcesDeduplicatedAndCapped(): void {
    $resources = [
      self::resourceItem('r1', 'https://idaholegalaid.org/a'),
      self::resourceItem('r2', 'https://idaholegalaid.org/b'),
      self::resourceItem('r3', 'https://idaholegalaid.org/c'),
    ];
    $faq = [
      ['id' => 'faq_1', 'title' => 'FAQ one', 'url' => 'https://idaholegalaid.org/a', 'source_class' => 'faq_lexical'],
      ['id' => 'faq_2', 'title' => 'FAQ two', 'url' => 'https://idaholegalaid.org/faq-two', 'source_class' => 'faq_lexical'],
    ];
    $augmenter = $this->buildAugmenter(self::DEFAULT_SETTINGS, $resources);
    $augmented = $augmenter->augment(
      ['type' => 'topic', 'message' => 'Topic page.'],
      ['type' => 'topic'],
      'Where can I read about eviction?',
      $faq,
    );

    // FAQ leads (non-resource query); duplicate URL /a dropped; capped at 3.
    $this->assertCount(3, $augmented['results']);
    $this->assertSame('faq_1', $augmented['results'][0]['id']);
    $this->assertSame('faq_2', $augmented['results'][1]['id']);
    $this->assertSame('r2', $augmented['results'][2]['id']);
  }

  /**
   * Document-style queries put resource results first.
   */
  public function testAugmentResourceLeadingForDocumentQueries(): void {
    $resources = [self::resourceItem('r1', 'https://idaholegalaid.org/forms/custody')];
    $faq = [['id' => 'faq_1', 'title' => 'FAQ', 'url' => 'https://idaholegalaid.org/faq', 'source_class' => 'faq_lexical']];
    $augmenter = $this->buildAugmenter(self::DEFAULT_SETTINGS, $resources);
    $augmented = $augmenter->augment(
      ['type' => 'disambiguation', 'message' => 'Which topic?'],
      ['type' => 'disambiguation'],
      'I need custody forms.',
      $faq,
    );

    $this->assertSame('r1', $augmented['results'][0]['id']);
    $this->assertSame('faq_1', $augmented['results'][1]['id']);
  }

  /**
   * Zero-match retrieval still records a provable attempt.
   */
  public function testAugmentRecordsAttemptEvenWithoutMatches(): void {
    $augmenter = $this->buildAugmenter(self::DEFAULT_SETTINGS, []);
    $response = ['type' => 'navigation', 'message' => 'Nav.'];
    $augmented = $augmenter->augment($response, ['type' => 'navigation'], 'anything at all');

    $this->assertArrayNotHasKey('results', $augmented);
    $this->assertArrayNotHasKey('retrieval_supplemented', $augmented);
    // Retrieval ran — the attempt must be provable even with zero matches.
    $this->assertTrue($augmented['retrieval_attempted']);
  }

  /**
   * Retrieval exceptions leave the response untouched.
   */
  public function testAugmentFailsOpenOnRetrievalException(): void {
    $augmenter = $this->buildAugmenter(
      self::DEFAULT_SETTINGS,
      [],
      new \RuntimeException('index unavailable'),
    );
    $response = ['type' => 'service_area', 'message' => 'Area page.'];
    $augmented = $augmenter->augment($response, ['type' => 'service_area', 'area' => 'housing'], 'I am behind on my mortgage.');

    $this->assertSame($response, $augmented);
  }

  /**
   * Refusal-style safety classes get no escalation retrieval.
   */
  public function testEscalationQueryForRefusalClassIsNull(): void {
    $augmenter = $this->buildAugmenter();
    $this->assertNull($augmenter->escalationQueryFor([
      'class' => 'dv_emergency',
      'requires_refusal' => TRUE,
    ]));
  }

  /**
   * Non-emergency classes get no escalation retrieval.
   */
  public function testEscalationQueryForNonEmergencyClassIsNull(): void {
    $augmenter = $this->buildAugmenter();
    $this->assertNull($augmenter->escalationQueryFor([
      'class' => 'wrongdoing',
      'requires_refusal' => FALSE,
    ]));
  }

  /**
   * Informational emergencies map to curated retrieval queries.
   */
  public function testEscalationQueryForEmergencyClasses(): void {
    $augmenter = $this->buildAugmenter();
    $this->assertSame(
      'eviction notice tenant rights',
      $augmenter->escalationQueryFor(['class' => 'eviction_emergency', 'requires_refusal' => FALSE]),
    );
    $this->assertSame(
      'protection order domestic violence',
      $augmenter->escalationQueryFor(['class' => 'dv_emergency', 'requires_refusal' => FALSE]),
    );
  }

  /**
   * Escalation retrieval honors the intent_types config.
   */
  public function testEscalationQueryDisabledWhenEscalationTypeNotConfigured(): void {
    $settings = self::DEFAULT_SETTINGS;
    $settings['intent_types'] = ['navigation', 'topic'];
    $augmenter = $this->buildAugmenter($settings);
    $this->assertNull($augmenter->escalationQueryFor(['class' => 'dv_emergency', 'requires_refusal' => FALSE]));
  }

  /**
   * Escalation augmentation is additive to safety copy.
   */
  public function testAugmentEscalationAttachesResultsAdditively(): void {
    $augmenter = $this->buildAugmenter(
      self::DEFAULT_SETTINGS,
      [self::resourceItem('r1', 'https://idaholegalaid.org/protection-order', 'Protection Order Guide')],
    );
    $response_data = [
      'type' => 'escalation',
      'message' => 'If you are in danger, call 911.',
      'actions' => [['label' => 'Call 911', 'url' => 'tel:911']],
    ];
    $augmented = $augmenter->augmentEscalation($response_data, [
      'class' => 'dv_emergency',
      'requires_refusal' => FALSE,
    ]);

    $this->assertSame('If you are in danger, call 911.', $augmented['message']);
    $this->assertSame($response_data['actions'], $augmented['actions']);
    $this->assertCount(1, $augmented['results']);
    $this->assertTrue($augmented['retrieval_supplemented']);
  }

  /**
   * Refusals pass through escalation augmentation unchanged.
   */
  public function testAugmentEscalationLeavesRefusalUntouched(): void {
    $augmenter = $this->buildAugmenter(
      self::DEFAULT_SETTINGS,
      [self::resourceItem('r1', 'https://idaholegalaid.org/x')],
    );
    $response_data = ['type' => 'refusal', 'message' => 'I cannot help with that.'];
    $augmented = $augmenter->augmentEscalation($response_data, [
      'class' => 'wrongdoing',
      'requires_refusal' => TRUE,
    ]);

    $this->assertSame($response_data, $augmented);
  }

  /**
   * Escalation augmentation fails open on retrieval errors.
   */
  public function testAugmentEscalationFailsOpenOnException(): void {
    $augmenter = $this->buildAugmenter(
      self::DEFAULT_SETTINGS,
      [],
      new \RuntimeException('pinecone timeout'),
    );
    $response_data = ['type' => 'escalation', 'message' => 'Urgent help copy.'];
    $augmented = $augmenter->augmentEscalation($response_data, [
      'class' => 'eviction_emergency',
      'requires_refusal' => FALSE,
    ]);

    $this->assertSame($response_data, $augmented);
  }

}
