<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Exception\LlmAdmissionDeniedException;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\GroundedAnswerGenerator;
use Drupal\ilas_site_assistant\Service\GroundedGenerationTransportInterface;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmRequestPipeline;
use Drupal\ilas_site_assistant\Service\LlmRuntimeConfigResolver;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for grounded answer generation guards and fallbacks.
 */
#[Group('ilas_site_assistant')]
final class GroundedAnswerGeneratorTest extends TestCase {

  private const GOOD_ANSWER = 'You can apply for help online or by calling the Legal Advice Line. Intake is open Monday through Wednesday, and staff will ask about your income and your legal problem.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * A well-formed cited answer replaces nothing and maps citations to results.
   */
  public function testGeneratesCitedAnswerAndMapsCitationsToResults(): void {
    $transport = new ScriptedGroundedTransport([
      'text' => "**" . self::GOOD_ANSWER . "**",
      'citations' => [
        ['start' => 40, 'end' => 60, 'text' => 'Legal Advice Line', 'document_ids' => ['doc_1:0']],
        ['start' => 0, 'end' => 20, 'text' => 'You can apply', 'document_ids' => ['doc_0']],
        ['start' => 5, 'end' => 9, 'text' => 'dup', 'document_ids' => ['doc_0', 'doc_9']],
      ],
      'finish_reason' => 'COMPLETE',
      'usage' => ['input' => 800, 'output' => 40, 'total' => 840],
    ]);
    $generator = $this->buildGenerator($transport);
    $response = $this->buildResponse();

    $result = $generator->generate('How do I apply for help? My SSN is 123-45-6789.', $response, [
      GroundedAnswerGenerator::OPTION_USER_IDENTIFIER => '203.0.113.9',
      GroundedAnswerGenerator::OPTION_ELAPSED_MS => 1500.0,
    ]);

    $this->assertTrue($result['attempted']);
    $this->assertTrue($result['used']);
    $this->assertSame('generated', $result['reason']);
    $this->assertSame(self::GOOD_ANSWER, $result['message'], 'light markdown is stripped');
    $this->assertSame('en', $result['language']);
    $this->assertSame(2, $result['documents_count']);
    $this->assertSame(['input' => 800, 'output' => 40, 'total' => 840], $result['usage']);
    $this->assertCount(2, $result['citations']);
    $this->assertSame(0, $result['citations'][0]['result_index']);
    $this->assertSame('/faq/apply#how-to-apply', $result['citations'][0]['url']);
    $this->assertSame('How do I apply?', $result['citations'][0]['title']);
    $this->assertSame(1, $result['citations'][1]['result_index']);
    $this->assertSame('doc_1', $result['citations'][1]['document_id']);
    $this->assertSame('/resources/intake-hours', $result['citations'][1]['url']);

    // Provider request shape and PII redaction.
    $this->assertCount(1, $transport->calls);
    $call = $transport->calls[0];
    $this->assertSame('system', $call['messages'][0]['role']);
    $this->assertStringContainsString('NO_ANSWER', $call['messages'][0]['content']);
    $this->assertStringNotContainsString('Responde en español', $call['messages'][0]['content']);
    $this->assertStringNotContainsString('123-45-6789', $call['messages'][1]['content']);
    $this->assertStringContainsString('How do I apply for help?', $call['messages'][1]['content']);
    $this->assertSame(['doc_0', 'doc_1'], array_column($call['documents'], 'id'));
    $this->assertSame('How do I apply?', $call['documents'][0]['data']['title']);
    $this->assertStringNotContainsString('<p>', $call['documents'][0]['data']['snippet']);
    $this->assertArrayNotHasKey('url', $call['documents'][0]['data']);
    $this->assertSame(220, $call['options']['max_tokens']);
    $this->assertSame('FAST', $call['options']['citation_mode']);
    $this->assertSame('CONTEXTUAL', $call['options']['safety_mode']);
    $this->assertSame(8.0, $call['options']['timeout']);

    // Untouched input.
    $this->assertSame('Template answer.', $response['message']);
  }

