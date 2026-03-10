<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests proving Sentry and Langfuse pipelines never emit raw PII.
 *
 * End-to-end validation that the before_send callback and PiiRedactor
 * cooperate to replace all 9 PII types with [REDACTED-*] tokens.
 *
 * @group ilas_site_assistant
 */
#[Group('ilas_site_assistant')]
class ObservabilityRedactionContractTest extends TestCase {

  /**
   * Skips the test if Sentry SDK is not installed.
   */
  protected function requireSentry(): void {
    if (!class_exists('\Sentry\Event')) {
      $this->markTestSkipped('Sentry SDK not installed.');
    }
  }

  /**
   * Returns a string containing all 9 PII types.
   */
  private function allPiiString(): string {
    return implode(' | ', [
      'email john@example.com',
      'phone 208-555-1234',
      'ssn 123-45-6789',
      'cc 4111111111111111',
      'born on 01/15/1990',
      'date 2025-03-15',
      '123 Main Street',
      'name John Smith',
      'CV-24-0001',
    ]);
  }

  /**
   * Returns the expected redaction tokens for all 9 PII types.
   */
  private function allTokens(): array {
    return [
      PiiRedactor::TOKEN_EMAIL,
      PiiRedactor::TOKEN_PHONE,
      PiiRedactor::TOKEN_SSN,
      PiiRedactor::TOKEN_CC,
      PiiRedactor::TOKEN_DOB,
      PiiRedactor::TOKEN_DATE,
      PiiRedactor::TOKEN_ADDRESS,
      PiiRedactor::TOKEN_NAME,
      PiiRedactor::TOKEN_CASE,
    ];
  }

  /**
   * Returns the raw PII values that must not appear after redaction.
   */
  private function rawPiiValues(): array {
    return [
      'john@example.com',
      '208-555-1234',
      '123-45-6789',
      '4111111111111111',
      '01/15/1990',
      '2025-03-15',
      '123 Main Street',
      'John Smith',
      'CV-24-0001',
    ];
  }

  /**
   * Returns a multilingual/contextual PII string.
   */
  private function multilingualContextualPiiString(): string {
    return implode(' | ', [
      'Me llamo Juan García',
      'fecha de nacimiento 01/15/1990',
      'Mi direccion es 123 Main Street Boise ID 83702',
      'Mi telefono es +52-208-555-1234',
      'Idaho license is AB123456C',
      'client Maria Lopez',
    ]);
  }

  /**
   * Tests explicit coverage for ISO dates and compact name context.
   */
  public function testIsoDateAndCompactNameAreExplicitlyRedacted(): void {
    $redacted = PiiRedactor::redact('date 2025-03-15 name John Smith');

    $this->assertStringContainsString(PiiRedactor::TOKEN_DATE, $redacted);
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $redacted);
    $this->assertStringNotContainsString('2025-03-15', $redacted);
    $this->assertStringNotContainsString('John Smith', $redacted);
  }

  /**
   * Tests multilingual/contextual PII coverage through the Sentry callback.
   */
  public function testSentryBeforeSendRedactsMultilingualContextualPii(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage($this->multilingualContextualPiiString());

    $result = $callback($sentryEvent, NULL);
    $this->assertNotNull($result);

    $message = $result->getMessage();
    foreach ([
      'Juan García',
      '01/15/1990',
      '123 Main Street Boise ID 83702',
      '+52-208-555-1234',
      'AB123456C',
      'Maria Lopez',
    ] as $raw) {
      $this->assertStringNotContainsString($raw, $message, "Raw PII '{$raw}' must not appear in multilingual redacted message");
    }

    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $message);
  }

  /**
   * Tests that beforeSend redacts all 9 PII types from event messages.
   */
  public function testSentryBeforeSendRedactsAllNinePiiTypes(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage($this->allPiiString());

    $result = $callback($sentryEvent, NULL);
    $this->assertNotNull($result);

    $message = $result->getMessage();
    foreach ($this->rawPiiValues() as $raw) {
      $this->assertStringNotContainsString($raw, $message, "Raw PII '{$raw}' must not appear in redacted message");
    }
  }

  /**
   * Tests that beforeSend redacts PII from exception values.
   */
  public function testSentryBeforeSendRedactsExceptionPii(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $sentryEvent = \Sentry\Event::createEvent();
    $exception = new \RuntimeException($this->allPiiString());
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $sentryEvent->setExceptions([$exceptionBag]);

    $result = $callback($sentryEvent, NULL);
    $this->assertNotNull($result);

    $exceptions = $result->getExceptions();
    $this->assertCount(1, $exceptions);
    $value = $exceptions[0]->getValue();

    foreach ($this->rawPiiValues() as $raw) {
      $this->assertStringNotContainsString($raw, $value, "Raw PII '{$raw}' must not appear in exception value");
    }
  }

  /**
   * Tests that beforeSend redacts PII from extra context strings.
   */
  public function testSentryBeforeSendRedactsExtraContextPii(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setExtra([
      'user_input' => $this->allPiiString(),
      'count' => 42,
    ]);

    $result = $callback($sentryEvent, NULL);
    $this->assertNotNull($result);

    $extra = $result->getExtra();
    foreach ($this->rawPiiValues() as $raw) {
      $this->assertStringNotContainsString($raw, $extra['user_input'], "Raw PII '{$raw}' must not appear in extra context");
    }
    $this->assertSame(42, $extra['count'], 'Non-string values must be untouched');
  }

  /**
   * Tests that PiiRedactor covers all 9 TOKEN_* constants.
   */
  public function testPiiRedactorCoversAllNineTokenTypes(): void {
    $ref = new ReflectionClass(PiiRedactor::class);
    $tokenConstants = array_filter(
      $ref->getConstants(),
      fn(string $name) => str_starts_with($name, 'TOKEN_'),
      ARRAY_FILTER_USE_KEY,
    );

    $this->assertCount(9, $tokenConstants, 'PiiRedactor must define exactly 9 TOKEN_* constants');

    // Verify each token can be produced by redaction.
    $input = $this->allPiiString();
    $redacted = PiiRedactor::redact($input);

    foreach ($tokenConstants as $name => $token) {
      $this->assertStringContainsString(
        $token,
        $redacted,
        "TOKEN constant {$name} ('{$token}') must appear in redacted output",
      );
    }
  }

  /**
   * Tests that redactForStorage applies both truncation and redaction.
   */
  public function testRedactForStorageTruncatesAndRedacts(): void {
    $input = $this->allPiiString() . ' ' . str_repeat('a', 600);
    $result = PiiRedactor::redactForStorage($input);

    $this->assertLessThanOrEqual(500, mb_strlen($result), 'redactForStorage must truncate to 500 chars');
    foreach ($this->rawPiiValues() as $raw) {
      $this->assertStringNotContainsString($raw, $result, "Raw PII '{$raw}' must not appear after redactForStorage");
    }
  }

  /**
   * Tests that redactForLog applies both truncation and redaction.
   */
  public function testRedactForLogTruncatesAndRedacts(): void {
    $input = $this->allPiiString() . ' ' . str_repeat('b', 200);
    $result = PiiRedactor::redactForLog($input);

    $this->assertLessThanOrEqual(100, mb_strlen($result), 'redactForLog must truncate to 100 chars');
    foreach ($this->rawPiiValues() as $raw) {
      $this->assertStringNotContainsString($raw, $result, "Raw PII '{$raw}' must not appear after redactForLog");
    }
  }

}
