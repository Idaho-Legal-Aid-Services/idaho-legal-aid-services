<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\VoyageReranker;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VoyageReranker.
 */
#[Group('ilas_site_assistant')]
class VoyageRerankerTest extends TestCase {

  /**
   * Default Voyage config for tests.
   */
  protected function defaultConfig(): array {
    return [
      'enabled' => TRUE,
      'rerank_model' => 'rerank-2',
      'api_timeout' => 3.0,
      'max_candidates' => 20,
      'top_k' => 5,
      'min_results_to_rerank' => 2,
      'circuit_breaker' => [
        'failure_threshold' => 3,
        'cooldown_seconds' => 300,
      ],
      'fallback_on_error' => TRUE,
    ];
  }

  /**
   * Sample FAQ items.
   */
  protected function sampleFaqItems(): array {
    return [
      ['paragraph_id' => 1, 'question' => 'How do I apply?', 'answer_snippet' => 'You can apply online.', 'score' => 80],
      ['paragraph_id' => 2, 'question' => 'What are the fees?', 'answer_snippet' => 'Services are free.', 'score' => 60],
      ['paragraph_id' => 3, 'question' => 'Where are offices?', 'answer_snippet' => 'Boise and Lewiston.', 'score' => 40],
    ];
  }

  /**
   * Sample resource items.
   */
  protected function sampleResourceItems(): array {
    return [
      ['nid' => 10, 'title' => 'Housing Rights Guide', 'description' => 'A guide about tenant rights.', 'score' => 70],
      ['nid' => 11, 'title' => 'Family Law FAQ', 'description' => 'Frequently asked questions.', 'score' => 50],
    ];
  }

