<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the approved Sentry payload schema.
 *
 * Validates that SentryOptionsSubscriber constants match the actual enforcement
 * logic, ensuring the approved payload contract stays synchronized (PHARD-01).
 *
 * @group ilas_site_assistant
 */
#[Group('ilas_site_assistant')]
class SentryPayloadContractTest extends TestCase {

  /**
   * APPROVED_TAGS constant exists and is a non-empty array.
   */
  public function testApprovedTagsConstantExists(): void {
    $this->assertIsArray(SentryOptionsSubscriber::APPROVED_TAGS);
    $this->assertNotEmpty(SentryOptionsSubscriber::APPROVED_TAGS);
  }

  /**
   * SENSITIVE_KEYS constant matches what isSensitiveKey enforces.
   *
   * Every key in SENSITIVE_KEYS must be treated as sensitive by the subscriber.
   */
  public function testSensitiveKeysConstantMatchesEnforcement(): void {
    $this->requireSentry();

    $expectedSensitive = SentryOptionsSubscriber::SENSITIVE_KEYS;
    $this->assertNotEmpty($expectedSensitive);

    // Verify each sensitive key is actually redacted in structured scrubbing.
    $callback = SentryOptionsSubscriber::beforeSendCallback();

    foreach ($expectedSensitive as $key) {
      $event = \Sentry\Event::createEvent();
      $event->setExtra([$key => 'secret-value-12345']);
      $result = $callback($event, NULL);
      $this->assertNotNull($result, "Event should not be dropped for key: {$key}");

      $extra = $result->getExtra();
      $this->assertSame(
        '[REDACTED]',
        $extra[$key] ?? NULL,
        "Sensitive key '{$key}' must be redacted to '[REDACTED]'",
      );
    }
  }

  /**
   * BODY_LIKE_KEYS constant matches what isBodyLikeKey enforces.
   *
   * Every key in BODY_LIKE_KEYS with a string value must have PII scrubbed.
   */
  public function testBodyLikeKeysConstantMatchesEnforcement(): void {
    $this->requireSentry();

    $expectedBodyLike = SentryOptionsSubscriber::BODY_LIKE_KEYS;
    $this->assertNotEmpty($expectedBodyLike);

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $piiString = 'Contact john@example.com for details';

    foreach ($expectedBodyLike as $key) {
      $event = \Sentry\Event::createEvent();
      $event->setExtra([$key => $piiString]);
      $result = $callback($event, NULL);
      $this->assertNotNull($result, "Event should not be dropped for key: {$key}");

      $extra = $result->getExtra();
      $this->assertStringNotContainsString(
        'john@example.com',
        $extra[$key] ?? '',
        "Body-like key '{$key}' must have PII scrubbed",
      );
    }
  }

  /**
   * SEND_DEFAULT_PII invariant is FALSE.
   */
  public function testSendDefaultPiiInvariantIsFalse(): void {
    $this->assertFalse(SentryOptionsSubscriber::SEND_DEFAULT_PII);
  }

  /**
   * Observability context keys are a subset of APPROVED_TAGS.
   *
   * Every tag key produced by observabilityContext() (except server_name,
   * which is used for server_name not tags) must appear in APPROVED_TAGS.
   */
  public function testObservabilityContextKeysSubsetOfApprovedTags(): void {
    $context = SentryOptionsSubscriber::observabilityContext();

    foreach ($context as $key => $value) {
      if ($key === 'server_name') {
        // server_name is set on the options, not as a tag.
        continue;
      }
      $this->assertContains(
        $key,
        SentryOptionsSubscriber::APPROVED_TAGS,
        "Observability context key '{$key}' must be in APPROVED_TAGS",
      );
    }
  }

  /**
   * SENSITIVE_KEYS are all lowercase.
   */
  public function testSensitiveKeysAreLowercase(): void {
    foreach (SentryOptionsSubscriber::SENSITIVE_KEYS as $key) {
      $this->assertSame(
        mb_strtolower($key),
        $key,
        "Sensitive key '{$key}' must be lowercase",
      );
    }
  }

  /**
   * BODY_LIKE_KEYS are all lowercase.
   */
  public function testBodyLikeKeysAreLowercase(): void {
    foreach (SentryOptionsSubscriber::BODY_LIKE_KEYS as $key) {
      $this->assertSame(
        mb_strtolower($key),
        $key,
        "Body-like key '{$key}' must be lowercase",
      );
    }
  }

  /**
   * SENSITIVE_KEYS and BODY_LIKE_KEYS are disjoint sets.
   */
  public function testSensitiveAndBodyLikeKeysAreDisjoint(): void {
    $overlap = array_intersect(
      SentryOptionsSubscriber::SENSITIVE_KEYS,
      SentryOptionsSubscriber::BODY_LIKE_KEYS,
    );
    $this->assertEmpty(
      $overlap,
      'SENSITIVE_KEYS and BODY_LIKE_KEYS must not overlap: ' . implode(', ', $overlap),
    );
  }

  /**
   * Skips the test if Sentry SDK is not installed.
   */
  protected function requireSentry(): void {
    if (!class_exists('\Sentry\Event')) {
      $this->markTestSkipped('Sentry SDK not installed.');
    }
  }

}
