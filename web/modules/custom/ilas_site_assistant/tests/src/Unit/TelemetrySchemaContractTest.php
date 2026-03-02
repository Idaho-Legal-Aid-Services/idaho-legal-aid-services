<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\TelemetrySchema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for TelemetrySchema field consistency.
 *
 * Ensures the telemetry schema value object remains the single source of
 * truth for field names used by Langfuse metadata, Sentry tags, and
 * Drupal log context.
 *
 * @group ilas_site_assistant
 */
#[Group('ilas_site_assistant')]
class TelemetrySchemaContractTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Tests that REQUIRED_FIELDS contains exactly 5 expected field names.
   */
  public function testRequiredFieldsContainsExactlyFiveFields(): void {
    $this->assertCount(5, TelemetrySchema::REQUIRED_FIELDS);

    $expected = [
      TelemetrySchema::FIELD_INTENT,
      TelemetrySchema::FIELD_SAFETY_CLASS,
      TelemetrySchema::FIELD_FALLBACK_PATH,
      TelemetrySchema::FIELD_REQUEST_ID,
      TelemetrySchema::FIELD_ENV,
    ];

    $this->assertSame($expected, TelemetrySchema::REQUIRED_FIELDS);
  }

  /**
   * Tests that normalize() returns all required keys with non-empty values.
   */
  public function testNormalizeReturnsAllRequiredKeysWithValues(): void {
    $result = TelemetrySchema::normalize(
      intent: 'faq',
      safety_class: 'safe',
      fallback_path: 'none',
      request_id: 'test-123',
      env: 'dev',
    );

    foreach (TelemetrySchema::REQUIRED_FIELDS as $field) {
      $this->assertArrayHasKey($field, $result, "Missing required field: {$field}");
      $this->assertNotEmpty($result[$field], "Field '{$field}' must not be empty");
    }

    $this->assertSame('faq', $result[TelemetrySchema::FIELD_INTENT]);
    $this->assertSame('safe', $result[TelemetrySchema::FIELD_SAFETY_CLASS]);
    $this->assertSame('none', $result[TelemetrySchema::FIELD_FALLBACK_PATH]);
    $this->assertSame('test-123', $result[TelemetrySchema::FIELD_REQUEST_ID]);
    $this->assertSame('dev', $result[TelemetrySchema::FIELD_ENV]);
  }

  /**
   * Tests that normalize() provides safe defaults for null inputs.
   */
  public function testNormalizeDefaultsForNullInputs(): void {
    $result = TelemetrySchema::normalize();

    $this->assertSame('unknown', $result[TelemetrySchema::FIELD_INTENT]);
    $this->assertSame('safe', $result[TelemetrySchema::FIELD_SAFETY_CLASS]);
    $this->assertSame('none', $result[TelemetrySchema::FIELD_FALLBACK_PATH]);
    $this->assertSame('unknown', $result[TelemetrySchema::FIELD_REQUEST_ID]);
    // ENV defaults to PANTHEON_ENVIRONMENT or 'local'.
    $expected_env = getenv('PANTHEON_ENVIRONMENT') ?: 'local';
    $this->assertSame($expected_env, $result[TelemetrySchema::FIELD_ENV]);
  }

  /**
   * Tests that AssistantApiController references all TelemetrySchema constants.
   */
  public function testControllerReferencesAllTelemetrySchemaConstants(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/Controller/AssistantApiController.php'
    );

    $this->assertStringContainsString(
      'TelemetrySchema::FIELD_INTENT',
      $source,
      'Controller must reference TelemetrySchema::FIELD_INTENT',
    );
    $this->assertStringContainsString(
      'TelemetrySchema::FIELD_SAFETY_CLASS',
      $source,
      'Controller must reference TelemetrySchema::FIELD_SAFETY_CLASS',
    );
    $this->assertStringContainsString(
      'TelemetrySchema::FIELD_FALLBACK_PATH',
      $source,
      'Controller must reference TelemetrySchema::FIELD_FALLBACK_PATH',
    );
    $this->assertStringContainsString(
      'TelemetrySchema::FIELD_REQUEST_ID',
      $source,
      'Controller must reference TelemetrySchema::FIELD_REQUEST_ID',
    );
    $this->assertStringContainsString(
      'TelemetrySchema::FIELD_ENV',
      $source,
      'Controller must reference TelemetrySchema::FIELD_ENV',
    );
    $this->assertStringContainsString(
      'TelemetrySchema::normalize(',
      $source,
      'Controller must call TelemetrySchema::normalize()',
    );
  }

  /**
   * Tests that SentryOptionsSubscriber references TelemetrySchema::REQUIRED_FIELDS.
   */
  public function testSentrySubscriberReferencesTelemetrySchemaRequiredFields(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/src/EventSubscriber/SentryOptionsSubscriber.php'
    );

    $this->assertStringContainsString(
      'TelemetrySchema::REQUIRED_FIELDS',
      $source,
      'SentryOptionsSubscriber must reference TelemetrySchema::REQUIRED_FIELDS',
    );
  }

}
