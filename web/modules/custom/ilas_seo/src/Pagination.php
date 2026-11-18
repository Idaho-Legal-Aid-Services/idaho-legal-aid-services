<?php

declare(strict_types=1);

namespace Drupal\ilas_seo;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the pager position from a request without trusting its shape.
 *
 * The 'page' query parameter is caller-supplied. JSON:API clients send it as
 * an array ("?page[limit]=5"), and Symfony's InputBag::get() throws a
 * BadRequestException for any non-scalar value. Because the noindex logic
 * reads 'page' on every rendered page, including the error page Drupal renders
 * for a failed request, that exception turned a harmless malformed URL into a
 * logged 400 (Sentry PHP-C4).
 *
 * No Drupal dependencies, so this is directly unit testable.
 */
final class Pagination {

  /**
   * Returns the pager page of the request.
   *
   * @return int
   *   The positive page number, or 0 when the parameter is absent, not numeric,
   *   not scalar, or not greater than zero.
   */
  public static function pageNumber(Request $request): int {
    $page = $request->query->all()['page'] ?? NULL;
    if (!is_scalar($page) || !is_numeric($page)) {
      return 0;
    }

    return max(0, (int) $page);
  }

}
