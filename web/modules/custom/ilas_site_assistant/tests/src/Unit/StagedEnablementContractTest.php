<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests proving the disabled-to-enabled toggle path is safe.
 *
 * Validates that observability services degrade gracefully when disabled,
 * missing credentials, or zero sample rate, and that install defaults
 * ship with all feature flags off.
 */
#[Group('ilas_site_assistant')]
class StagedEnablementContractTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Returns parsed install config.
   */
  private static function installConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml';
    self::assertFileExists($path, 'Install config YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Builds a LangfuseTracer with given config overrides.
   */
  private function buildTracer(array $overrides): LangfuseTracer {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => $overrides[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createStub(LoggerInterface::class);

    return new LangfuseTracer($configFactory, $logger);
  }

  /**
   * Tests that Langfuse disabled produces no payload.
   */
  public function testLangfuseDisabledProducesNoPayload(): void {
    $tracer = $this->buildTracer([
      'langfuse.enabled' => FALSE,
    ]);

    $this->assertFalse($tracer->isEnabled());
    $tracer->startTrace('test-request', 'test');
    $payload = $tracer->getTracePayload();
    $this->assertNull($payload);
  }

  /**
   * Tests that Langfuse enabled without credentials disables itself.
   */
  public function testLangfuseEnabledWithoutCredentialsDisables(): void {
    $tracer = $this->buildTracer([
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => '',
      'langfuse.secret_key' => '',
      'langfuse.sample_rate' => 1.0,
    ]);

    $this->assertFalse($tracer->isEnabled());
  }

  /**
   * Tests that Langfuse enabled with credentials produces a payload.
   */
  public function testLangfuseEnabledWithCredentialsProducesPayload(): void {
    $tracer = $this->buildTracer([
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.sample_rate' => 1.0,
    ]);

    $this->assertTrue($tracer->isEnabled());
    $tracer->startTrace('test-request', 'test');
    $tracer->endTrace('success');
    $payload = $tracer->getTracePayload();
    $this->assertNotNull($payload);
    $this->assertArrayHasKey('batch', $payload);
    $this->assertNotEmpty($payload['batch']);
  }

  /**
   * Tests that zero sample rate disables tracing.
   */
  public function testLangfuseSampleRateZeroDisablesAll(): void {
    $tracer = $this->buildTracer([
      'langfuse.enabled' => TRUE,
      'langfuse.public_key' => 'pk-test-123',
      'langfuse.secret_key' => 'sk-test-456',
      'langfuse.sample_rate' => 0.0,
    ]);

    // With 0% sample rate, traces should be sampled out.
    $tracer->startTrace('test-request', 'test');
    $tracer->endTrace('success');
    $payload = $tracer->getTracePayload();
    // Either NULL (sampled out entirely) or empty batch.
    $hasBatch = $payload !== NULL && !empty($payload['batch'] ?? []);
    $this->assertFalse($hasBatch, 'Zero sample rate should produce no batch events');
  }

  /**
   * Tests that SentryOptionsSubscriber returns empty events without Raven.
   */
  public function testSentrySubscriberNoopWithoutRaven(): void {
    if (class_exists('Drupal\raven\Event\OptionsAlter')) {
      $this->markTestSkipped('Raven is installed; cannot test no-Raven path.');
    }

    $events = SentryOptionsSubscriber::getSubscribedEvents();
    $this->assertEmpty($events, 'Without Raven, subscriber must return empty event map');
  }

  /**
   * Tests that all observability toggles default to disabled in install YAML.
   */
  public function testAllObservabilityTogglesDefaultDisabled(): void {
    $install = self::installConfig();

    $this->assertFalse($install['langfuse']['enabled'] ?? NULL, 'langfuse.enabled must be false in install');
    $this->assertFalse($install['safety_alerting']['enabled'] ?? NULL, 'safety_alerting.enabled must be false in install');
  }

  /**
   * Tests that the SLO config block has all expected keys with correct types.
   */
  public function testSloConfigBlockHasAllExpectedKeys(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('slo', $install, 'Install YAML must have slo block');

    $slo = $install['slo'];
    $expectedKeys = [
      'availability_target_pct' => 'double',
      'latency_p95_target_ms' => 'integer',
      'latency_p99_target_ms' => 'integer',
      'error_rate_target_pct' => 'double',
      'error_budget_window_hours' => 'integer',
      'cron_max_age_seconds' => 'integer',
      'cron_expected_cadence_seconds' => 'integer',
      'queue_max_depth' => 'integer',
      'queue_max_age_seconds' => 'integer',
    ];

    foreach ($expectedKeys as $key => $expectedType) {
      $this->assertArrayHasKey($key, $slo, "SLO block missing key: {$key}");
      $actualType = gettype($slo[$key]);
      $this->assertSame(
        $expectedType,
        $actualType,
        "SLO key '{$key}' has type '{$actualType}', expected '{$expectedType}'",
      );
    }
  }

}
