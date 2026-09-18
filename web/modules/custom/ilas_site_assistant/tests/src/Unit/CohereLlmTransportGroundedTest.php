<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\CohereLlmTransport;
use Drupal\ilas_site_assistant\Service\GroundedGenerationTransportInterface;
use Drupal\ilas_site_assistant\Service\LlmRuntimeConfigResolver;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the Cohere grounded (document-cited) generation call.
 */
#[Group('ilas_site_assistant')]
final class CohereLlmTransportGroundedTest extends TestCase {

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
   * The grounded request sends documents and citation options, never a schema.
   */
  public function testGroundedRequestSendsDocumentsWithoutResponseFormat(): void {
    $captured = NULL;
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): Response {
        $captured = ['method' => $method, 'url' => $url, 'options' => $options];
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
          'finish_reason' => 'COMPLETE',
          'message' => [
            'content' => [
              ['type' => 'text', 'text' => 'You can apply online or call the Legal Advice Line.'],
              ['type' => 'text', 'text' => 'Intake hours are Monday to Wednesday.'],
            ],
            'citations' => [
              [
                'start' => 0,
                'end' => 20,
                'text' => 'You can apply online',
                'sources' => [
                  ['type' => 'document', 'id' => 'doc_0:0', 'document' => ['id' => 'doc_0', 'title' => 'Apply']],
                ],
              ],
              [
                'start' => 55,
                'end' => 80,
                'text' => 'Intake hours',
                'sources' => [
                  ['type' => 'tool', 'id' => 'tool-1'],
                  ['type' => 'document', 'id' => 'doc_1'],
                  ['type' => 'document', 'id' => 'doc_1'],
                ],
              ],
              'not-an-array',
            ],
          ],
          'usage' => ['tokens' => ['input_tokens' => 900, 'output_tokens' => 40]],
        ], JSON_THROW_ON_ERROR));
      });

    $transport = new CohereLlmTransport($client, $this->buildResolver());
    $this->assertInstanceOf(GroundedGenerationTransportInterface::class, $transport);

    $result = $transport->completeGrounded(
      [
        ['role' => 'system', 'content' => 'Answer only from the documents.'],
        ['role' => 'user', 'content' => 'How do I apply?'],
      ],
      [
        ['id' => 'doc_0', 'data' => ['title' => 'Apply', 'snippet' => 'Apply online.', 'url' => '/apply']],
        ['id' => 'doc_1', 'data' => ['title' => 'Hours', 'snippet' => 'Mon-Wed.', 'url' => '/hours']],
      ],
      ['max_tokens' => 200, 'temperature' => 0.1, 'citation_mode' => 'accurate', 'timeout' => 6.5],
    );

    $this->assertSame('POST', $captured['method']);
    $this->assertSame('https://api.cohere.com/v2/chat', $captured['url']);
    $json = $captured['options']['json'];
    $this->assertArrayNotHasKey('response_format', $json);
    $this->assertSame('command-a-03-2025', $json['model']);
    $this->assertCount(2, $json['documents']);
    $this->assertSame('doc_1', $json['documents'][1]['id']);
    $this->assertSame(['mode' => 'ACCURATE'], $json['citation_options']);
    $this->assertSame(200, $json['max_tokens']);
    $this->assertSame(0.1, $json['temperature']);
    $this->assertSame('CONTEXTUAL', $json['safety_mode']);
    $this->assertFalse($json['stream']);
    $this->assertSame(6.5, $captured['options']['timeout']);
    $this->assertSame(2.0, $captured['options']['connect_timeout']);
    $this->assertSame('Bearer cohere-secret-value', $captured['options']['headers']['Authorization']);

    $this->assertSame(
      "You can apply online or call the Legal Advice Line.\nIntake hours are Monday to Wednesday.",
      $result['text'],
    );
    $this->assertSame('COMPLETE', $result['finish_reason']);
    $this->assertSame(['input' => 900, 'output' => 40, 'total' => 940], $result['usage']);
    $this->assertCount(2, $result['citations']);
    $this->assertSame(['doc_0'], $result['citations'][0]['document_ids'], 'document.id wins over the source id');
    $this->assertSame(['doc_1'], $result['citations'][1]['document_ids'], 'tool sources are ignored and ids are deduped');
    $this->assertSame(55, $result['citations'][1]['start']);
    $this->assertSame(80, $result['citations'][1]['end']);
  }

  /**
   * Unknown citation modes fall back to FAST and empty citations are tolerated.
   */
  public function testInvalidCitationModeFallsBackAndMissingCitationsAreEmpty(): void {
    $captured = NULL;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): Response {
        $captured = $options;
        return new Response(200, [], json_encode([
          'message' => ['content' => [['type' => 'text', 'text' => 'NO_ANSWER']]],
        ], JSON_THROW_ON_ERROR));
      });

    $transport = new CohereLlmTransport($client, $this->buildResolver());
    $result = $transport->completeGrounded([['role' => 'user', 'content' => 'x']], [], ['citation_mode' => 'bogus']);

    $this->assertSame(['mode' => 'FAST'], $captured['json']['citation_options']);
    $this->assertSame(220, $captured['json']['max_tokens']);
    $this->assertSame(8.0, $captured['timeout']);
    $this->assertSame('NO_ANSWER', $result['text']);
    $this->assertSame([], $result['citations']);
    $this->assertNull($result['finish_reason']);
    $this->assertSame(['input' => 0, 'output' => 0, 'total' => 0], $result['usage']);
  }

  /**
   * Missing runtime key fails before any HTTP request.
   */
  public function testMissingApiKeyThrowsWithoutRequest(): void {
    new Settings(['hash_salt' => 'test-salt']);
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $transport = new CohereLlmTransport($client, $this->buildResolver());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Missing ILAS_COHERE_API_KEY runtime secret.');
    $transport->completeGrounded([['role' => 'user', 'content' => 'x']], []);
  }

  /**
   * Provider HTTP errors propagate as Guzzle exceptions without leaking the key.
   */
  public function testHttpErrorPropagatesWithoutLeakingSecret(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willThrowException(new ClientException(
        'Unauthorized',
        new Request('POST', 'https://api.cohere.com/v2/chat'),
        new Response(401, [], '{"message":"invalid api token"}'),
      ));

    $transport = new CohereLlmTransport($client, $this->buildResolver());

    try {
      $transport->completeGrounded([['role' => 'user', 'content' => 'x']], []);
      $this->fail('Expected ClientException.');
    }
    catch (ClientException $e) {
      $this->assertSame(401, $e->getResponse()->getStatusCode());
      $this->assertStringNotContainsString(
        'cohere-secret-value',
        $e->getMessage(),
      );
    }
  }

  /**
   * Builds a resolver with Cohere enabled and the default model.
   */
  private function buildResolver(): LlmRuntimeConfigResolver {
    $values = [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ];
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return new LlmRuntimeConfigResolver($factory);
  }

}
