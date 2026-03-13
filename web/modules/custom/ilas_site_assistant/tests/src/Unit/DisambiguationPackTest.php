<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\DisambiguationPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the YAML-backed disambiguation catalog.
 */
#[Group('ilas_site_assistant')]
class DisambiguationPackTest extends TestCase {

  /**
   * Tests that the catalog file loads with required sections.
   */
  public function testCatalogLoadsWithRequiredSections(): void {
    $pack = new DisambiguationPack();
    $data = $pack->getRawData();

    $this->assertSame('1.0', $pack->getVersion());
    $this->assertArrayHasKey('families', $data);
    $this->assertArrayHasKey('topic_lexicon', $data);
    $this->assertArrayHasKey('confusable_pairs', $data);
    $this->assertNotEmpty($pack->getFamilies());
    $this->assertNotEmpty($pack->getConfusablePairs());
  }

  /**
   * Tests that family definitions expose the expected matcher fields.
   */
  public function testFamilyDefinitionsExposeMatcherFields(): void {
    $pack = new DisambiguationPack();
    $families = $pack->getFamilies();

    $this->assertArrayHasKey('generic_help', $families);
    $generic = $families['generic_help'];

    $this->assertSame('generic_help', $generic['stable_family']);
    $this->assertNotEmpty($generic['question'] ?? '');
    $this->assertNotEmpty($generic['options'] ?? []);
    $this->assertNotEmpty($generic['exact_aliases'] ?? []);
    $this->assertNotEmpty($generic['any_tokens'] ?? []);
    $this->assertNotEmpty($generic['negative_tokens'] ?? []);
  }

  /**
   * Tests that topic lexicon includes settings and topics.
   */
  public function testTopicLexiconIncludesSettingsAndTopics(): void {
    $pack = new DisambiguationPack();
    $topicLexicon = $pack->getTopicLexicon();

    $this->assertNotEmpty($topicLexicon['modifiers'] ?? []);
    $this->assertNotEmpty($topicLexicon['filler_words'] ?? []);
    $this->assertNotEmpty($topicLexicon['lead_patterns'] ?? []);
    $this->assertArrayHasKey('custody', $topicLexicon['topics'] ?? []);
    $this->assertSame('family', $topicLexicon['topics']['custody']['area'] ?? '');
  }

}
