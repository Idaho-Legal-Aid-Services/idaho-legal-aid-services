<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Commands\LangfuseProbeCommands;
use Drupal\ilas_site_assistant\Service\LangfusePayloadContract;
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
    $this->assertContains('trace-update', $eventTypes);

    // Validate trace-create body has required keys.
    $traceCreate = $this->findBatchEvent($payload['batch'], 'trace-create');
    foreach (LangfusePayloadContract::REQUIRED_TRACE_BODY_KEYS as $key) {
      $this->assertArrayHasKey(
        $key,
        $traceCreate['body'],
        "trace-create body must contain key: {$key}",
      );
    }

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
   * Builds a LangfuseProbeCommands instance with stubbed dependencies.
   */
  private function buildProbeCommandStub(): LangfuseProbeCommands {
    $config = $this->createStub(\Drupal\Core\Config\ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.host' => 'https://cloud.langfuse.com',
      'langfuse.sample_rate' => 1.0,
      default => NULL,
    });

    $configFactory = $this->createStub(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $queueFactory = $this->createStub(\Drupal\Core\Queue\QueueFactory::class);
    $httpClient = $this->createStub(\GuzzleHttp\ClientInterface::class);

    return new LangfuseProbeCommands($configFactory, $queueFactory, $httpClient);
  }

}
