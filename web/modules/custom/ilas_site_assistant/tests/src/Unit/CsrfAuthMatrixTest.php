<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\ilas_site_assistant\Access\StrictCsrfRequestHeaderAccessCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * CSRF auth matrix coverage for strict CSRF enforcement.
 *
 * @group ilas_site_assistant
 * @see \Drupal\ilas_site_assistant\Access\StrictCsrfRequestHeaderAccessCheck
 */
class CsrfAuthMatrixTest extends TestCase {

  /**
   * The access checker under test.
   *
   * @var \Drupal\ilas_site_assistant\Access\StrictCsrfRequestHeaderAccessCheck
   */
  private StrictCsrfRequestHeaderAccessCheck $checker;

  /**
   * Mock CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator|\PHPUnit\Framework\MockObject\MockObject
   */
  private CsrfTokenGenerator $csrfTokenGenerator;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->csrfTokenGenerator = $this->createMock(CsrfTokenGenerator::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->checker = new StrictCsrfRequestHeaderAccessCheck(
      $this->csrfTokenGenerator,
      $this->logger
    );
  }

  /**
   * Strict checker applies when the custom requirement is present.
   */
  public function testAppliesToStrictCsrfRoutes(): void {
    $route = new Route('/assistant/api/message', [], [
      '_ilas_strict_csrf_token' => 'TRUE',
      '_method' => 'POST',
    ]);
    $this->assertTrue($this->checker->applies($route));
  }

  /**
   * Strict checker does not apply to routes without the custom requirement.
   */
  public function testDoesNotApplyWhenRequirementMissing(): void {
    $route = new Route('/assistant/api/message', [], [
      '_method' => 'POST',
    ]);
    $this->assertFalse($this->checker->applies($route));
  }

  /**
   * Strict checker does not apply to read-only routes.
   */
  public function testDoesNotApplyToReadOnlyMethods(): void {
    $route = new Route('/assistant/api/suggest', [], [
      '_ilas_strict_csrf_token' => 'TRUE',
      '_method' => 'GET',
    ]);
    $this->assertFalse($this->checker->applies($route));
  }

  /**
   * Anonymous missing CSRF token is denied and logged.
   */
  public function testAnonymousWithoutTokenIsForbiddenAndLogged(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->attributes->set('_route', 'ilas_site_assistant.api.message');
    $account = $this->createAnonymousAccount();

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['event'] ?? '') === 'csrf_deny'
            && ($context['token_state'] ?? '') === 'missing'
            && ($context['auth_state'] ?? '') === 'anonymous'
            && ($context['route_name'] ?? '') === 'ilas_site_assistant.api.message'
            && ($context['method'] ?? '') === 'POST';
        })
      );

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isForbidden());
    $this->assertEquals('csrf_missing', $request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Authenticated missing CSRF token is denied and logged.
   */
  public function testAuthenticatedWithoutTokenIsForbiddenAndLogged(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->attributes->set('_route', 'ilas_site_assistant.api.message');
    $account = $this->createAuthenticatedAccount();

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['event'] ?? '') === 'csrf_deny'
            && ($context['token_state'] ?? '') === 'missing'
            && ($context['auth_state'] ?? '') === 'authenticated'
            && ($context['method'] ?? '') === 'POST';
        })
      );

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isForbidden());
    $this->assertEquals('csrf_missing', $request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Invalid token is denied and logged for authenticated users.
   */
  public function testAuthenticatedWithInvalidTokenIsForbiddenAndLogged(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->headers->set('X-CSRF-Token', 'wrong-token');
    $request->attributes->set('_route', 'ilas_site_assistant.api.message');
    // Simulate an active session so hasPreviousSession() returns true.
    $session = new Session(new MockArraySessionStorage());
    $request->setSession($session);
    $request->cookies->set($session->getName(), 'test-sess-id');
    $account = $this->createAuthenticatedAccount();

    $this->csrfTokenGenerator->method('validate')->willReturn(FALSE);
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['event'] ?? '') === 'csrf_deny'
            && ($context['token_state'] ?? '') === 'invalid'
            && ($context['auth_state'] ?? '') === 'authenticated';
        })
      );

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isForbidden());
    $this->assertEquals('csrf_invalid', $request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Invalid token is denied and logged for anonymous users.
   */
  public function testAnonymousWithInvalidTokenIsForbiddenAndLogged(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->headers->set('X-CSRF-Token', 'wrong-token');
    $request->attributes->set('_route', 'ilas_site_assistant.api.message');
    $account = $this->createAnonymousAccount();

    $this->csrfTokenGenerator->method('validate')->willReturn(FALSE);
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['event'] ?? '') === 'csrf_deny'
            && ($context['token_state'] ?? '') === 'invalid'
            && ($context['auth_state'] ?? '') === 'anonymous';
        })
      );

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isForbidden());
    $this->assertEquals('csrf_expired', $request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Authenticated valid token is allowed and not logged as deny.
   */
  public function testAuthenticatedWithValidTokenIsAllowed(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->headers->set('X-CSRF-Token', 'valid-token');
    $account = $this->createAuthenticatedAccount();

    $this->csrfTokenGenerator->method('validate')
      ->willReturnCallback(function ($token, $key): bool {
        return $token === 'valid-token' && $key === CsrfRequestHeaderAccessCheck::TOKEN_KEY;
      });
    $this->logger->expects($this->never())->method('warning');

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isAllowed());
    $this->assertNull($request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Anonymous valid token is allowed and not logged as deny.
   */
  public function testAnonymousWithValidTokenIsAllowed(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $request->headers->set('X-CSRF-Token', 'valid-token');
    $account = $this->createAnonymousAccount();

    $this->csrfTokenGenerator->method('validate')
      ->willReturnCallback(function ($token, $key): bool {
        return $token === 'valid-token' && $key === CsrfRequestHeaderAccessCheck::TOKEN_KEY;
      });
    $this->logger->expects($this->never())->method('warning');

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isAllowed());
    $this->assertNull($request->attributes->get('_ilas_csrf_denial_code'));
  }

  /**
   * Read-only requests are always allowed.
   */
  public function testGetRequestAlwaysAllowed(): void {
    $request = Request::create('/assistant/api/suggest', 'GET');
    $account = $this->createAuthenticatedAccount();
    $this->logger->expects($this->never())->method('warning');

    $result = $this->checker->access($request, $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Creates a mock anonymous account.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private function createAnonymousAccount(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);
    return $account;
  }

  /**
   * Creates a mock authenticated account.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private function createAuthenticatedAccount(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    return $account;
  }

}