  /**
   * Spanish input adds the language hint and accepts Spanish output.
   */
  public function testSpanishInputAddsHintAndAcceptsSpanishOutput(): void {
    $transport = new ScriptedGroundedTransport([
      'text' => 'Usted puede solicitar ayuda en línea o llamar a la Línea de Consejos Legales para su situación.',
      'citations' => [['start' => 0, 'end' => 10, 'text' => 'Usted', 'document_ids' => ['doc_0']]],
      'finish_reason' => 'COMPLETE',
      'usage' => [],
    ]);
    $generator = $this->buildGenerator($transport);

    $result = $generator->generate('Necesito ayuda con un desalojo', $this->buildResponse());

    $this->assertTrue($result['used']);
    $this->assertSame('es', $result['language']);
    $this->assertStringContainsString('Responde en español', $transport->calls[0]['messages'][0]['content']);
  }

  /**
   * Every output guard falls back to the template with a stable reason.
   */
  #[DataProvider('outputGuardProvider')]
  public function testOutputGuardsFallBack(array $completion, string $expectedReason, string $userMessage = 'How do I apply for help?'): void {
    $transport = new ScriptedGroundedTransport($completion + [
      'citations' => [['start' => 0, 'end' => 5, 'text' => 'x', 'document_ids' => ['doc_0']]],
      'finish_reason' => 'COMPLETE',
      'usage' => [],
    ]);
    $generator = $this->buildGenerator($transport);

    $result = $generator->generate($userMessage, $this->buildResponse());

    $this->assertTrue($result['attempted']);
    $this->assertFalse($result['used']);
    $this->assertSame($expectedReason, $result['reason']);
    $this->assertNull($result['message']);
    $this->assertSame([], $result['citations']);
  }

  /**
   * Output guard cases.
   */
  public static function outputGuardProvider(): array {
    $long = implode(' ', array_fill(0, 170, 'word')) . '.';
    $cited = static fn(string $text, array $ids = ['doc_0']): array => [
      'text' => $text,
      'citations' => [['start' => 0, 'end' => 1, 'text' => 'x', 'document_ids' => $ids]],
    ];
    return [
      'empty output' => [['text' => '   '], 'empty_output'],
      'refusal sentinel' => [['text' => 'NO_ANSWER'], 'refusal_sentinel'],
      'refusal sentinel with trailing prose' => [
        ['text' => 'no_answer - the documents do not cover this'],
        'refusal_sentinel',
      ],
      'truncated at max tokens' => [
        ['text' => 'You can apply online and then the intake', 'finish_reason' => 'MAX_TOKENS'],
        'truncated',
      ],
      'url in output' => [['text' => 'Apply at https://example.org/apply today.'], 'url_in_output'],
      'www in output' => [['text' => 'Apply at www.example.org today.'], 'url_in_output'],
      'too long' => [['text' => $long], 'too_long'],
      'no citations' => [['text' => self::GOOD_ANSWER, 'citations' => []], 'no_citations'],
      'unknown document id' => [$cited(self::GOOD_ANSWER, ['doc_7']), 'no_citations'],
      'legal advice' => [
        ['text' => 'You should file a motion with the court right away to protect yourself.'],
        'legal_advice_detected',
      ],
      'invented phone number' => [
        ['text' => 'Call 208-555-0199 to schedule an intake appointment with staff.'],
        'unsupported_contact_info',
      ],
      'invented dollar amount' => [
        ['text' => 'The filing fee is $207 and staff can explain the waiver.'],
        'unsupported_contact_info',
      ],
      'language mismatch' => [
        ['text' => 'Apply online or call for help.'],
        'language_mismatch',
        'Necesito ayuda con un desalojo',
      ],
    ];
  }

