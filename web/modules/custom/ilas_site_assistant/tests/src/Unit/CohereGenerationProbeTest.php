<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\CohereGenerationProbe;
use Drupal\ilas_site_assistant\Service\CohereLlmTransport;
use Drupal\ilas_site_assistant\Service\LlmRuntimeConfigResolver;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the safe Cohere exact-output probe.
 */
#[Group('ilas_site_assistant')]
final class CohereGenerationProbeTest extends TestCase {

  /**
   *
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   *
   */
  public function testProbeSucceedsOnExactExpectedContent(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
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
      ->willReturn($this->buildCohereResponse(CohereGenerationProbe::EXPECTED_TEXT));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]);
    $result = $probe->probe();

    $this->assertTrue($result['generation_attempted']);
    $this->assertTrue($result['request_time_generation_reachable']);
    $this->assertTrue($result['generation_probe_passed']);
    $this->assertSame('command-a-03-2025', $captured['json']['model'] ?? NULL);
    $this->assertSame('Bearer cohere-secret-value', $captured['headers']['Authorization'] ?? NULL);

    $json = json_encode($result, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('cohere-secret-value', $json);
  }

  /**
   *
   */
  public function testProbeDoesNotCallProviderWhenDisabled(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $probe = $this->buildProbe($client, [
      'llm.enabled' => FALSE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]);
    $result = $probe->probe();

    $this->assertFalse($result['generation_attempted']);
    $this->assertFalse($result['generation_probe_passed']);
    $this->assertSame('disabled', $result['reason']);
  }

  /**
   *
   */
  public function testProbeReportsUnexpectedContentAsFailureWithoutLeakingResponse(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn($this->buildCohereResponse('different'));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]);
    $result = $probe->probe();

    $this->assertTrue($result['request_time_generation_reachable']);
    $this->assertFalse($result['generation_probe_passed']);
    $this->assertSame('unexpected_content', $result['reason']);
    $this->assertSame('UnexpectedProbeContent', $result['last_error']['class'] ?? NULL);
    $this->assertStringNotContainsString('different', json_encode($result, JSON_THROW_ON_ERROR));
  }

  /**
   *
   */
  public function testProbeDoesNotCallProviderWhenProviderIsNotCohere(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'other-provider',
      'llm.model' => 'command-a-03-2025',
    ]);
    $result = $probe->probe();

    $this->assertFalse($result['generation_attempted']);
    $this->assertSame('provider_not_cohere', $result['reason']);
  }

  /**
   *
   */
  public function testReadinessSummaryFallsBackToDefaultModelWhenStoredModelIsBlank(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => '',
    ]);
    $summary = $probe->getReadinessSummary();

    $this->assertSame('command-a-03-2025', $summary['model'] ?? NULL);
    $this->assertTrue($summary['runtime_ready'] ?? FALSE);
  }

  /**
   *
   */
  public function testProbeSanitizesTransportErrors(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(new RequestException(
      'Authorization: Bearer cohere-secret-value failed',
      new Request('POST', 'https://api.cohere.com/v2/chat'),
      new Response(401),
    ));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]);
    $result = $probe->probe();

    $this->assertFalse($result['request_time_generation_reachable']);
    $this->assertFalse($result['generation_probe_passed']);
    $this->assertSame(RequestException::class, $result['last_error']['class'] ?? NULL);
    $this->assertStringNotContainsString('cohere-secret-value', json_encode($result, JSON_THROW_ON_ERROR));
  }

  /**
   *
   */
  public function testReadinessSummaryReplaysLastStoredProbeState(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn($this->buildCohereResponse(CohereGenerationProbe::EXPECTED_TEXT));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]);
    $probe->probe();
    $summary = $probe->getReadinessSummary();

    $this->assertTrue($summary['generation_attempted'] ?? FALSE);
    $this->assertTrue($summary['request_time_generation_reachable'] ?? FALSE);
    $this->assertTrue($summary['generation_probe_passed'] ?? FALSE);
    $this->assertSame('cohere', $summary['provider'] ?? NULL);
  }

  /**
   * The grounded probe sends synthetic documents and passes on a cited answer.
   */
  public function testGroundedProbePassesOnCitedAnswerAndStoresSummary(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
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
        'finish_reason' => 'COMPLETE',
        'message' => [
          'content' => [['type' => 'text', 'text' => 'The probe library opens at nine in the morning on weekdays.']],
          'citations' => [
            [
              'start' => 0,
              'end' => 20,
              'text' => 'opens at nine',
              'sources' => [['type' => 'document', 'id' => 'probe_doc_0:0']],
            ],
          ],
        ],
        'usage' => ['tokens' => ['input_tokens' => 120, 'output_tokens' => 14]],
      ], JSON_THROW_ON_ERROR)));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => TRUE,
    ]);
    $result = $probe->probeGrounded();

    $this->assertTrue($result['generation_attempted']);
    $this->assertTrue($result['grounded_probe_passed']);
    $this->assertSame('grounded_content', $result['reason']);
    $this->assertSame(1, $result['citation_count']);
    $this->assertSame('grounded_answer', $result['operation']);
    $this->assertArrayNotHasKey('response_format', $captured['json']);
    $this->assertSame(['probe_doc_0', 'probe_doc_1'], array_column($captured['json']['documents'], 'id'));
    $this->assertSame(['mode' => 'FAST'], $captured['json']['citation_options']);

    $summary = $probe->getGroundedReadinessSummary();
    $this->assertTrue($summary['generation_runtime_ready']);
    $this->assertTrue($summary['grounded_probe_attempted']);
    $this->assertTrue($summary['grounded_probe_passed']);
    $this->assertSame(1, $summary['grounded_citation_count']);

    // The classification probe state is untouched.
    $this->assertFalse($probe->getReadinessSummary()['generation_attempted']);
    $this->assertStringNotContainsString('cohere-secret-value', json_encode($result, JSON_THROW_ON_ERROR));
  }

  /**
   * The grounded probe never calls the provider while generation is disabled.
   */
  public function testGroundedProbeDoesNotCallProviderWhenGenerationDisabled(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => FALSE,
    ]);
    $result = $probe->probeGrounded();

    $this->assertFalse($result['generation_attempted']);
    $this->assertFalse($result['grounded_probe_passed']);
    $this->assertSame('generation_disabled', $result['reason']);
  }

  /**
   * A refusal sentinel fails the grounded probe without an exception.
   */
  public function testGroundedProbeFailsOnRefusalSentinel(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn($this->buildCohereResponse('NO_ANSWER'));

    $probe = $this->buildProbe($client, [
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => TRUE,
    ]);
    $result = $probe->probeGrounded();

    $this->assertTrue($result['reachable']);
    $this->assertFalse($result['grounded_probe_passed']);
    $this->assertSame('refusal_sentinel', $result['reason']);
  }

  /**
   * @param array<string, mixed> $configValues
   */
  private function buildProbe(ClientInterface $client, array $configValues): CohereGenerationProbe {
    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory($configValues));
    return new CohereGenerationProbe(
      $resolver,
      new CohereLlmTransport($client, $resolver),
      $this->buildState(),
    );
  }

  /**
   *
   */
  private function buildConfigFactory(array $values): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $factory;
  }

  /**
   *
   */
  private function buildState(): StateInterface {
    return new class() implements StateInterface {
      private array $storage = [];

      /**
       *
       */
      public function get($key, $default = NULL): mixed {
        return $this->storage[$key] ?? $default;
      }

      /**
       *
       */
      public function getMultiple(array $keys): array {
        $values = [];
        foreach ($keys as $key) {
          if (array_key_exists($key, $this->storage)) {
            $values[$key] = $this->storage[$key];
          }
        }
        return $values;
      }

      /**
       *
       */
      public function set($key, $value = NULL) {
        $this->storage[$key] = $value;
        return $this;
      }

      /**
       *
       */
      public function setMultiple(array $data) {
        foreach ($data as $key => $value) {
          $this->storage[$key] = $value;
        }
        return $this;
      }

      /**
       *
       */
      public function delete($key) {
        unset($this->storage[$key]);
        return TRUE;
      }

      /**
       *
       */
      public function deleteMultiple(array $keys) {
        foreach ($keys as $key) {
          unset($this->storage[$key]);
        }
        return TRUE;
      }

      /**
       *
       */
      public function resetCache() {}

      /**
       *
       */
      public function getValuesSetDuringRequest(string $key): ?array {
        $value = $this->storage[$key] ?? NULL;
        return is_array($value) ? $value : NULL;
      }

    };
  }

  /**
   *
   */
  private function buildCohereResponse(string $text): Response {
    return new Response(200, [], json_encode([
      'message' => [
        'content' => [
          [
            'type' => 'text',
            'text' => $text,
          ],
        ],
      ],
      'usage' => [
        'tokens' => [
          'input_tokens' => 6,
          'output_tokens' => 4,
        ],
      ],
    ], JSON_THROW_ON_ERROR));
  }

}
