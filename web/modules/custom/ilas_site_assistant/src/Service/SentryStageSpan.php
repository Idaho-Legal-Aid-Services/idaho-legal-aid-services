<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Times a named stage of a request as a child span of the Sentry transaction.
 *
 * Spans carry a fixed stage name only: never query text, results or IDs.
 * Without a sampled transaction (or without the SDK) every call is a no-op,
 * and no failure here may reach the request.
 */
final class SentryStageSpan {

  /**
   * Span operation shared by all stage spans.
   */
  public const OP = 'assistant.stage';

  /**
   * Starts a stage span and makes it the current span.
   *
   * @param string $stage
   *   Fixed stage name, for example `faq.lexical_query`.
   *
   * @return array{0: \Sentry\Tracing\Span, 1: \Sentry\Tracing\Span}|null
   *   The [span, parent] pair to pass to finish(), or NULL when not tracing.
   */
  public static function start(string $stage): ?array {
    if (!class_exists(SentrySdk::class)) {
      return NULL;
    }

    try {
      $hub = SentrySdk::getCurrentHub();
      $parent = $hub->getSpan();
      if (!$parent instanceof Span || !$parent->getSampled()) {
        return NULL;
      }

      $span = $parent->startChild(
        SpanContext::make()->setOp(self::OP)->setDescription($stage)
      );
      $hub->setSpan($span);

      return [$span, $parent];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Finishes a stage span and restores its parent as the current span.
   *
   * @param array{0: \Sentry\Tracing\Span, 1: \Sentry\Tracing\Span}|null $handle
   *   The value start() returned.
   */
  public static function finish(?array $handle): void {
    if ($handle === NULL) {
      return;
    }

    try {
      [$span, $parent] = $handle;
      $span->finish();
      SentrySdk::getCurrentHub()->setSpan($parent);
    }
    catch (\Throwable) {
      // Tracing must never break the request.
    }
  }

  /**
   * Runs a callable inside a stage span.
   *
   * @param string $stage
   *   Fixed stage name.
   * @param callable $callable
   *   The work to time.
   *
   * @return mixed
   *   Whatever the callable returns.
   */
  public static function run(string $stage, callable $callable): mixed {
    $handle = self::start($stage);
    try {
      return $callable();
    }
    finally {
      self::finish($handle);
    }
  }

}
