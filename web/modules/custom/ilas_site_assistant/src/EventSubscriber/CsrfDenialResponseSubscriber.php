<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts CSRF denial 403 exceptions to JSON responses with error codes.
 *
 * Reads the '_ilas_csrf_denial_code' request attribute set by
 * StrictCsrfRequestHeaderAccessCheck and, when present on a 403
 * exception, short-circuits the default HTML error page with a
 * machine-readable JSON body the widget can branch on.
 */
class CsrfDenialResponseSubscriber implements EventSubscriberInterface {

  /**
   * Human-readable messages keyed by denial code.
   */
  private const MESSAGES = [
    'csrf_missing' => 'CSRF token required',
    'csrf_invalid' => 'CSRF token invalid',
    'csrf_expired' => 'Session expired',
  ];

  /**
   * Backward-compatible denial code aliases.
   */
  private const LEGACY_ALIASES = [
    'session_expired' => 'csrf_expired',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 100: run before default HTML exception renderers (~0).
    return [
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

  /**
   * Converts assistant API 403 exceptions to JSON responses.
   *
   * If a CSRF denial code is set, uses that specific error code. Otherwise,
   * for any 403 on an /assistant/api/ route, returns a generic JSON 403 so
   * the widget always receives machine-readable errors.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof HttpExceptionInterface || $exception->getStatusCode() !== 403) {
      return;
    }

    $request = $event->getRequest();
    $code = $request->attributes->get('_ilas_csrf_denial_code');

    // If no CSRF denial code, only intercept requests to assistant API routes.
    if ($code === NULL) {
      $path = $request->getPathInfo();
      if (!str_starts_with($path, '/assistant/api/')) {
        return;
      }
      $errorCode = 'access_denied';
      $message = 'Access denied';
    }
    else {
      $normalizedCode = self::LEGACY_ALIASES[$code] ?? $code;
      $errorCode = $normalizedCode;
      $message = self::MESSAGES[$normalizedCode] ?? 'Access denied';
    }

    $response = new JsonResponse([
      'error' => TRUE,
      'error_code' => $errorCode,
      'message' => $message,
    ], 403);

    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    $event->setResponse($response);
  }

}
