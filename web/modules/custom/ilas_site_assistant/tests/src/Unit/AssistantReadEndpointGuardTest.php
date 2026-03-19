<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\AssistantReadEndpointGuard;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers read-endpoint abuse guard behavior.
 */
#[Group('ilas_site_assistant')]
final class AssistantReadEndpointGuardTest extends TestCase {

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
   * Default suggest limits are used and allowed requests register both buckets.
   */
  public function testSuggestDefaultsRegisterExpectedFloodBuckets(): void {
    $calls = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(function (string $event, int $threshold, int $window, string $identifier) use (&$calls): bool {
        $calls[] = [
          'method' => 'isAllowed',
          'event' => $event,
          'threshold' => $threshold,
          'window' => $window,
          'identifier' => $identifier,
        ];
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(function (string $event, int $window, string $identifier) use (&$calls): void {
        $calls[] = [
          'method' => 'register',
          'event' => $event,
          'window' => $window,
          'identifier' => $identifier,
        ];
      });

    $guard = $this->buildGuard(NULL, $flood);
    $request = Request::create('https://www.example.com/assistant/api/suggest', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.25',
    ]);

    $decision = $guard->evaluate($request, 'suggest');

    $this->assertTrue($decision['allowed']);
    $this->assertSame('suggest', $decision['endpoint']);
    $this->assertNull($decision['retry_after']);
    $this->assertSame('198.51.100.25', $decision['effective_client_ip']);
    $this->assertSame(120, $decision['thresholds']['rate_limit_per_minute']);
    $this->assertSame(1200, $decision['thresholds']['rate_limit_per_hour']);
    $this->assertSame([
      [
        'method' => 'isAllowed',
        'event' => 'ilas_assistant_suggest_min',
        'threshold' => 120,
        'window' => 60,
        'identifier' => 'ilas_assistant_suggest:198.51.100.25',
      ],
      [
        'method' => 'isAllowed',
        'event' => 'ilas_assistant_suggest_hour',
        'threshold' => 1200,
        'window' => 3600,
        'identifier' => 'ilas_assistant_suggest:198.51.100.25',
      ],
      [
        'method' => 'register',
        'event' => 'ilas_assistant_suggest_min',
        'window' => 60,
        'identifier' => 'ilas_assistant_suggest:198.51.100.25',
      ],
      [
        'method' => 'register',
        'event' => 'ilas_assistant_suggest_hour',
        'window' => 3600,
        'identifier' => 'ilas_assistant_suggest:198.51.100.25',
      ],
    ], $calls);
  }

  /**
   * Minute-limit denials return a 60-second retry_after and log the decision.
   */
  public function testMinuteLimitDenialReturnsRetryAfter60(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('event={event}'),
        $this->callback(function (array $context): bool {
          return ($context['endpoint'] ?? NULL) === 'faq'
            && ($context['reason'] ?? NULL) === 'minute_limit'
            && ($context['retry_after'] ?? NULL) === 60
            && ($context['effective_client_ip'] ?? NULL) === '203.0.113.77';
        }),
      );

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('ilas_assistant_faq_min', 4, 60, 'ilas_assistant_faq:203.0.113.77')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $guard = $this->buildGuard([
      'faq' => [
        'rate_limit_per_minute' => 4,
        'rate_limit_per_hour' => 40,
      ],
    ], $flood, $logger);
    $request = Request::create('https://www.example.com/assistant/api/faq', 'GET', [], [], [], [
      'REMOTE_ADDR' => '203.0.113.77',
    ]);

    $decision = $guard->evaluate($request, 'faq');

    $this->assertFalse($decision['allowed']);
    $this->assertSame(60, $decision['retry_after']);
    $this->assertSame('faq', $decision['endpoint']);
  }

  /**
   * Hour-limit denials return a 3600-second retry_after.
   */
  public function testHourLimitDenialReturnsRetryAfter3600(): void {
    $calls = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(function (string $event, int $threshold, int $window, string $identifier) use (&$calls): bool {
        $calls[] = [
          'event' => $event,
          'threshold' => $threshold,
          'window' => $window,
          'identifier' => $identifier,
        ];
        return count($calls) === 1;
      });
    $flood->expects($this->never())->method('register');

    $guard = $this->buildGuard([
      'faq' => [
        'rate_limit_per_minute' => 10,
        'rate_limit_per_hour' => 11,
      ],
    ], $flood);
    $request = Request::create('https://www.example.com/assistant/api/faq', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.99',
    ]);

    $decision = $guard->evaluate($request, 'faq');

    $this->assertFalse($decision['allowed']);
    $this->assertSame(3600, $decision['retry_after']);
    $this->assertSame([
      [
        'event' => 'ilas_assistant_faq_min',
        'threshold' => 10,
        'window' => 60,
        'identifier' => 'ilas_assistant_faq:198.51.100.99',
      ],
      [
        'event' => 'ilas_assistant_faq_hour',
        'threshold' => 11,
        'window' => 3600,
        'identifier' => 'ilas_assistant_faq:198.51.100.99',
      ],
    ], $calls);
  }

  /**
   * Trust-inspector-resolved client IP is used for FAQ flood identity.
   */
  public function testEvaluateUsesTrustedForwardedClientIp(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $calls = [];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(function (string $event, int $threshold, int $window, string $identifier) use (&$calls): bool {
        $calls[] = $identifier;
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(function (string $event, int $window, string $identifier) use (&$calls): void {
        $calls[] = $identifier;
      });

    $guard = $this->buildGuard(NULL, $flood);
    $request = Request::create('https://www.example.com/assistant/api/faq', 'GET', [], [], [], [
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.10',
    ]);

    $decision = $guard->evaluate($request, 'faq');

    $this->assertTrue($decision['allowed']);
    $this->assertSame('198.51.100.7', $decision['effective_client_ip']);
    $this->assertSame([
      'ilas_assistant_faq:198.51.100.7',
      'ilas_assistant_faq:198.51.100.7',
      'ilas_assistant_faq:198.51.100.7',
      'ilas_assistant_faq:198.51.100.7',
    ], $calls);
  }

  /**
   * Threshold summaries normalize configured values for both read endpoints.
   */
  public function testThresholdSummaryNormalizesValues(): void {
    $guard = $this->buildGuard([
      'suggest' => [
        'rate_limit_per_minute' => 0,
        'rate_limit_per_hour' => 7,
      ],
      'faq' => [
        'rate_limit_per_minute' => 3,
        'rate_limit_per_hour' => 0,
      ],
    ]);

    $summary = $guard->getThresholdSummary();

    $this->assertSame(1, $summary['suggest']['rate_limit_per_minute']);
    $this->assertSame(7, $summary['suggest']['rate_limit_per_hour']);
    $this->assertSame(3, $summary['faq']['rate_limit_per_minute']);
    $this->assertSame(1, $summary['faq']['rate_limit_per_hour']);
  }

  /**
   * Builds a read-endpoint guard with stubbed config.
   */
  private function buildGuard(
    ?array $limits = NULL,
    ?FloodInterface $flood = NULL,
    ?LoggerInterface $logger = NULL,
  ): AssistantReadEndpointGuard {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($limits) {
      return $key === 'read_endpoint_rate_limits' ? $limits : NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    if ($flood === NULL) {
      $flood = $this->createStub(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }
    $logger ??= $this->createStub(LoggerInterface::class);

    return new AssistantReadEndpointGuard(
      $configFactory,
      $flood,
      new RequestTrustInspector(),
      $logger,
    );
  }

}
