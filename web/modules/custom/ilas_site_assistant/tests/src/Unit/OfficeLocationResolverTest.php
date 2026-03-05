<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

// Load the required class directly (no Drupal bootstrap needed).
require_once __DIR__ . '/../../../src/Service/OfficeLocationResolver.php';

use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;

/**
 * Unit tests for OfficeLocationResolver.
 *
 * Tests city, county, and abbreviation resolution to ILAS offices.
 */
#[Group('ilas_site_assistant')]
class OfficeLocationResolverTest extends TestCase {

  /**
   * The resolver under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\OfficeLocationResolver
   */
  protected $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resolver = new OfficeLocationResolver();
  }

  /**
   * Tests that known cities resolve to the correct office.
   */
  #[DataProvider('cityResolutionProvider')]
  public function testCityResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);

    $this->assertNotNull($result, "Input '$input' should resolve to an office");
    $this->assertEquals(
      $expected_office,
      $result['name'],
      "Input '$input' should resolve to '$expected_office', got '{$result['name']}'"
    );
  }

  /**
   * Data provider for city resolution tests.
   */
  public static function cityResolutionProvider(): array {
    return [
      'boise' => ['boise', 'Boise'],
      'boise_uppercase' => ['BOISE', 'Boise'],
      'boise_whitespace' => ['  boise  ', 'Boise'],
      'nampa_nearest_boise' => ['nampa', 'Boise'],
      'meridian' => ['meridian', 'Boise'],
      'caldwell' => ['caldwell', 'Boise'],
      'eagle' => ['eagle', 'Boise'],
      'mountain_home' => ['mountain home', 'Boise'],
      'pocatello' => ['pocatello', 'Pocatello'],
      'blackfoot' => ['blackfoot', 'Pocatello'],
      'american_falls' => ['american falls', 'Pocatello'],
      'twin_falls' => ['twin falls', 'Twin Falls'],
      'jerome' => ['jerome', 'Twin Falls'],
      'burley' => ['burley', 'Twin Falls'],
      'hailey' => ['hailey', 'Twin Falls'],
      'ketchum' => ['ketchum', 'Twin Falls'],
      'lewiston' => ['lewiston', 'Lewiston'],
      'moscow' => ['moscow', 'Lewiston'],
      'coeur_dalene' => ["coeur d'alene", 'Lewiston'],
      'sandpoint' => ['sandpoint', 'Lewiston'],
      'post_falls' => ['post falls', 'Lewiston'],
      'idaho_falls' => ['idaho falls', 'Idaho Falls'],
      'rexburg' => ['rexburg', 'Idaho Falls'],
      'driggs' => ['driggs', 'Idaho Falls'],
      'salmon' => ['salmon', 'Idaho Falls'],
      'rigby' => ['rigby', 'Idaho Falls'],
    ];
  }

  /**
   * Tests abbreviation resolution.
   */
  #[DataProvider('abbreviationProvider')]
  public function testAbbreviationResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);

    $this->assertNotNull($result, "Abbreviation '$input' should resolve to an office");
    $this->assertEquals(
      $expected_office,
      $result['name'],
      "Abbreviation '$input' should resolve to '$expected_office'"
    );
  }

  /**
   * Data provider for abbreviation tests.
   */
  public static function abbreviationProvider(): array {
    return [
      'cda' => ['CDA', 'Lewiston'],
      'cda_lowercase' => ['cda', 'Lewiston'],
      'if' => ['IF', 'Idaho Falls'],
      'tf' => ['TF', 'Twin Falls'],
      'mtn_home' => ['mtn home', 'Boise'],
    ];
  }

  /**
   * Tests county resolution.
   */
  #[DataProvider('countyResolutionProvider')]
  public function testCountyResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);

    $this->assertNotNull($result, "County input '$input' should resolve to an office");
    $this->assertEquals(
      $expected_office,
      $result['name'],
      "County input '$input' should resolve to '$expected_office'"
    );
  }

  /**
   * Data provider for county resolution tests.
   */
  public static function countyResolutionProvider(): array {
    return [
      'ada_county' => ['ada county', 'Boise'],
      'Ada_County_capitalized' => ['Ada County', 'Boise'],
      'canyon_county' => ['canyon county', 'Boise'],
      'bannock_county' => ['bannock county', 'Pocatello'],
      'bingham_county' => ['bingham county', 'Pocatello'],
      'twin_falls_county' => ['twin falls county', 'Twin Falls'],
      'blaine_county' => ['blaine county', 'Twin Falls'],
      'nez_perce_county' => ['nez perce county', 'Lewiston'],
      'kootenai_county' => ['kootenai county', 'Lewiston'],
      'latah_county' => ['latah county', 'Lewiston'],
      'bonneville_county' => ['bonneville county', 'Idaho Falls'],
      'madison_county' => ['madison county', 'Idaho Falls'],
      'fremont_county' => ['fremont county', 'Idaho Falls'],
      // Bare county names (without "county" suffix).
      'bonneville_bare' => ['bonneville', 'Idaho Falls'],
      'bannock_bare' => ['bannock', 'Pocatello'],
      'latah_bare' => ['latah', 'Lewiston'],
      'blaine_bare' => ['blaine', 'Twin Falls'],
      'elmore_bare' => ['elmore', 'Boise'],
    ];
  }

  /**
   * Tests that unknown locations return NULL.
   */
  #[DataProvider('unknownLocationProvider')]
  public function testUnknownLocationReturnsNull(string $input): void {
    $result = $this->resolver->resolve($input);

    $this->assertNull($result, "Input '$input' should return NULL");
  }

  /**
   * Data provider for unknown location tests.
   */
  public static function unknownLocationProvider(): array {
    return [
      'empty' => [''],
      'whitespace_only' => ['   '],
      'mars' => ['mars'],
      'new_york' => ['new york'],
      'portland' => ['portland'],
      'gibberish' => ['asdfghjkl'],
      'help' => ['i need help'],
    ];
  }

  /**
   * Tests that resolved offices contain all required keys.
   */
  public function testResolvedOfficeHasRequiredKeys(): void {
    $result = $this->resolver->resolve('boise');

    $this->assertNotNull($result);
    $this->assertArrayHasKey('name', $result);
    $this->assertArrayHasKey('address', $result);
    $this->assertArrayHasKey('phone', $result);
    $this->assertArrayHasKey('hours', $result);
    $this->assertArrayHasKey('url', $result);
    $this->assertNotEmpty($result['name']);
    $this->assertNotEmpty($result['address']);
    $this->assertNotEmpty($result['phone']);
    $this->assertNotEmpty($result['hours']);
    $this->assertNotEmpty($result['url']);
  }

  /**
   * Tests getAllOffices returns exactly 5 entries with required keys.
   */
  public function testGetAllOfficesReturnsAllFive(): void {
    $offices = $this->resolver->getAllOffices();

    $this->assertCount(5, $offices);

    foreach ($offices as $slug => $office) {
      $this->assertArrayHasKey('name', $office, "Office '$slug' must have 'name'");
      $this->assertArrayHasKey('address', $office, "Office '$slug' must have 'address'");
      $this->assertArrayHasKey('phone', $office, "Office '$slug' must have 'phone'");
      $this->assertArrayHasKey('hours', $office, "Office '$slug' must have 'hours'");
      $this->assertArrayHasKey('url', $office, "Office '$slug' must have 'url'");
      $this->assertNotEmpty($office['name']);
      $this->assertNotEmpty($office['address']);
      $this->assertNotEmpty($office['phone']);
      $this->assertStringContainsString('call to confirm', strtolower($office['hours']));
      $this->assertStringStartsWith('/contact/offices/', $office['url']);
    }
  }

  /**
   * Tests that office URLs follow the pathauto pattern.
   */
  public function testOfficeUrlsFollowPathautoPattern(): void {
    $offices = $this->resolver->getAllOffices();

    $expected_urls = [
      'boise' => '/contact/offices/boise',
      'pocatello' => '/contact/offices/pocatello',
      'twin_falls' => '/contact/offices/twin-falls',
      'lewiston' => '/contact/offices/lewiston',
      'idaho_falls' => '/contact/offices/idaho-falls',
    ];

    foreach ($expected_urls as $slug => $url) {
      $this->assertEquals($url, $offices[$slug]['url'], "Office '$slug' URL mismatch");
    }
  }

}
