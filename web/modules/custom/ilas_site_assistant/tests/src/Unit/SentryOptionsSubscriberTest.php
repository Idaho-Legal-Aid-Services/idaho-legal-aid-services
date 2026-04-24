<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SentryOptionsSubscriber.
 */
#[CoversClass(SentryOptionsSubscriber::class)]
#[Group('ilas_site_assistant')]
class SentryOptionsSubscriberTest extends TestCase {

  private const DRUPAL_ANNOUNCEMENTS_URL = 'https://www.drupal.org/announcements.json';

  /**
   * Skips the test if Sentry SDK is not installed.
   */
  protected function requireSentry(): void {
    if (!class_exists('\Sentry\Event')) {
      $this->markTestSkipped('Sentry SDK not installed.');
    }
  }

  /**
   * Skips the test if Raven module is not installed.
   */
  protected function requireRaven(): void {
    if (!class_exists('Drupal\raven\Event\OptionsAlter')) {
      $this->markTestSkipped('drupal/raven not installed.');
    }
  }

  /**
   * Tests that getSubscribedEvents includes OptionsAlter when Raven is present.
   */
  public function testSubscribedEventsIncludesOptionsAlter(): void {
    $this->requireRaven();

    $events = SentryOptionsSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('Drupal\raven\Event\OptionsAlter', $events);
    $this->assertSame('onOptionsAlter', $events['Drupal\raven\Event\OptionsAlter']);
  }

  /**
   * Tests that send_default_pii is disabled after onOptionsAlter.
   */
  public function testSendDefaultPiiDisabled(): void {
    $this->requireRaven();

    $options = ['send_default_pii' => TRUE];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $this->assertFalse($event->options['send_default_pii']);
    $this->assertIsCallable($event->options['before_send']);
  }

  /**
   * Tests that server_name is set after onOptionsAlter.
   */
  public function testServerNameIsSet(): void {
    $this->requireRaven();

    $options = [];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $this->assertArrayHasKey('server_name', $event->options);
    // Should be "{env}.{sapi}" format.
    $this->assertMatchesRegularExpression('/^.+\..+$/', $event->options['server_name']);
    $this->assertStringContainsString(PHP_SAPI, $event->options['server_name']);
  }

