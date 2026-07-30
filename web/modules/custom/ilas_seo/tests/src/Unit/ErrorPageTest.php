<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Unit;

use Drupal\ilas_seo\ErrorPage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers error-page detection.
 *
 * The detection has to be exact in both directions. A false negative reopens
 * the canonical encoding loop; a false positive strips the canonical from a
 * real 200 page, which would be an SEO defect of its own. The spoofing test
 * below is the one that matters most: '_exception_statuscode' is a query
 * parameter core adds to the sub-request, so anyone can append it to a URL.
 *
 * @see \Drupal\ilas_seo\ErrorPage
 */
#[Group('ilas_seo')]
final class ErrorPageTest extends UnitTestCase {

  /**
   * An ordinary request is not an error page.
   */
  public function testOrdinaryRequestIsNotAnError(): void {
    $request = Request::create('/legal-help/housing');

    $this->assertNull(ErrorPage::statusCode($request));
    $this->assertFalse(ErrorPage::isError($request));
  }

  /**
   * A missing request is not an error page.
   */
  public function testNullRequestIsNotAnError(): void {
    $this->assertNull(ErrorPage::statusCode(NULL));
    $this->assertFalse(ErrorPage::isError(NULL));
  }

  /**
   * The 404 and 403 sub-requests core builds are detected.
   */
  public function testHttpExceptionAttributeIsDetected(): void {
    $notFound = Request::create('/nope');
    $notFound->attributes->set('exception', new NotFoundHttpException());
    $this->assertSame(404, ErrorPage::statusCode($notFound));
    $this->assertTrue(ErrorPage::isError($notFound));

    $denied = Request::create('/admin/content');
    $denied->attributes->set('exception', new AccessDeniedHttpException());
    $this->assertSame(403, ErrorPage::statusCode($denied));
    $this->assertTrue(ErrorPage::isError($denied));
  }

  /**
   * The status-code query parameter alone must not trigger detection.
   *
   * Core adds '_exception_statuscode' to the sub-request's query bag, so it is
   * user-reachable on any URL. Trusting it would let anyone suppress the
   * canonical of a real page by appending a parameter.
   */
  public function testQueryParameterAloneDoesNotTriggerDetection(): void {
    $request = Request::create('/legal-help/housing?_exception_statuscode=404');

    $this->assertNull(ErrorPage::statusCode($request));
    $this->assertFalse(ErrorPage::isError($request));
  }

  /**
   * A non-exception value in the attribute is ignored.
   */
  public function testNonExceptionAttributeIsIgnored(): void {
    $request = Request::create('/legal-help/housing');
    $request->attributes->set('exception', '404');

    $this->assertNull(ErrorPage::statusCode($request));

    $request->attributes->set('exception', new \RuntimeException('not http'));
    $this->assertNull(ErrorPage::statusCode($request));
  }

}