  /**
   * Builds a VoyageReranker with mocked dependencies.
   */
  protected function buildReranker(array $config = [], ?ClientInterface $http = NULL, ?StateInterface $state = NULL): VoyageReranker {
    $voyage_config = array_replace_recursive($this->defaultConfig(), $config);

    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')
      ->willReturnCallback(function ($key) use ($voyage_config) {
        if ($key === 'voyage') {
          return $voyage_config;
        }
        return NULL;
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($immutableConfig);

    $logger = $this->createMock(LoggerInterface::class);

    if ($state === NULL) {
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturn(NULL);
    }

    if ($http === NULL) {
      $http = $this->createMock(ClientInterface::class);
    }

    return new VoyageReranker($configFactory, $http, $logger, $state);
  }

  /**
   * Builds a successful Voyage API response.
   */
  protected function buildVoyageResponse(array $results): Response {
    return new Response(200, [], json_encode(['data' => $results]));
  }

  /**
   * Initializes Settings for tests.
   */
  protected function initSettings(array $settings = []): void {
    $defaults = ['ilas_voyage_api_key' => 'test-voyage-key'];
    new Settings(array_merge($defaults, $settings));
  }

  /**
   * Tests successful reranking with mocked HTTP.
   */
  public function testSuccessfulReranking(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 2, 'relevance_score' => 0.95],
        ['index' => 0, 'relevance_score' => 0.80],
        ['index' => 1, 'relevance_score' => 0.60],
      ]));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('how to apply', $items);

    $this->assertTrue($result['meta']['applied']);
    $this->assertTrue($result['meta']['attempted']);
    $this->assertSame('rerank-2', $result['meta']['model']);
    $this->assertCount(3, $result['items']);
    // First item should be the one Voyage ranked highest (index 2).
    $this->assertSame(3, $result['items'][0]['paragraph_id']);
    $this->assertSame(0.95, $result['items'][0]['voyage_score']);
  }

  /**
   * Tests that all original fields are preserved after reordering.
   */
  public function testFieldPreservation(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 1, 'relevance_score' => 0.90],
        ['index' => 0, 'relevance_score' => 0.70],
        ['index' => 2, 'relevance_score' => 0.50],
      ]));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    foreach ($result['items'] as $item) {
      $this->assertArrayHasKey('paragraph_id', $item);
      $this->assertArrayHasKey('question', $item);
      $this->assertArrayHasKey('answer_snippet', $item);
      $this->assertArrayHasKey('score', $item);
      $this->assertArrayHasKey('voyage_score', $item);
    }
  }

  /**
   * Tests Voyage score annotation on items.
   */
  public function testVoyageScoreAnnotation(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 0, 'relevance_score' => 0.88],
        ['index' => 1, 'relevance_score' => 0.55],
      ]));

    $reranker = $this->buildReranker(['top_k' => 2], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertSame(0.88, $result['items'][0]['voyage_score']);
    $this->assertSame(0.55, $result['items'][1]['voyage_score']);
    $this->assertSame(0.88, $result['meta']['top_score']);
    $this->assertEqualsWithDelta(0.33, $result['meta']['score_delta'], 0.01);
  }

  /**
   * Tests timeout fallback returns original items.
   */
  public function testTimeoutFallback(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willThrowException(new ConnectException(
        'Connection timed out',
        new Request('POST', VoyageReranker::API_ENDPOINT),
      ));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['applied']);
    $this->assertTrue($result['meta']['attempted']);
    $this->assertSame('timeout', $result['meta']['fallback_reason']);
    $this->assertCount(3, $result['items']);
    // Original order preserved.
    $this->assertSame(1, $result['items'][0]['paragraph_id']);
  }

  /**
   * Tests API error fallback returns original items.
   */
  public function testApiErrorFallback(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willThrowException(new RequestException(
        'Server error',
        new Request('POST', VoyageReranker::API_ENDPOINT),
        new Response(500),
      ));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['applied']);
    $this->assertSame('api_error', $result['meta']['fallback_reason']);
    $this->assertCount(3, $result['items']);
  }

  /**
   * Tests malformed response fallback.
   */
  public function testMalformedResponseFallback(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturn(new Response(200, [], '{"unexpected": true}'));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['applied']);
    $this->assertSame('malformed_response', $result['meta']['fallback_reason']);
    $this->assertCount(3, $result['items']);
  }

  /**
   * Tests disabled config passthrough (no API call).
   */
  public function testDisabledConfigPassthrough(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');

    $reranker = $this->buildReranker(['enabled' => FALSE], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['attempted']);
    $this->assertSame('disabled', $result['meta']['fallback_reason']);
    $this->assertCount(3, $result['items']);
  }

  /**
   * Tests missing API key passthrough (no API call).
   */
  public function testMissingApiKeyPassthrough(): void {
    new Settings([]);
    $items = $this->sampleFaqItems();

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['attempted']);
    $this->assertSame('no_api_key', $result['meta']['fallback_reason']);
  }

  /**
   * Tests circuit breaker open state blocks requests.
   */
  public function testCircuitBreakerOpen(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with(VoyageReranker::CIRCUIT_STATE_KEY)
      ->willReturn([
        'state' => 'open',
        'consecutive_failures' => 3,
        'last_failure_time' => time(),
        'opened_at' => time(),
      ]);

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');

    $reranker = $this->buildReranker([], $http, $state);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['attempted']);
    $this->assertSame('circuit_open', $result['meta']['fallback_reason']);
  }

  /**
   * Tests circuit breaker half-open allows probe request.
   */
  public function testCircuitBreakerHalfOpen(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    // Circuit opened 600 seconds ago, cooldown is 300.
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with(VoyageReranker::CIRCUIT_STATE_KEY)
      ->willReturn([
        'state' => 'open',
        'consecutive_failures' => 3,
        'last_failure_time' => time() - 600,
        'opened_at' => time() - 600,
      ]);
    $state->expects($this->atLeastOnce())->method('set');

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 0, 'relevance_score' => 0.90],
        ['index' => 1, 'relevance_score' => 0.70],
        ['index' => 2, 'relevance_score' => 0.50],
      ]));

    $reranker = $this->buildReranker([], $http, $state);
    $result = $reranker->rerank('test', $items);

    $this->assertTrue($result['meta']['applied']);
  }

  /**
   * Tests circuit breaker closes on success after half-open.
   */
  public function testCircuitBreakerClosesOnSuccess(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    $stored_state = NULL;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with(VoyageReranker::CIRCUIT_STATE_KEY)
      ->willReturn([
        'state' => 'half_open',
        'consecutive_failures' => 3,
        'last_failure_time' => time() - 600,
        'opened_at' => time() - 600,
      ]);
    $state->method('set')
      ->willReturnCallback(function ($key, $value) use (&$stored_state) {
        $stored_state = $value;
      });

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 0, 'relevance_score' => 0.90],
        ['index' => 1, 'relevance_score' => 0.70],
        ['index' => 2, 'relevance_score' => 0.50],
      ]));

    $reranker = $this->buildReranker([], $http, $state);
    $reranker->rerank('test', $items);

    $this->assertSame('closed', $stored_state['state']);
    $this->assertSame(0, $stored_state['consecutive_failures']);
  }

  /**
   * Tests FAQ item document extraction.
   */
  public function testFaqDocumentExtraction(): void {
    $this->initSettings();
    $reranker = $this->buildReranker();

    $item = ['question' => 'How do I apply?', 'answer_snippet' => 'Visit the application page.'];
    $doc = $reranker->extractDocument($item);

    $this->assertSame('How do I apply? Visit the application page.', $doc);
  }

  /**
   * Tests resource item document extraction.
   */
  public function testResourceDocumentExtraction(): void {
    $this->initSettings();
    $reranker = $this->buildReranker();

    $item = ['title' => 'Housing Rights', 'description' => 'Know your tenant rights.'];
    $doc = $reranker->extractDocument($item);

    $this->assertSame('Housing Rights Know your tenant rights.', $doc);
  }

  /**
   * Tests content truncation at MAX_DOCUMENT_LENGTH.
   */
  public function testContentTruncation(): void {
    $this->initSettings();
    $reranker = $this->buildReranker();

    $long_text = str_repeat('a', 1500);
    $item = ['title' => $long_text, 'description' => ''];
    $doc = $reranker->extractDocument($item);

    $this->assertSame(VoyageReranker::MAX_DOCUMENT_LENGTH, mb_strlen($doc));
  }

  /**
   * Tests min results guard skips reranking.
   */
  public function testMinResultsGuard(): void {
    $this->initSettings();
    $items = [['paragraph_id' => 1, 'question' => 'Q', 'answer_snippet' => 'A', 'score' => 80]];

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');

    $reranker = $this->buildReranker(['min_results_to_rerank' => 2], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertFalse($result['meta']['attempted']);
    $this->assertSame('insufficient_results', $result['meta']['fallback_reason']);
  }

  /**
   * Tests max candidates cap.
   */
  public function testMaxCandidatesCap(): void {
    $this->initSettings();

    // Create 25 items.
    $items = [];
    for ($i = 0; $i < 25; $i++) {
      $items[] = ['paragraph_id' => $i, 'question' => "Q$i", 'answer_snippet' => "A$i", 'score' => 100 - $i];
    }

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturnCallback(function ($method, $url, $options) {
        // Verify only max_candidates documents sent.
        $body = $options['json'];
        $this->assertCount(20, $body['documents']);
        // Return all 20 in reverse order.
        $data = [];
        for ($i = 19; $i >= 0; $i--) {
          $data[] = ['index' => $i, 'relevance_score' => 0.5 + ($i * 0.02)];
        }
        return new Response(200, [], json_encode(['data' => $data]));
      });

    $reranker = $this->buildReranker(['max_candidates' => 20], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertTrue($result['meta']['applied']);
    $this->assertSame(20, $result['meta']['input_count']);
    // Overflow items (ids 20-24) appended after reranked.
    $this->assertCount(25, $result['items']);
  }

  /**
   * Tests order change detection.
   */
  public function testOrderChangeDetection(): void {
    $this->initSettings();
    $items = $this->sampleFaqItems();

    // Return same order.
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturn($this->buildVoyageResponse([
        ['index' => 0, 'relevance_score' => 0.95],
        ['index' => 1, 'relevance_score' => 0.80],
        ['index' => 2, 'relevance_score' => 0.60],
      ]));

    $reranker = $this->buildReranker([], $http);
    $result = $reranker->rerank('test', $items);

    $this->assertTrue($result['meta']['applied']);
    $this->assertFalse($result['meta']['order_changed']);
  }

}
