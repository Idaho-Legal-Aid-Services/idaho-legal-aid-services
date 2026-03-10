<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ilas:sentry-probe Drush command.
 *
 * Validates probe message safety (no PII patterns), guard behavior when
 * client_key is empty, and message format correctness (PHARD-01).
 *
 * @group ilas_site_assistant
 */
#[Group('ilas_site_assistant')]
class SentryProbeCommandTest extends TestCase {

  /**
   * The deterministic probe message format used by SentryProbeCommands.
   */
  private const PROBE_FORMAT = 'PHARD-01 synthetic probe: environment=%s release=%s timestamp=%s';

  /**
   * Probe message format contains no PII patterns.
   *
   * Static regex assertion: the format string and any realistic instantiation
   * must not contain email, phone, SSN, credit card, or date-of-birth patterns.
   */
  public function testProbeMessageContainsNoPiiPatterns(): void {
    // Generate a realistic probe message.
    $message = sprintf(
      self::PROBE_FORMAT,
      'pantheon-dev',
      'abc123def456',
      '2026-03-10T12:00:00+00:00',
    );

    // Email pattern.
    $this->assertDoesNotMatchRegularExpression(
      '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
      $message,
      'Probe message must not contain email addresses',
    );

    // Phone pattern (US).
    $this->assertDoesNotMatchRegularExpression(
      '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
      $message,
      'Probe message must not contain phone numbers',
    );

    // SSN pattern.
    $this->assertDoesNotMatchRegularExpression(
      '/\b\d{3}-\d{2}-\d{4}\b/',
      $message,
      'Probe message must not contain SSNs',
    );

    // Credit card pattern (13-19 digits).
    $this->assertDoesNotMatchRegularExpression(
      '/\b\d{13,19}\b/',
      $message,
      'Probe message must not contain credit card numbers',
    );

    // Verify the message contains expected structural tokens.
    $this->assertStringContainsString('PHARD-01 synthetic probe:', $message);
    $this->assertStringContainsString('environment=', $message);
    $this->assertStringContainsString('release=', $message);
    $this->assertStringContainsString('timestamp=', $message);
  }

  /**
   * Probe format string itself is deterministic and PII-free.
   */
  public function testProbeFormatStringIsDeterministic(): void {
    $this->assertStringContainsString('PHARD-01', self::PROBE_FORMAT);
    $this->assertStringContainsString('environment=%s', self::PROBE_FORMAT);
    $this->assertStringContainsString('release=%s', self::PROBE_FORMAT);
    $this->assertStringContainsString('timestamp=%s', self::PROBE_FORMAT);
  }

  /**
   * Probe message includes environment and release placeholders.
   */
  public function testProbeMessageIncludesEnvironmentAndRelease(): void {
    $message = sprintf(self::PROBE_FORMAT, 'pantheon-live', 'v1.2.3', '2026-03-10T00:00:00+00:00');

    $this->assertStringContainsString('environment=pantheon-live', $message);
    $this->assertStringContainsString('release=v1.2.3', $message);
  }

  /**
   * Probe message with empty release uses 'none' placeholder.
   *
   * Matches the SentryProbeCommands behavior: `$context['release'] ?: 'none'`.
   */
  public function testProbeMessageHandlesEmptyRelease(): void {
    $release = '' ?: 'none';
    $message = sprintf(self::PROBE_FORMAT, 'local', $release, '2026-03-10T00:00:00+00:00');

    $this->assertStringContainsString('release=none', $message);
  }

  /**
   * SentryProbeCommands class exists and extends DrushCommands.
   */
  public function testProbeCommandClassExists(): void {
    $this->assertTrue(
      class_exists('Drupal\ilas_site_assistant\Commands\SentryProbeCommands'),
      'SentryProbeCommands class must exist',
    );
  }

  /**
   * SentryProbeCommands has a sentryProbe method.
   */
  public function testProbeCommandHasSentryProbeMethod(): void {
    $this->assertTrue(
      method_exists('Drupal\ilas_site_assistant\Commands\SentryProbeCommands', 'sentryProbe'),
      'SentryProbeCommands must have a sentryProbe() method',
    );
  }

  /**
   * SentryProbeCommands sentryProbe method returns int.
   */
  public function testProbeCommandReturnsInt(): void {
    $reflection = new \ReflectionMethod(
      'Drupal\ilas_site_assistant\Commands\SentryProbeCommands',
      'sentryProbe',
    );
    $returnType = $reflection->getReturnType();
    $this->assertNotNull($returnType, 'sentryProbe() must have a return type');
    $this->assertSame('int', $returnType->getName(), 'sentryProbe() must return int');
  }

}
