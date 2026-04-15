<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\CohereLlmTransport;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Focused transport tests for Cohere runtime authentication and parsing.
 */
#[Group('ilas_site_assistant')]
final class LlmEnhancerApiKeyTest extends TestCase {

  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  public function testCohereTransportSendsBearerHeaderAndParsesJsonPayload(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-test-key',
      'hash_salt' => 'test-salt',
    ]);

    $captured = [];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cohere.com/v2/chat',
        $this->callback(static function (array $options) use (&$captured): bool {
          $captured = $options;
          return TRUE;
        }),
      )
      ->willReturn(new Response(200, [], json_encode([
        'message' => [
          'content' => [
            [
              'type' => 'text',
              'text' => '{"intent":"faq"}',
            ],
          ],
        ],
        'usage' => [
          'tokens' => [
            'input_tokens' => 11,
            'output_tokens' => 4,
          ],
        ],
      ], JSON_THROW_ON_ERROR)));

    $transport = new CohereLlmTransport($client);
    $result = $transport->completeStructuredJson(
      [
        ['role' => 'system', 'content' => 'Return JSON only.'],
        ['role' => 'user', 'content' => 'faq'],
      ],
      [
        'name' => 'assistant_intent_response',
        'schema' => [
          'type' => 'object',
          'properties' => [
            'intent' => ['type' => 'string'],
          ],
          'required' => ['intent'],
        ],
      ],
      ['max_tokens' => 32, 'temperature' => 0.1],
    );

    $this->assertSame('Bearer cohere-test-key', $captured['headers']['Authorization'] ?? NULL);
    $this->assertSame('ilas-site-assistant', $captured['headers']['X-Client-Name'] ?? NULL);
    $this->assertSame('json_object', $captured['json']['response_format']['type'] ?? NULL);
    $this->assertSame('faq', $result['payload']['intent'] ?? NULL);
    $this->assertSame(['input' => 11, 'output' => 4, 'total' => 15], $result['usage'] ?? NULL);
  }

  public function testCohereTransportAcceptsFencedJsonResponses(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-test-key',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, [], json_encode([
        'message' => [
          'content' => [
            [
              'type' => 'text',
              'text' => "```json\n{\"intent\":\"guides\"}\n```",
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR)));

    $transport = new CohereLlmTransport($client);
    $result = $transport->completeStructuredJson(
      [['role' => 'user', 'content' => 'guides']],
      [
        'name' => 'assistant_intent_response',
        'schema' => ['type' => 'object'],
      ],
    );

    $this->assertSame('guides', $result['payload']['intent'] ?? NULL);
  }

}
