<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

require_once __DIR__ . '/../Support/CanonicalUrlFixtures.php';

use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\Tests\ilas_site_assistant\Support\CanonicalUrlFixtures;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for ResponseBuilder + TopIntentsPack integration.
 *
 * Validates that sub-topic intents use pack data instead of the generic
 * fallback, while hardcoded intents retain priority.
 */
#[Group('ilas_site_assistant')]
class ResponseBuilderPackTest extends TestCase {

  /**
   * The TopIntentsPack instance (reads from real YAML).
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pack = new TopIntentsPack(NULL);
  }

  /**
   * Sub-topic intent uses pack answer_text and primary_action.
   */
  public function testSubTopicUsesPackData(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'topic_family_custody'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('topic', $response['response_mode']);
    $this->assertEquals('topic', $response['type']);
    $this->assertStringContainsString('custody', strtolower($response['answer_text']));
    $this->assertEquals('/legal-help/family', $response['primary_action']['url']);
    $this->assertStringStartsWith('intent_pack_', $response['reason_code']);
  }

  /**
   * Eviction sub-topic uses pack data.
   */
  public function testEvictionSubTopicUsesPackData(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'topic_housing_eviction'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('topic', $response['response_mode']);
    $this->assertStringContainsString('eviction', strtolower($response['answer_text']));
    $this->assertEquals('/legal-help/housing', $response['primary_action']['url']);
  }

  /**
   * Debt collection sub-topic uses pack data.
   */
  public function testDebtCollectionSubTopicUsesPackData(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'topic_consumer_debt_collection'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('topic', $response['response_mode']);
    $this->assertStringContainsString('debt collection', strtolower($response['answer_text']));
    $this->assertEquals('/legal-help/consumer', $response['primary_action']['url']);
  }

  /**
   * Hardcoded intents retain priority over pack data.
   */
  public function testHardcodedIntentsTakePriority(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);

    // apply_for_help is both in the pack and hardcoded — hardcoded wins.
    $intent = ['type' => 'apply_for_help'];
    $response = $builder->buildFromIntent($intent);

    // The hardcoded 'apply' case uses MODE_NAVIGATE and type 'apply_cta'.
    $this->assertEquals('navigate', $response['response_mode']);
    $this->assertEquals('apply_cta', $response['type']);
    $this->assertEquals('direct_navigation_apply', $response['reason_code']);
  }

  /**
   * Hotline hardcoded intent is not overridden by pack.
   */
  public function testHotlineHardcodedNotOverridden(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'legal_advice_line'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('navigate', $response['response_mode']);
    $this->assertEquals('direct_navigation_hotline', $response['reason_code']);
  }

  /**
   * Unknown intents still hit the default fallback.
   */
  public function testUnknownIntentHitsDefault(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'completely_unknown_xyz'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('fallback', $response['response_mode']);
    $this->assertEquals('fallback', $response['type']);
    $this->assertEquals('no_match_fallback', $response['reason_code']);
  }

  /**
   * Without TopIntentsPack, sub-topic intents hit fallback.
   */
  public function testWithoutPackSubTopicHitsFallback(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults());
    $intent = ['type' => 'topic_family_custody'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('fallback', $response['response_mode']);
    $this->assertEquals('no_match_fallback', $response['reason_code']);
  }

  /**
   * Meta intents use pack data (meta_help, meta_contact, etc.).
   */
  public function testMetaIntentUsesPackData(): void {
    $builder = new ResponseBuilder(CanonicalUrlFixtures::defaults(), $this->pack);
    $intent = ['type' => 'meta_help'];
    $response = $builder->buildFromIntent($intent);

    $this->assertEquals('topic', $response['response_mode']);
    $this->assertStringContainsString('help you find', strtolower($response['answer_text']));
    $this->assertStringStartsWith('intent_pack_', $response['reason_code']);
  }

}
