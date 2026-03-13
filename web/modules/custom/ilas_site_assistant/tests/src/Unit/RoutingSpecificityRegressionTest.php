<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../../../src/Service/OfficeLocationResolver.php';
require_once __DIR__ . '/../../../src/Service/Disambiguator.php';

use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;
use Drupal\ilas_site_assistant\Service\Disambiguator;

/**
 * Regression tests for routing specificity and UX improvements (PHARD-07).
 *
 * Covers:
 * - Office detail request regex widening
 * - Resolver + detail-request gate relaxation
 * - Vague query disambiguation entries
 * - Topic lead pattern improvements
 */
#[Group('ilas_site_assistant')]
class RoutingSpecificityRegressionTest extends TestCase {

  /**
   * Tests isOfficeDetailRequest matches widened patterns via resolver.
   *
   * These sentences contain city names the resolver finds AND phrasing
   * that should now pass the widened isOfficeDetailRequest regex.
   */
  #[DataProvider('officeDetailRequestProvider')]
  public function testOfficeResolverFindsCity(string $message, string $expected_office): void {
    $resolver = new OfficeLocationResolver();
    $result = $resolver->resolve($message);

    $this->assertNotNull($result, "Resolver should find an office in: '$message'");
    $this->assertEquals(
      $expected_office,
      $result['name'],
      "Message '$message' should resolve to '$expected_office'"
    );
  }

  /**
   * Data provider for office detail request tests.
   */
  public static function officeDetailRequestProvider(): array {
    return [
      'where_is_boise' => ['where is the Boise office', 'Boise'],
      'what_office_twin_falls' => ['what office helps me in Twin Falls', 'Twin Falls'],
      'closest_office_nampa' => ['closest office to Nampa', 'Boise'],
      'directions_to_lewiston' => ['directions to the Lewiston office', 'Lewiston'],
      'visit_pocatello' => ['can I visit the Pocatello office', 'Pocatello'],
      'nearest_idaho_falls' => ['nearest office to Idaho Falls', 'Idaho Falls'],
    ];
  }

  /**
   * Tests that vague queries produce disambiguation responses.
   */
  #[DataProvider('vagueQueryRegressionProvider')]
  public function testVagueQueryDisambiguation(string $message, array $expected_intents): void {
    $disambiguator = new Disambiguator();
    $result = $disambiguator->check($message, []);

    $this->assertNotNull($result, "Expected disambiguation for: '$message'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('vague_query', $result['reason']);

    $option_intents = array_map(
      static fn(array $option): string => (string) ($option['intent'] ?? ''),
      $result['options']
    );

    foreach ($expected_intents as $intent) {
      $this->assertContains(
        $intent,
        $option_intents,
        "Disambiguation for '$message' should include intent '$intent'"
      );
    }
  }

  /**
   * Data provider for vague query regression tests.
   */
  public static function vagueQueryRegressionProvider(): array {
    return [
      'help_me' => ['help me', ['apply_for_help', 'legal_advice_line', 'forms_finder', 'guides_finder']],
      'please_help' => ['please help', ['apply_for_help', 'legal_advice_line']],
      'please_help_me' => ['please help me', ['apply_for_help', 'legal_advice_line']],
      'i_need_legal_help' => ['i need legal help', ['apply_for_help', 'legal_advice_line', 'forms_finder']],
      'i_dont_know' => ['i dont know what to do', ['apply_for_help', 'legal_advice_line', 'offices_contact']],
      'im_lost' => ['im lost', ['apply_for_help', 'legal_advice_line', 'offices_contact', 'forms_finder']],
      'necesito_ayuda' => ['necesito ayuda', ['apply_for_help', 'legal_advice_line']],
      'ayudame' => ['ayudame', ['apply_for_help', 'legal_advice_line']],
      'ayudeme' => ['ayudeme', ['apply_for_help', 'legal_advice_line']],
    ];
  }

  /**
   * Tests that "i got kicked out" resolves to housing via topic triggers.
   *
   * This message routes through IntentRouter's scoreAllIntents to housing,
   * not through Disambiguator. The test verifies the resolver doesn't
   * intercept it as an office query (no city name present).
   */
  public function testEvictionNarrativeNotInterceptedByOfficeResolver(): void {
    $resolver = new OfficeLocationResolver();
    $result = $resolver->resolve('i got kicked out');
    $this->assertNull($result, "'i got kicked out' should NOT resolve to an office");
  }

  /**
   * Tests that action-implied lead patterns resolve to topic disambiguation.
   */
  #[DataProvider('actionImpliedTopicProvider')]
  public function testActionImpliedTopicLeadPatterns(string $message, string $expected_area): void {
    $disambiguator = new Disambiguator();
    $result = $disambiguator->check($message, []);

    $this->assertNotNull($result, "Expected disambiguation for: '$message'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
  }

  /**
   * Data provider for action-implied topic tests.
   */
  public static function actionImpliedTopicProvider(): array {
    return [
      'file_for_divorce' => ['i need to file for divorce', 'family'],
      'file_custody' => ['i need to file for custody', 'family'],
      'how_file_eviction' => ['how do i file for eviction', 'housing'],
      'how_get_divorce' => ['how can i get a divorce', 'family'],
    ];
  }

  /**
   * Tests that "what services do you offer" is a vague query.
   */
  public function testServicesOverviewVagueQuery(): void {
    $disambiguator = new Disambiguator();
    $result = $disambiguator->check('what do you offer', []);

    $this->assertNotNull($result, "Expected disambiguation for 'what do you offer'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('vague_query', $result['reason']);

    $option_intents = array_map(
      static fn(array $option): string => (string) ($option['intent'] ?? ''),
      $result['options']
    );
    $this->assertContains('services_overview', $option_intents);
  }

}