  /**
   * Pre-provider guards never reach the transport.
   */
  public function testPreProviderGuardsNeverCallTransport(): void {
    $transport = new ScriptedGroundedTransport(['text' => self::GOOD_ANSWER]);

    $disabled = $this->buildGenerator($transport, ['llm.generation.enabled' => FALSE]);
    $this->assertSame('disabled', $disabled->generate('How do I apply?', $this->buildResponse())['reason']);
    $this->assertFalse($disabled->isEnabled());

    $not_ready = $this->buildGenerator($transport, ['llm.enabled' => FALSE]);
    $this->assertSame('not_configured', $not_ready->generate('How do I apply?', $this->buildResponse())['reason']);

    $killed = $this->buildGenerator($transport, [], killSwitch: TRUE);
    $this->assertSame('kill_switch', $killed->generate('How do I apply?', $this->buildResponse())['reason']);

    $generator = $this->buildGenerator($transport);
    $this->assertSame('eval_override_off', $generator->generate('x', $this->buildResponse(), [
      GroundedAnswerGenerator::OPTION_EVAL_OVERRIDE => 'off',
    ])['reason']);
    $this->assertSame('not_applicable', $generator->generate('x', ['type' => 'topic', 'message' => 'm', 'results' => [['title' => 't']]])['reason']);
    $this->assertSame('not_applicable', $generator->generate('x', ['type' => 'faq', 'message' => 'm', 'results' => []])['reason']);
    $this->assertSame('not_applicable', $generator->generate('x', ['type' => 'faq', 'message' => '', 'results' => [['title' => 't']]])['reason']);
    $this->assertSame('not_applicable', $generator->generate('x', $this->buildResponse() + ['_requires_review' => TRUE])['reason']);
    $this->assertSame('time_budget_exhausted', $generator->generate('x', $this->buildResponse(), [
      GroundedAnswerGenerator::OPTION_ELAPSED_MS => 12001.0,
    ])['reason']);
    $this->assertSame('no_documents', $generator->generate('x', [
      'type' => 'faq',
      'message' => 'Template',
      'results' => [['title' => 'Title only', 'url' => '/x'], 'not-an-array'],
    ])['reason']);
    $this->assertSame('empty_after_sanitization', $generator->generate('   ', $this->buildResponse())['reason']);

    $this->assertSame([], $transport->calls, 'no guard above may reach the provider');
  }

  /**
   * Admission denial and transport failures fall back without throwing.
   */
  public function testAdmissionDenialAndExceptionFallBack(): void {
    $policy = $this->createMock(CostControlPolicy::class);
    $policy->method('beginRequest')->willReturn(['allowed' => FALSE, 'reason' => 'per_ip_budget_exceeded']);
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->never())->method('recordFailure');
    $transport = new ScriptedGroundedTransport(['text' => self::GOOD_ANSWER]);
    $generator = $this->buildGenerator($transport, [], costControl: $policy, breaker: $breaker);

