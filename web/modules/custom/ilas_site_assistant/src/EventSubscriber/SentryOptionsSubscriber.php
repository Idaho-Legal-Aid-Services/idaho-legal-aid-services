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
    if (!class_exists('Drupal\raven\Event\OptionsAlter')) {
      return [];
    }

    return [
      'Drupal\raven\Event\OptionsAlter' => 'onOptionsAlter',
    ];
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

      // Drop noise from ad-hoc drush php:eval / php:script sessions in local,
      // unless SENTRY_CAPTURE_DRUSH_EVAL=1 is set to force capture.
      if (static::isDrushEvalNoise()) {
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
   * Used to suppress Sentry noise from ad-hoc debugging commands in local dev.
   * Override with SENTRY_CAPTURE_DRUSH_EVAL=1 to force capture.
   *
   * @return bool
   *   TRUE if the event should be dropped as eval noise.
   */
  public static function isDrushEvalNoise(): bool {
    if (PHP_SAPI !== 'cli') {
      return FALSE;
    }

    // Allow explicit opt-in to capture eval errors.
    if (getenv('SENTRY_CAPTURE_DRUSH_EVAL') === '1') {
      return FALSE;
    }

    // Only filter in local environment (no PANTHEON_ENVIRONMENT set).
    if (getenv('PANTHEON_ENVIRONMENT')) {
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
      $sentryEvent->setMessage(PiiRedactor::redact($message));
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
    $sentryEvent->setTags($tags);

    return $sentryEvent;
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
