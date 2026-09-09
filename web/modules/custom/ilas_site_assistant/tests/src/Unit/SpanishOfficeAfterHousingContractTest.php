<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
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
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * An explicit Spanish office question after housing turns reaches offices.
 *
 * Controller-level counterpart of the router (IntentRouterSpanishOfficesTest)
 * and predicate (HousingEvictionContinuityGuardTest) coverage for the weekly
 * hosted eval case "[edge-spanish-full] donde estan sus oficinas", which was
 * answered with housing resources because the follow-up carryover won over
 * an office question the router could not classify.
 *
 * The flow runner is a stub, so no office resolves from the message or the
 * history and the controller takes the no-city office branch. That is the
 * exact shape the eval assertion needs ("office" in the message).
 */
#[Group('ilas_site_assistant')]
final class SpanishOfficeAfterHousingContractTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once __DIR__ . '/controller_test_bootstrap.php';

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

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      /**
       * Returns a null logger for any channel.
       */
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
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Two housing turns then "donde estan sus oficinas" must yield offices.
   */
  public function testSpanishOfficeQuestionAfterHousingTurnsRoutesToOffices(): void {
    $observed = [];
    $controller = $this->buildController($observed, static function (string $message): array {
      $lower = mb_strtolower($message);
      if (preg_match('/\b(casero|desalojo|inquilino)\b/u', $lower)) {
        return [
          'type' => 'service_area',
          'area' => 'housing',
          'topic' => 'eviction',
          'confidence' => 0.8,
          'source' => 'rule_based',
          'extraction' => [],
        ];
      }
      if (str_contains($lower, 'oficina')) {
        return ['type' => 'offices_contact', 'confidence' => 0.85, 'source' => 'rule_based', 'extraction' => []];
      }
      return ['type' => 'unknown', 'confidence' => 0.2, 'extraction' => []];
    });
    $conversationId = 'a40babea-b20a-4bdb-9287-bc3db065683c';

    $first = $controller->message($this->buildJsonRequest([
      'message' => 'mi casero me quiere sacar de mi casa, tengo 3 ninos',
      'conversation_id' => $conversationId,
    ]));
    $this->assertSame(200, $first->getStatusCode());

    $second = $controller->message($this->buildJsonRequest([
      'message' => 'que derechos tengo como inquilino',
      'conversation_id' => $conversationId,
    ]));
    $secondBody = json_decode((string) $second->getContent(), TRUE);
    $this->assertSame(200, $second->getStatusCode());
    $this->assertContains(
      $secondBody['type'] ?? NULL,
      ['navigation', 'resources', 'topic'],
      'Housing turns must establish housing context before the office question.'
    );

    $third = $controller->message($this->buildJsonRequest([
      'message' => 'donde estan sus oficinas',
      'conversation_id' => $conversationId,
    ]));
    $body = json_decode((string) $third->getContent(), TRUE);

    $this->assertSame(200, $third->getStatusCode());
    $this->assertSame(3, $observed['route_calls']);
    $this->assertSame('navigation', $body['type'] ?? NULL, 'Office question must not be answered as housing resources.');
    $this->assertSame('direct_navigation_offices', $body['reason_code'] ?? NULL);
    $message = (string) ($body['message'] ?? '');
    $this->assertNotFalse(stripos($message, 'office'), 'Office reply must mention an office.');
    $this->assertStringContainsString('Línea', $message, 'Spanish input must receive the bilingual postscript.');
    $this->assertSame('/contact/offices', $body['primary_action']['url'] ?? NULL);
    $secondaryUrls = array_map(static fn(array $action): string => (string) ($action['url'] ?? ''), $body['secondary_actions'] ?? []);
    $this->assertContains('/apply-for-help', $secondaryUrls);
    $this->assertContains('/Legal-Advice-Line', $secondaryUrls);
  }

  /**
   * Builds a controller with deterministic stubs and persisted history.
   */
  private function buildController(array &$observed, callable $routeCallback): AssistantApiController {
    $observed = ['route_calls' => 0];

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

    $intentRouter = $this->createMock(IntentRouter::class);
    $intentRouter->method('route')->willReturnCallback(function (string $message, array $context = []) use (&$observed, $routeCallback): array {
      $observed['route_calls']++;
      return $routeCallback($message, $context);
    });

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createMock(ResourceFinder::class);
    $resourceFinder->method('findForms')->willReturn([]);
    $resourceFinder->method('findGuides')->willReturn([]);
    $resourceFinder->method('findResources')->willReturnCallback(static function (string $query, int $limit = 3): array {
      unset($limit);
      if (str_contains(mb_strtolower($query), 'housing')) {
        return [
          [
            'title' => 'Housing Guide for Tenants',
            'url' => 'https://idaholegalaid.org/resources/housing-guide-tenants',
            'score' => 0.9,
          ],
        ];
      }
      return [];
    });

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn([
      'passed' => TRUE,
      'violation' => FALSE,
    ]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('isEnabled')->willReturn(FALSE);

    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $cache = new SpanishOfficeInMemoryCacheBackend();
    $logger = $this->createStub(LoggerInterface::class);
    $topIntentsPack = new TopIntentsPack();
    $selectionRegistry = new SelectionRegistry($topIntentsPack);
    $selectionStateStore = new SelectionStateStore($cache);
    $state = $this->createStub(StateInterface::class);
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());

    // The stubbed runner auto-doubles OfficeLocationResolver, whose resolve()
    // returns NULL, so no office is found and the no-city branch renders.
    $assistantFlowRunner = $this->createStub(AssistantFlowRunner::class);
    $assistantFlowRunner->method('evaluatePending')->willReturn(['status' => 'continue']);
    $assistantFlowRunner->method('evaluatePostResponse')->willReturn(['status' => 'continue']);

    return new AssistantApiController(
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
      assistant_flow_runner: $assistantFlowRunner,
      selection_registry: $selectionRegistry,
      selection_state_store: $selectionStateStore,
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
      top_intents_pack: $topIntentsPack,
      source_governance: $sourceGovernance,
    );
  }

  /**
   * Builds a JSON message request.
   */
  private function buildJsonRequest(array $payload): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );
  }

}

/**
 * In-memory cache backend for controller conversation/selection state.
 */
final class SpanishOfficeInMemoryCacheBackend implements CacheBackendInterface {

  /**
   * Stored entries keyed by cache ID.
   *
   * @var array<string, object>
   */
  private array $storage = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->storage[$cid] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $results = [];
    foreach ($cids as $cid) {
      if (isset($this->storage[$cid])) {
        $results[$cid] = $this->storage[$cid];
      }
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->storage[$cid] = (object) [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
      'valid' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      $this->set(
        $cid,
        $item['data'] ?? NULL,
        $item['expire'] ?? Cache::PERMANENT,
        $item['tags'] ?? []
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      unset($this->storage[$cid]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->storage = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {}

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->deleteAll();
  }

}
