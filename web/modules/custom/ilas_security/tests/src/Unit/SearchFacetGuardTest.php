<?php

namespace Drupal\Tests\ilas_security\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the pre-bootstrap facet-spam guard used by settings.php.
 *
 * The request strings are taken from live access logs (2026-09-20 to
 * 2026-11-20), including the lower-case encoding that the previous inline
 * check let through with a 200.
 */
#[Group('ilas_security')]
class SearchFacetGuardTest extends TestCase {

  /**
   * Absolute path to web/sites/default.
   */
  private static function sitesDefault(): string {
    return dirname(__DIR__, 7) . '/web/sites/default';
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once self::sitesDefault() . '/settings.search-guard.php';
  }

  /**
   * Requests the guard must refuse.
   */
  public static function blockedRequests(): array {
    return [
      'upper-case encoding, as the crawler sends it' => ['/search?f%5B0%5D=topics%3A2416&f%5B1%5D=topics%3A2421&f%5B2%5D=topics%3A2469'],
      'lower-case encoding, previously answered 200' => ['/search?f%5b2%5d=tags%3a2815'],
      'literal brackets' => ['/search?f[0]=im_field_category:2565&page=74'],
      'mixed-case encoding' => ['/search?f%5b0%5D=topics%3A2416'],
      'encoded letter f' => ['/search?%66%5B0%5D=topics%3A2416'],
      'facet after another parameter' => ['/search?keys=eviction&f%5B0%5D=topics%3A2487'],
      'language prefix' => ['/sw/search?f%5B0%5D=topics%3A2416'],
      'spanish prefix' => ['/es/search?page=2&f%5B0%5D=topics%3A2416'],
      'index.php prefix' => ['/index.php/search?f%5B0%5D=topics%3A2416'],
      'trailing slash' => ['/search/?f%5B0%5D=topics%3A2416'],
      'upper-case path' => ['/SEARCH?f%5B0%5D=topics%3A2416'],
      'high filter index' => ['/search?f%5B11%5D=topics%3A2596&f%5B12%5D=topics%3A2638'],
    ];
  }

  /**
   * Requests the guard must leave alone.
   */
  public static function allowedRequests(): array {
    return [
      'search page' => ['/search'],
      'keyword search' => ['/search?keys=eviction'],
      'keyword search with antibot key and page' => ['/search?keys=custody&antibot_key=abc123&page=2'],
      'language search page' => ['/es/search?page=1'],
      'keyword containing f[' => ['/search?keys=pdf%5B1%5D'],
      'parameter merely ending in f' => ['/search?ref%5B0%5D=x'],
      'facet-like parameter on another page' => ['/resources?f%5B0%5D=topics%3A2416'],
      'admin path' => ['/admin/config/search/pages?f%5B0%5D=x'],
      'home page' => ['/'],
      'path that only starts with search' => ['/searching-for-help?f%5B0%5D=x'],
    ];
  }

  /**
   * Faceted search requests are recognised in every encoding.
   */
  #[DataProvider('blockedRequests')]
  public function testFacetRequestsAreBlocked(string $requestUri): void {
    $this->assertTrue(_ilas_is_facet_spam_request($requestUri), $requestUri);
  }

  /**
   * Ordinary requests are not treated as facet spam.
   */
  #[DataProvider('allowedRequests')]
  public function testOrdinaryRequestsAreAllowed(string $requestUri): void {
    $this->assertFalse(_ilas_is_facet_spam_request($requestUri), $requestUri);
  }

  /**
   * The real settings.php stops a faceted request before doing anything else.
   *
   * Runs settings.php in a child PHP process, because the guard ends the
   * request with die().
   */
  #[DataProvider('blockedRequests')]
  public function testSettingsPhpRefusesFacetRequests(string $requestUri): void {
    $code = sprintf(
      '$_SERVER["REQUEST_URI"] = %s; include %s; echo "REACHED-END-OF-SETTINGS";',
      var_export($requestUri, TRUE),
      var_export(self::sitesDefault() . '/settings.php', TRUE)
    );
    $output = [];
    $exit = NULL;
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

    $this->assertSame('Error: Invalid search parameters.', implode("\n", $output), $requestUri);
    $this->assertSame(0, $exit);
  }

}
