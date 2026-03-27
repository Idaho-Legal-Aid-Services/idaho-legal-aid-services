<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant\Service\TelemetrySchema;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Configures Sentry client options via Raven's OptionsAlter event.
 *
 * Responsibilities:
 * - Forces send_default_pii = false.
 * - Registers a before_send callback that scrubs PII from event messages,
 *   exception values, and extra context using PiiRedactor::redact().
 * - Sets server_name and tags for environment/runtime attribution.
 *
 * Soft dependency: returns an empty event map if drupal/raven is not installed.
 */
class SentryOptionsSubscriber implements EventSubscriberInterface {

  /**
   * Tags that are approved for Sentry event payloads.
   *
   * These are the only tags that should appear on outbound Sentry events.
   * Any tag not in this list is either redacted or not attached.
   *
   * @var string[]
   */
  public const APPROVED_TAGS = [
    'environment',
    'pantheon_env',
    'multidev_name',
    'site_name',
    'site_id',
    'php_sapi',
    'runtime_context',
    'assistant_name',
    'release',
    'git_sha',
    'intent',
    'safety_class',
    'fallback_path',
    'request_id',
    'env',
    'scrub_opacity',
    'exception_class',
  ];

  /**
   * Keys whose values are always fully redacted to '[REDACTED]'.
   *
   * These carry authentication/session material that must never leave the
   * process boundary.
   *
   * @var string[]
   */
  public const SENSITIVE_KEYS = [
    'authorization',
    'cookie',
    'set-cookie',
    'x-csrf-token',
    'password',
    'token',
    'session',
    'session_id',
  ];

  /**
   * Keys whose string values are PII-scrubbed but not fully redacted.
   *
   * These may carry user-generated free text that needs redaction of PII
   * patterns (emails, SSNs, etc.) but the structural content is preserved.
   *
   * @var string[]
   */
  public const BODY_LIKE_KEYS = [
    'data',
    'body',
    'message',
    'prompt',
    'response',
    'content',
    'query_string',
  ];

  /**
   * Invariant: send_default_pii is always forced to FALSE.
   *
   * @var bool
   */
  public const SEND_DEFAULT_PII = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Soft dependency guard: if Raven is not installed, subscribe to nothing.
    if (!static::hasRavenOptionsAlterEvent()) {
      return [];
    }

