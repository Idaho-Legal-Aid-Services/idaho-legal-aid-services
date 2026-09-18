<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Commands\CohereProbeCommands;
use Drupal\ilas_site_assistant\Service\CohereGenerationProbe;
use Drupal\ilas_site_assistant\Service\CohereLlmTransport;
use Drupal\ilas_site_assistant\Service\LlmRuntimeConfigResolver;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit coverage for the ilas:cohere-probe Drush command.
 */
#[Group('ilas_site_assistant')]
final class CohereProbeCommandsTest extends TestCase {

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
  public function testCommandPrintsJsonAndReturnsSuccessForExpectedProbe(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn($this->buildCohereResponse(CohereGenerationProbe::EXPECTED_TEXT));
    $command = new CohereProbeCommands($this->buildProbe($client, TRUE));
    $output = new BufferedOutput();
    $command->setOutput($output);

    $this->assertSame(0, $command->cohereProbe());
    $decoded = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($decoded['generation_probe_passed'] ?? FALSE);
    $this->assertArrayNotHasKey('api_key', $decoded);
  }

  /**
   *
   */
  public function testCommandReturnsFailureWhenDisabled(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $command = new CohereProbeCommands($this->buildProbe($client, FALSE));
    $command->setOutput(new BufferedOutput());

    $this->assertSame(1, $command->cohereProbe());
  }

  /**
   * The --grounded option routes to the grounded probe and its pass flag.
   */
  public function testGroundedOptionRunsGroundedProbe(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], json_encode([
      'message' => [
        'content' => [['type' => 'text', 'text' => 'The probe library opens at nine.']],
        'citations' => [
          [
            'start' => 0,
            'end' => 5,
            'text' => 'nine',
            'sources' => [['type' => 'document', 'id' => 'probe_doc_0']],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR)));
    $command = new CohereProbeCommands($this->buildProbe($client, TRUE, TRUE));
    $output = new BufferedOutput();
    $command->setOutput($output);

    $this->assertSame(0, $command->cohereProbe(['grounded' => TRUE]));
    $decoded = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($decoded['grounded_probe_passed'] ?? FALSE);
    $this->assertSame('grounded_answer', $decoded['operation'] ?? NULL);
  }

  /**
   * The --grounded option fails closed while generation is disabled.
   */
  public function testGroundedOptionFailsWhenGenerationDisabled(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $command = new CohereProbeCommands($this->buildProbe($client, TRUE, FALSE));
    $command->setOutput(new BufferedOutput());

    $this->assertSame(1, $command->cohereProbe(['grounded' => TRUE]));
  }

  /**
   *
   */
  private function buildProbe(ClientInterface $client, bool $enabled, bool $generationEnabled = FALSE): CohereGenerationProbe {
    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory($enabled, $generationEnabled));
    return new CohereGenerationProbe(
      $resolver,
      new CohereLlmTransport($client, $resolver),
      $this->buildState(),
    );
  }

  /**
   *
   */
  private function buildConfigFactory(bool $enabled, bool $generationEnabled = FALSE): ConfigFactoryInterface {
    $values = [
      'llm.enabled' => $enabled,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => $generationEnabled,
    ];
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
          ['type' => 'text', 'text' => $text],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
  }

}
