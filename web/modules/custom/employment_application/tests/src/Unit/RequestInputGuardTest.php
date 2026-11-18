<?php

declare(strict_types=1);

namespace Drupal\Tests\employment_application\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards against reading request input in a way that throws on arrays.
 *
 * Symfony's InputBag::get() throws a BadRequestException for a non-scalar
 * value. Reading "?page=" that way on every rendered page turned malformed
 * URLs into logged 400s (Sentry PHP-C4), and the same call sat in front of
 * code that believed it handled arrays. The fix was applied site by site
 * once already; this test is what stops the pattern coming back.
 *
 * Custom code reads the query string and the form body through a
 * RequestInput helper (or ->all()). A direct ->query->get( or
 * ->request->get( anywhere in custom modules or the theme fails here.
 */
#[Group('employment_application')]
final class RequestInputGuardTest extends TestCase {

  /**
   * Matches $x->query->get( and $x->request->get( on a request object.
   */
  private const FORBIDDEN = '/->(?:query|request)->get\s*\(/';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Lists the production PHP files of custom modules and the custom theme.
   *
   * @return string[]
   *   Absolute file paths.
   */
  private static function productionFiles(): array {
    $root = self::repoRoot();
    $files = [];
    foreach (['web/modules/custom', 'web/themes/custom/b5subtheme'] as $base) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root . '/' . $base, \FilesystemIterator::SKIP_DOTS),
      );
      foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (preg_match('#/(tests|node_modules|vendor)/#', $path) === 1) {
          continue;
        }
        if (preg_match('/\.(php|module|theme|inc|install)$/', $path) === 1) {
          $files[] = $path;
        }
      }
    }
    sort($files);
    return $files;
  }

  /**
   * No production file reads request input with a call that throws on arrays.
   */
  public function testNoDirectInputBagGetInCustomCode(): void {
    $files = self::productionFiles();
    $this->assertGreaterThan(50, count($files), 'The scan found the custom code.');

    $violations = [];
    foreach ($files as $path) {
      foreach (file($path) as $number => $line) {
        if (preg_match(self::FORBIDDEN, $line) === 1) {
          $violations[] = substr($path, strlen(self::repoRoot()) + 1) . ':' . ($number + 1) . '  ' . trim($line);
        }
      }
    }

    $this->assertSame(
      [],
      $violations,
      "Read request input through RequestInput::string()/stringOrNull()/raw() or ->all(); InputBag::get() throws on array values:\n" . implode("\n", $violations),
    );
  }

  /**
   * The pattern really matches the calls it is meant to forbid.
   */
  public function testPatternMatchesTheForbiddenCalls(): void {
    $this->assertMatchesRegularExpression(self::FORBIDDEN, '$page = $request->query->get(\'page\');');
    $this->assertMatchesRegularExpression(self::FORBIDDEN, '$v = $this->request->request->get ($field);');
    $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, '$v = $request->query->all()[\'page\'] ?? NULL;');
    $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, '$v = $request->headers->get(\'X-Id\');');
    $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, '$v = RequestInput::string($request->query, \'q\');');
  }

}
