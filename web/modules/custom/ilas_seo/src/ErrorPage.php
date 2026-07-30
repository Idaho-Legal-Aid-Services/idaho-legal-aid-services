<?php

declare(strict_types=1);

namespace Drupal\ilas_seo;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Detects the sub-request Drupal issues to render a 4xx or 5xx error page.
 *
 * Drupal renders error pages by cloning the original request and re-routing it
 * to the configured error path, deliberately keeping the original URL so the
 * response is generated "on behalf of the master request"
 * (\Drupal\Core\EventSubscriber\DefaultExceptionHtmlSubscriber::makeSubrequest,
 * and CustomPageExceptionHtmlSubscriber for the /node/119 and /user/login
 * paths this site configures in system.site).
 *
 * That is the right call for the response as a whole, but it means anything
 * deriving a URL from the request during rendering describes the URL that
 * *failed*, not a page that exists. Two of those are actively harmful:
 *
 * - Metatag resolves [current-page:url:absolute] into <link rel="canonical">
 *   and <meta property="og:url">. On an unrouted path the token module falls
 *   back to Url::fromUserInput($request->getPathInfo()), which percent-encodes
 *   a path that is already percent-encoded, so a request for "%C3%B3" is
 *   advertised back as "%25C3%25B3". A crawler that follows it is handed a
 *   fresh URL one encoding level deeper, every time, without bound.
 * - GraphBuilder emits a BreadcrumbList whose item and @id are the requested
 *   URL, republishing an arbitrary caller-supplied string as structured data.
 *
 * Detection reads the 'exception' request attribute, which
 * HttpExceptionSubscriberBase::onException() sets on the master request before
 * any of the above clone it. The attributes bag is server-side only, so unlike
 * the '_exception_statuscode' query parameter core also adds, it cannot be
 * spoofed by appending something to a URL — which matters, because suppressing
 * the canonical of a real 200 page would be an SEO defect of its own.
 *
 * No Drupal dependencies, so this is directly unit testable.
 */
final class ErrorPage {

  /**
   * Returns the status code of the error page being rendered, or NULL.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL when there is none (CLI, early bootstrap).
   *
   * @return int|null
   *   The status code core is rendering this page for, or NULL when this is an
   *   ordinary request rather than an error-page sub-request.
   */
  public static function statusCode(?Request $request): ?int {
    if ($request === NULL) {
      return NULL;
    }

    $exception = $request->attributes->get('exception');
    if (!$exception instanceof HttpExceptionInterface) {
      return NULL;
    }

    $code = $exception->getStatusCode();

    return ($code >= 400 && $code <= 599) ? $code : NULL;
  }

  /**
   * Whether the current request is rendering an error page.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL when there is none.
   *
   * @return bool
   *   TRUE when core is rendering a 4xx or 5xx error page for this request.
   */
  public static function isError(?Request $request): bool {
    return self::statusCode($request) !== NULL;
  }

}
