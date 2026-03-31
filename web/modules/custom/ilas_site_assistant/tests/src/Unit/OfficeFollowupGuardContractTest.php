<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\ConversationStateStore;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Contract tests for bounded office follow-up slot-fill behavior.
 */
#[Group('ilas_site_assistant')]
final class OfficeFollowupGuardContractTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );
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
   * Builds a controller exposing office follow-up helper methods.
   */
  private function buildController(?CacheBackendInterface $cache = NULL, array $flowConfig = [], ?ConversationStateStore $conversationStateStore = NULL): OfficeFollowupTestableController {
    require_once __DIR__ . '/controller_test_bootstrap.php';

    $intentRouter = $this->createStub(IntentRouter::class);
    $intentRouter->method('route')->willReturn(['type' => 'faq', 'confidence' => 0.9]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $resourceFinder = $this->createStub(ResourceFinder::class);
    $policyFilter = $this->createStub(PolicyFilter::class);
    $analyticsLogger = $this->createStub(AnalyticsLogger::class);
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $flood = $this->createStub(FloodInterface::class);

    if ($cache === NULL) {
      $cache = new InMemoryCacheBackend();
    }
    if ($conversationStateStore === NULL) {
      $conversationStateStore = new RecordingConversationStateStore();
    }

    $configFactory = $this->buildFlowConfigFactory($flowConfig);
    $assistantFlowRunner = new AssistantFlowRunner($configFactory, new OfficeLocationResolver(), $conversationStateStore);

    return new OfficeFollowupTestableController(
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
      new NullLogger(),
      assistant_flow_runner: $assistantFlowRunner,
      selection_registry: new \Drupal\ilas_site_assistant\Service\SelectionRegistry(new \Drupal\ilas_site_assistant\Service\TopIntentsPack()),
      selection_state_store: new \Drupal\ilas_site_assistant\Service\SelectionStateStore($cache),
    );
  }

  /**
   * Builds a config factory with office follow-up defaults and overrides.
   */
  private function buildFlowConfigFactory(array $flowConfig = []): ConfigFactoryInterface {
    $defaults = [
      'flows.enabled' => TRUE,
      'flows.office_followup.enabled' => TRUE,
      'flows.office_followup.trigger_intents' => ['apply'],
      'flows.office_followup.require_followup_prompt' => TRUE,
      'flows.office_followup.max_turns' => 2,
      'flows.office_followup.ttl_seconds' => 1800,
    ];

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(static function (string $key) use ($defaults, $flowConfig) {
      $values = $flowConfig + $defaults;
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    return $configFactory;
  }

  /**
   * Builds the authoritative pre-routing decision engine.
   */
  private function buildPreRoutingDecisionEngine(): PreRoutingDecisionEngine {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturn([]);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $policyFilter = new PolicyFilter($configFactory);
    $policyFilter->setStringTranslation($this->createStub(TranslationInterface::class));

    return new PreRoutingDecisionEngine(
      $policyFilter,
      new SafetyClassifier($configFactory),
      new OutOfScopeClassifier($configFactory),
    );
  }

  /**
   * Unrelated turns must not be treated as office follow-up slot-fill.
   */
  public function testUnrelatedTurnDoesNotQualifyForOfficeFollowup(): void {
    $controller = $this->buildController();

    $message = 'if i do not qualify what else can i do';
    $this->assertFalse($controller->exposedIsLocationLikeOfficeReply($message));
    $this->assertFalse($controller->exposedIsExplicitOfficeFollowupTurn($message));
    $this->assertFalse($controller->exposedIsLocationLikeOfficeReply('divorce'));
    $this->assertFalse($controller->exposedIsLocationLikeOfficeReply('mi casero me quiere sacar de mi casa'));
  }

  /**
   * Location-like and explicit office turns are still recognized.
   */
  public function testLocationAndExplicitOfficeFollowupsStillQualify(): void {
    $controller = $this->buildController();

    $this->assertTrue($controller->exposedIsLocationLikeOfficeReply('boise'));
    $this->assertTrue($controller->exposedIsExplicitOfficeFollowupTurn('which office is closest to me'));
  }

  /**
   * Follow-up state uses durable storage with config-backed metadata.
   */
  public function testFollowupStateUsesConfiguredTurnBudgetTtlAndSessionFingerprint(): void {
    $cache = new InMemoryCacheBackend();
    $conversationStateStore = new RecordingConversationStateStore(1700000000);
    $controller = $this->buildController($cache, [
      'flows.office_followup.max_turns' => 4,
      'flows.office_followup.ttl_seconds' => 15,
    ], $conversationStateStore);
    $conversationId = '11111111-1111-4111-8111-111111111111';

    $controller->exposedSaveOfficeFollowupState($conversationId, [
      'type' => 'office_location',
      'origin_intent' => 'apply',
      'created_at' => 1700000000,
    ], 'session-a');
    $loaded = $controller->exposedLoadOfficeFollowupState($conversationId, 'session-a');
    $this->assertNotNull($loaded);
    $this->assertSame(4, $loaded['remaining_turns']);
    $this->assertSame('apply', $loaded['origin_intent']);
    $this->assertSame('session-a', $conversationStateStore->rows[$conversationId]['session_fingerprint'] ?? NULL);
    $this->assertSame(1700000015, $conversationStateStore->rows[$conversationId]['expires'] ?? NULL);
    $this->assertSame(15, $conversationStateStore->saveCalls[0]['ttl_seconds'] ?? NULL);
    $this->assertNull($controller->exposedLoadOfficeFollowupState($conversationId, 'session-b'));
    $this->assertArrayNotHasKey($conversationId, $conversationStateStore->rows);

    $controller->exposedSaveOfficeFollowupState($conversationId, [
      'type' => 'office_location',
      'origin_intent' => 'apply',
      'remaining_turns' => 1,
      'created_at' => 1700000000,
    ], 'session-a');
    $conversationStateStore->setCurrentTime(1700000020);
    $this->assertNull($controller->exposedLoadOfficeFollowupState($conversationId, 'session-a'));
    $this->assertArrayNotHasKey($conversationId, $conversationStateStore->rows);
  }

  /**
   * Office detail requests can resolve office context from recent history.
   */
  public function testOfficeDetailResolutionUsesRecentHistory(): void {
    $controller = $this->buildController();
    $resolver = new OfficeLocationResolver();
    $history = [
      ['text' => 'whats the address for the boise office'],
    ];

    $resolved = $controller->exposedResolveOfficeFromMessageOrHistory(
      'what are the hours can i go after work',
      $history,
      $resolver
    );

    $this->assertNotNull($resolved);
    $this->assertSame('Boise', $resolved['name']);
    $this->assertTrue($controller->exposedIsOfficeDetailRequest('what are the hours can i go after work'));
    $this->assertTrue($controller->exposedIsOfficeDetailRequest('can i just walk in or do i need an appointment'));
  }

  /**
   * Office-specific detail requests return concrete office address/hours data.
   */
  public function testOfficeIntentReturnsOfficeDetailPayload(): void {
    $controller = $this->buildController();

    $response = $controller->exposedProcessIntent(
      ['type' => 'offices', 'confidence' => 0.9],
      'whats the address for the boise office',
      []
    );

    $this->assertSame('office_location', $response['type']);
    $this->assertSame('Boise', $response['office']['name']);
    $this->assertArrayHasKey('hours', $response['office']);
    $this->assertStringContainsString('call', strtolower((string) $response['office']['hours']));
    $this->assertSame('/contact/offices/boise', $response['primary_action']['url']);
  }

  /**
   * Topic-shift detection only triggers when user explicitly shifts topics.
   */
  public function testExplicitServiceAreaShiftDetectionRequiresSignal(): void {
    $controller = $this->buildController();

    $this->assertFalse($controller->exposedIsExplicitServiceAreaShift(
      'what about all the money he already owes me the back pay',
      'family'
    ));
    $this->assertTrue($controller->exposedIsExplicitServiceAreaShift(
      'different issue now, my landlord gave me an eviction notice',
      'family'
    ));
  }

  /**
   * Office follow-up urgency storage uses the authoritative decision engine.
   */
  public function testOfficeFollowupUrgencyUsesAuthoritativeDecisionEngine(): void {
    $engine = $this->buildPreRoutingDecisionEngine();

    $deadlineDecision = $engine->evaluate('i must respond in 48 hours');
    $this->assertSame(['deadline_pressure'], $deadlineDecision['urgency_signals']);
    $this->assertSame('high_risk_deadline', $deadlineDecision['routing_override_intent']['risk_category'] ?? NULL);

    $evictionDecision = $engine->evaluate('i got locked out today');
    $this->assertContains('eviction_imminent', $evictionDecision['urgency_signals']);
    $this->assertSame(PreRoutingDecisionEngine::DECISION_SAFETY_EXIT, $evictionDecision['decision_type']);
    $this->assertSame(SafetyClassifier::CLASS_EVICTION_EMERGENCY, $evictionDecision['safety']['class']);
  }

}

/**
 * Testable controller exposing follow-up helper methods.
 */
final class OfficeFollowupTestableController extends AssistantApiController {

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [];
  }

  public function exposedLoadOfficeFollowupState(string $conversation_id, string $session_fingerprint = ''): ?array {
    return $this->loadOfficeFollowupState($conversation_id, $session_fingerprint);
  }

  public function exposedSaveOfficeFollowupState(string $conversation_id, array $state, string $session_fingerprint = ''): void {
    $this->saveOfficeFollowupState($conversation_id, $state, $session_fingerprint);
  }

  public function exposedIsLocationLikeOfficeReply(string $message): bool {
    return $this->isLocationLikeOfficeReply($message);
  }

  public function exposedIsExplicitOfficeFollowupTurn(string $message): bool {
    return $this->isExplicitOfficeFollowupTurn($message);
  }

  public function exposedIsOfficeDetailRequest(string $message): bool {
    return $this->isOfficeDetailRequest($message);
  }

  public function exposedResolveOfficeFromMessageOrHistory(string $message, array $server_history, OfficeLocationResolver $resolver): ?array {
    return $this->resolveOfficeFromMessageOrHistory($message, $server_history, $resolver);
  }

  public function exposedProcessIntent(array $intent, string $message, array $server_history): array {
    return $this->processIntent($intent, $message, [], 'req-unit-test', $server_history);
  }

  public function exposedIsExplicitServiceAreaShift(string $message, string $historyArea): bool {
    return $this->isExplicitServiceAreaShift($message, $historyArea);
  }
}

/**
 * Durable conversation-state test double for office follow-up unit coverage.
 */
final class RecordingConversationStateStore extends ConversationStateStore {

  /**
   * Stored rows keyed by conversation UUID.
   *
   * @var array<string, array<string, int|string>>
   */
  public array $rows = [];

  /**
   * Recorded save calls.
   *
   * @var array<int, array<string, int|string>>
   */
  public array $saveCalls = [];

  /**
   * Current fake time.
   */
  private int $currentTime;

  public function __construct(?int $currentTime = NULL) {
    $this->currentTime = $currentTime ?? time();
  }

  public function setCurrentTime(int $currentTime): void {
    $this->currentTime = $currentTime;
  }

  public function loadOfficeFollowupState(string $conversation_id, string $session_fingerprint = ''): ?array {
    $row = $this->rows[$conversation_id] ?? NULL;
    if (!is_array($row)) {
      return NULL;
    }

    $stored_fingerprint = (string) ($row['session_fingerprint'] ?? '');
    if ($stored_fingerprint !== '' && $session_fingerprint !== '' && !hash_equals($stored_fingerprint, $session_fingerprint)) {
      $this->clear($conversation_id);
      return NULL;
    }

    if (($row['pending_flow_type'] ?? '') !== self::FLOW_TYPE_OFFICE_LOCATION) {
      return NULL;
    }

    $created_at = (int) ($row['pending_flow_created'] ?? 0);
    $remaining_turns = (int) ($row['pending_flow_remaining_turns'] ?? 0);
    $expires = (int) ($row['expires'] ?? 0);
    if ($created_at <= 0 || $remaining_turns <= 0 || $expires <= $this->currentTime) {
      $this->clear($conversation_id);
      return NULL;
    }

    return [
      'type' => self::FLOW_TYPE_OFFICE_LOCATION,
      'origin_intent' => (string) ($row['pending_flow_origin_intent'] ?? 'apply'),
      'remaining_turns' => $remaining_turns,
      'created_at' => $created_at,
    ];
  }

  public function saveOfficeFollowupState(string $conversation_id, array $state, string $session_fingerprint, int $ttl_seconds): void {
    if ($conversation_id === '') {
      return;
    }

    $created_at = max(1, (int) ($state['created_at'] ?? $this->currentTime));
    $remaining_turns = max(0, (int) ($state['remaining_turns'] ?? 0));
    $ttl_seconds = max(1, $ttl_seconds);
    $row = [
      'session_fingerprint' => mb_substr($session_fingerprint, 0, 64),
      'pending_flow_type' => self::FLOW_TYPE_OFFICE_LOCATION,
      'pending_flow_origin_intent' => mb_substr((string) ($state['origin_intent'] ?? 'apply'), 0, 64),
      'pending_flow_remaining_turns' => $remaining_turns,
      'pending_flow_created' => $created_at,
      'updated' => $this->currentTime,
      'expires' => $created_at + $ttl_seconds,
    ];

    $this->rows[$conversation_id] = $row;
    $this->saveCalls[] = ['conversation_id' => $conversation_id, 'ttl_seconds' => $ttl_seconds] + $row;
  }

  public function clear(string $conversation_id): void {
    unset($this->rows[$conversation_id]);
  }

}

/**
 * Simple in-memory cache backend for unit tests.
 */
final class InMemoryCacheBackend implements CacheBackendInterface {

  /**
   * Stored entries.
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
