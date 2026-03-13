<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the array_keys(ArrayIterator) TypeError.
 *
 * Drupal's RouteProvider::getAllRoutes() returns an ArrayIterator, not an array.
 * Calling array_keys() on it crashes on PHP 8.x with:
 *   TypeError: array_keys(): Argument #1 ($array) must be of type array,
 *              ArrayIterator given
 *
 * This test verifies that the safe conversion pattern used in
 * scripts/drush/route-names.php works correctly for all iterable types.
 */
#[Group('ilas_site_assistant')]
class SafeToArrayTest extends TestCase {

  /**
   * Converts any iterable to a plain array, safely.
   *
   * This mirrors the pattern used in scripts/drush/route-names.php.
   *
   * @param iterable $iterable
   *   An array, ArrayIterator, or any Traversable.
   *
   * @return array
   *   A plain PHP array.
   */
  private static function safeToArray(iterable $iterable): array {
    if (is_array($iterable)) {
      return $iterable;
    }
    return iterator_to_array($iterable, TRUE);
  }

  /**
   * Tests that a plain array passes through unchanged.
   */
  public function testPlainArray(): void {
    $input = ['route.a' => 'objA', 'route.b' => 'objB'];
    $result = self::safeToArray($input);

    $this->assertIsArray($result);
    $this->assertSame(['route.a', 'route.b'], array_keys($result));
  }

  /**
   * Tests that an ArrayIterator is converted correctly.
   *
   * This is the exact type returned by RouteProvider::getAllRoutes().
   */
  public function testArrayIterator(): void {
    $input = new \ArrayIterator(['route.a' => 1, 'route.b' => 2]);
    $result = self::safeToArray($input);

    $this->assertIsArray($result);
    $this->assertSame(['route.a', 'route.b'], array_keys($result));
  }

  /**
   * Tests that array_keys() on an ArrayIterator without conversion throws.
   *
   * This is the exact bug we're preventing. If PHP ever changes behavior,
   * this test will tell us.
   */
  public function testArrayKeysOnArrayIteratorThrows(): void {
    $iter = new \ArrayIterator(['a' => 1, 'b' => 2]);

    $this->expectException(\TypeError::class);
    // @phpstan-ignore-next-line Intentional type error to verify PHP behavior.
    array_keys($iter);
  }

  /**
   * Tests an IteratorAggregate (like Symfony RouteCollection).
   */
  public function testIteratorAggregate(): void {
    $aggregate = new class implements \IteratorAggregate {

      public function getIterator(): \ArrayIterator {
        return new \ArrayIterator(['route.x' => 'X', 'route.y' => 'Y']);
      }

    };

    $result = self::safeToArray($aggregate);

    $this->assertIsArray($result);
    $this->assertSame(['route.x', 'route.y'], array_keys($result));
  }

  /**
   * Tests a Generator (another common Traversable type).
   */
  public function testGenerator(): void {
    $gen = (function () {
      yield 'route.first' => 1;
      yield 'route.second' => 2;
    })();

    $result = self::safeToArray($gen);

    $this->assertIsArray($result);
    $this->assertSame(['route.first', 'route.second'], array_keys($result));
  }

  /**
   * Tests that an empty iterable produces an empty array.
   */
  public function testEmptyIterator(): void {
    $result = self::safeToArray(new \ArrayIterator([]));
    $this->assertIsArray($result);
    $this->assertSame([], $result);
  }

}
