<?php

namespace Drupal\ilas_site_assistant\Access;

use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Enforces strict CSRF header validation for assistant write endpoints.
 */
class StrictCsrfRequestHeaderAccessCheck implements AccessCheckInterface {

  /**
   * Route requirement key used by this access checker.
   */
  private const REQUIREMENT_KEY = '_ilas_strict_csrf_token';

  /**
   * Constructs the strict CSRF access checker.
   */
  public function __construct(
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    $requirements = $route->getRequirements();
    if (!array_key_exists(self::REQUIREMENT_KEY, $requirements)) {
      return FALSE;
    }

    if (isset($requirements['_method'])) {
      $methods = explode('|', $requirements['_method']);
      $write_methods = array_diff($methods, ['GET', 'HEAD', 'OPTIONS', 'TRACE']);
      if (empty($write_methods)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks access.
   */
  public function access(Request $request, AccountInterface $account): AccessResultInterface {
    $method = $request->getMethod();
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], TRUE)) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    if (!$request->headers->has('X-CSRF-Token')) {
      $this->logDeny($request, $account, 'missing');
      return AccessResult::forbidden()
        ->setReason('X-CSRF-Token request header is missing')
        ->setCacheMaxAge(0);
    }

    $csrfToken = $request->headers->get('X-CSRF-Token');
    $isValid = $this->csrfToken->validate($csrfToken, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $this->csrfToken->validate($csrfToken, 'rest');

    if (!$isValid) {
      $this->logDeny($request, $account, 'invalid');
      return AccessResult::forbidden()
        ->setReason('X-CSRF-Token request header is invalid')
        ->setCacheMaxAge(0);
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Logs a strict-CSRF deny decision.
   */
  private function logDeny(Request $request, AccountInterface $account, string $tokenState): void {
    $this->logger->warning(
      'event={event} token_state={token_state} auth_state={auth_state} route_name={route_name} path={path} method={method} has_session={has_session} origin={origin} referer={referer} user_agent={user_agent}',
      [
        'event' => 'csrf_deny',
        'token_state' => $tokenState,
        'auth_state' => $account->isAuthenticated() ? 'authenticated' : 'anonymous',
        'route_name' => (string) $request->attributes->get('_route', 'unknown'),
        'path' => $request->getPathInfo(),
        'method' => $request->getMethod(),
        'has_session' => $request->hasPreviousSession() ? 'yes' : 'no',
        'origin' => (string) $request->headers->get('Origin', ''),
        'referer' => (string) $request->headers->get('Referer', ''),
        'user_agent' => (string) $request->headers->get('User-Agent', ''),
      ],
    );
  }

}
