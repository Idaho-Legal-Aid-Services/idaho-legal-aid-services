<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides an assistant-specific CSRF/session bootstrap endpoint.
 */
class AssistantSessionBootstrapController implements ContainerInjectionInterface {

  /**
   * Constructs an AssistantSessionBootstrapController object.
   */
  public function __construct(
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('csrf_token'),
      $container->get('page_cache_kill_switch')
    );
  }

  /**
   * Returns a session-bound CSRF token for assistant write endpoints.
   */
  public function bootstrap(Request $request): Response {
    // Prevent the Internal Page Cache from ever caching this response.
    $this->pageCacheKillSwitch->trigger();

    // Ensure anonymous requests have a started session so token validation can
    // succeed on subsequent POST requests using the same cookie jar.
    if ($request->hasSession()) {
      $session = $request->getSession();
      if (!$session->isStarted()) {
        $session->start();
      }
      // Force session persistence for anonymous callers so the response
      // emits a session cookie and the token remains bound to that session.
      $session->set('ilas_site_assistant.csrf_bootstrap', (string) time());
    }

    $token = $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    return new Response($token, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
