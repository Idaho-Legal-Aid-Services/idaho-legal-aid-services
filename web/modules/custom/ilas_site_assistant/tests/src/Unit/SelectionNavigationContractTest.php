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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Contracts structured-selection navigation behavior in /message.
 */
#[Group('ilas_site_assistant')]
final class SelectionNavigationContractTest extends TestCase {

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
   * Structured button IDs outrank free-text message content and quickAction.
   */
  public function testButtonIdBeatsMessageTextAndQuickAction(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Show me FAQs',
      'context' => [
        'quickAction' => 'faq',
        'selection' => [
          'button_id' => 'forms_family',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $observed['route_calls']);
    $this->assertSame('form_finder_clarify', $body['type'] ?? NULL);
    $this->assertSame('forms_family', $body['active_selection']['button_id'] ?? NULL);
    $this->assertStringContainsString('Family & Custody.', $body['message'] ?? '');
    $this->assertSame('forms_topic_family_divorce', $body['topic_suggestions'][1]['action'] ?? NULL);
    $this->assertSame('forms_topic_family_divorce', $body['topic_suggestions'][1]['selection']['button_id'] ?? NULL);
  }

  /**
   * Exact label fallback resolves the known child branch when button_id fails.
   */
  public function testExactLabelFallbackResolvesKnownSelection(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Forms',
      'context' => [
        'selection' => [
          'button_id' => 'missing-button',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $observed['route_calls']);
    $this->assertSame('forms_family', $body['active_selection']['button_id'] ?? NULL);
    $this->assertSame('Custody or parenting time', $body['topic_suggestions'][0]['label'] ?? NULL);
  }

  /**
   * Exact label fallback resolves a child branch by visible button text.
   */
  public function testExactChildLabelFallbackResolvesKnownSelection(): void {
    $observed = [];
    $controller = $this->buildController($observed);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Forms',
      'context' => [
        'selection' => [
          'button_id' => 'missing-button',
          'label' => 'Divorce or separation',
          'parent_button_id' => 'forms_family',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $observed['route_calls']);
    $this->assertSame('forms_topic_family_divorce', $body['active_selection']['button_id'] ?? NULL);
    $this->assertNotEmpty($body['results'] ?? []);
    $this->assertStringContainsString('Divorce or separation', $body['message'] ?? '');
  }

  /**
   * Typed back pops the active selection to its parent branch menu.
   */
  public function testTypedBackReturnsParentBranchMenu(): void {
    $observed = [];
    $controller = $this->buildController($observed);
    $conversationId = '22222222-2222-4222-8222-222222222222';

    $controller->message($this->buildJsonRequest([
      'message' => 'Family & Custody',
      'conversation_id' => $conversationId,
      'context' => [
        'selection' => [
          'button_id' => 'forms_family',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'go back',
      'conversation_id' => $conversationId,
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('forms_inventory', $body['type'] ?? NULL);
    $this->assertSame('forms', $body['active_selection']['button_id'] ?? NULL);
    $this->assertStringContainsString('Back to Forms.', $body['message'] ?? '');
    $this->assertSame('forms_housing', $body['topic_suggestions'][0]['action'] ?? NULL);
  }

  /**
   * Typed child text inside an active branch advances without re-routing.
   */
  public function testTypedChildSelectionAdvancesToResults(): void {
    $observed = [];
    $controller = $this->buildController($observed);
    $conversationId = '33333333-3333-4333-8333-333333333333';

    $controller->message($this->buildJsonRequest([
      'message' => 'Family & Custody',
      'conversation_id' => $conversationId,
      'context' => [
        'selection' => [
          'button_id' => 'forms_family',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Divorce or separation',
      'conversation_id' => $conversationId,
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(0, $observed['route_calls']);
    $this->assertSame('forms_topic_family_divorce', $body['active_selection']['button_id'] ?? NULL);
    $this->assertNotEmpty($body['results'] ?? []);
    $this->assertStringContainsString('Divorce or separation', $body['message'] ?? '');
  }

  /**
   * Repeat-menu recovery reprocesses the child selection instead of repeating.
   */
  public function testRepeatMenuRecoveryAdvancesInsteadOfRepeatingMenu(): void {
    $observed = [];
    $controller = $this->buildController($observed, TRUE);
    $conversationId = '44444444-4444-4444-8444-444444444444';

    $controller->message($this->buildJsonRequest([
      'message' => 'Family & Custody',
      'conversation_id' => $conversationId,
      'context' => [
        'selection' => [
          'button_id' => 'forms_family',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Divorce or separation',
      'conversation_id' => $conversationId,
      'context' => [
        'selection' => [
          'button_id' => 'forms_topic_family_divorce',
          'label' => 'Divorce or separation',
          'parent_button_id' => 'forms_family',
          'source' => 'widget_button',
        ],
      ],
    ]));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('resources', $body['type'] ?? NULL);
    $this->assertSame('forms_topic_family_divorce', $body['active_selection']['button_id'] ?? NULL);
    $this->assertSame(1, $controller->forcedRepeatCount);
    $this->assertStringNotContainsString('I may be repeating myself.', $body['message'] ?? '');
  }

  /**
   * Reused conversation IDs cannot inherit selection state across sessions.
   */
  public function testSessionMismatchDoesNotReuseForeignSelectionState(): void {
    $observed = [];
    $controller = $this->buildController($observed);
    $conversationId = '55555555-5555-4555-8555-555555555555';

    $controller->message($this->buildJsonRequest([
      'message' => 'Family & Custody',
      'conversation_id' => $conversationId,
      'context' => [
        'selection' => [
          'button_id' => 'forms_family',
          'label' => 'Family & Custody',
          'parent_button_id' => 'forms',
          'source' => 'widget_button',
        ],
      ],
    ], $this->buildStartedSession('selection-nav-session-a')));

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'go back',
      'conversation_id' => $conversationId,
    ], $this->buildStartedSession('selection-nav-session-b')));

    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $observed['route_calls']);
    $this->assertNotSame('forms_inventory', $body['type'] ?? NULL);
    $this->assertNull($body['active_selection'] ?? NULL);
  }

  /**
   * Builds a controller with deterministic stubs for selection routing tests.
   */
  private function buildController(array &$observed, bool $repeatGuardMode = FALSE): AssistantApiController {
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
    $intentRouter->method('route')->willReturnCallback(function () use (&$observed): array {
      $observed['route_calls']++;
      return ['type' => 'unknown', 'confidence' => 0.2, 'extraction' => []];
    });

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createMock(ResourceFinder::class);
    $resourceFinder->method('findForms')->willReturnCallback(static function (string $query, int $limit = 6): array {
      unset($limit);
      $joined = mb_strtolower($query);
      if (str_contains($joined, 'custody or parenting time')) {
        return [
          ['title' => 'Temporary Orders Forms', 'url' => 'https://idaholegalaid.org/forms/temporary-orders-forms.pdf'],
        ];
      }
      if (str_contains($joined, 'divorce')) {
        return [
          ['title' => 'Divorce Petition', 'url' => 'https://idaholegalaid.org/forms/divorce-petition'],
        ];
      }
      return [];
    });
    $resourceFinder->method('findGuides')->willReturnCallback(static function (string $query, int $limit = 6): array {
      unset($query, $limit);
      return [];
    });
    $resourceFinder->method('findResources')->willReturn([]);

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

    $cache = new SelectionNavigationInMemoryCacheBackend();
    $logger = $this->createStub(LoggerInterface::class);
    $topIntentsPack = new TopIntentsPack();
    $selectionRegistry = new SelectionRegistry($topIntentsPack);
    $selectionStateStore = new SelectionStateStore($cache);
    $state = $this->createStub(StateInterface::class);
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());

    $assistantFlowRunner = $this->createStub(AssistantFlowRunner::class);
    $assistantFlowRunner->method('evaluatePending')->willReturn(['status' => 'continue']);
    $assistantFlowRunner->method('evaluatePostResponse')->willReturn(['status' => 'continue']);

    $class = $repeatGuardMode ? SelectionRepeatGuardTestableController::class : AssistantApiController::class;

    return new $class(
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
  private function buildJsonRequest(array $payload, ?Session $session = NULL): Request {
    $request = Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );

    if ($session !== NULL) {
      $request->setSession($session);
    }

    return $request;
  }

  /**
   * Builds a started Symfony session with a deterministic ID.
   */
  private function buildStartedSession(string $session_id): Session {
    $session = new Session(new MockArraySessionStorage());
    $session->setId($session_id);
    $session->start();

    return $session;
  }

}

/**
 * In-memory cache backend for controller conversation/selection state.
 */
final class SelectionNavigationInMemoryCacheBackend implements CacheBackendInterface {

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

/**
 * Controller double that forces one repeated menu before recovery.
 */
final class SelectionRepeatGuardTestableController extends AssistantApiController {

  /**
   * Number of forced repeated menus returned before parent handling resumes.
   */
  public int $forcedRepeatCount = 0;

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = []) {
    $selection = is_array($intent['selection'] ?? NULL) ? $intent['selection'] : [];
    if (($selection['button_id'] ?? '') !== 'forms_topic_family_divorce' || $this->forcedRepeatCount > 0) {
      return parent::processIntent($intent, $message, $context, $request_id, $server_history);
    }

    $this->forcedRepeatCount++;
    return [
      'type' => 'form_finder_clarify',
      'response_mode' => 'clarify',
      'message' => 'What type of family law issue are you dealing with?',
      'topic_suggestions' => [
        ['label' => 'Custody or parenting time', 'action' => 'forms_topic_family_custody'],
        ['label' => 'Divorce or separation', 'action' => 'forms_topic_family_divorce'],
        ['label' => 'Child support', 'action' => 'forms_topic_family_child_support'],
        ['label' => 'Protection order', 'action' => 'forms_topic_family_protection_order'],
      ],
      'primary_action' => [
        'label' => 'Browse All Forms',
        'url' => '/forms',
      ],
      'secondary_actions' => [],
      'reason_code' => 'repeat_guard_test',
    ];
  }

}
