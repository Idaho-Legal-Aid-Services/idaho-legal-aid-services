<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\Commands\LangfuseProbeCommands;
use Drupal\ilas_site_assistant\Service\LangfusePayloadContract;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use Drush\Log\DrushLoggerManager;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests for the Langfuse probe command (PHARD-02).
 *
 * Validates that the probe command exists, produces deterministic PII-free
 * payloads matching the approved Langfuse ingestion format, and that the
 * Drush service entry is correctly registered.
 *
 */
#[Group('ilas_site_assistant')]
class LangfuseProbeCommandTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Probe command class exists with the expected method.
   */
  public function testProbeCommandClassAndMethodExist(): void {
    $this->assertTrue(
      class_exists(LangfuseProbeCommands::class),
      'LangfuseProbeCommands class must exist',
    );
    $this->assertTrue(
      method_exists(LangfuseProbeCommands::class, 'langfuseProbe'),
      'LangfuseProbeCommands must have langfuseProbe method',
    );
    $this->assertTrue(
      method_exists(LangfuseProbeCommands::class, 'buildSyntheticBatch'),
      'LangfuseProbeCommands must have buildSyntheticBatch method',
    );
  }

  /**
   * Synthetic batch is deterministic and PII-free.
   */
  public function testSyntheticBatchIsDeterministicAndPiiFree(): void {
    $stub = $this->buildProbeCommandStub();
    $result = $stub->buildSyntheticBatch();

    $this->assertArrayHasKey('trace_id', $result);
    $this->assertArrayHasKey('payload', $result);
    $this->assertNotEmpty($result['trace_id']);

    $payload = $result['payload'];
    $this->assertArrayHasKey('batch', $payload);
    $this->assertArrayHasKey('metadata', $payload);

    // PII regex patterns.
    $piiPatterns = [
      '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',  // email
      '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',                     // phone
      '/\b\d{3}-\d{2}-\d{4}\b/',                              // SSN
      '/\b(?:4\d{12}(?:\d{3})?|5[1-5]\d{14})\b/',             // CC
    ];

    $json = json_encode($payload);
    foreach ($piiPatterns as $pattern) {
      $this->assertDoesNotMatchRegularExpression(
        $pattern,
        $json,
        "Synthetic batch must not contain PII matching: {$pattern}",
      );
    }
  }

  /**
   * Synthetic payload matches Langfuse ingestion format using contract constants.
   */
  public function testSyntheticPayloadMatchesLangfuseIngestionFormat(): void {
    $stub = $this->buildProbeCommandStub();
    $result = $stub->buildSyntheticBatch();
    $payload = $result['payload'];

    // Validate metadata keys.
    foreach (LangfusePayloadContract::REQUIRED_METADATA_KEYS as $key) {
      $this->assertArrayHasKey(
        $key,
        $payload['metadata'],
        "Payload metadata must contain key: {$key}",
      );
    }

    // Validate SDK name and version.
    $this->assertSame(
      LangfusePayloadContract::SDK_NAME,
      $payload['metadata']['sdk_name'],
    );
    $this->assertSame(
      LangfusePayloadContract::SDK_VERSION,
      $payload['metadata']['sdk_version'],
    );

    // Validate batch event types are all approved.
    $eventTypes = array_map(fn($e) => $e['type'], $payload['batch']);
    foreach ($eventTypes as $type) {
      $this->assertContains(
        $type,
        LangfusePayloadContract::APPROVED_EVENT_TYPES,
        "Event type '{$type}' must be in APPROVED_EVENT_TYPES",
      );
    }

    // Validate required event types for the probe are present.
    $this->assertContains('trace-create', $eventTypes);
    $this->assertContains('span-create', $eventTypes);
    $this->assertContains('event-create', $eventTypes);

    // Validate trace-create body has required keys.
    $traceCreate = $this->findBatchEvent($payload['batch'], 'trace-create');
    foreach (LangfusePayloadContract::REQUIRED_TRACE_BODY_KEYS as $key) {
      $this->assertArrayHasKey(
        $key,
        $traceCreate['body'],
        "trace-create body must contain key: {$key}",
      );
    }
    $this->assertArrayHasKey('input', $traceCreate['body']);
    $this->assertArrayHasKey('output', $traceCreate['body']);
    $this->assertNotSame('', $traceCreate['body']['input']);
    $this->assertNotSame('', $traceCreate['body']['output']);

    // Validate span-create body has required keys.
    $spanCreate = $this->findBatchEvent($payload['batch'], 'span-create');
    foreach (LangfusePayloadContract::REQUIRED_SPAN_BODY_KEYS as $key) {
      $this->assertArrayHasKey(
        $key,
        $spanCreate['body'],
        "span-create body must contain key: {$key}",
      );
    }

    // Validate batch_size matches actual batch count.
    $this->assertSame(
      count($payload['batch']),
      $payload['metadata']['batch_size'],
      'batch_size metadata must match actual batch count',
    );
  }

  /**
   * Queue mode enqueues the same top-level shape the worker expects.
   */
  public function testQueuedProbeUsesWorkerContractShape(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->exactly(2))
      ->method('numberOfItems')
      ->willReturnOnConsecutiveCalls(10, 11);
    $queue->expects($this->once())
      ->method('createItem')
      ->with($this->callback(function (array $item): bool {
        $this->assertArrayHasKey('batch', $item);
        $this->assertArrayHasKey('metadata', $item);
        $this->assertArrayHasKey('enqueued_at', $item);
        $this->assertArrayNotHasKey('payload', $item);
        return TRUE;
      }))
      ->willReturn(123);

    $queueFactory = $this->createStub(QueueFactory::class);
    $queueFactory->method('get')->with('ilas_langfuse_export')->willReturn($queue);

    $logger = $this->createMock(DrushLoggerManager::class);
    $successMessages = [];
    $noticeMessages = [];
    $logger->expects($this->once())
      ->method('success')
      ->willReturnCallback(function (string $message) use (&$successMessages): void {
        $successMessages[] = $message;
      });
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(function (string $message) use (&$noticeMessages): void {
        $noticeMessages[] = $message;
      });

    $command = $this->buildProbeCommandStub(
      queueFactory: $queueFactory,
      logger: $logger,
    );

    $result = $command->langfuseProbe();

    $this->assertSame(0, $result);
    $this->assertCount(1, $successMessages);
    $this->assertStringContainsString('Langfuse probe enqueued.', $successMessages[0]);
    $this->assertSame(['Queue depth: 10 -> 11'], $noticeMessages);
  }

  /**
   * Direct mode uses exporter defaults and reports partial success details.
   */
  public function testDirectProbeUsesExporterDefaultsAndLogsPartialSuccess(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://us.cloud.langfuse.com/api/public/ingestion',
        $this->callback(function (array $options): bool {
          $this->assertSame(7.5, $options['timeout']);
          $this->assertSame(7.5, $options['connect_timeout']);
          $this->assertSame(['pk-test-123', 'sk-test-456'], $options['auth']);
          $this->assertArrayHasKey('json', $options);
          return TRUE;
        }),
      )
      ->willReturn(new Response(207, [], json_encode([
        'successes' => [['id' => 'ok-1']],
        'errors' => [['id' => 'err-1'], ['id' => 'err-2']],
      ], JSON_THROW_ON_ERROR)));

    $logger = $this->createMock(DrushLoggerManager::class);
    $successMessages = [];
    $noticeMessages = [];
    $logger->expects($this->once())
      ->method('success')
      ->willReturnCallback(function (string $message) use (&$successMessages): void {
        $successMessages[] = $message;
      });
    $logger->expects($this->exactly(3))
      ->method('notice')
      ->willReturnCallback(function (string $message) use (&$noticeMessages): void {
        $noticeMessages[] = $message;
      });

    $command = $this->buildProbeCommandStub(
      configValues: [
        'langfuse.host' => NULL,
        'langfuse.timeout' => 7.5,
      ],
      httpClient: $httpClient,
      logger: $logger,
    );

    $result = $command->langfuseProbe(['direct' => TRUE]);

    $this->assertSame(0, $result);
    $this->assertCount(1, $successMessages);
    $this->assertStringContainsString('HTTP status: 207', $successMessages[0]);
    $this->assertContains('Langfuse direct probe partial success: 1 succeeded, 2 errors', $noticeMessages);
    $this->assertCount(3, $noticeMessages);
  }

  /**
   * Drush services entry exists and references correct class.
   */
  public function testDrushServicesEntryExists(): void {
    $servicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($servicesPath);

    $services = Yaml::parseFile($servicesPath);
    $this->assertArrayHasKey('services', $services);
    $this->assertArrayHasKey(
      'ilas_site_assistant.langfuse_probe_commands',
      $services['services'],
      'drush.services.yml must register langfuse_probe_commands service',
    );

    $entry = $services['services']['ilas_site_assistant.langfuse_probe_commands'];
    $this->assertSame(
      '\\' . LangfuseProbeCommands::class,
      $entry['class'],
      'Service class must reference LangfuseProbeCommands',
    );
  }

  /**
   * Finds the first batch event of a given type.
   */
  private function findBatchEvent(array $batch, string $type): array {
    foreach ($batch as $event) {
      if ($event['type'] === $type) {
        return $event;
      }
    }
    $this->fail("No batch event of type '{$type}' found");
  }

  /**
   * Probe guard message references secrets, not config edit instructions.
   */
  public function testProbeGuardMessageMentionsSecretsNotConfigEdit(): void {
    $logger = $this->createMock(DrushLoggerManager::class);
    $errorMessages = [];
    $logger->expects($this->once())
      ->method('error')
      ->willReturnCallback(function (string $message) use (&$errorMessages): void {
        $errorMessages[] = $message;
      });

    $command = $this->buildProbeCommandStub(
      configValues: [
        'langfuse.enabled' => FALSE,
      ],
      logger: $logger,
    );

    $result = $command->langfuseProbe();

    $this->assertSame(1, $result);
    $this->assertCount(1, $errorMessages);
    $this->assertStringContainsString('LANGFUSE_PUBLIC_KEY', $errorMessages[0]);
    $this->assertStringContainsString('LANGFUSE_SECRET_KEY', $errorMessages[0]);
    $this->assertStringNotContainsString('Set langfuse.enabled=true', $errorMessages[0]);
  }

  /**
   * Probe guard message suggests ilas:langfuse-status for diagnostics.
   */
  public function testProbeGuardMessageSuggestsLangfuseStatus(): void {
    $logger = $this->createMock(DrushLoggerManager::class);
    $errorMessages = [];
    $logger->expects($this->once())
      ->method('error')
      ->willReturnCallback(function (string $message) use (&$errorMessages): void {
        $errorMessages[] = $message;
      });

    $command = $this->buildProbeCommandStub(
      configValues: [
        'langfuse.enabled' => FALSE,
      ],
      logger: $logger,
    );

    $command->langfuseProbe();

    $this->assertStringContainsString('ilas:langfuse-status', $errorMessages[0]);
  }

  /**
   * Credential-absent guard references environment variables.
   */
  public function testProbeCredentialGuardMentionsEnvironmentVariables(): void {
    $logger = $this->createMock(DrushLoggerManager::class);
    $errorMessages = [];
    $logger->expects($this->once())
      ->method('error')
      ->willReturnCallback(function (string $message) use (&$errorMessages): void {
        $errorMessages[] = $message;
      });

    $command = $this->buildProbeCommandStub(
      configValues: [
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
      ],
      logger: $logger,
    );

    $result = $command->langfuseProbe();

    $this->assertSame(1, $result);
    $this->assertStringContainsString('Pantheon secrets or environment variables', $errorMessages[0]);
    $this->assertStringContainsString('ilas:langfuse-status', $errorMessages[0]);
  }

  /**
   * Diagnose option outputs readiness JSON with verdict without probing.
   */
  public function testDiagnoseOptionOutputsReadinessJson(): void {
    $command = $this->buildProbeCommandStub();

    ob_start();
    $result = $command->langfuseProbe(['direct' => FALSE, 'diagnose' => TRUE]);
    $output = ob_get_clean();

    $this->assertSame(0, $result);

    $decoded = json_decode($output, TRUE);
    $this->assertIsArray($decoded);
    $this->assertArrayHasKey('verdict', $decoded);
    $this->assertArrayHasKey('stored_enabled', $decoded);
    $this->assertArrayHasKey('effective_enabled', $decoded);
    $this->assertArrayHasKey('public_key_present', $decoded);
    $this->assertArrayHasKey('secret_key_present', $decoded);
    $this->assertArrayHasKey('environment', $decoded);
    $this->assertArrayHasKey('sample_rate', $decoded);
    $this->assertArrayHasKey('queue', $decoded);
    $this->assertArrayHasKey('suggestion', $decoded);
    $this->assertSame('READY', $decoded['verdict']);
    $this->assertNull($decoded['suggestion']);
  }

  /**
   * Diagnose reports DISABLED_NO_SECRETS when Langfuse is runtime-disabled.
   */
  public function testDiagnoseReportsDisabledWhenNoSecrets(): void {
    $command = $this->buildProbeCommandStub(
      configValues: [
        'langfuse.enabled' => FALSE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
      ],
    );

    ob_start();
    $result = $command->langfuseProbe(['direct' => FALSE, 'diagnose' => TRUE]);
    $output = ob_get_clean();

    $this->assertSame(0, $result);

    $decoded = json_decode($output, TRUE);
    $this->assertSame('DISABLED_NO_SECRETS', $decoded['verdict']);
    $this->assertFalse($decoded['effective_enabled']);
    $this->assertNotNull($decoded['suggestion']);
    $this->assertStringContainsString('LANGFUSE_PUBLIC_KEY', $decoded['suggestion']);
  }

  /**
   * Builds a LangfuseProbeCommands instance with stubbed dependencies.
   */
  private function buildProbeCommandStub(
    array $configValues = [],
    ?QueueFactory $queueFactory = NULL,
    ?ClientInterface $httpClient = NULL,
    ?DrushLoggerManager $logger = NULL,
    ?RuntimeTruthSnapshotBuilder $snapshotBuilder = NULL,
    ?QueueHealthMonitor $queueHealthMonitor = NULL,
    ?SloDefinitions $sloDefinitions = NULL,
  ): TestableLangfuseProbeCommands {
    $values = $configValues + [
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.host' => 'https://us.cloud.langfuse.com',
      'langfuse.sample_rate' => 1.0,
      'langfuse.timeout' => 5.0,
      'langfuse.environment' => 'local',
    ];

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $queueFactory ??= $this->createStub(QueueFactory::class);
    $httpClient ??= $this->createStub(ClientInterface::class);

    $command = new TestableLangfuseProbeCommands(
      $configFactory,
      $queueFactory,
      $httpClient,
      $snapshotBuilder,
      $queueHealthMonitor,
      $sloDefinitions,
    );
    $command->testLogger = $logger;

    return $command;
  }

}

final class TestableLangfuseProbeCommands extends LangfuseProbeCommands {

  /**
   * Test logger override.
   */
  public ?DrushLoggerManager $testLogger = NULL;

  /**
   * {@inheritdoc}
   */
  public function logger(): ?DrushLoggerManager {
    return $this->testLogger;
  }

}
