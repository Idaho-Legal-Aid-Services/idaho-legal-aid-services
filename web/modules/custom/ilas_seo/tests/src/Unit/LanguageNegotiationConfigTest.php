<?php

namespace Drupal\Tests\ilas_seo\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the interface-language negotiation methods against silent re-enabling.
 *
 * Browser language negotiation must stay disabled for language_interface.
 * Core's LanguageNegotiationBrowser::getLangcode() calls
 * $this->pageCacheKillSwitch->trigger() unconditionally, and it is reached
 * whenever language-url fails to match. With prefixes {en: '', es, sw, nl},
 * language-url matches only '/' and paths starting es/sw/nl, so enabling
 * language-browser makes every other URL on the site uncacheable -- including
 * redirects and 404s, because negotiation runs at kernel.request priority 255,
 * before routing. Measured effect was a sitewide cache hit ratio of ~20%.
 *
 * See docs/pantheon-cloudflare-preimplementation-validation.md section 8.9B.
 *
 * The risk this guards against is silent reintroduction: an editor toggling
 * "Browser" at /admin/config/regional/language/detection followed by a config
 * export puts the line back with no visible signal. Pure YAML scan, no Drupal
 * bootstrap required.
 */
#[Group('ilas_seo')]
class LanguageNegotiationConfigTest extends TestCase {

  /**
   * Path to the config sync directory.
   */
  protected string $configDir;

  /**
   * Parsed language.types config.
   */
  protected array $languageTypes;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // 7 levels up from Unit/ dir reaches the project root.
    $this->configDir = dirname(__DIR__, 7) . '/config';
    $this->assertDirectoryExists($this->configDir,
      'Config sync directory not found at: ' . $this->configDir);

    $file = $this->configDir . '/language.types.yml';
    $this->assertFileExists($file);
    $this->languageTypes = Yaml::parseFile($file);
  }

  /**
   * Browser negotiation must not be enabled for the interface language.
   */
  public function testBrowserNegotiationIsNotEnabledForInterface(): void {
    $enabled = $this->languageTypes['negotiation']['language_interface']['enabled'] ?? NULL;
    $this->assertIsArray($enabled,
      'language.types must define negotiation.language_interface.enabled.');

    $this->assertArrayNotHasKey('language-browser', $enabled,
      'Caching: "language-browser" must NOT be enabled under '
      . 'negotiation.language_interface.enabled. Core triggers the page-cache '
      . 'kill switch unconditionally in that negotiation method, which makes '
      . 'every non-language-prefixed URL on the site uncacheable. If this was '
      . 're-enabled from the admin UI at '
      . '/admin/config/regional/language/detection, uncheck "Browser" and '
      . 're-export. See docs/pantheon-cloudflare-preimplementation-validation.md '
      . 'section 8.9B.');
  }

  /**
   * The method weight is retained so re-enabling stays a one-line change.
   */
  public function testBrowserMethodWeightIsRetained(): void {
    $weights = $this->languageTypes['negotiation']['language_interface']['method_weights'] ?? NULL;
    $this->assertIsArray($weights,
      'language.types must define negotiation.language_interface.method_weights.');

    $this->assertArrayHasKey('language-browser', $weights,
      'Rollback: "language-browser" must remain in '
      . 'negotiation.language_interface.method_weights so that restoring '
      . 'browser negotiation is a one-line change to the "enabled" map.');
    $this->assertSame(-2, $weights['language-browser'],
      'Rollback: the retained language-browser method weight must stay -2.');
  }

  /**
   * The negotiation methods the site actually relies on must stay enabled.
   */
  public function testRequiredInterfaceNegotiationMethodsRemainEnabled(): void {
    $enabled = $this->languageTypes['negotiation']['language_interface']['enabled'] ?? [];

    $expected = [
      'language-user-admin' => -10,
      'language-url' => -8,
      'language-selected' => 12,
    ];

    foreach ($expected as $method => $weight) {
      $this->assertArrayHasKey($method, $enabled,
        'Language: "' . $method . '" must stay enabled for language_interface. '
        . 'Removing it changes how the interface language is resolved.');
      $this->assertSame($weight, $enabled[$method],
        'Language: "' . $method . '" must keep weight ' . $weight
        . ' so negotiation order is unchanged.');
    }

    $this->assertSame(array_keys($expected), array_keys($enabled),
      'Language: language_interface.enabled must contain exactly '
      . implode(', ', array_keys($expected)) . ' -- no more, no less.');
  }

  /**
   * URL prefix negotiation is the mechanism the fix depends on.
   */
  public function testUrlNegotiationStillResolvesLanguagePrefixes(): void {
    $file = $this->configDir . '/language.negotiation.yml';
    $this->assertFileExists($file);

    $negotiation = Yaml::parseFile($file);

    $this->assertSame('path_prefix', $negotiation['url']['source'] ?? NULL,
      'Language: URL negotiation must use path_prefix. Spanish, Swahili and '
      . 'Dutch URLs resolve entirely through the prefix now that browser '
      . 'negotiation is disabled.');

    $prefixes = $negotiation['url']['prefixes'] ?? [];
    foreach (['es', 'sw', 'nl'] as $langcode) {
      $this->assertSame($langcode, $prefixes[$langcode] ?? NULL,
        'Language: the "' . $langcode . '" URL prefix must be preserved.');
    }
    $this->assertSame('', $prefixes['en'] ?? NULL,
      'Language: English must keep the empty prefix so English URLs stay '
      . 'unprefixed.');
  }

}
