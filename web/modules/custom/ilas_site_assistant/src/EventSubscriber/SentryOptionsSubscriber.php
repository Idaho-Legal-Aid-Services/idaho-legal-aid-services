<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

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

    // Runtime attribution.
    $pantheonEnv = getenv('PANTHEON_ENVIRONMENT') ?: 'local';
    $sapi = PHP_SAPI;
    $event->options['server_name'] = "{$pantheonEnv}.{$sapi}";

    // Merge tags (preserve any existing tags from Raven or other subscribers).
    $tags = $event->options['tags'] ?? [];
    $tags['pantheon_env'] = $pantheonEnv;
    $tags['php_sapi'] = $sapi;
    $tags['runtime_context'] = static::resolveRuntimeContext();
    $event->options['tags'] = $tags;

    // Chain before_send: preserve any existing callback.
    $previous = $event->options['before_send'] ?? NULL;
    $event->options['before_send'] = static::beforeSendCallback($previous);
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

      // Scrub event message.
      $message = $sentryEvent->getMessage();
      if ($message !== NULL && $message !== '') {
        $sentryEvent->setMessage(PiiRedactor::redact($message));
      }

      // Scrub exception values.
      $exceptions = $sentryEvent->getExceptions();
      foreach ($exceptions as $exceptionBag) {
        $value = $exceptionBag->getValue();
        if ($value !== '') {
          $exceptionBag->setValue(PiiRedactor::redact($value));
        }
      }

      // Scrub extra context strings.
      $extra = $sentryEvent->getExtra();
      if (!empty($extra)) {
        $scrubbed = FALSE;
        foreach ($extra as $key => $val) {
          if (is_string($val) && $val !== '') {
            $redacted = PiiRedactor::redact($val);
            if ($redacted !== $val) {
              $extra[$key] = $redacted;
              $scrubbed = TRUE;
            }
          }
        }
        if ($scrubbed) {
          $sentryEvent->setExtra($extra);
        }
      }

      // Promote recognized telemetry fields from extra to tags for
      // searchability in Sentry (e.g. watchdog-captured error events).
      $extra = $sentryEvent->getExtra();
      if (!empty($extra)) {
        $tags = $sentryEvent->getTags();
        foreach (TelemetrySchema::REQUIRED_FIELDS as $field) {
          if (isset($extra[$field]) && is_string($extra[$field]) && !isset($tags[$field])) {
            $tags[$field] = $extra[$field];
          }
        }
        $sentryEvent->setTags($tags);
      }

      return $sentryEvent;
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

}
