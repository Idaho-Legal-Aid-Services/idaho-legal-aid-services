<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\AssistantSessionBootstrapGuard;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Covers anonymous bootstrap rate-limiting and observability guardrails.
 */
#[Group('ilas_site_assistant')]
final class AssistantSessionBootstrapGuardTest extends TestCase {

  /**
   * Trusted forwarded-header bitmask used by the settings contract.
   */
  private const TRUSTED_HEADERS =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_FORWARDED;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Existing sessions bypass flood checks and snapshot writes.
   */
  public function testReuseSessionBypassesFloodAndStateWrites(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');

    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $guard = $this->buildGuard(
      flood: $flood,
      requestTrustInspector: new RequestTrustInspector(),
      state: $state,
      logger: $logger,
    );

    $request = Request::create('/assistant/api/session/bootstrap', 'GET');
    $session = new Session(new MockArraySessionStorage());
    $request->setSession($session);
    $request->cookies->set($session->getName(), 'existing-session-id');

    $decision = $guard->evaluate($request);

    $this->assertTrue($decision['allowed']);
    $this->assertSame('reuse', $decision['mode']);
    $this->assertNull($decision['retry_after']);
    $this->assertSame('', $decision['effective_client_ip']);
    $this->assertSame(60, $decision['thresholds']['rate_limit_per_minute']);
    $this->assertSame(600, $decision['thresholds']['rate_limit_per_hour']);
    $this->assertSame(24, $decision['thresholds']['observation_window_hours']);
  }

