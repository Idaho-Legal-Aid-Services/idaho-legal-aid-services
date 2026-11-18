<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Unit;

use Drupal\ilas_seo\Pagination;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers reading the pager position from caller-supplied query strings.
 *
 * @see \Drupal\ilas_seo\Pagination
 */
#[Group('ilas_seo')]
final class PaginationTest extends UnitTestCase {

  /**
   * Query strings and the page number each must yield.
   */
  public static function queryProvider(): array {
    return [
      'absent' => ['/news', 0],
      'first page' => ['/news?page=0', 0],
      'second page' => ['/news?page=2', 2],
      'not numeric' => ['/news?page=abc', 0],
      'negative' => ['/news?page=-3', 0],
      'empty' => ['/news?page=', 0],
      'views multi-pager' => ['/news?page=0,1', 0],
      'json:api array' => ['/jsonapi/media/document?page[limit]=5', 0],
      'plain array' => ['/news?page[]=2', 0],
    ];
  }

  /**
   * The page number is read without throwing, whatever shape 'page' has.
   */
  #[DataProvider('queryProvider')]
  public function testPageNumber(string $uri, int $expected): void {
    $this->assertSame($expected, Pagination::pageNumber(Request::create($uri)));
  }

  /**
   * Documents the Symfony behaviour this class exists to avoid.
   */
  public function testInputBagGetThrowsOnArrayPage(): void {
    $request = Request::create('/jsonapi/media/document?page[limit]=5');

    $this->expectException(BadRequestException::class);
    $request->query->get('page');
  }

}
