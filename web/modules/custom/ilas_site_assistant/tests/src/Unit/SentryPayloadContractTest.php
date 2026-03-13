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
   * Non-approved tags are stripped from events by before_send.
   */
  public function testNonApprovedTagsAreStripped(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $event->setTags([
      'environment' => 'test',
      'os' => 'Linux',
      'os.name' => 'Linux',
      'runtime' => 'php 8.3',
      'runtime.name' => 'php',
      'server_name' => 'test.local',
      'url' => 'http://localhost/',
      'user' => 'id:0',
      'level' => 'error',
      'bogus_tag' => 'should-be-stripped',
    ]);

    $result = $callback($event, NULL);
    $this->assertNotNull($result);

    $tags = $result->getTags();
    $approved = SentryOptionsSubscriber::APPROVED_TAGS;

    foreach ($tags as $key => $value) {
      $this->assertContains(
        $key,
        $approved,
        "Tag '{$key}' must be in APPROVED_TAGS but was not stripped",
      );
    }

    // Approved tags should survive.
    $this->assertArrayHasKey('environment', $tags);

    // Non-approved tags must be gone.
    $this->assertArrayNotHasKey('os', $tags);
    $this->assertArrayNotHasKey('os.name', $tags);
    $this->assertArrayNotHasKey('runtime', $tags);
    $this->assertArrayNotHasKey('server_name', $tags);
    $this->assertArrayNotHasKey('url', $tags);
    $this->assertArrayNotHasKey('user', $tags);
    $this->assertArrayNotHasKey('level', $tags);
    $this->assertArrayNotHasKey('bogus_tag', $tags);
  }

  /**
   * User context is cleared by before_send.
   */
  public function testUserContextIsCleared(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $event->setUser(\Sentry\UserDataBag::createFromArray(['id' => '0']));

    $result = $callback($event, NULL);
    $this->assertNotNull($result);
    $this->assertNull($result->getUser());
  }

  /**
   * Request data is cleared by before_send.
   */
  public function testRequestDataIsCleared(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $event->setRequest([
      'url' => 'http://localhost/',
      'method' => 'GET',
      'headers' => ['User-Agent' => 'Symfony'],
    ]);

    $result = $callback($event, NULL);
    $this->assertNotNull($result);
    $this->assertEmpty($result->getRequest());
  }

  /**
   * OS and Runtime contexts are cleared by before_send.
   */
  public function testOsAndRuntimeContextsAreCleared(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $event->setOsContext(new \Sentry\Context\OsContext(
      'Linux',
      '5.15.0',
      '5.15.0-generic',
      'x86_64',
    ));
    $event->setRuntimeContext(new \Sentry\Context\RuntimeContext(
      'php',
      '8.3.0',
    ));

    $result = $callback($event, NULL);
    $this->assertNotNull($result);
    $this->assertNull($result->getOsContext(), 'OS context must be cleared');
    $this->assertNull($result->getRuntimeContext(), 'Runtime context must be cleared');
  }

  /**
   * Breadcrumbs are scrubbed: PII in messages redacted, sensitive keys cleared.
   */
  public function testBreadcrumbsAreScrubbed(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();

    // Breadcrumb with PII in message.
    $bc1 = new \Sentry\Breadcrumb(
      \Sentry\Breadcrumb::LEVEL_INFO,
      \Sentry\Breadcrumb::TYPE_DEFAULT,
      'test',
      'User email is john@example.com',
    );

    // Breadcrumb with sensitive key in metadata.
    $bc2 = new \Sentry\Breadcrumb(
      \Sentry\Breadcrumb::LEVEL_INFO,
      \Sentry\Breadcrumb::TYPE_HTTP,
      'http',
      'GET /api/data',
      ['authorization' => 'Bearer secret-token-123'],
    );

    // Breadcrumb with PII in metadata string value.
    $bc3 = new \Sentry\Breadcrumb(
      \Sentry\Breadcrumb::LEVEL_INFO,
      \Sentry\Breadcrumb::TYPE_DEFAULT,
      'app',
      'Form submitted',
      ['detail' => 'Contact jane@example.com for info'],
    );

    $event->setBreadcrumb([$bc1, $bc2, $bc3]);

    $result = $callback($event, NULL);
    $this->assertNotNull($result);

    $breadcrumbs = $result->getBreadcrumbs();
    $this->assertCount(3, $breadcrumbs);

    // BC1: PII in message must be redacted.
    $this->assertStringNotContainsString(
      'john@example.com',
      $breadcrumbs[0]->getMessage() ?? '',
      'PII in breadcrumb message must be redacted',
    );

    // BC2: Sensitive key must be fully redacted.
    $meta2 = $breadcrumbs[1]->getMetadata();
    $this->assertSame(
      '[REDACTED]',
      $meta2['authorization'] ?? NULL,
      'Sensitive metadata key must be [REDACTED]',
    );

    // BC3: PII in metadata string value must be scrubbed.
    $meta3 = $breadcrumbs[2]->getMetadata();
    $this->assertStringNotContainsString(
      'jane@example.com',
      $meta3['detail'] ?? '',
      'PII in breadcrumb metadata must be redacted',
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