  /**
   * New anonymous sessions are keyed by effective client IP and recorded.
   */
  public function testNewSessionUsesResolvedClientIpAndRecordsSnapshot(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $is_allowed_calls = [];
    $register_calls = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $limit, int $window, string $identifier) use (&$is_allowed_calls): bool {
        $is_allowed_calls[] = [$event, $limit, $window, $identifier];
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$register_calls): void {
        $register_calls[] = [$event, $window, $identifier];
      });

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');

    $state_values = [];
    $state = $this->buildState($state_values);

    $guard = $this->buildGuard(
      flood: $flood,
      requestTrustInspector: new RequestTrustInspector(),
      state: $state,
      logger: $logger,
      time: $this->fixedTime(1700000000),
    );

    $decision = $guard->evaluate(Request::create('/assistant/api/session/bootstrap', 'GET', [], [], [], [
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 10.0.0.10',
    ]));

    $this->assertTrue($decision['allowed']);
    $this->assertSame('new_session', $decision['mode']);
    $this->assertSame('198.51.100.25', $decision['effective_client_ip']);
    $this->assertSame([
      ['ilas_assistant_session_bootstrap_min', 60, 60, 'ilas_assistant_session_bootstrap:198.51.100.25'],
      ['ilas_assistant_session_bootstrap_hour', 600, 3600, 'ilas_assistant_session_bootstrap:198.51.100.25'],
    ], $is_allowed_calls);
    $this->assertSame([
      ['ilas_assistant_session_bootstrap_min', 60, 'ilas_assistant_session_bootstrap:198.51.100.25'],
      ['ilas_assistant_session_bootstrap_hour', 3600, 'ilas_assistant_session_bootstrap:198.51.100.25'],
    ], $register_calls);

    $snapshot = $state_values[AssistantSessionBootstrapGuard::SNAPSHOT_STATE_KEY] ?? NULL;
    $this->assertIsArray($snapshot);
    $this->assertSame(1700000000, $snapshot['window_started_at']);
    $this->assertSame(1700000000, $snapshot['recorded_at']);
    $this->assertSame(1, $snapshot['new_session_requests']);
    $this->assertSame(0, $snapshot['rate_limited_requests']);
    $this->assertSame(1700000000, $snapshot['last_new_session_at']);
    $this->assertNull($snapshot['last_rate_limited_at']);
    $this->assertSame(60, $snapshot['thresholds']['rate_limit_per_minute']);
    $this->assertSame(600, $snapshot['thresholds']['rate_limit_per_hour']);
    $this->assertSame(24, $snapshot['thresholds']['observation_window_hours']);

    $reported = $guard->getSnapshot();
    $this->assertSame($snapshot, $reported);
  }

  /**
   * Denied new-session requests log and record the bounded retry window.
   */
  public function testRateLimitedNewSessionRecordsDenialSnapshotAndLogs(): void {
    new Settings([]);
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('ilas_assistant_session_bootstrap_min', 60, 60, 'ilas_assistant_session_bootstrap:203.0.113.77')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['event'] ?? '') === 'session_bootstrap_rate_limit_denied'
            && ($context['reason'] ?? '') === 'minute_limit'
            && ($context['retry_after'] ?? NULL) === 60
            && ($context['effective_client_ip'] ?? '') === '203.0.113.77'
            && ($context['trust_status'] ?? '') === RequestTrustInspector::STATUS_FORWARDED_HEADERS_UNTRUSTED;
        }),
      );

    $state_values = [];
    $state = $this->buildState($state_values);

    $guard = $this->buildGuard(
      flood: $flood,
      requestTrustInspector: new RequestTrustInspector(),
      state: $state,
      logger: $logger,
      time: $this->fixedTime(1700000100),
    );

    $decision = $guard->evaluate(Request::create('/assistant/api/session/bootstrap', 'GET', [], [], [], [
      'REMOTE_ADDR' => '203.0.113.77',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.5, 203.0.113.77',
    ]));

    $this->assertFalse($decision['allowed']);
    $this->assertSame('rate_limited', $decision['mode']);
    $this->assertSame(60, $decision['retry_after']);
    $this->assertSame('203.0.113.77', $decision['effective_client_ip']);

    $snapshot = $state_values[AssistantSessionBootstrapGuard::SNAPSHOT_STATE_KEY] ?? NULL;
    $this->assertIsArray($snapshot);
    $this->assertSame(0, $snapshot['new_session_requests']);
    $this->assertSame(1, $snapshot['rate_limited_requests']);
    $this->assertNull($snapshot['last_new_session_at']);
    $this->assertSame(1700000100, $snapshot['last_rate_limited_at']);
  }

  /**
   * Builds a guard instance with configurable doubles.
   */
  private function buildGuard(
    ?ConfigFactoryInterface $configFactory = NULL,
    ?FloodInterface $flood = NULL,
    ?RequestTrustInspector $requestTrustInspector = NULL,
    ?StateInterface $state = NULL,
    ?LoggerInterface $logger = NULL,
    ?TimeInterface $time = NULL,
  ): AssistantSessionBootstrapGuard {
    $configFactory ??= $this->buildConfigFactory([
      'rate_limit_per_minute' => 60,
      'rate_limit_per_hour' => 600,
      'observation_window_hours' => 24,
    ]);
    $flood ??= $this->createStub(FloodInterface::class);
    $requestTrustInspector ??= new RequestTrustInspector();
    $state_values = [];
    $state ??= $this->buildState($state_values);
    $logger ??= $this->createStub(LoggerInterface::class);
    $time ??= $this->fixedTime(1700000000);

    return new AssistantSessionBootstrapGuard(
      $configFactory,
      $flood,
      $requestTrustInspector,
      $state,
      $logger,
      $time,
    );
  }

  /**
   * Builds a config factory stub for session bootstrap thresholds.
   */
  private function buildConfigFactory(array $thresholds): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($thresholds) {
        return $key === 'session_bootstrap' ? $thresholds : NULL;
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $config_factory;
  }

  /**
   * Builds an in-memory State API double backed by an array.
   */
  private function buildState(array &$values): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, $default = NULL) use (&$values) {
        return $values[$key] ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(static function (string $key, $value) use (&$values): void {
        $values[$key] = $value;
      });

    return $state;
  }

  /**
   * Builds a fixed-time test double.
   */
  private function fixedTime(int $timestamp): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($timestamp);
    return $time;
  }

}