  /**
   * Tests that tags are set after onOptionsAlter.
   */
  public function testTagsAreSet(): void {
    $this->requireRaven();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=dev');
      new Settings([]);

      $options = [];
      $event = new \Drupal\raven\Event\OptionsAlter($options);

      $subscriber = new SentryOptionsSubscriber();
      $subscriber->onOptionsAlter($event);

      $this->assertArrayHasKey('tags', $event->options);
      $tags = $event->options['tags'];
      $this->assertArrayHasKey('pantheon_env', $tags);
      $this->assertSame('dev', $tags['pantheon_env']);
      $this->assertArrayHasKey('php_sapi', $tags);
      $this->assertArrayHasKey('runtime_context', $tags);
      $this->assertArrayHasKey('site_name', $tags);
      $this->assertArrayHasKey('assistant_name', $tags);
      $this->assertSame(PHP_SAPI, $tags['php_sapi']);
      $this->assertSame('aila', $tags['assistant_name']);
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests that existing tags are preserved (merged, not overwritten).
   */
  public function testExistingTagsArePreserved(): void {
    $this->requireRaven();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=dev');
      new Settings([]);

      $options = [
        'tags' => ['custom_tag' => 'custom_value'],
      ];
      $event = new \Drupal\raven\Event\OptionsAlter($options);

      $subscriber = new SentryOptionsSubscriber();
      $subscriber->onOptionsAlter($event);

      $tags = $event->options['tags'];
      $this->assertSame('custom_value', $tags['custom_tag'], 'Pre-existing tags must be preserved');
      $this->assertArrayHasKey('pantheon_env', $tags);
      $this->assertArrayHasKey('php_sapi', $tags);
      $this->assertArrayHasKey('runtime_context', $tags);
      $this->assertArrayHasKey('assistant_name', $tags);
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests that before_send chains a previous callback.
   */
  public function testBeforeSendChainingCallsPrevious(): void {
    $this->requireRaven();
    $this->requireSentry();

    $previousCalled = FALSE;
    $previousCallback = static function (\Sentry\Event $event, ?\Sentry\EventHint $hint = NULL) use (&$previousCalled): ?\Sentry\Event {
      $previousCalled = TRUE;
      $event->setMessage('modified-by-previous: ' . $event->getMessage());
      return $event;
    };

    $options = ['before_send' => $previousCallback];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    // Call the chained before_send.
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('test message');
    $result = ($event->options['before_send'])($sentryEvent, NULL);

    $this->assertTrue($previousCalled, 'Previous before_send callback must be called');
    $this->assertNotNull($result);
    $this->assertStringContainsString('modified-by-previous', $result->getMessage());
  }

  /**
   * Tests that chaining handles a previous callback that drops the event.
   */
  public function testBeforeSendChainingRespectsNull(): void {
    $this->requireSentry();

    $droppingCallback = static function (\Sentry\Event $event, ?\Sentry\EventHint $hint = NULL): ?\Sentry\Event {
      return NULL;
    };

    $callback = SentryOptionsSubscriber::beforeSendCallback($droppingCallback);
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('should be dropped');
    $result = $callback($sentryEvent, NULL);

    $this->assertNull($result, 'If previous callback returns NULL, event should be dropped');
  }

  /**
   * Tests that before_send scrubs PII from the event message.
   */
  public function testBeforeSendScrubsEventMessage(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('Error for user john@example.com with SSN 123-45-6789');

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $message = $result->getMessage();
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $message);
    $this->assertStringNotContainsString('john@example.com', $message);
    $this->assertStringNotContainsString('123-45-6789', $message);
  }

  /**
   * Tests that before_send preserves message params and formatted text.
   *
   * Raven sets all three setMessage() arguments (template, params, formatted).
   * The scrubEvent() callback must preserve params and scrub formatted text,
   * not reset them to empty by calling setMessage() with only the template.
   *
   * @see https://idaho-legal-aid-services.sentry.io/issues/7356727676/ (PHP-3A)
   */
  public function testBeforeSendPreservesMessageParamsAndFormatted(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $template = '[@cid] Nonce validation failed from @ip [@type] [@class]';
    $params = [
      '@cid' => 'EA-abc12345',
      '@ip' => '203.0.113.42',
      '@type' => 'invalid_nonce',
      '@class' => 'likely_browser',
    ];
    $formatted = '[EA-abc12345] Nonce validation failed from 203.0.113.42 [invalid_nonce] [likely_browser]';

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage($template, $params, $formatted);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $this->assertSame($params, $result->getMessageParams(), 'Message params must be preserved through scrubEvent()');
    $this->assertNotNull($result->getMessageFormatted(), 'Formatted message must be preserved through scrubEvent()');
    $this->assertStringContainsString('EA-abc12345', $result->getMessageFormatted(), 'Formatted message must contain resolved placeholder values');
    $this->assertSame($template, $result->getMessage(), 'Raw template should pass through unchanged (no PII in template)');
  }

  /**
   * Tests that before_send scrubs PII in formatted message while preserving params.
   */
  public function testBeforeSendScrubsPiiInFormattedMessage(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $template = 'Error for user @email';
    $params = ['@email' => 'john@example.com'];
    $formatted = 'Error for user john@example.com';

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage($template, $params, $formatted);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $this->assertSame($params, $result->getMessageParams(), 'Params must be preserved even when formatted contains PII');
    $formattedResult = $result->getMessageFormatted();
    $this->assertNotNull($formattedResult);
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $formattedResult, 'PII in formatted message must be redacted');
    $this->assertStringNotContainsString('john@example.com', $formattedResult, 'Raw email must not appear in formatted message');
  }

