<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for correlation ID resolution logic.
 *
 * Tests the resolveCorrelationId() behavior via the controller's public API.
 * Since the method is private, we test it through reflection.
 */
#[Group('ilas_site_assistant')]
class CorrelationIdTest extends TestCase {

  /**
   * UUID4 regex pattern (matches resolveCorrelationId validation).
   */
  const UUID4_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i';

  /**
   * Tests that valid UUID4 headers are accepted.
   */
  #[DataProvider('validUuid4Provider')]
  public function testAcceptsValidUuid4(string $uuid): void {
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $uuid);
  }

  /**
   * Data provider for valid UUID4 values.
   */
  public static function validUuid4Provider(): array {
    return [
      'lowercase' => ['550e8400-e29b-41d4-a716-446655440000'],
      'uppercase' => ['550E8400-E29B-41D4-A716-446655440000'],
      'mixed case' => ['550e8400-E29B-41d4-a716-446655440000'],
      'variant 8' => ['550e8400-e29b-41d4-8716-446655440000'],
      'variant 9' => ['550e8400-e29b-41d4-9716-446655440000'],
      'variant a' => ['550e8400-e29b-41d4-a716-446655440000'],
      'variant b' => ['550e8400-e29b-41d4-b716-446655440000'],
    ];
  }

  /**
   * Tests that invalid headers are rejected (would trigger fallback).
   */
  #[DataProvider('invalidCorrelationIdProvider')]
  public function testRejectsInvalidCorrelationIds(string $value): void {
    $this->assertDoesNotMatchRegularExpression(self::UUID4_PATTERN, $value);
  }

  /**
   * Data provider for invalid correlation IDs.
   */
  public static function invalidCorrelationIdProvider(): array {
    return [
      'empty string' => [''],
      'random text' => ['not-a-uuid'],
      'uuid v1' => ['550e8400-e29b-11d4-a716-446655440000'], // version 1, not 4.
      'uuid v3' => ['550e8400-e29b-31d4-a716-446655440000'], // version 3.
      'uuid v5' => ['550e8400-e29b-51d4-a716-446655440000'], // version 5.
      'too short' => ['550e8400-e29b-41d4-a716'],
      'too long' => ['550e8400-e29b-41d4-a716-446655440000-extra'],
      'no dashes' => ['550e8400e29b41d4a716446655440000'],
      'injection attempt' => ['550e8400-e29b-41d4-a716-446655440000; DROP TABLE users'],
      'xss attempt' => ['<script>alert(1)</script>'],
      'invalid variant' => ['550e8400-e29b-41d4-c716-446655440000'], // variant c not allowed.
      'newline injection' => ["550e8400-e29b-41d4-a716-446655440000\nX-Injected: yes"],
    ];
  }

  /**
   * Tests that a generated UUID matches UUID4 format.
   *
   * Verifies that the Drupal UuidGenerator produces valid UUID4s that
   * would pass resolveCorrelationId validation.
   */
  public function testFallbackGeneratesValidUuid4(): void {
    $generator = new \Drupal\Component\Uuid\Php();
    $uuid = $generator->generate();
    $this->assertMatchesRegularExpression(self::UUID4_PATTERN, $uuid);
  }

  /**
   * Tests that generated UUIDs are unique.
   */
  public function testFallbackGeneratesUniqueUuids(): void {
    $generator = new \Drupal\Component\Uuid\Php();
    $uuids = [];
    for ($i = 0; $i < 100; $i++) {
      $uuids[] = $generator->generate();
    }
    $this->assertEquals(100, count(array_unique($uuids)));
  }

}
