<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\EventSubscriber\CsrfDenialResponseSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for CsrfDenialResponseSubscriber.
 *
 * @see \Drupal\ilas_site_assistant\EventSubscriber\CsrfDenialResponseSubscriber
 */
#[Group('ilas_site_assistant')]
class CsrfDenialResponseSubscriberTest extends TestCase {

  /**
   * The subscriber under test.
   */
  private CsrfDenialResponseSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new CsrfDenialResponseSubscriber();
  }

  /**
   * Subscribes to kernel.exception at priority 100.
   */
  public function testSubscribedEvents(): void {
    $events = CsrfDenialResponseSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
    $this->assertEquals(['onException', 100], $events[KernelEvents::EXCEPTION]);
  }

  /**
   * Converts 403 with csrf_missing attribute to JSON response.
   */
  public function testConverts403WithCsrfMissingToJson(): void {
    $event = $this->createExceptionEvent('csrf_missing');
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response, 'Response should be set on the event');
    $this->assertEquals(403, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_missing', $data['error_code']);
    $this->assertEquals('CSRF token required', $data['message']);

    $this->assertSecurityHeaders($response);
  }

  /**
   * Converts 403 with csrf_invalid attribute to JSON response.
   */
  public function testConverts403WithCsrfInvalidToJson(): void {
    $event = $this->createExceptionEvent('csrf_invalid');
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(403, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_invalid', $data['error_code']);
    $this->assertEquals('CSRF token invalid', $data['message']);

    $this->assertSecurityHeaders($response);
  }

  /**
   * Converts 403 with csrf_expired attribute to JSON response.
   */
  public function testConverts403WithCsrfExpiredToJson(): void {
    $event = $this->createExceptionEvent('csrf_expired');
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(403, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_expired', $data['error_code']);
    $this->assertEquals('Session expired', $data['message']);

    $this->assertSecurityHeaders($response);
  }

  /**
   * Normalizes legacy session_expired attribute to csrf_expired in output.
   */
  public function testNormalizesLegacySessionExpiredToCsrfExpired(): void {
    $event = $this->createExceptionEvent('session_expired');
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertEquals(403, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['error']);
    $this->assertEquals('csrf_expired', $data['error_code']);
    $this->assertEquals('Session expired', $data['message']);
  }

  /**
   * Returns generic JSON 403 for assistant API routes without denial code.
   */
  public function testReturnsGenericJson403ForAssistantApiWithoutAttribute(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    // No _ilas_csrf_denial_code attribute set.
    $exception = new AccessDeniedHttpException('Access denied');
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertNotNull($response, 'Response should be set for assistant API 403');
    $this->assertEquals(403, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['error']);
    $this->assertEquals('access_denied', $data['error_code']);
    $this->assertEquals('Access denied', $data['message']);

    $this->assertSecurityHeaders($response);
  }

  /**
   * Ignores 403 on non-assistant routes without the denial code attribute.
   */
  public function testIgnores403OnNonAssistantRouteWithoutAttribute(): void {
    $request = Request::create('/node/1', 'GET');
    // No _ilas_csrf_denial_code attribute set.
    $exception = new AccessDeniedHttpException('Access denied');
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->subscriber->onException($event);

    $this->assertNull($event->getResponse(), 'Response should NOT be set for non-assistant 403');
  }

  /**
   * Ignores non-403 exceptions even when attribute is present.
   */
  public function testIgnoresNon403WithAttribute(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->attributes->set('_ilas_csrf_denial_code', 'csrf_missing');
    $exception = new ServiceUnavailableHttpException(NULL, 'Service unavailable');
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    $this->subscriber->onException($event);

    $this->assertNull($event->getResponse(), 'Response should NOT be set for non-403 exceptions');
  }

  /**
   * Creates an ExceptionEvent with a 403 exception and the given denial code.
   */
  private function createExceptionEvent(string $denialCode): ExceptionEvent {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->attributes->set('_ilas_csrf_denial_code', $denialCode);

    $exception = new AccessDeniedHttpException('Access denied');
    $kernel = $this->createMock(HttpKernelInterface::class);

    return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
  }

  /**
   * Asserts the response includes required security headers.
   */
  private function assertSecurityHeaders($response): void {
    $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
  }

}
