<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the controller-level live debug guard.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerDebugGuardTest extends TestCase {

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
    putenv('ILAS_CHATBOT_DEBUG');
    putenv('PANTHEON_ENVIRONMENT');
    unset($_ENV['ILAS_CHATBOT_DEBUG'], $_ENV['PANTHEON_ENVIRONMENT']);
    new Settings([]);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Debug metadata is still available outside live when explicitly enabled.
   */
  public function testNonLiveDebugModeEmitsDebugMetadata(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertIsArray($body['_debug'] ?? NULL);
    $this->assertTrue($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   * Live environment must suppress debug metadata even when the env var drifts.
   */
  public function testLiveEnvironmentBlocksDebugMetadataWhenEnvVarEnabled(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';
    putenv('PANTHEON_ENVIRONMENT=live');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_debug', $body);
    $this->assertFalse($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   * An authoritative settings flag can force-disable debug metadata.
   */
  public function testForceDisableSettingBlocksDebugMetadataOutsideLive(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';
    new Settings([
      'ilas_site_assistant_debug_metadata_force_disable' => TRUE,
    ]);

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_debug', $body);
    $this->assertFalse($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   * Builds a minimal controller for debug-guard assertions.
   */
  private function buildController(): DebugGuardTestableController {
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
    $policyFilter->method('check')->willReturn([
      'passed' => FALSE,
      'violation' => TRUE,
      'type' => 'external_site',
      'escalation_level' => 'standard',
      'response' => 'I can only help with information on the ILAS website.',
      'links' => [],
    ]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $logger = $this->createStub(LoggerInterface::class);

    return new DebugGuardTestableController(
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
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
      environment_detector: new EnvironmentDetector(),
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
    );
  }

  /**
   * Builds a valid JSON POST request.
   */
  private function buildJsonRequest(): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['message' => 'Housing help'])
    );
  }

}

/**
 * Minimal testable controller for live debug guard assertions.
 */
final class DebugGuardTestableController extends AssistantApiController {

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
      ['label' => 'Call Hotline', 'url' => '/hotline', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply', 'type' => 'apply'],
      ['label' => 'Give Feedback', 'url' => '/feedback', 'type' => 'feedback'],
    ];
  }

  /**
   * Exposes the protected debug-mode gate for direct assertions.
   */
  public function exposedIsDebugMode(Request $request): bool {
    return $this->isDebugMode($request);
  }

}
