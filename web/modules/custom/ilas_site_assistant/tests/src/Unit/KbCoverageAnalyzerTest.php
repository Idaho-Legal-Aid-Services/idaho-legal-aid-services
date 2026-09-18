<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\KbCoverageAnalyzer;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Content-coverage measurement per routed sub-topic.
 */
#[Group('ilas_site_assistant')]
final class KbCoverageAnalyzerTest extends TestCase {

  private const TOPIC_MAP = [
    'housing' => [
      'label' => 'Housing',
      'topics' => [
        'landlord_tenant' => [
          'canonical_key' => 'housing_landlord_tenant',
          'term_name' => 'Tenant Rights',
          'aliases' => ['landlord', 'inquilino', 'rent', 'fee'],
        ],
        'foreclosure' => [
          'canonical_key' => 'housing_foreclosure',
          'term_name' => 'Foreclosure',
        ],
      ],
    ],
    'family' => [
      'label' => 'Family Law',
      'topics' => [
        'custody' => [
          'canonical_key' => 'family_custody_parenting',
          'pack_intent' => 'topic_family_custody',
          'term_name' => 'Custody',
        ],
      ],
    ],
    'not_an_area' => 'ignored',
  ];

  /**
   * Indexes analyzer rows by canonical key.
   */
  private static function byKey(array $rows): array {
    return array_column($rows, NULL, 'canonical_key');
  }

  /**
   * Heading matches are strong, body matches weak, bundles counted apart.
   */
  public function testHeadingBodyAndBundleCounts(): void {
    $rows = self::byKey(KbCoverageAnalyzer::analyze(self::TOPIC_MAP, [
      ['bundle' => 'faq_item', 'heading' => 'Can my LANDLORD enter?', 'body' => 'x'],
      ['bundle' => 'accordion_item', 'heading' => 'Rights &amp; Responsibilities of a landlord', 'body' => 'x'],
      ['bundle' => 'accordion_item', 'heading' => 'Moving out', 'body' => '<p>Tell your <strong>landlord</strong> in writing.</p>'],
      ['bundle' => 'faq_item', 'heading' => 'Divorce basics', 'body' => 'No match here.'],
    ], ['topic_housing_landlord_tenant', 'topic_family_custody']));

    $landlord = $rows['housing_landlord_tenant'];
    $this->assertSame(1, $landlord['faq_heading']);
    $this->assertSame(1, $landlord['accordion_heading']);
    $this->assertSame(1, $landlord['body_only']);
    $this->assertSame(KbCoverageAnalyzer::STATUS_OK, $landlord['status']);
    $this->assertTrue($landlord['has_pack']);
    $this->assertSame('Housing', $landlord['area']);

    $this->assertSame(KbCoverageAnalyzer::STATUS_NONE, $rows['housing_foreclosure']['status']);
    $this->assertFalse($rows['housing_foreclosure']['has_pack']);
  }

  /**
   * Body-only evidence is reported as thin, not as covered.
   */
  public function testBodyOnlyCoverageIsThin(): void {
    $rows = self::byKey(KbCoverageAnalyzer::analyze(self::TOPIC_MAP, [
      ['bundle' => 'accordion_item', 'heading' => 'Housing help', 'body' => 'Facing foreclosure? Call us.'],
    ], []));

    $this->assertSame(KbCoverageAnalyzer::STATUS_THIN, $rows['housing_foreclosure']['status']);
  }

  /**
   * Terms match whole words only, and very short aliases are ignored.
   */
  public function testWholeWordMatchingAndShortTermsIgnored(): void {
    $rows = self::byKey(KbCoverageAnalyzer::analyze(self::TOPIC_MAP, [
      // "landlords" is not the alias "landlord", "parent" does not contain
      // the word "rent", and the 3-character alias "fee" is ignored outright.
      ['bundle' => 'faq_item', 'heading' => 'Landlords, a parent, and a late fee', 'body' => ''],
    ], []));

    $this->assertSame(0, $rows['housing_landlord_tenant']['faq_heading']);
    $terms = KbCoverageAnalyzer::termsFor('landlord_tenant', self::TOPIC_MAP['housing']['topics']['landlord_tenant']);
    $this->assertContains('rent', $terms);
    $this->assertNotContains('fee', $terms);
  }

  /**
   * Spanish aliases match accented, mixed-case text.
   */
  public function testSpanishAliasMatches(): void {
    $rows = self::byKey(KbCoverageAnalyzer::analyze(self::TOPIC_MAP, [
      ['bundle' => 'faq_item', 'heading' => '¿Qué derechos tiene un Inquilino?', 'body' => ''],
    ], []));

    $this->assertSame(1, $rows['housing_landlord_tenant']['faq_heading']);
  }

  /**
   * The pack_intent override and pending stubs are reported.
   */
  public function testPackIntentOverrideAndPendingStubs(): void {
    $rows = self::byKey(KbCoverageAnalyzer::analyze(self::TOPIC_MAP, [], ['topic_family_custody'], [
      ['question' => 'Can my landlord keep my deposit?'],
      ['question' => 'How is custody decided?'],
      'malformed',
    ]));

    $this->assertSame('topic_family_custody', $rows['family_custody_parenting']['pack_intent']);
    $this->assertTrue($rows['family_custody_parenting']['has_pack']);
    $this->assertSame(1, $rows['family_custody_parenting']['stubs_pending']);
    $this->assertSame(1, $rows['housing_landlord_tenant']['stubs_pending']);
  }

  /**
   * The shipped topic map yields one row per sub-topic with a valid status.
   */
  public function testShippedTopicMapProducesARowPerSubTopic(): void {
    $topic_map = Yaml::parseFile(dirname(__DIR__, 3) . '/config/routing/topic_map.yml');
    $expected = 0;
    foreach ($topic_map as $area) {
      $expected += is_array($area) ? count($area['topics'] ?? []) : 0;
    }

    $rows = KbCoverageAnalyzer::analyze($topic_map, [], (new TopIntentsPack(NULL))->getAllKeys());
    $this->assertGreaterThan(0, $expected);
    $this->assertCount($expected, $rows);
    $this->assertSame([KbCoverageAnalyzer::STATUS_NONE], array_values(array_unique(array_column($rows, 'status'))));
    $this->assertTrue(self::byKey($rows)['housing_landlord_tenant']['has_pack']);
  }

}
