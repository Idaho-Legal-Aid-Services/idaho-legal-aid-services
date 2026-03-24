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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * IMP-REL-04 contract tests: post-sanitize guard + clarify-loop prevention.
 *
 * Validates that the existing controller guards for empty post-sanitize input
 * and clarify-loop prevention behave correctly.
 */
#[Group('ilas_site_assistant')]
class PostSanitizeAndLoopGuardContractTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once __DIR__ . '/controller_test_bootstrap.php';

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
   * Builds a testable controller with access to protected methods.
   */
  private function buildTestableController(?CacheBackendInterface $cache = NULL): LoopGuardTestableController {
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

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

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
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => 'allow',
      'reason_code' => 'test',
      'confidence' => 1.0,
    ]);

    if ($cache === NULL) {
      $cache = $this->createStub(CacheBackendInterface::class);
      $cache->method('get')->willReturn(FALSE);
    }

    $logger = $this->createStub(\Psr\Log\LoggerInterface::class);

    return new LoopGuardTestableController(
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
    );
  }

  // ─── Post-Sanitize Guard Tests ──────────────────────────────────────

  /**
   * Post-sanitize empty guard returns deterministic 400.
   */
  public function testPostSanitizeEmptyGuardReturnsDeterministic400(): void {
    $controller = $this->buildTestableController();

    // HTML-only input sanitizes to empty string.
    $sanitized = $controller->exposedSanitizeInput('<b></b>');
    $this->assertSame('', $sanitized, 'HTML-only input must sanitize to empty string');

    // Now verify the controller returns 400 for empty message.
    $request = \Symfony\Component\HttpFoundation\Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['message' => '<b></b>'])
    );

    $response = $controller->message($request);
    $this->assertEquals(400, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_message', $body['error_code'] ?? '');
  }

  /**
   * Post-sanitize guard does not trigger on valid input.
   */
  public function testPostSanitizeGuardDoesNotTriggerOnValidInput(): void {
    $controller = $this->buildTestableController();

    $sanitized = $controller->exposedSanitizeInput('<b>hello</b>');
    $this->assertSame('hello', $sanitized, 'Valid content should survive sanitization');
  }

  // ─── Clarify-Loop Guard Tests ───────────────────────────────────────

  /**
   * Clarify loop guard activates at threshold (count=2, same hash → break).
   */
  public function testClarifyLoopGuardActivatesAtThreshold(): void {
    $controller = $this->buildTestableController();

    $question = 'What kind of help do you need?';
    $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $question));
    $hash = hash('sha256', $normalized);

    // Meta with clarify_count=2 and same hash means next one hits threshold (3).
    $meta = [
      'clarify_count' => 2,
      'prior_question_hash' => $hash,
      'updated_at' => time(),
    ];

    $response = [
      'type' => 'disambiguation',
      'response_mode' => 'clarify',
      'message' => $question,
      'options' => [],
    ];

    $result = $controller->exposedApplyClarifyLoopGuard($response, 'conv-test-1', 'req-test-1', $meta);

    $this->assertSame('clarify_loop_break', $result['type'], 'Loop guard should activate at threshold');
    $this->assertNotEmpty($result['topic_suggestions']);
  }

  /**
   * Clarify loop guard resets counter on different question.
   */
  public function testClarifyLoopGuardResetsOnDifferentQuestion(): void {
    $controller = $this->buildTestableController();

    $old_question = 'What kind of help do you need?';
    $old_hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', $old_question)));

    $meta = [
      'clarify_count' => 2,
      'prior_question_hash' => $old_hash,
      'updated_at' => time(),
    ];

    $new_question = 'What type of forms do you need?';
    $response = [
      'type' => 'disambiguation',
      'response_mode' => 'clarify',
      'message' => $new_question,
      'options' => [],
    ];

    $result = $controller->exposedApplyClarifyLoopGuard($response, 'conv-test-2', 'req-test-2', $meta);

    // Different question hash → counter resets to 1, no loop-break.
    $this->assertNotSame('clarify_loop_break', $result['type'] ?? '', 'Different question should reset counter, not trigger loop-break');
  }

  /**
   * Clarify loop guard resets counter on non-clarify response.
   */
  public function testClarifyLoopGuardResetsOnNonClarifyResponse(): void {
    $controller = $this->buildTestableController();

    $meta = [
      'clarify_count' => 2,
      'prior_question_hash' => 'some-hash',
      'updated_at' => time(),
    ];

    $response = [
      'type' => 'faq',
      'response_mode' => 'answer',
      'message' => 'Here is your answer.',
    ];

    $result = $controller->exposedApplyClarifyLoopGuard($response, 'conv-test-3', 'req-test-3', $meta);

    // Non-clarify response → counter resets to 0, response passes through.
    $this->assertSame('faq', $result['type'], 'Non-clarify response should pass through unchanged');
  }

  // ─── isClarifyLikeResponse Detection ────────────────────────────────

  /**
   * Verifies isClarifyLikeResponse detects all clarify-like types.
   */
  public function testIsClarifyLikeResponseDetectsAllTypes(): void {
    $controller = $this->buildTestableController();

    $clarifyTypes = [
      'clarify',
      'disambiguation',
      'form_finder_clarify',
      'guide_finder_clarify',
      'office_location_clarify',
    ];

    foreach ($clarifyTypes as $type) {
      $response = ['type' => $type, 'message' => 'test'];
      $this->assertTrue(
        $controller->exposedIsClarifyLikeResponse($response),
        "Type '$type' should be detected as clarify-like"
      );
    }

    // Also test response_mode=clarify detection.
    $modeResponse = ['type' => 'unknown', 'response_mode' => 'clarify', 'message' => 'test'];
    $this->assertTrue(
      $controller->exposedIsClarifyLikeResponse($modeResponse),
      "response_mode='clarify' should be detected as clarify-like"
    );

    // Negative case: non-clarify response.
    $nonClarify = ['type' => 'faq', 'response_mode' => 'answer', 'message' => 'test'];
    $this->assertFalse(
      $controller->exposedIsClarifyLikeResponse($nonClarify),
      "Type 'faq' with response_mode 'answer' should not be clarify-like"
    );
  }

}

/**
 * Testable controller that exposes protected methods for unit testing.
 */
class LoopGuardTestableController extends AssistantApiController {

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
      ['label' => 'Give Feedback', 'url' => '/feedback', 'type' => 'feedback'],
    ];
  }

  /**
   * Exposes sanitizeInput for direct testing.
   */
  public function exposedSanitizeInput(string $input): string {
    return $this->sanitizeInput($input);
  }

  /**
   * Exposes applyClarifyLoopGuard for direct testing.
   */
  public function exposedApplyClarifyLoopGuard(array $response, string $conversation_id, string $request_id, array $meta): array {
    return $this->applyClarifyLoopGuard($response, $conversation_id, $request_id, $meta);
  }

  /**
   * Exposes isClarifyLikeResponse for direct testing.
   */
  public function exposedIsClarifyLikeResponse(array $response): bool {
    return $this->isClarifyLikeResponse($response);
  }

}
