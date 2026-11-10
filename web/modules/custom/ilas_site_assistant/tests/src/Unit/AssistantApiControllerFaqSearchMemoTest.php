<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * The FAQ index is searched once per message, and once for each message.
 *
 * The early retrieval pass and the `faq` branch of processIntent() search the
 * same text. FaqIndex does not cache results that carry vector matches, so a
 * second call repeats the lexical, embedding and vector round trip.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerFaqSearchMemoTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/controller_test_bootstrap.php';
    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      /**
       * Returns a null logger for any channel.
       */
      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', $this->translationStub());
    $container->set('config.factory', $this->buildConfigFactory());
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
   * An FAQ-intent message reaches FaqIndex::search() once, not twice.
   */
  public function testFaqIntentMessageSearchesTheFaqIndexOnce(): void {
    $searches = [];
    $controller = $this->buildController($searches);

    $response = $controller->message($this->buildRequest('what is a power of attorney'));
    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('faq', $body['type']);
    $this->assertNotEmpty($body['results'], 'the FAQ branch still answers from the search results');
    $this->assertSame([['what is a power of attorney', 3]], $searches);
  }

  /**
   * A different message on the same controller gets its own search.
   */
  public function testEachMessageGetsItsOwnSearch(): void {
    $searches = [];
    $controller = $this->buildController($searches);

    $first = json_decode((string) $controller->message($this->buildRequest('what is a power of attorney'))->getContent(), TRUE);
    $second = json_decode((string) $controller->message($this->buildRequest('what is a living will'))->getContent(), TRUE);

    $this->assertSame([
      ['what is a power of attorney', 3],
      ['what is a living will', 3],
    ], $searches);
    $this->assertSame('what is a power of attorney', $first['results'][0]['question']);
    $this->assertSame('what is a living will', $second['results'][0]['question']);
  }

  /**
   * The same message sent again is searched again, not served from the memo.
   */
  public function testRepeatedMessageIsSearchedAgainOnTheNextRequest(): void {
    $searches = [];
    $controller = $this->buildController($searches);

    $controller->message($this->buildRequest('what is a power of attorney'));
    $controller->message($this->buildRequest('what is a power of attorney'));

    $this->assertCount(2, $searches, 'the memo is scoped to one message() call');
  }

  /**
   * Builds the controller with the real message() and processIntent() paths.
   *
   * @param array<int, array{0: string, 1: int}> $searches
   *   Receives every FaqIndex::search() call as [query, limit].
   */
  private function buildController(array &$searches): AssistantApiController {
    $configFactory = $this->buildConfigFactory();

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn(['type' => 'faq', 'confidence' => 0.95]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturnCallback(static function (string $query, int $limit = 5) use (&$searches): array {
      $searches[] = [$query, $limit];
      return [
        [
          'id' => 'faq_1',
          'question' => $query,
          'title' => $query,
          'answer' => 'A general answer from the website.',
          'url' => '/faq#answer',
          'source_url' => '/faq#answer',
          'source' => 'vector',
          'source_class' => 'faq_vector',
          'score' => 82.0,
          'freshness' => ['status' => 'fresh'],
        ],
      ];
    });

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn(['passed' => TRUE, 'violation' => FALSE]);

    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(static fn(string $key, $default = NULL) => $default);
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());

    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn(['decision' => 'allow', 'reason_code' => 'test', 'confidence' => 1.0]);

    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    return new FaqSearchMemoTestableController(
      config_factory: $configFactory,
      intent_router: $intentRouter,
      faq_index: $faqIndex,
      resource_finder: $this->createStub(ResourceFinder::class),
      policy_filter: $policyFilter,
      analytics_logger: $this->createStub(AnalyticsLogger::class),
      llm_enhancer: new FaqSearchMemoTestableLlmEnhancer(),
      fallback_gate: $fallbackGate,
      flood: $flood,
      conversation_cache: $cache,
      logger: new NullLogger(),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
      response_grounder: new ResponseGrounder($sourceGovernance),
      source_governance: $sourceGovernance,
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
    );
  }

  /**
   * Builds a deterministic JSON request.
   */
  private function buildRequest(string $message): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['message' => $message]),
    );
  }

  /**
   * Builds the config factory used by the controller and services.
   */
  private function buildConfigFactory(): ConfigFactoryInterface {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(static function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_resources' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];
      return $values[$key] ?? NULL;
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);
    return $configFactory;
  }

  /**
   * Pass-through translator.
   */
  private function translationStub(): TranslationInterface {
    return new class implements TranslationInterface {

      /**
       * {@inheritdoc}
       */
      public function translate($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      }

      /**
       * {@inheritdoc}
       */
      public function translateString(TranslatableMarkup $translated_string) {
        return strtr($translated_string->getUntranslatedString(), $translated_string->getArguments());
      }

      /**
       * {@inheritdoc}
       */
      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        return strtr($count == 1 ? $singular : $plural, $args);
      }

    };
  }

}

/**
 * Enhancer double that never classifies.
 */
final class FaqSearchMemoTestableLlmEnhancer extends LlmEnhancer {

  public function __construct() {}

}

/**
 * Controller double keeping the real message() and processIntent() paths.
 */
final class FaqSearchMemoTestableController extends AssistantApiController {

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [];
  }

}
