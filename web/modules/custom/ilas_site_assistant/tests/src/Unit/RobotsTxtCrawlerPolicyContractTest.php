<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Locks assistant crawler-policy contracts to the served static robots file.
 */
#[Group('ilas_site_assistant')]
final class RobotsTxtCrawlerPolicyContractTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return str_replace("\r\n", "\n", trim($contents));
  }

  /**
   * Reads a YAML file from repo root.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML: {$relativePath}");
    return $parsed;
  }

  /**
   * Returns the normalized robots.txt content from a YAML export.
   */
  private static function readRobotsConfigContent(string $relativePath): string {
    $parsed = self::readYaml($relativePath);
    self::assertArrayHasKey('content', $parsed, "Missing content key in {$relativePath}");
    self::assertIsString($parsed['content'], "Config content must be a string in {$relativePath}");
    return str_replace("\r\n", "\n", trim($parsed['content']));
  }

  /**
   * Splits normalized robots.txt content into trimmed non-empty lines.
   */
  private static function robotsLines(string $contents): array {
    $lines = array_map('trim', explode("\n", $contents));
    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
  }

  /**
   * The served robots.txt must disallow assistant API crawling.
   */
  public function testStaticRobotsTxtContainsAssistantApiDisallows(): void {
    $robots = self::readFile('web/robots.txt');

    $this->assertStringContainsString('Disallow: /assistant/api/', $robots);
    $this->assertStringContainsString('Disallow: /index.php/assistant/api/', $robots);
  }

  /**
   * Config mirrors must stay byte-for-byte aligned with the static robots file.
   */
  public function testRobotstxtConfigExportsMatchStaticRobotsTxt(): void {
    $robots = self::readFile('web/robots.txt');

    $this->assertSame($robots, self::readRobotsConfigContent('config/robotstxt.settings.yml'));
    $this->assertSame($robots, self::readRobotsConfigContent('web/sites/default/files/sync/robotstxt.settings.yml'));
  }

  /**
   * The public assistant page must remain crawlable while APIs are blocked.
   */
  public function testAssistantPageRouteIsNotDisallowed(): void {
    $lines = self::robotsLines(self::readFile('web/robots.txt'));

    $this->assertNotContains('Disallow: /assistant', $lines);
    $this->assertNotContains('Disallow: /assistant/', $lines);
  }

  /**
   * Composer scaffold must keep the static robots file as the live source.
   */
  public function testComposerScaffoldPreservesStaticRobotsTxt(): void {
    $composer = json_decode(self::readFile('composer.json'), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertIsArray($composer);
    $this->assertSame(
      FALSE,
      $composer['extra']['drupal-scaffold']['file-mapping']['[web-root]/robots.txt'] ?? NULL
    );
  }

}
