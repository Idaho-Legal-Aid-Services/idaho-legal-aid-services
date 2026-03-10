<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\ilas_site_assistant\Service\AssistantSessionBootstrapGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides an assistant-specific CSRF/session bootstrap endpoint.
 */
class AssistantSessionBootstrapController implements ContainerInjectionInterface {

  /**
   * Session marker key proving bootstrap created/persisted continuity.
   */
  private const SESSION_MARKER_KEY = 'ilas_site_assistant.csrf_bootstrap';

  /**
   * Constructs an AssistantSessionBootstrapController object.
   */
  public function __construct(
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly KillSwitch $pageCacheKillSwitch,
    private readonly AssistantSessionBootstrapGuard $bootstrapGuard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('csrf_token'),
      $container->get('page_cache_kill_switch'),
      $container->get('ilas_site_assistant.session_bootstrap_guard'),
    );
  }

  /**
   * Returns a session-bound CSRF token for assistant write endpoints.
   */
  public function bootstrap(Request $request): Response {
    // Prevent the Internal Page Cache from ever caching this response.
    $this->pageCacheKillSwitch->trigger();

    $decision = $this->bootstrapGuard->evaluate($request);
    if (!$decision['allowed']) {
      return new Response('Too many bootstrap requests. Please wait before starting a new session.', 429, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'no-store, private',
        'Retry-After' => (string) ($decision['retry_after'] ?? 60),
        'X-Content-Type-Options' => 'nosniff',
      ]);
    }

    // Ensure anonymous requests have a started session so token validation can
    // succeed on subsequent POST requests using the same cookie jar.
    if ($request->hasSession()) {
      $session = $request->getSession();
      if (!$session->isStarted()) {
        $session->start();
      }
      // Persist the marker only when bootstrap established continuity for the
      // first time. Reuse requests should avoid repeated anonymous session
      // writes and cookie churn.
      if (!$request->hasPreviousSession() || !$session->has(self::SESSION_MARKER_KEY)) {
        $session->set(self::SESSION_MARKER_KEY, (string) time());
      }
    }

    $token = $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    return new Response($token, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