    return [
      'Drupal\raven\Event\OptionsAlter' => 'onOptionsAlter',
    ];
  }

  /**
   * Returns TRUE when Raven's OptionsAlter event class is available.
   */
  protected static function hasRavenOptionsAlterEvent(): bool {
    return class_exists('Drupal\raven\Event\OptionsAlter');
  }

  /**
   * Alters Sentry client options to disable default PII and add scrubbing.
   *
   * @param object $event
   *   The OptionsAlter event. Typed as object to avoid a hard class dependency.
   */
  public function onOptionsAlter(object $event): void {
    // Disable default PII collection (IP address, cookies, etc.).
    $event->options['send_default_pii'] = FALSE;
    $context = static::observabilityContext();
    $event->options['server_name'] = $context['server_name'];
    $event->options['tags'] = static::mergeObservabilityTags($event->options['tags'] ?? [], $context);

    // Chain before_send: preserve any existing callback.
    $previous = $event->options['before_send'] ?? NULL;
    $event->options['before_send'] = static::beforeSendCallback($previous);

    $previousTransaction = $event->options['before_send_transaction'] ?? NULL;
    $event->options['before_send_transaction'] = static::beforeSendTransactionCallback($previousTransaction);

    $previousLog = $event->options['before_send_log'] ?? NULL;
    $event->options['before_send_log'] = static::beforeSendLogCallback($previousLog);
  }

  /**
   * Returns the before_send callback that scrubs PII from Sentry events.
   *
   * @param callable|null $previous
   *   An optional previous before_send callback to chain.
   *
   * @return callable
   *   A callback compatible with Sentry's before_send option.
   */
  public static function beforeSendCallback(?callable $previous = NULL): callable {
    return static function (\Sentry\Event $sentryEvent, ?\Sentry\EventHint $hint = NULL) use ($previous): ?\Sentry\Event {
      // Chain previous callback first.
      if ($previous !== NULL) {
        $sentryEvent = $previous($sentryEvent, $hint);
        if ($sentryEvent === NULL) {
          return NULL;
        }
      }

      // Drop noise from ad-hoc drush php:eval / php:script sessions in local
      // development only. On deployed environments (Pantheon), eval errors are
      // real operational signals that reach Sentry normally.
      // Override with SENTRY_CAPTURE_DRUSH_EVAL=1 to force capture locally.
      if (static::isDrushEvalNoise()) {
        return NULL;
      }

      // Drop simple_sitemap warnings about omitted custom paths on non-live
      // environments. These paths resolve on live (content nodes exist) but
      // not on dev/test where the database doesn't have those nodes (PHP-X/W/V).
      if (static::isSitemapCustomPathNoise($sentryEvent)) {
        return NULL;
      }

      // Drop cron noise from transient drupal.org announcements feed timeouts.
      if (static::isDrupalAnnouncementsTimeoutNoise($sentryEvent, $hint)) {
        return NULL;
      }

      // Drop temporary SMTP transport failures from drush cron. These are
      // provider-side deferrals/noise, not application defects.
      if (static::isTemporaryCronMailNoise($sentryEvent)) {
        return NULL;
      }

      // Drop transient AI provider 503s from drush cron. These are
      // embedding-service blips, not application defects. Unindexed items
      // retry on the next cron cycle via VectorIndexHygieneService.
      if (static::isTransientAiProviderCronNoise($sentryEvent, $hint)) {
        return NULL;
      }

      // Drop CSP report noise from browser extensions, ad networks,
      // translators, and bots. These are not first-party code violations.
      if (static::isCspExtensionNoise($sentryEvent)) {
        return NULL;
      }

      return static::scrubEvent($sentryEvent);
    };
  }

  /**
   * Returns the before_send_transaction callback that scrubs transactions.
   *
   * @param callable|null $previous
   *   An optional previous before_send_transaction callback to chain.
   *
   * @return callable
   *   A callback compatible with Sentry's before_send_transaction option.
   */
  public static function beforeSendTransactionCallback(?callable $previous = NULL): callable {
    return static function (\Sentry\Event $transaction) use ($previous): ?\Sentry\Event {
      if ($previous !== NULL) {
        $transaction = $previous($transaction);
        if ($transaction === NULL) {
          return NULL;
        }
      }

      return static::scrubEvent($transaction, TRUE);
    };
  }

  /**
   * Returns the before_send_log callback that scrubs structured logs.
   *
   * @param callable|null $previous
   *   An optional previous before_send_log callback to chain.
   *
   * @return callable
   *   A callback compatible with Sentry's before_send_log option.
   */
  public static function beforeSendLogCallback(?callable $previous = NULL): callable {
    return static function (\Sentry\Logs\Log $log) use ($previous): ?\Sentry\Logs\Log {
      if ($previous !== NULL) {
        $log = $previous($log);
        if ($log === NULL) {
          return NULL;
        }
      }

      $log->setBody(PiiRedactor::redact($log->getBody()));

      $attributes = $log->attributes()->toSimpleArray();
      foreach ($attributes as $key => $value) {
        if (static::isSensitiveKey($key)) {
          $log->setAttribute($key, '[REDACTED]');
          continue;
        }

        $log->setAttribute($key, static::scrubStructuredValue($value));
      }

      foreach (static::observabilityContext() as $key => $value) {
        if ($key === 'server_name' || $value === '') {
          continue;
        }
        $log->setAttribute($key, $value);
      }

      return $log;
    };
  }

  /**
   * Checks if the current CLI context is a drush php:eval/php:script session.
   *
   * Suppresses Sentry noise from ad-hoc debugging commands in local
   * development only. On deployed environments (Pantheon), eval errors are
   * real operational signals that should reach Sentry.
   * Override with SENTRY_CAPTURE_DRUSH_EVAL=1 to force capture locally.
   *
   * @return bool
   *   TRUE if the event should be dropped as eval noise.
   */
  public static function isDrushEvalNoise(): bool {
    if (PHP_SAPI !== 'cli') {
      return FALSE;
    }

    // Allow explicit opt-in to capture eval errors even locally.
    if (getenv('SENTRY_CAPTURE_DRUSH_EVAL') === '1') {
      return FALSE;
    }

    // Only filter eval noise in local development. On deployed environments
    // (Pantheon), eval errors are real operational signals that should reach
    // Sentry — e.g. runbook verification commands via terminus.
    $settings = Settings::get('ilas_observability', []);
    $env = $settings['environment'] ?? '';
    if ($env !== '' && $env !== 'local') {
      return FALSE;
    }

    $argv = $_SERVER['argv'] ?? [];
    foreach ($argv as $arg) {
      if (str_starts_with($arg, '-') || str_contains($arg, 'drush')) {
        continue;
      }
      // Match eval-family commands.
      return in_array($arg, [
        'php:eval', 'ev', 'eval',
        'php:script', 'scr',
        'php:cli', 'php', 'core:cli',
      ], TRUE);
    }

    return FALSE;
  }

  /**
   * Checks if the event is a simple_sitemap custom path warning on non-live.
   *
   * Custom sitemap paths like /events, /press-room, /what-we-do/resources
   * resolve on live (where the content nodes exist) but not on dev/test
   * where the database lacks those nodes. These warnings are expected
   * non-live noise and should not consume Sentry quota.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect.
   *
   * @return bool
   *   TRUE if the event should be dropped as sitemap custom path noise.
   */
  public static function isSitemapCustomPathNoise(\Sentry\Event $sentryEvent): bool {
    if ($sentryEvent->getLogger() !== 'simple_sitemap') {
      return FALSE;
    }

    $env = getenv('PANTHEON_ENVIRONMENT') ?: '';
    if ($env === 'live' || $env === '') {
      return FALSE;
    }

    // Check both raw and formatted message. The simple_sitemap Logger calls
    // strtr() before dispatching to the PSR logger, so Raven receives the
    // already-formatted string as getMessage(). getMessageFormatted() is a
    // fallback for SDK versions that store the formatted string separately.
    $needle = 'has been omitted from the XML sitemaps';
    $message = $sentryEvent->getMessage() ?? '';
    if (str_contains($message, $needle)) {
      return TRUE;
    }

    $formatted = $sentryEvent->getMessageFormatted() ?? '';
    return str_contains($formatted, $needle);
  }

  /**
   * Checks if the event is a Drupal announcements feed timeout noise event.
   *
   * The announcements feed is optional admin/community functionality. Live cron
   * should not spend Sentry quota on transient outbound connection timeouts for
   * the fixed drupal.org announcements endpoint.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect.
   * @param \Sentry\EventHint|null $hint
   *   Additional exception context supplied by the Sentry SDK.
   *
   * @return bool
   *   TRUE if the event should be dropped as announcements timeout noise.
   */
  public static function isDrupalAnnouncementsTimeoutNoise(\Sentry\Event $sentryEvent, ?\Sentry\EventHint $hint = NULL): bool {
    if (!in_array($sentryEvent->getLogger(), ['cron', 'announcements_feed'], TRUE)) {
      return FALSE;
    }

    $isConnectException = $hint?->exception instanceof \GuzzleHttp\Exception\ConnectException;
    if (!$isConnectException) {
      $firstException = $sentryEvent->getExceptions()[0] ?? NULL;
      $isConnectException = $firstException instanceof \Sentry\ExceptionDataBag
        && $firstException->getType() === \GuzzleHttp\Exception\ConnectException::class;
    }
    if (!$isConnectException) {
      return FALSE;
    }

    $url = 'https://www.drupal.org/announcements.json';
    if (str_contains($sentryEvent->getMessage() ?? '', $url)) {
      return TRUE;
    }
    if (str_contains($sentryEvent->getMessageFormatted() ?? '', $url)) {
      return TRUE;
    }

    foreach ($sentryEvent->getBreadcrumbs() as $breadcrumb) {
      if (($breadcrumb->getMetadata()['http.url'] ?? NULL) === $url) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the event is a temporary SMTP transport failure during cron.
   *
   * This intentionally filters only temporary provider-side deferrals from
   * drush cron mail senders. Permanent delivery/auth/header failures remain
   * visible in Sentry.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect.
   *
   * @return bool
   *   TRUE if the event should be dropped as temporary cron mail noise.
   */
  public static function isTemporaryCronMailNoise(\Sentry\Event $sentryEvent): bool {
    if (!in_array($sentryEvent->getLogger(), ['mail', 'symfony_mailer_lite'], TRUE)) {
      return FALSE;
    }

    // Match drush-cron explicitly, but also drush-cli: Pantheon invokes
    // Drupal cron through wrapper scripts that resolve as drush-cli rather
    // than drush-cron, so temporary SMTP failures during those runs also
    // need to be dropped (PHP-3R/3S).
    $runtimeContext = static::resolveRuntimeContext();
    if ($runtimeContext !== 'drush-cron' && $runtimeContext !== 'drush-cli') {
      return FALSE;
    }

    $candidates = [
      $sentryEvent->getMessage() ?? '',
      $sentryEvent->getMessageFormatted() ?? '',
    ];

    foreach ($sentryEvent->getExceptions() as $exceptionBag) {
      $candidates[] = $exceptionBag->getValue();
    }

    foreach ($candidates as $candidate) {
      if (!is_string($candidate) || $candidate === '') {
        continue;
      }

      $normalizedCandidate = mb_strtolower($candidate);
      $hasTemporaryCode = preg_match('/\b421\b/', $candidate) === 1;
      $hasGsmtpMarker = str_contains($normalizedCandidate, 'gsmtp');
      $hasTemporaryMarker = str_contains($normalizedCandidate, 'temporary')
        || str_contains($normalizedCandidate, 'try again later')
        || str_contains($normalizedCandidate, 'system problem')
        || preg_match('/\b4\.\d+\.\d+\b/', $candidate) === 1;

      if ($hasTemporaryCode || ($hasGsmtpMarker && $hasTemporaryMarker)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the event is a transient AI provider failure during cron.
   *
   * Embedding provider 503s are transient infrastructure blips, not application
   * defects. Items not indexed will retry on the next cron cycle via
   * VectorIndexHygieneService. Only filters AiRequestErrorException with
   * service-unavailable messages in drush-cron context.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect.
   * @param \Sentry\EventHint|null $hint
   *   Additional exception context supplied by the Sentry SDK.
   *
   * @return bool
   *   TRUE if the event should be dropped as transient AI provider noise.
   */
  public static function isTransientAiProviderCronNoise(\Sentry\Event $sentryEvent, ?\Sentry\EventHint $hint = NULL): bool {
    if (static::resolveRuntimeContext() !== 'drush-cron') {
      return FALSE;
    }

    // Check the hint exception first (most reliable path).
    $exception = $hint?->exception;
    if ($exception !== NULL && static::isTransientAiProviderException($exception)) {
      return TRUE;
    }

    // Fallback: check Sentry exception bags for serialized exception data.
    foreach ($sentryEvent->getExceptions() as $exceptionBag) {
      $type = $exceptionBag->getType() ?? '';
      if (!str_contains($type, 'AiRequestErrorException')) {
        continue;
      }
      $value = $exceptionBag->getValue();
      if (static::isTransientProviderMessage($value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if an exception is a transient AI provider service-unavailable.
   *
   * @param \Throwable $exception
   *   The exception to inspect.
   *
   * @return bool
   *   TRUE if the exception is a transient AI provider 503.
   */
  private static function isTransientAiProviderException(\Throwable $exception): bool {
    // Match AiRequestErrorException by class name to avoid a hard dependency
    // on the ai module (it may not be installed in all environments).
    $class = get_class($exception);
    if (!str_contains($class, 'AiRequestErrorException')) {
      return FALSE;
    }

    return static::isTransientProviderMessage($exception->getMessage());
  }

  /**
   * Checks if an error message indicates a transient provider unavailability.
   *
   * @param string $message
   *   The error message to inspect.
   *
   * @return bool
   *   TRUE if the message indicates a transient 503/unavailable condition.
   */
  private static function isTransientProviderMessage(string $message): bool {
    $normalized = mb_strtolower($message);
    return str_contains($normalized, 'currently unavailable')
      || str_contains($normalized, 'service is unavailable')
      || str_contains($normalized, '503');
  }

  /**
   * Known noise patterns in CSP violation reports from browser extensions,
   * ad networks, translators, and bots.
   *
   * Each pattern is a PCRE regex matched against the CSP report message.
   * Order does not matter — first match wins.
   *
   * @var string[]
   */
  private const CSP_NOISE_PATTERNS = [
    // Google Translate / ads inject cross-origin images from ccTLD domains.
    '/\bgoogle\.(com?\.)?\w{2,}/',
    // Firefox extensions.
    '/\bmoz-extension:/',
    // Chrome extensions.
    '/\bchrome-extension:/',
    // Perplexity AI browser extension fonts/scripts.
    '/\bperplexity\.ai\b/',
    // LaunchDarkly SDK (injected by other sites/extensions).
    '/\blaunchdarkly\.com\b/',
    // SafeSearch extension.
    '/\bsafesearchinc\.com\b/',
    // Ad-blocker extension.
    '/\bkilladsapi\.com\b/',
    // LiveChat widget injection.
    '/\blivechatinc\.com\b/',
    // Google ad network resources.
    '/\bdoubleclick\.net\b/',
    '/\bgoogleadservices\.com\b/',
    // Google static (Translate styles).
    '/\bgstatic\.com\b/',
    // Extension eval/blob script injection.
    "/Blocked 'script' from 'eval:'/",
    "/Blocked 'script' from 'blob:'/",
  ];

  /**
   * Checks if the event is a CSP violation from a browser extension or ad network.
   *
   * CSP reports from third-party injections are the single highest-volume
   * noise source. These are not first-party code violations — the CSP is
   * working correctly by blocking them.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect.
   *
   * @return bool
   *   TRUE if the event should be dropped as CSP extension/ad noise.
   */
  public static function isCspExtensionNoise(\Sentry\Event $sentryEvent): bool {
    if (!in_array($sentryEvent->getLogger(), ['csp', 'seckit'], TRUE)) {
      return FALSE;
    }

    $message = $sentryEvent->getMessage() ?? '';
    $formatted = $sentryEvent->getMessageFormatted() ?? '';
    $haystack = $message . ' ' . $formatted;

    if ($haystack === ' ') {
      return FALSE;
    }

    foreach (self::CSP_NOISE_PATTERNS as $pattern) {
      if (preg_match($pattern, $haystack) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the normalized observability context for Sentry tags.
   *
   * @return array<string, string>
   *   Normalized runtime attribution tags.
   */
  public static function observabilityContext(): array {
    $settings = Settings::get('ilas_observability', []);
    $environment = isset($settings['environment']) && is_string($settings['environment']) && $settings['environment'] !== ''
      ? $settings['environment']
      : static::normalizeEnvironment(getenv('PANTHEON_ENVIRONMENT') ?: NULL);
    $pantheonEnv = isset($settings['pantheon_environment']) && is_string($settings['pantheon_environment'])
      ? $settings['pantheon_environment']
      : (getenv('PANTHEON_ENVIRONMENT') ?: '');
    $siteName = isset($settings['pantheon_site_name']) && is_string($settings['pantheon_site_name']) && $settings['pantheon_site_name'] !== ''
      ? $settings['pantheon_site_name']
      : (getenv('PANTHEON_SITE_NAME') ?: 'local');
    $siteId = isset($settings['pantheon_site_id']) && is_string($settings['pantheon_site_id'])
      ? $settings['pantheon_site_id']
      : (getenv('PANTHEON_SITE_ID') ?: '');
    $multidev = isset($settings['multidev_name']) && is_string($settings['multidev_name'])
      ? $settings['multidev_name']
      : static::multidevName($pantheonEnv);
    $release = isset($settings['release']) && is_string($settings['release']) ? $settings['release'] : '';
    $gitSha = isset($settings['git_sha']) && is_string($settings['git_sha']) ? $settings['git_sha'] : '';
    $sapi = PHP_SAPI;
    $runtimeContext = static::resolveRuntimeContext();

    return [
      'environment' => $environment,
      'pantheon_env' => $pantheonEnv,
      'multidev_name' => $multidev,
      'site_name' => $siteName,
      'site_id' => $siteId,
      'php_sapi' => $sapi,
      'runtime_context' => $runtimeContext,
      'assistant_name' => 'aila',
      'release' => $release,
      'git_sha' => $gitSha,
      'server_name' => $siteName . '.' . $environment . '.' . $sapi,
    ];
  }

  /**
   * Normalizes a Pantheon environment name into the observability contract.
   */
  public static function normalizeEnvironment(?string $pantheonEnv): string {
    $normalized = mb_strtolower(trim((string) $pantheonEnv));
    if ($normalized === '') {
      return 'local';
    }

    return match ($normalized) {
      'dev' => 'pantheon-dev',
      'test' => 'pantheon-test',
      'live' => 'pantheon-live',
      default => 'pantheon-multidev-' . trim((string) preg_replace('/[^a-z0-9-]+/', '-', $normalized), '-'),
    };
  }

  /**
   * Returns the multidev name when the environment is neither dev/test/live.
   */
  public static function multidevName(?string $pantheonEnv): string {
    $normalized = mb_strtolower(trim((string) $pantheonEnv));
    if ($normalized === '' || in_array($normalized, ['dev', 'test', 'live'], TRUE)) {
      return '';
    }

    return $normalized;
  }

  /**
   * Determines the runtime context based on PHP SAPI and argv.
   *
   * @return string
   *   One of: drush-cron, drush-updb, drush-deploy, drush-cr, drush-eval,
   *   drush-cli, web, cli-other.
   */
  private static function resolveRuntimeContext(): string {
    if (PHP_SAPI !== 'cli') {
      return 'web';
    }

    $argv = $_SERVER['argv'] ?? [];
    // Find the Drush command in argv (skip flags and the drush binary itself).
    foreach ($argv as $arg) {
      // Skip the binary path and flags.
      if (str_starts_with($arg, '-') || str_contains($arg, 'drush')) {
        continue;
      }
      return match ($arg) {
        'cron', 'core:cron' => 'drush-cron',
        'updb', 'updatedb', 'update:db' => 'drush-updb',
        'deploy' => 'drush-deploy',
        'cr', 'cache:rebuild', 'cache-rebuild' => 'drush-cr',
        'php:eval', 'ev', 'eval', 'php:script', 'scr', 'php:cli', 'php', 'core:cli' => 'drush-eval',
        default => 'drush-cli',
      };
    }

    return 'cli-other';
  }

  /**
   * Applies consistent scrubbing and tagging to a Sentry event.
   */
  private static function scrubEvent(\Sentry\Event $sentryEvent, bool $transaction = FALSE): \Sentry\Event {
    $message = $sentryEvent->getMessage();
    if ($message !== NULL && $message !== '') {
      $params = $sentryEvent->getMessageParams();
      $formatted = $sentryEvent->getMessageFormatted();
      $sentryEvent->setMessage(
        PiiRedactor::redact($message),
        $params,
        $formatted !== null ? PiiRedactor::redact($formatted) : null
      );
    }

    if ($transaction) {
      $transactionName = $sentryEvent->getTransaction();
      if ($transactionName !== NULL && $transactionName !== '') {
        $sentryEvent->setTransaction(static::sanitizeTransactionName($transactionName));
      }
    }

    $exceptions = $sentryEvent->getExceptions();
    foreach ($exceptions as $exceptionBag) {
      $value = $exceptionBag->getValue();
      if ($value !== '') {
        $exceptionBag->setValue(PiiRedactor::redact($value));
      }
    }

    $extra = static::scrubStructuredValue($sentryEvent->getExtra());
    $sentryEvent->setExtra(is_array($extra) ? $extra : []);

    $tags = static::mergeObservabilityTags($sentryEvent->getTags(), static::observabilityContext());
    $extraTags = is_array($extra) ? static::telemetryTagsFromExtra($extra) : [];
    foreach ($extraTags as $key => $value) {
      if (!isset($tags[$key])) {
        $tags[$key] = $value;
      }
    }

    // Filter to APPROVED_TAGS only — strip SDK-auto-added tags (os, runtime,
    // url, user, server_name, etc.) that are not in the approved set.
    $approved = array_flip(self::APPROVED_TAGS);
    $tags = array_intersect_key($tags, $approved);
    $sentryEvent->setTags($tags);

    // Strip user context to prevent uid/IP leakage.
    $sentryEvent->setUser(NULL);

    // Strip request data (synthetic HTTP headers in CLI, real headers in web).
    $sentryEvent->setRequest([]);

    // Clear SDK-auto-added OS/Runtime contexts (infrastructure detail).
    $sentryEvent->setOsContext(NULL);
    $sentryEvent->setRuntimeContext(NULL);

    // Scrub breadcrumbs — PII-redact messages, strip sensitive metadata.
    $scrubbed = [];
    foreach ($sentryEvent->getBreadcrumbs() as $breadcrumb) {
      $msg = $breadcrumb->getMessage();
      if ($msg !== NULL) {
        $breadcrumb = $breadcrumb->withMessage(PiiRedactor::redact($msg));
      }
      foreach ($breadcrumb->getMetadata() as $key => $value) {
        if (static::isSensitiveKey($key)) {
          $breadcrumb = $breadcrumb->withMetadata($key, '[REDACTED]');
        }
        elseif (is_string($value)) {
          $breadcrumb = $breadcrumb->withMetadata($key, PiiRedactor::redact($value));
        }
      }
      $scrubbed[] = $breadcrumb;
    }
    $sentryEvent->setBreadcrumb($scrubbed);

    // Minimum-context guarantee: if scrubbing left the event fully opaque
    // (empty message + empty exception values), preserve exception type and
    // mark the event so it still has triage value (PHP-1M).
    if (!$transaction) {
      static::ensureMinimumContext($sentryEvent);
    }

    return $sentryEvent;
  }

  /**
   * Ensures an event retains minimum triage context after scrubbing.
   *
   * When PII scrubbing empties both the message and all exception values, the
   * event becomes opaque — no debugging value remains. This adds a
   * 'scrub_opacity' tag ('full') and preserves the exception class name as
   * 'exception_class' so the event can still be triaged in Sentry.
   *
   * @param \Sentry\Event $sentryEvent
   *   The Sentry event to inspect and annotate.
   */
  private static function ensureMinimumContext(\Sentry\Event $sentryEvent): void {
    $message = $sentryEvent->getMessage() ?? '';
    $formatted = $sentryEvent->getMessageFormatted() ?? '';
    $hasMessage = $message !== '' || $formatted !== '';

    $hasExceptionValue = FALSE;
    $firstExceptionType = NULL;
    foreach ($sentryEvent->getExceptions() as $exceptionBag) {
      if ($firstExceptionType === NULL) {
        $firstExceptionType = $exceptionBag->getType();
      }
      if ($exceptionBag->getValue() !== '') {
        $hasExceptionValue = TRUE;
        break;
      }
    }

    if ($hasMessage || $hasExceptionValue) {
      return;
    }

    // Event is fully opaque after scrubbing — add minimum triage context.
    $tags = $sentryEvent->getTags();
    $tags['scrub_opacity'] = 'full';
    if ($firstExceptionType !== NULL && $firstExceptionType !== '') {
      $tags['exception_class'] = mb_substr($firstExceptionType, 0, 255);
    }
    $sentryEvent->setTags($tags);
  }

  /**
   * Merges observability tags onto an existing tag set.
   *
   * @param array<string, mixed> $existing
   *   Existing tags.
   * @param array<string, string> $context
   *   Observability context.
   *
   * @return array<string, string>
   *   Sanitized merged tags.
   */
  private static function mergeObservabilityTags(array $existing, array $context): array {
    $tags = [];
    foreach ($existing as $key => $value) {
      if (!is_string($key) || $key === '') {
        continue;
      }
      if (is_scalar($value) && $value !== '') {
        $tags[$key] = mb_substr(PiiRedactor::redact((string) $value), 0, 255);
      }
    }

    foreach ($context as $key => $value) {
      if ($key === 'server_name' || $value === '') {
        continue;
      }
      $tags[$key] = mb_substr((string) $value, 0, 255);
    }

    return $tags;
  }

  /**
   * Returns recognized telemetry tags from the event extra payload.
   *
   * @param array<string, mixed> $extra
   *   Scrubbed extra payload.
   *
   * @return array<string, string>
   *   Low-cardinality telemetry tags.
   */
  private static function telemetryTagsFromExtra(array $extra): array {
    $tags = [];
    foreach (TelemetrySchema::REQUIRED_FIELDS as $field) {
      if (isset($extra[$field]) && is_scalar($extra[$field])) {
        $tags[$field] = mb_substr((string) $extra[$field], 0, 255);
      }
    }

    return $tags;
  }

  /**
   * Recursively redacts structured values before they leave the process.
   *
   * @param mixed $value
   *   The value to scrub.
   *
   * @return mixed
   *   The scrubbed value.
   */
  private static function scrubStructuredValue(mixed $value): mixed {
    if (is_string($value)) {
      return PiiRedactor::redact($value);
    }

    if (is_array($value)) {
      $scrubbed = [];
      foreach ($value as $key => $item) {
        $normalizedKey = is_string($key) ? $key : (string) $key;
        if (static::isSensitiveKey($normalizedKey)) {
          $scrubbed[$normalizedKey] = '[REDACTED]';
          continue;
        }

        if (static::isBodyLikeKey($normalizedKey) && is_string($item)) {
          $scrubbed[$normalizedKey] = PiiRedactor::redact($item);
          continue;
        }

        $scrubbed[$normalizedKey] = static::scrubStructuredValue($item);
      }

      return $scrubbed;
    }

    if ($value instanceof \Stringable) {
      return PiiRedactor::redact((string) $value);
    }

    return $value;
  }

  /**
   * Removes high-cardinality identifiers and query strings from transactions.
   */
  private static function sanitizeTransactionName(string $transactionName): string {
    $name = preg_replace('/\?.*$/', '', trim($transactionName));
    $name = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27}\b/i', ':uuid', (string) $name);
    $name = preg_replace('/\/\d{2,}(?=\/|$)/', '/:id', (string) $name);

    return PiiRedactor::redact((string) $name);
  }

  /**
   * Returns TRUE when a key always carries sensitive data.
   */
  private static function isSensitiveKey(string $key): bool {
    return in_array(mb_strtolower($key), self::SENSITIVE_KEYS, TRUE);
  }

  /**
   * Returns TRUE when a key may contain user/body-like free text.
   */
  private static function isBodyLikeKey(string $key): bool {
    return in_array(mb_strtolower($key), self::BODY_LIKE_KEYS, TRUE);
  }

}
