<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests locking PII redaction token constants and method contracts.
 *
 * These tests pin the TOKEN_* constants and truncation behavior so that
 * refactors cannot silently alter token formats or break storage/log
 * truncation guarantees.
 */
#[Group('ilas_site_assistant')]
class PiiRedactorContractTest extends TestCase {

  /**
   * Locks the exact set of 9 TOKEN_* constants.
   */
  public function testAllTokenConstantsExist(): void {
    $expected = [
      'TOKEN_EMAIL',
      'TOKEN_PHONE',
      'TOKEN_SSN',
      'TOKEN_CC',
      'TOKEN_DOB',
      'TOKEN_DATE',
      'TOKEN_ADDRESS',
      'TOKEN_NAME',
      'TOKEN_CASE',
      'TOKEN_CREDENTIAL',
    ];

    $ref = new \ReflectionClass(PiiRedactor::class);
    $tokenConstants = array_filter(
      array_keys($ref->getConstants()),
      fn(string $name) => str_starts_with($name, 'TOKEN_'),
    );

    sort($expected);
    sort($tokenConstants);
    $this->assertSame($expected, $tokenConstants, 'TOKEN_* constant set has changed');
  }

  /**
   * No TOKEN_* constant may be an empty string.
   */
  public function testTokenConstantValuesAreNonEmpty(): void {
    $ref = new \ReflectionClass(PiiRedactor::class);
    $tokenConstants = array_filter(
      $ref->getConstants(),
      fn(string $name) => str_starts_with($name, 'TOKEN_'),
      ARRAY_FILTER_USE_KEY,
    );

    foreach ($tokenConstants as $name => $value) {
      $this->assertNotEmpty($value, "TOKEN constant {$name} must not be empty");
    }
  }

  /**
   * All TOKEN_* values must follow the bracketed format [REDACTED-TYPE].
   */
  public function testTokenConstantValuesFollowBracketedFormat(): void {
    $ref = new \ReflectionClass(PiiRedactor::class);
    $tokenConstants = array_filter(
      $ref->getConstants(),
      fn(string $name) => str_starts_with($name, 'TOKEN_'),
      ARRAY_FILTER_USE_KEY,
    );

    foreach ($tokenConstants as $name => $value) {
      $this->assertMatchesRegularExpression(
        '/^\[REDACTED-[A-Z]+\]$/',
        $value,
        "TOKEN constant {$name} value '{$value}' does not follow [REDACTED-TYPE] format",
      );
    }
  }

  /**
   * All TOKEN_* values must be unique (no duplicate tokens).
   */
  public function testTokenConstantValuesAreUnique(): void {
    $ref = new \ReflectionClass(PiiRedactor::class);
    $tokenConstants = array_filter(
      $ref->getConstants(),
      fn(string $name) => str_starts_with($name, 'TOKEN_'),
      ARRAY_FILTER_USE_KEY,
    );

    $values = array_values($tokenConstants);
    $unique = array_unique($values);
    $this->assertCount(
      count($values),
      $unique,
      'Duplicate TOKEN_* values found: ' . implode(', ', array_diff_assoc($values, $unique)),
    );
  }

  /**
   * RedactForStorage() must truncate to 500 chars by default.
   */
  public function testRedactForStorageDefaultTruncation(): void {
    // 600 chars of safe input (no PII to redact).
    $input = str_repeat('a', 600);
    $result = PiiRedactor::redactForStorage($input);
    $this->assertSame(500, mb_strlen($result), 'redactForStorage must truncate to 500 chars by default');
  }

  /**
   * RedactForLog() must truncate to 100 chars by default.
   */
  public function testRedactForLogDefaultTruncation(): void {
    // 200 chars of safe input (no PII to redact).
    $input = str_repeat('b', 200);
    $result = PiiRedactor::redactForLog($input);
    $this->assertSame(100, mb_strlen($result), 'redactForLog must truncate to 100 chars by default');
  }

}
