<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies assistant flood keys use explicit request-trust resolution.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerProxyTrustTest extends TestCase {

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
  protected function setUp(): void {
    parent::setUp();

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', $translationStub);
    $container->set('config.factory', $configFactory);

    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);
    new Settings([]);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Message flood IDs must use the trusted forwarded client IP.
   */
  public function testMessageFloodUsesResolvedTrustedClientIp(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $calls = [];
    $controller = $this->buildController($this->captureFloodCalls($calls));
    $request = Request::create('https://www.example.com/assistant/api/message', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.10',
    ], json_encode(['message' => 'Housing help']));

    $controller->message($request);
    $identifiers = array_column($calls, 'identifier');
    $this->assertSame([
      'ilas_assistant:198.51.100.7',
      'ilas_assistant:198.51.100.7',
      'ilas_assistant:198.51.100.7',
      'ilas_assistant:198.51.100.7',
    ], $identifiers);
  }

  /**
   * Track flood IDs must use the trusted forwarded client IP.
   */
  public function testTrackFloodUsesResolvedTrustedClientIp(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $calls = [];
    $controller = $this->buildController($this->captureFloodCalls($calls));
    $request = Request::create('https://www.example.com/assistant/api/track', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.10',
      'HTTP_ORIGIN' => 'https://www.example.com',
    ], json_encode([
      'event_type' => 'chat_open',
      'event_value' => 'trusted-forwarded-chain',
    ]));

    $response = $controller->track($request);

    $this->assertSame(200, $response->getStatusCode());
    $identifiers = array_column($calls, 'identifier');
    $this->assertSame([
      'ilas_assistant_track:198.51.100.7',
      'ilas_assistant_track:198.51.100.7',
    ], $identifiers);
  }

  /**
   * Redundant self-forwarded public chains should not emit trust warnings.
   */
  public function testRedundantSelfForwardedChainDoesNotLogWarning(): void {
    new Settings([]);
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);

    $calls = [];
    $logger = new RecordingLogger();

    $controller = $this->buildController($this->captureFloodCalls($calls), $logger);
    $request = Request::create('https://www.example.com/assistant/api/message', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '93.184.216.34',
      'HTTP_X_FORWARDED_FOR' => '93.184.216.34, 93.184.216.34',
    ], json_encode(['message' => 'Housing help']));

    $controller->message($request);
    $identifiers = array_column($calls, 'identifier');
    $this->assertSame([
      'ilas_assistant:93.184.216.34',
      'ilas_assistant:93.184.216.34',
      'ilas_assistant:93.184.216.34',
      'ilas_assistant:93.184.216.34',
    ], $identifiers);
    $this->assertCount(0, $logger->warnings);
  }

  /**
   * Divergent untrusted forwarded chains should still log trust warnings.
   */
  public function testDivergentForwardedChainStillLogsWarning(): void {
    new Settings([]);
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);

    $calls = [];
    $logger = new RecordingLogger();

    $controller = $this->buildController($this->captureFloodCalls($calls), $logger);
    $request = Request::create('https://www.example.com/assistant/api/track', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
      'REMOTE_ADDR' => '93.184.216.34',
      'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 93.184.216.34',
      'HTTP_ORIGIN' => 'https://www.example.com',
    ], json_encode([
      'event_type' => 'chat_open',
      'event_value' => '',
    ]));

    $response = $controller->track($request);

    $this->assertSame(200, $response->getStatusCode());
    $identifiers = array_column($calls, 'identifier');
    $this->assertSame([
      'ilas_assistant_track:93.184.216.34',
      'ilas_assistant_track:93.184.216.34',
    ], $identifiers);
    $this->assertCount(1, $logger->warnings);
    $this->assertStringContainsString('event={event}', $logger->warnings[0]['message']);
    $this->assertSame('assistant_track_flood_identity', $logger->warnings[0]['context']['event'] ?? NULL);
    $this->assertSame(RequestTrustInspector::STATUS_FORWARDED_HEADERS_UNTRUSTED, $logger->warnings[0]['context']['trust_status'] ?? NULL);
    $this->assertSame('93.184.216.34', $logger->warnings[0]['context']['effective_client_ip'] ?? NULL);
    $this->assertSame('93.184.216.34', $logger->warnings[0]['context']['remote_addr'] ?? NULL);
  }

  /**
   * Builds a contract-testable controller with trust inspection enabled.
   */
  private function buildController(FloodInterface $flood, ?LoggerInterface $logger = NULL): ProxyTrustTestableController {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn([
      'type' => 'faq',
      'confidence' => 0.9,
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn(['passed' => TRUE, 'violation' => FALSE]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('enhanceResponse')->willReturnCallback(
      static fn($response, $message) => $response
    );
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $logger = $logger ?? $this->createStub(LoggerInterface::class);

    return new ProxyTrustTestableController(
      $configFactory,
      $intentRouter,
      $faqIndex,
      $resourceFinder,
      $policyFilter,
      $analyticsLogger,
      $llmEnhancer,
      $fallbackGate,
      $flood,
      $cache,
      $logger,
      request_trust_inspector: new RequestTrustInspector(),
    );
  }

  /**
   * Captures flood identifiers for later assertions.
   */
  private function captureFloodCalls(array &$calls): FloodInterface {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturnCallback(function (string $event, int $threshold, int $window, string $identifier) use (&$calls): bool {
      $calls[] = [
        'method' => 'isAllowed',
        'event' => $event,
        'identifier' => $identifier,
      ];
      return TRUE;
    });
    $flood->method('register')->willReturnCallback(function (string $event, int $window, string $identifier) use (&$calls): void {
      $calls[] = [
        'method' => 'register',
        'event' => $event,
        'identifier' => $identifier,
      ];
    });

    return $flood;
  }

}

/**
 * Minimal testable controller for flood-identity assertions.
 */
final class ProxyTrustTestableController extends AssistantApiController {

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    return [
      'type' => 'faq',
      'message' => 'Test FAQ response',
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [
      ['label' => 'Call Hotline', 'url' => 'tel:+12083451011', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply', 'type' => 'apply'],
    ];
  }

}

/**
 * Minimal logger that records warning payloads without interrupting the flow.
 */
final class RecordingLogger extends NullLogger {

  /**
   * Captured warning records.
   *
   * @var array<int, array{message: string|\Stringable, context: array}>
   */
  public array $warnings = [];

  /**
   * {@inheritdoc}
   */
  public function warning($message, array $context = []): void {
    $this->warnings[] = [
      'message' => $message,
      'context' => $context,
    ];
  }

}