    $result = $generator->generate('How do I apply?', $this->buildResponse());
    $this->assertSame('admission_denied', $result['reason']);
    $this->assertSame('per_ip_budget_exceeded', $result['admission_reason']);
    $this->assertSame([], $transport->calls);

    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->once())->method('recordFailure');
    $failing = new ScriptedGroundedTransport(new \RuntimeException('boom'));
    $generator = $this->buildGenerator($failing, [], breaker: $breaker);

    $result = $generator->generate('How do I apply?', $this->buildResponse());
    $this->assertSame('exception', $result['reason']);
    $this->assertFalse($result['used']);
  }

  /**
   * Document building honours caps, ordering, and resource item keys.
   */
  public function testDocumentBuildingHonoursCapsAndResourceKeys(): void {
    $transport = new ScriptedGroundedTransport([
      'text' => self::GOOD_ANSWER,
      'citations' => [['start' => 0, 'end' => 1, 'text' => 'x', 'document_ids' => ['doc_2']]],
      'finish_reason' => 'COMPLETE',
      'usage' => [],
    ]);
    $generator = $this->buildGenerator($transport, [
      'llm.generation.max_documents' => 2,
      'llm.generation.snippet_chars' => 200,
    ]);
    $long = str_repeat('Intake details. ', 40);
    $response = [
      'type' => 'resources',
      'message' => 'Template answer.',
      'results' => [
        ['title' => 'No body resource', 'url' => '/r0', 'description' => ''],
        ['title' => 'Guide one', 'url' => '/r1', 'description' => $long],
        ['title' => 'Guide two', 'url' => '/r2', 'description' => 'Second guide body.'],
        ['title' => 'Guide three', 'url' => '/r3', 'description' => 'Never sent.'],
      ],
    ];

    $result = $generator->generate('Where can I read about intake?', $response);

    $this->assertTrue($result['used']);
    $documents = $transport->calls[0]['documents'];
    $this->assertSame(['doc_1', 'doc_2'], array_column($documents, 'id'), 'empty bodies are skipped and max_documents caps the list');
    $this->assertLessThanOrEqual(201, mb_strlen($documents[0]['data']['snippet']));
    $this->assertStringEndsWith('…', $documents[0]['data']['snippet']);
    $this->assertSame(2, $result['citations'][0]['result_index']);
    $this->assertSame('/r2', $result['citations'][0]['url']);
  }

  /**
   * A cache hit reports generated_cached without touching the transport.
   */
  public function testCacheHitReportsCachedReason(): void {
    $cache = $this->createStub(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) [
      'data' => [
        'text' => self::GOOD_ANSWER,
        'citations' => [['start' => 0, 'end' => 3, 'text' => 'You', 'document_ids' => ['doc_0']]],
        'finish_reason' => 'COMPLETE',
      ],
    ]);
    $transport = new ScriptedGroundedTransport(['text' => 'never used']);
    $generator = $this->buildGenerator($transport, [], cache: $cache);

    $result = $generator->generate('How do I apply?', $this->buildResponse());

    $this->assertTrue($result['used']);
    $this->assertTrue($result['cache_hit']);
    $this->assertSame('generated_cached', $result['reason']);
    $this->assertSame([], $transport->calls);
  }

  /**
   * Builds a generator with real pipeline/filters and a scripted transport.
   */
  private function buildGenerator(
    GroundedGenerationTransportInterface $transport,
    array $configOverrides = [],
    bool $killSwitch = FALSE,
    ?CostControlPolicy $costControl = NULL,
    ?LlmCircuitBreaker $breaker = NULL,
    ?CacheBackendInterface $cache = NULL,
  ): GroundedAnswerGenerator {
    $configFactory = $this->buildConfigFactory($configOverrides);
    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, $default = NULL) use ($killSwitch) {
      return $key === GroundedAnswerGenerator::KILL_SWITCH_STATE_KEY ? $killSwitch : $default;
    });

    return new GroundedAnswerGenerator(
      $configFactory,
      new LlmRuntimeConfigResolver($configFactory),
      $transport,
      new LlmRequestPipeline($configFactory, $cache, $breaker, $costControl),
      new NullLogger(),
      $state,
      new PolicyFilter($configFactory),
      new ResponseGrounder(),
      $breaker,
    );
  }

  /**
   * Builds a config factory with generation enabled by default.
   */
  private function buildConfigFactory(array $overrides = []): ConfigFactoryInterface {
    $values = $overrides + [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 3600,
      'llm.max_retries' => 1,
      'llm.generation.enabled' => TRUE,
      'llm.generation.max_tokens' => 220,
      'llm.generation.temperature' => 0.2,
      'llm.generation.timeout_seconds' => 8.0,
      'llm.generation.max_documents' => 4,
      'llm.generation.snippet_chars' => 1200,
      'llm.generation.max_words' => 120,
      'llm.generation.citation_mode' => 'FAST',
      'llm.generation.cache_ttl' => 3600,
      'llm.generation.max_retries' => 0,
      'llm.generation.response_types' => ['faq', 'resources', 'search_results', 'eligibility'],
    ];
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * Builds a typical FAQ + resource result set.
   */
  private function buildResponse(): array {
    return [
      'type' => 'faq',
      'message' => 'Template answer.',
      'results' => [
        [
          'question' => 'How do I apply?',
          'answer' => '<p>You can apply for help online or by calling the <strong>Legal Advice Line</strong>.</p>',
          'answer_snippet' => 'You can apply for help online…',
          'title' => 'How do I apply?',
          'url' => '/faq/apply',
          'anchor' => 'how-to-apply',
        ],
        [
          'title' => 'Intake hours',
          'url' => '/resources/intake-hours',
          'description' => 'Intake is open Monday through Wednesday. Staff will ask about your income and your legal problem.',
          'score' => 12.5,
        ],
      ],
    ];
  }

}

/**
 * Scripted grounded transport for tests.
 */
final class ScriptedGroundedTransport implements GroundedGenerationTransportInterface {

  /**
   * Captured calls.
   *
   * @var array<int, array<string, mixed>>
   */
  public array $calls = [];

  /**
   * Constructs the scripted transport.
   *
   * @param array<string, mixed>|\Throwable $completion
   *   Scripted completion or throwable.
   */
  public function __construct(private readonly array|\Throwable $completion) {}

  /**
   * {@inheritdoc}
   */
  public function completeGrounded(array $messages, array $documents, array $options = []): array {
    $this->calls[] = ['messages' => $messages, 'documents' => $documents, 'options' => $options];
    if ($this->completion instanceof \Throwable) {
      throw $this->completion;
    }
    return $this->completion + ['text' => '', 'citations' => [], 'finish_reason' => NULL, 'usage' => []];
  }

}
