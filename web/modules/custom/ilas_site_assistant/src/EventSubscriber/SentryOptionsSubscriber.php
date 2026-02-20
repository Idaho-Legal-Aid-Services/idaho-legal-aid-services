<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Drupal\ilas_site_assistant\Service\PiiRedactor;
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

      return $sentryEvent;
    };
  }

  /**
   * Determines the runtime context based on PHP SAPI and argv.
   *
   * @return string
   *   One of: drush-cron, drush-updb, drush-deploy, drush-cr, drush-cli, web,
   *   cli-other.
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
        default => 'drush-cli',
      };
    }

    return 'cli-other';
  }

}
