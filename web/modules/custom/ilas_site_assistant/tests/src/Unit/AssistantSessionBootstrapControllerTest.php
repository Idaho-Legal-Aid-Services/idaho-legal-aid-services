<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Controller\AssistantSessionBootstrapController;
use Drupal\ilas_site_assistant\Service\AssistantSessionBootstrapGuard;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers bootstrap-controller DI wiring via proper container resolution.
 */
#[Group('ilas_site_assistant')]
final class AssistantSessionBootstrapControllerTest extends TestCase {

  /**
   * The Drupal container saved before the test.
   */
  private mixed $previousContainer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    try {
      $this->previousContainer = \Drupal::getContainer();
    }
    catch (\Throwable) {
      $this->previousContainer = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->previousContainer !== NULL) {
      \Drupal::setContainer($this->previousContainer);
    }
    else {
      \Drupal::unsetContainer();
    }

    parent::tearDown();
  }

  /**
   * Controller creation resolves the bootstrap guard from the container.
   */
  public function testCreateResolvesBootstrapGuardFromContainer(): void {
    $csrf_token = $this->createMock(CsrfTokenGenerator::class);
    $csrf_token->expects($this->once())
      ->method('get')
      ->with(CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      ->willReturn('bootstrap-token');

    $kill_switch = $this->createMock(KillSwitch::class);
    $kill_switch->expects($this->once())
      ->method('trigger');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturn(TRUE);
    $flood->expects($this->exactly(2))
      ->method('register');

    $state_values = [];
    $state = $this->buildState($state_values);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())
      ->method('notice');

    $time = $this->createStub(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1700000000);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        return $key === 'session_bootstrap' ? NULL : NULL;
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $guard = new AssistantSessionBootstrapGuard(
      $config_factory,
      $flood,
      new RequestTrustInspector(),
      $state,
      $logger,
      $time,
    );

    $container = new ContainerBuilder();
    $container->set('csrf_token', $csrf_token);
    $container->set('page_cache_kill_switch', $kill_switch);
    $container->set('ilas_site_assistant.session_bootstrap_guard', $guard);

    \Drupal::setContainer($container);

    $controller = AssistantSessionBootstrapController::create($container);
    $response = $controller->bootstrap(Request::create('/assistant/api/session/bootstrap', 'GET', [], [], [], [
      'REMOTE_ADDR' => '203.0.113.10',
    ]));

    $this->assertInstanceOf(AssistantSessionBootstrapController::class, $controller);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('bootstrap-token', $response->getContent());
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
    $this->assertIsArray($state_values['ilas_site_assistant.session_bootstrap.snapshot'] ?? NULL);
  }

  /**
   * Builds a state double that records snapshot writes.
   */
  private function buildState(array &$values): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$values): mixed {
        return $values[$key] ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(static function (string $key, mixed $value) use (&$values): void {
        $values[$key] = $value;
      });

    return $state;
  }

}