  /**
   * Tests that before_send scrubs PII from exception values.
   */
  public function testBeforeSendScrubsExceptionValues(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $exception = new \RuntimeException('User called 208-555-1234 for help');
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $sentryEvent->setExceptions([$exceptionBag]);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $exceptions = $result->getExceptions();
    $this->assertCount(1, $exceptions);
    $value = $exceptions[0]->getValue();
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $value);
    $this->assertStringNotContainsString('208-555-1234', $value);
  }

  /**
   * Tests that before_send scrubs PII from extra context strings.
   */
  public function testBeforeSendScrubsExtraData(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setExtra([
      'user_input' => 'my name is John Smith',
      'count' => 42,
      'clean' => 'no PII here',
    ]);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $extra = $result->getExtra();
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $extra['user_input']);
    $this->assertSame(42, $extra['count'], 'Non-string values should be untouched');
    $this->assertSame('no PII here', $extra['clean'], 'Clean strings should be unchanged');
  }

  /**
   * Tests that before_send returns the event (does not drop it).
   */
  public function testBeforeSendReturnsEvent(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('Clean message with no PII');

    $result = $callback($sentryEvent, NULL);

    $this->assertSame($sentryEvent, $result, 'Callback should return the same event instance');
  }

  /**
   * Tests that transaction callbacks scrub identifiers and query strings.
   */
  public function testBeforeSendTransactionScrubsTransactionName(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendTransactionCallback();
    $transaction = \Sentry\Event::createTransaction();
    $transaction->setTransaction('/assistant/api/message/12345678-1234-4123-8123-123456789abc?message=my email is john@example.com');

    $result = $callback($transaction);

    $this->assertNotNull($result);
    $this->assertSame('/assistant/api/message/:uuid', $result->getTransaction());
  }

  /**
   * Tests that log callbacks scrub body and structured attributes.
   */
  public function testBeforeSendLogScrubsStructuredAttributes(): void {
    if (!class_exists('\Sentry\Logs\Log')) {
      $this->markTestSkipped('Sentry logs API not installed.');
    }

    $callback = SentryOptionsSubscriber::beforeSendLogCallback();
    $log = new \Sentry\Logs\Log(
      microtime(TRUE),
      'trace-id',
      \Sentry\Logs\LogLevel::error(),
      'Assistant failure for john@example.com'
    );
    $log->setAttribute('authorization', 'Bearer secret-token');
    $log->setAttribute('payload', [
      'prompt' => 'My SSN is 123-45-6789',
      'request_id' => '12345678-1234-4123-8123-123456789abc',
    ]);

    $result = $callback($log);

    $this->assertNotNull($result);
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $result->getBody());
    $attributes = $result->attributes()->toSimpleArray();

    $this->assertSame('[REDACTED]', $attributes['authorization']);
    $this->assertIsString($attributes['payload']);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $attributes['payload']);
    $this->assertSame('aila', $attributes['assistant_name']);
  }

  /**
   * Tests environment normalization for core Pantheon envs and multidev.
   */
  public function testNormalizeEnvironmentMapsPantheonEnvs(): void {
    $this->assertSame('local', SentryOptionsSubscriber::normalizeEnvironment(NULL));
    $this->assertSame('pantheon-dev', SentryOptionsSubscriber::normalizeEnvironment('dev'));
    $this->assertSame('pantheon-test', SentryOptionsSubscriber::normalizeEnvironment('test'));
    $this->assertSame('pantheon-live', SentryOptionsSubscriber::normalizeEnvironment('live'));
    $this->assertSame('pantheon-multidev-feature-a', SentryOptionsSubscriber::normalizeEnvironment('Feature_A'));
    $this->assertSame('feature_a', SentryOptionsSubscriber::multidevName('Feature_A'));
  }

  /**
   * Tests isDrushEvalNoise returns FALSE for non-CLI SAPI.
   */
  public function testIsDrushEvalNoiseReturnsFalseForWeb(): void {
    // PHP_SAPI in PHPUnit is 'cli', so we can only test the method's
    // behavior indirectly. When running tests, SAPI is 'cli' and argv
    // typically points to phpunit, not drush — so it should return FALSE.
    $result = SentryOptionsSubscriber::isDrushEvalNoise();
    $this->assertFalse($result, 'isDrushEvalNoise should be FALSE when not running a drush eval command');
  }

  /**
   * Tests isDrushEvalNoise respects SENTRY_CAPTURE_DRUSH_EVAL env var.
   */
  public function testIsDrushEvalNoiseRespectsEnvOverride(): void {
    $original = getenv('SENTRY_CAPTURE_DRUSH_EVAL');
    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('SENTRY_CAPTURE_DRUSH_EVAL=1');
      putenv('PANTHEON_ENVIRONMENT');

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertFalse($result, 'isDrushEvalNoise should be FALSE when SENTRY_CAPTURE_DRUSH_EVAL=1');
    }
    finally {
      // Restore.
      if ($original !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$original");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isDrushEvalNoise returns FALSE on Pantheon (eval errors captured).
   */
  public function testIsDrushEvalNoiseReturnsFalseOnPantheon(): void {
    $originalCapture = getenv('SENTRY_CAPTURE_DRUSH_EVAL');
    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      new Settings(['ilas_observability' => ['environment' => 'pantheon-live']]);
      putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'php:eval', 'echo 1;'];

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertFalse($result, 'isDrushEvalNoise should NOT filter drush eval on Pantheon — eval errors are operational signals');
    }
    finally {
      if ($originalCapture !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$originalCapture");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests isDrushEvalNoise returns TRUE in local development.
   */
  public function testIsDrushEvalNoiseReturnsTrueLocally(): void {
    $originalCapture = getenv('SENTRY_CAPTURE_DRUSH_EVAL');
    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      new Settings(['ilas_observability' => ['environment' => 'local']]);
      putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'php:eval', 'echo 1;'];

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertTrue($result, 'isDrushEvalNoise should filter drush eval in local development');
    }
    finally {
      if ($originalCapture !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$originalCapture");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests SENTRY_CAPTURE_DRUSH_EVAL=1 overrides local filtering.
   */
  public function testIsDrushEvalNoiseLocalCaptureOverride(): void {
    $originalCapture = getenv('SENTRY_CAPTURE_DRUSH_EVAL');
    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      new Settings(['ilas_observability' => ['environment' => 'local']]);
      putenv('SENTRY_CAPTURE_DRUSH_EVAL=1');
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'php:eval', 'echo 1;'];

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertFalse($result, 'SENTRY_CAPTURE_DRUSH_EVAL=1 should override local filtering');
    }
    finally {
      if ($originalCapture !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$originalCapture");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  // ─── Sitemap noise filter tests ─────────────────────────────────────

  /**
   * Tests isSitemapCustomPathNoise drops events on non-live with raw message.
   */
  public function testSitemapNoiseFilterDropsOnDevWithRawMessage(): void {
    $this->requireSentry();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=dev');

      $event = \Sentry\Event::createEvent();
      $event->setLogger('simple_sitemap');
      $event->setMessage('The custom path /events has been omitted from the XML sitemaps as it does not exist.');

      $this->assertTrue(
        SentryOptionsSubscriber::isSitemapCustomPathNoise($event),
        'Sitemap custom path noise should be detected on dev',
      );
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isSitemapCustomPathNoise checks formatted message fallback.
   */
  public function testSitemapNoiseFilterChecksFormattedMessage(): void {
    $this->requireSentry();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=test');

      $event = \Sentry\Event::createEvent();
      $event->setLogger('simple_sitemap');
      // Set raw message as template, formatted as resolved string.
      $event->setMessage(
        'The custom path @path has been omitted from the XML sitemaps as it does not exist.',
        ['@path' => '/events'],
        'The custom path /events has been omitted from the XML sitemaps as it does not exist.',
      );

      $this->assertTrue(
        SentryOptionsSubscriber::isSitemapCustomPathNoise($event),
        'Sitemap noise should be detected via raw message with template placeholders',
      );
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isSitemapCustomPathNoise falls back to getMessageFormatted().
   */
  public function testSitemapNoiseFilterFormattedFallback(): void {
    $this->requireSentry();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=dev');

      $event = \Sentry\Event::createEvent();
      $event->setLogger('simple_sitemap');
      // Simulate an edge case where getMessage() returns a template without
      // the needle but getMessageFormatted() has the full resolved text.
      $event->setMessage(
        '@sitemap_warning',
        ['@sitemap_warning' => 'The custom path /press-room has been omitted from the XML sitemaps as it does not exist.'],
        'The custom path /press-room has been omitted from the XML sitemaps as it does not exist.',
      );

      $this->assertTrue(
        SentryOptionsSubscriber::isSitemapCustomPathNoise($event),
        'Sitemap noise should be detected via getMessageFormatted() fallback',
      );
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isSitemapCustomPathNoise allows through on live environment.
   */
  public function testSitemapNoiseFilterAllowsOnLive(): void {
    $this->requireSentry();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=live');

      $event = \Sentry\Event::createEvent();
      $event->setLogger('simple_sitemap');
      $event->setMessage('The custom path /events has been omitted from the XML sitemaps as it does not exist.');

      $this->assertFalse(
        SentryOptionsSubscriber::isSitemapCustomPathNoise($event),
        'Sitemap warnings on live should NOT be filtered — they indicate real missing content',
      );
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isSitemapCustomPathNoise skips non-simple_sitemap loggers.
   */
  public function testSitemapNoiseFilterSkipsOtherLoggers(): void {
    $this->requireSentry();

    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('PANTHEON_ENVIRONMENT=dev');

      $event = \Sentry\Event::createEvent();
      $event->setLogger('system');
      $event->setMessage('has been omitted from the XML sitemaps');

      $this->assertFalse(
        SentryOptionsSubscriber::isSitemapCustomPathNoise($event),
        'Non-simple_sitemap logger events should not be filtered',
      );
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  // ─── Drupal announcements timeout noise filter tests ────────────────

  /**
   * Tests before_send drops cron ConnectExceptions for announcements.json.
   */
  public function testAnnouncementsTimeoutNoiseFilterDropsCronConnectException(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setLogger('cron');
    $event->setMessage('cURL error 28 while fetching ' . self::DRUPAL_ANNOUNCEMENTS_URL);

    $result = $callback($event, \Sentry\EventHint::fromArray([
      'exception' => $this->createConnectException(self::DRUPAL_ANNOUNCEMENTS_URL),
    ]));

    $this->assertNull($result, 'Announcements feed cron ConnectExceptions should be dropped.');
  }

  /**
   * Tests breadcrumb URL matching when the event exception bag is ConnectException.
   */
  public function testAnnouncementsTimeoutNoiseFilterMatchesBreadcrumbUrl(): void {
    $this->requireSentry();

    $event = \Sentry\Event::createEvent();
    $event->setLogger('announcements_feed');
    $event->setMessage('Unable to fetch Drupal announcements feed.');
    $event->setExceptions([
      new \Sentry\ExceptionDataBag($this->createConnectException(self::DRUPAL_ANNOUNCEMENTS_URL)),
    ]);
    $event->setBreadcrumb([
      $this->createHttpBreadcrumb(self::DRUPAL_ANNOUNCEMENTS_URL),
    ]);

    $this->assertTrue(
      SentryOptionsSubscriber::isDrupalAnnouncementsTimeoutNoise($event),
      'Announcements feed timeout should match via breadcrumb URL when the exception type is ConnectException.',
    );
  }

  /**
   * Tests the filter ignores ConnectExceptions for other URLs.
   */
  public function testAnnouncementsTimeoutNoiseFilterIgnoresOtherUrls(): void {
    $this->requireSentry();

    $otherUrl = 'https://www.drupal.org/security';
    $event = \Sentry\Event::createEvent();
    $event->setLogger('cron');
    $event->setMessage('cURL error 28 while fetching ' . $otherUrl);

    $this->assertFalse(
      SentryOptionsSubscriber::isDrupalAnnouncementsTimeoutNoise(
        $event,
        \Sentry\EventHint::fromArray([
          'exception' => $this->createConnectException($otherUrl),
        ]),
      ),
      'Only the exact announcements.json URL should be filtered.',
    );
  }

  /**
   * Tests the filter ignores the exact URL on unrelated loggers.
   */
  public function testAnnouncementsTimeoutNoiseFilterIgnoresOtherLoggers(): void {
    $this->requireSentry();

    $event = \Sentry\Event::createEvent();
    $event->setLogger('system');
    $event->setMessage('cURL error 28 while fetching ' . self::DRUPAL_ANNOUNCEMENTS_URL);

    $this->assertFalse(
      SentryOptionsSubscriber::isDrupalAnnouncementsTimeoutNoise(
        $event,
        \Sentry\EventHint::fromArray([
          'exception' => $this->createConnectException(self::DRUPAL_ANNOUNCEMENTS_URL),
        ]),
      ),
      'Only cron and announcements_feed loggers should be filtered.',
    );
  }

  /**
   * Tests the filter ignores the exact URL for non-ConnectException failures.
   */
  public function testAnnouncementsTimeoutNoiseFilterIgnoresOtherExceptionClasses(): void {
    $this->requireSentry();

    $event = \Sentry\Event::createEvent();
    $event->setLogger('cron');
    $event->setMessage('cURL error 28 while fetching ' . self::DRUPAL_ANNOUNCEMENTS_URL);

    $this->assertFalse(
      SentryOptionsSubscriber::isDrupalAnnouncementsTimeoutNoise(
        $event,
        \Sentry\EventHint::fromArray([
          'exception' => new \RuntimeException('Different transport failure for ' . self::DRUPAL_ANNOUNCEMENTS_URL),
        ]),
      ),
      'Only ConnectException failures should be filtered.',
    );
  }

  /**
   * Tests near-miss events still chain previous before_send callbacks.
   */
  public function testAnnouncementsTimeoutNoiseFilterStillChainsPreviousCallbackWhenNotMatched(): void {
    $this->requireSentry();

    $previousCalled = FALSE;
    $callback = SentryOptionsSubscriber::beforeSendCallback(
      static function (\Sentry\Event $event, ?\Sentry\EventHint $hint = NULL) use (&$previousCalled): ?\Sentry\Event {
        $previousCalled = TRUE;
        $event->setMessage('modified-by-previous: ' . ($event->getMessage() ?? ''));
        return $event;
      },
    );

    $event = \Sentry\Event::createEvent();
    $event->setLogger('cron');
    $event->setMessage('cURL error 28 while fetching ' . self::DRUPAL_ANNOUNCEMENTS_URL);

    $result = $callback($event, \Sentry\EventHint::fromArray([
      'exception' => new \RuntimeException('Generic timeout for ' . self::DRUPAL_ANNOUNCEMENTS_URL),
    ]));

    $this->assertTrue($previousCalled, 'Previous before_send callback must still run for near-miss events.');
    $this->assertNotNull($result, 'Near-miss events should not be dropped.');
    $this->assertStringContainsString('modified-by-previous', $result->getMessage() ?? '');
  }

  // ─── Temporary cron SMTP noise filter tests ────────────────────────

  /**
   * Tests before_send drops temporary SMTP 421 failures on the mail logger.
   */
  public function testTemporaryCronMailNoiseFilterDropsMailLogger421OnDrushCron(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setLogger('mail');
      $event->setMessage('SMTP response: 421 temporary system problem');

      $result = $callback($event, NULL);

      $this->assertNull($result, 'Temporary SMTP 421 failures during drush cron should be dropped.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests before_send drops temporary gsmtp failures on symfony_mailer_lite.
   */
  public function testTemporaryCronMailNoiseFilterDropsSymfonyMailerLiteGsmtpOnDrushCron(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'core:cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setLogger('symfony_mailer_lite');
      $event->setMessage(
        'An attempt to send an e-mail message failed.',
        [],
        '421 4.7.0 Temporary System Problem. Try again later. gsmtp',
      );

      $result = $callback($event, NULL);

      $this->assertNull($result, 'Temporary gsmtp failures during drush cron should be dropped.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests the same 421 message is retained outside any drush context.
   *
   * Temporary SMTP failures during web requests (not CLI) should remain
   * visible — only CLI contexts (drush-cron, drush-cli) are safe to filter
   * because the mail system retries autonomously.
   */
  public function testTemporaryCronMailNoiseFilterAllows421OutsideDrushContext(): void {
    $this->requireSentry();

    $originalSapi = NULL;
    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      // Simulate a non-drush CLI context (cli-other). PHP_SAPI can't be
      // overridden in PHPUnit, so empty argv triggers the cli-other branch.
      $_SERVER['argv'] = [];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setLogger('mail');
      $event->setMessage('SMTP response: 421 temporary system problem');

      $result = $callback($event, NULL);

      $this->assertNotNull($result, 'Temporary SMTP 421 failures outside drush CLI context should remain visible.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests that SMTP 421 failures are also filtered during drush-cli context.
   *
   * Pantheon may invoke Drupal cron through wrapper scripts that resolve as
   * drush-cli rather than drush-cron (PHP-3R/3S).
   */
  public function testTemporaryCronMailNoiseFilterDrops421InDrushCli(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'status'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setLogger('mail');
      $event->setMessage('SMTP response: 421 temporary system problem');

      $result = $callback($event, NULL);

      $this->assertNull($result, 'Temporary SMTP 421 failures during drush-cli should be dropped.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Data provider for permanent or non-temporary cron mail failures.
   *
   * @return array<string, array{0: string}>
   *   Failure messages that must remain visible in Sentry.
   */
  public static function permanentCronMailFailureProvider(): array {
    return [
      'smtp auth 535' => ['535 5.7.8 Username and Password not accepted'],
      'smtp recipient 550' => ['550 5.1.1 The email account that you tried to reach does not exist'],
      'smtp recipient 550 gsmtp' => ['550 5.1.1 The email account that you tried to reach does not exist. x12-20020a05620a170c00b006af12345678si123456qkk.123 - gsmtp'],
      'tls failure' => ['TLS negotiation failed: certificate verify failed'],
      'generic transport error' => ['Connection to SMTP server failed without temporary deferral code'],
    ];
  }

  /**
   * Tests permanent or generic cron mail failures are not filtered.
   */
  #[DataProvider('permanentCronMailFailureProvider')]
  public function testTemporaryCronMailNoiseFilterKeepsPermanentFailures(string $failureMessage): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setLogger('symfony_mailer_lite');
      $event->setMessage($failureMessage);

      $result = $callback($event, NULL);

      $this->assertNotNull($result, 'Permanent or generic cron mail failures must remain visible in Sentry.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  // ─── Transient AI provider cron noise filter tests ─────────────────

  /**
   * Tests before_send drops transient AI provider 503 on drush cron.
   */
  public function testTransientAiProviderCronNoiseFilterDrops503OnDrushCron(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setMessage('Error invoking model response: The service is currently unavailable.');
      $event->setExceptions([
        $this->createAiRequestErrorExceptionBag('Error invoking model response: The service is currently unavailable.'),
      ]);

      $result = $callback($event, NULL);

      $this->assertNull($result, 'Transient AI provider 503 during drush cron should be dropped.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests before_send drops AI provider 503 via hint exception on drush cron.
   */
  public function testTransientAiProviderCronNoiseFilterDropsViaHintException(): void {
    $this->requireSentry();

    if (!class_exists('Drupal\ai\Exception\AiRequestErrorException')) {
      $this->markTestSkipped('AI module not installed.');
    }

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setMessage('Error invoking model response: The service is currently unavailable.');

      $exception = new \Drupal\ai\Exception\AiRequestErrorException(
        'Error invoking model response: The service is currently unavailable.',
      );
      $hint = \Sentry\EventHint::fromArray(['exception' => $exception]);

      $result = $callback($event, $hint);

      $this->assertNull($result, 'AI provider 503 via hint exception during drush cron should be dropped.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests the same 503 is retained outside drush cron context.
   */
  public function testTransientAiProviderCronNoiseFilterAllows503OutsideDrushCron(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'status'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setMessage('Error invoking model response: The service is currently unavailable.');
      $event->setExceptions([
        $this->createAiRequestErrorExceptionBag('Error invoking model response: The service is currently unavailable.'),
      ]);

      $result = $callback($event, NULL);

      $this->assertNotNull($result, 'AI provider 503 outside drush cron should remain visible.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests non-503 AI errors are NOT filtered even during drush cron.
   */
  public function testTransientAiProviderCronNoiseFilterKeepsNon503AiErrors(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setMessage('Error invoking model response: Authentication failed.');
      $event->setExceptions([
        $this->createAiRequestErrorExceptionBag('Error invoking model response: Authentication failed.'),
      ]);

      $result = $callback($event, NULL);

      $this->assertNotNull($result, 'Non-503 AI errors must remain visible even during drush cron.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Tests non-AI exceptions are NOT filtered even with 503 message.
   */
  public function testTransientAiProviderCronNoiseFilterKeepsNonAiExceptions(): void {
    $this->requireSentry();

    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      $_SERVER['argv'] = ['/code/vendor/bin/drush', 'cron'];

      $callback = SentryOptionsSubscriber::beforeSendCallback();
      $event = \Sentry\Event::createEvent();
      $event->setMessage('The service is currently unavailable.');
      $event->setExceptions([
        new \Sentry\ExceptionDataBag(new \RuntimeException('The service is currently unavailable.')),
      ]);

      $result = $callback($event, NULL);

      $this->assertNotNull($result, 'Non-AI exceptions with 503-like messages must remain visible.');
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

  /**
   * Builds a Sentry ExceptionDataBag mimicking AiRequestErrorException.
   *
   * Uses a real ExceptionDataBag with overridden type to avoid a hard
   * dependency on the ai module in test infrastructure.
   */
  private function createAiRequestErrorExceptionBag(string $message): \Sentry\ExceptionDataBag {
    $exception = new \RuntimeException($message);
    $bag = new \Sentry\ExceptionDataBag($exception);
    // Override the type to simulate AiRequestErrorException serialization.
    $reflection = new \ReflectionClass($bag);
    $typeProp = $reflection->getProperty('type');
    $typeProp->setValue($bag, 'Drupal\\ai\\Exception\\AiRequestErrorException');
    return $bag;
  }

  // ─── Cron re-run lock noise filter tests ──────────────────────────

  /**
   * Tests before_send drops the Drupal cron re-run lock collision warning.
   */
  public function testCronRerunNoiseFilterDropsLockWarning(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setMessage('Attempting to re-run cron while it is already running.');

    $result = $callback($event, NULL);

    $this->assertNull($result, 'Cron lock collision warning should be dropped regardless of context.');
  }

  /**
   * Tests the filter is context-agnostic — drops in web context, not just CLI.
   */
  public function testCronRerunNoiseFilterDropsInWebContext(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    // Simulate event arriving from web context (no CLI argv).
    $event->setMessage('Attempting to re-run cron while it is already running.');

    $result = $callback($event, NULL);

    $this->assertNull($result, 'Cron lock collision warning should be dropped in web context too.');
  }

  /**
   * Tests isCronRerunNoise() returns FALSE for unrelated warning messages.
   */
  public function testCronRerunNoiseFilterKeepsUnrelatedWarnings(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setMessage('Cron completed successfully.');

    $result = $callback($event, NULL);

    $this->assertNotNull($result, 'Unrelated messages must not be dropped by the cron re-run filter.');
  }

  // ─── Minimum-context guarantee tests ──────────────────────────────

  /**
   * Tests that fully-scrubbed events get scrub_opacity and exception_class tags.
   */
  public function testMinimumContextGuaranteeForFullyScrubbed(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    // Exception with an empty value simulates a fully redacted event.
    $exception = new \RuntimeException('');
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $exceptionBag->setValue('');
    $event->setExceptions([$exceptionBag]);
    // No message — event is fully opaque.

    $result = $callback($event, NULL);

    $this->assertNotNull($result);
    $tags = $result->getTags();
    $this->assertSame('full', $tags['scrub_opacity'] ?? NULL, 'Fully scrubbed event must have scrub_opacity=full');
    $this->assertSame('RuntimeException', $tags['exception_class'] ?? NULL, 'Exception class must be preserved');
  }

  /**
   * Tests that events with a message do NOT get scrub_opacity tag.
   */
  public function testMinimumContextNotAppliedWhenMessageExists(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $event->setMessage('Error in module XYZ');

    $result = $callback($event, NULL);

    $this->assertNotNull($result);
    $tags = $result->getTags();
    $this->assertArrayNotHasKey('scrub_opacity', $tags, 'Events with messages should not have scrub_opacity');
  }

  /**
   * Tests that events with exception values do NOT get scrub_opacity tag.
   */
  public function testMinimumContextNotAppliedWhenExceptionHasValue(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $event = \Sentry\Event::createEvent();
    $exception = new \InvalidArgumentException('Bad input detected');
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $event->setExceptions([$exceptionBag]);

    $result = $callback($event, NULL);

    $this->assertNotNull($result);
    $tags = $result->getTags();
    $this->assertArrayNotHasKey('scrub_opacity', $tags, 'Events with exception values should not have scrub_opacity');
  }

  /**
   * Builds a ConnectException for a URL without making any network request.
   */
  private function createConnectException(string $url): \GuzzleHttp\Exception\ConnectException {
    return new \GuzzleHttp\Exception\ConnectException(
      'cURL error 28: Operation timed out for ' . $url,
      new \GuzzleHttp\Psr7\Request('GET', $url),
    );
  }

  /**
   * Builds a Sentry HTTP breadcrumb for a URL.
   */
  private function createHttpBreadcrumb(string $url): \Sentry\Breadcrumb {
    return new \Sentry\Breadcrumb(
      \Sentry\Breadcrumb::LEVEL_ERROR,
      \Sentry\Breadcrumb::TYPE_HTTP,
      'http',
      NULL,
      ['http.url' => $url],
    );
  }

  // ─── CSP extension/ad noise filter tests ──────────────────────────

  /**
   * Data provider for CSP noise patterns that should be dropped.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Logger and message pairs.
   */
  public static function cspNoiseProvider(): array {
    return [
      'google ccTLD img-src' => ['csp', "Blocked 'image' from 'www.google.co.uk'"],
      'google.com img-src' => ['csp', "Blocked 'image' from 'www.google.com'"],
      'google.de img-src' => ['csp', "Blocked 'image' from 'www.google.de'"],
      'google.com.mx img-src' => ['csp', "Blocked 'image' from 'www.google.com.mx'"],
      'moz-extension font' => ['csp', "Blocked 'font' from 'moz-extension:'"],
      'chrome-extension script' => ['csp', "Blocked 'script' from 'chrome-extension:'"],
      'perplexity font-src' => ['seckit', "CSP: Directive font-src violated. Blocked URI: https://r2cdn.perplexity.ai/fonts/FKGroteskNeue.woff2"],
      'launchdarkly connect' => ['csp', "Blocked 'connect' from 'clientstream.launchdarkly.com'"],
      'killadsapi connect' => ['csp', "Blocked 'connect' from 'api.killadsapi.com'"],
      'livechatinc img' => ['csp', "Blocked 'image' from 'cdn.livechatinc.com'"],
      'doubleclick script' => ['csp', "Blocked 'script' from 'googleads.g.doubleclick.net'"],
      'googleadservices connect' => ['csp', "Blocked 'connect' from 'www.googleadservices.com'"],
      'gstatic style' => ['csp', "Blocked 'style' from 'www.gstatic.com'"],
      'eval script' => ['csp', "Blocked 'script' from 'eval:'"],
      'blob script' => ['csp', "Blocked 'script' from 'blob:'"],
      'safesearchinc connect' => ['csp', "Blocked 'connect' from 'safesearchinc.com'"],
    ];
  }

  /**
   * Tests CSP noise events are dropped.
   */
  #[DataProvider('cspNoiseProvider')]
  public function testCspExtensionNoiseIsDropped(string $logger, string $message): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setLogger($logger);
    $event->setMessage($message);

    $result = $callback($event, NULL);

    $this->assertNull($result, "CSP noise should be dropped: {$message}");
  }

  /**
   * Tests that first-party CSP violations are NOT filtered.
   */
  public function testCspFirstPartyViolationsAreKept(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setLogger('csp');
    $event->setMessage("Blocked 'script' from 'unknown-suspicious-domain.com'");

    $result = $callback($event, NULL);

    $this->assertNotNull($result, 'CSP violations from unknown origins must remain visible.');
  }

  /**
   * Tests that non-CSP events with matching text are NOT filtered.
   */
  public function testNonCspEventsWithMatchingTextAreKept(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();
    $event = \Sentry\Event::createEvent();
    $event->setLogger('php');
    $event->setMessage('Error connecting to google.com service');

    $result = $callback($event, NULL);

    $this->assertNotNull($result, 'Non-CSP events matching noise patterns must remain visible.');
  }

  // ─── Runtime context resolution tests ──────────────────────────────

  /**
   * Data provider for runtime context resolution branches.
   *
   * The 'web' branch (PHP_SAPI !== 'cli') cannot be tested in PHPUnit because
   * PHP_SAPI is always 'cli'. All CLI branches are covered below.
   *
   * @return array<string, array{0: list<string>, 1: string}>
   */
  public static function runtimeContextProvider(): array {
    return [
      'drush cron (short)' => [['drush', 'cron'], 'drush-cron'],
      'drush core:cron' => [['drush', 'core:cron'], 'drush-cron'],
      'drush updb' => [['drush', 'updb'], 'drush-updb'],
      'drush deploy' => [['drush', 'deploy'], 'drush-deploy'],
      'drush cr (short)' => [['drush', 'cr'], 'drush-cr'],
      'drush cache:rebuild' => [['drush', 'cache:rebuild'], 'drush-cr'],
      'drush php:eval' => [['drush', 'php:eval'], 'drush-eval'],
      'drush ev (short)' => [['drush', 'ev'], 'drush-eval'],
      'drush unknown command' => [['drush', 'some-custom-cmd'], 'drush-cli'],
      'empty argv' => [[], 'cli-other'],
    ];
  }

  /**
   * Tests resolveRuntimeContext via observabilityContext() with argv injection.
   */
  #[DataProvider('runtimeContextProvider')]
  public function testResolveRuntimeContextBranches(array $argv, string $expected): void {
    $originalArgv = $_SERVER['argv'] ?? NULL;

    try {
      new Settings([]);
      $_SERVER['argv'] = $argv;

      $context = SentryOptionsSubscriber::observabilityContext();
      $this->assertSame($expected, $context['runtime_context']);
    }
    finally {
      if ($originalArgv === NULL) {
        unset($_SERVER['argv']);
      }
      else {
        $_SERVER['argv'] = $originalArgv;
      }
    }
  }

}
