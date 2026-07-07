<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/controller_test_bootstrap.php';

/**
 * Verifies the `topic` intent tolerates a missing or non-numeric topic_id.
 *
 * Regression test for Sentry PHP-2N / PHP-76: intent classification
 * (including LLM-derived intents) can emit a `topic` intent without a usable
 * topic_id. processIntent() must route to the browse-service-areas navigation
 * fallback instead of passing NULL into IntentRouter::getTopicInfo(int),
 * which fatals with a TypeError under strict typing.
 */
#[Group('ilas_site_assistant')]
final class TopicIntentMissingTopicIdTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Missing topic_id must not reach getTopicInfo() and must fall back.
   */
  public function testMissingTopicIdFallsBackToNavigation(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->never())->method('getTopicInfo');

    $controller = $this->buildController($intentRouter);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'topic', 'confidence' => 0.9],
      'tell me about my legal topic'
    );

    $this->assertSame('navigation', $response['type'],
      'A topic intent without topic_id must route to the navigation fallback.');
    $this->assertNotEmpty((string) $response['message'],
      'Fallback must carry a user-facing message.');
  }

  /**
   * Non-numeric topic_id must also be treated as absent.
   */
  public function testNonNumericTopicIdFallsBackToNavigation(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->never())->method('getTopicInfo');

    $controller = $this->buildController($intentRouter);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'topic', 'topic_id' => 'housing', 'confidence' => 0.9],
      'tell me about housing'
    );

    $this->assertSame('navigation', $response['type']);
  }

  /**
   * A numeric topic_id (including numeric strings) still resolves the topic.
   */
  public function testNumericTopicIdResolvesTopicInfo(): void {
    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->expects($this->once())
      ->method('getTopicInfo')
      ->with(11)
      ->willReturn([
        'name' => 'Housing',
        'service_area_url' => '/legal-help/housing',
      ]);

    $controller = $this->buildController($intentRouter);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'topic', 'topic_id' => '11', 'confidence' => 0.9],
      'tell me about housing'
    );

    $this->assertSame('Housing', $response['topic']['name'] ?? NULL,
      'A numeric-string topic_id must be cast and resolved normally.');
    $this->assertSame('/legal-help/housing', $response['service_area_url'] ?? NULL);
  }

  /**
   * Builds an AssistantApiController with stubs sufficient for processIntent.
   */
  private function buildController(IntentRouter $intentRouter): AssistantApiController {
    $values = [
      'enable_faq' => TRUE,
      'enable_resources' => TRUE,
      'enable_logging' => FALSE,
    ];

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($values) {
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $cache = $this->createStub(CacheBackendInterface::class);

    return new AssistantApiController(
      $configFactory,
      $intentRouter,
      $this->createStub(FaqIndex::class),
      $this->createStub(ResourceFinder::class),
      $this->createStub(PolicyFilter::class),
      $this->createStub(AnalyticsLogger::class),
      $this->createStub(LlmEnhancer::class),
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $cache,
      new NullLogger(),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
    );
  }

  /**
   * Invokes the protected processIntent() method via reflection.
   *
   * @param array<string, mixed> $intent
   *   Intent record passed to processIntent().
   *
   * @return array<string, mixed>
   *   The response array.
   */
  private function invokeProcessIntent(
    AssistantApiController $controller,
    array $intent,
    string $message,
  ): array {
    $method = (new \ReflectionClass(AssistantApiController::class))
      ->getMethod('processIntent');
    $method->setAccessible(TRUE);
    /** @var array<string, mixed> $response */
    $response = $method->invoke($controller, $intent, $message, [], 'test-req-id', [], []);
    return $response;
  }

}
