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
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
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
    $container->set('string_translation', $this->createStub(TranslationInterface::class));
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
  private function buildController(?CacheBackendInterface $cache = NULL): OfficeFollowupTestableController {
    require_once __DIR__ . '/controller_test_bootstrap.php';

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

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
      new NullLogger()
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
   * Follow-up state uses bounded turn metadata and expires safely.
   */
  public function testFollowupStateIsBoundedAndExpires(): void {
    $cache = new InMemoryCacheBackend();
    $controller = $this->buildController($cache);
    $conversationId = '11111111-1111-4111-8111-111111111111';

    $controller->exposedSaveOfficeFollowupState($conversationId, [
      'type' => 'office_location',
      'origin_intent' => 'apply',
      'remaining_turns' => 2,
      'created_at' => time(),
    ]);
    $loaded = $controller->exposedLoadOfficeFollowupState($conversationId);
    $this->assertNotNull($loaded);
    $this->assertSame(2, $loaded['remaining_turns']);
    $this->assertSame('apply', $loaded['origin_intent']);

    $controller->exposedSaveOfficeFollowupState($conversationId, [
      'type' => 'office_location',
      'origin_intent' => 'apply',
      'remaining_turns' => 1,
      'created_at' => time() - AssistantApiController::CONVERSATION_STATE_TTL - 5,
    ]);
    $this->assertNull($controller->exposedLoadOfficeFollowupState($conversationId));
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

  public function exposedLoadOfficeFollowupState(string $conversation_id): ?array {
    return $this->loadOfficeFollowupState($conversation_id);
  }

  public function exposedSaveOfficeFollowupState(string $conversation_id, array $state): void {
    $this->saveOfficeFollowupState($conversation_id, $state);
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
