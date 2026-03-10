<?php

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Sentry operationalization probes.
 */
class SentryProbeCommands extends DrushCommands {

  /**
   * Send a synthetic probe event to Sentry for operationalization verification.
   *
   * Emits a deterministic, PII-free message to Sentry and outputs the event ID
   * for cross-referencing in the Sentry UI. Used to verify live capture, tag
   * enrichment, and redaction pipeline end-to-end (PHARD-01).
   *
   * @command ilas:sentry-probe
   * @aliases sentry-probe
   * @usage ilas:sentry-probe
   *   Send a synthetic probe event to Sentry and print the event ID.
   */
  public function sentryProbe(): int {
    // Guard: Sentry SDK must be installed.
    if (!class_exists('\Sentry\SentrySdk')) {
      $this->logger()->error('Sentry SDK is not installed. Install drupal/raven and sentry/sentry.');
      return 1;
    }

    // Guard: Raven client key must be configured.
    $clientKey = \Drupal::config('raven.settings')->get('client_key');
    if (empty($clientKey)) {
      $this->logger()->error('Raven client_key is not configured. Set it in raven.settings or provide SENTRY_DSN.');
      return 1;
    }

    $context = SentryOptionsSubscriber::observabilityContext();
    $timestamp = gmdate('c');

    $message = sprintf(
      'PHARD-01 synthetic probe: environment=%s release=%s timestamp=%s',
      $context['environment'],
      $context['release'] ?: 'none',
      $timestamp,
    );

    $eventId = \Sentry\captureMessage($message);

    if ($eventId === NULL) {
      $this->logger()->error('Sentry captureMessage returned null. The event may have been dropped by a before_send callback or sampling.');
      return 1;
    }

    $this->logger()->success(sprintf('Sentry probe sent. Event ID: %s', (string) $eventId));
    $this->logger()->notice(sprintf('Environment: %s', $context['environment']));
    $this->logger()->notice(sprintf('Release: %s', $context['release'] ?: 'none'));
    $this->logger()->notice(sprintf('Timestamp: %s', $timestamp));

    return 0;
  }

}
