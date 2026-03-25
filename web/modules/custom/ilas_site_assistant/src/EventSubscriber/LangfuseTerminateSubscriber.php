<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\LangfuseTracer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes Langfuse trace data to the queue once per request.
 *
 * Prefers `kernel.response` when a finalized payload is available and falls
 * back to `kernel.terminate` so request-path traces are not silently missed.
 */
class LangfuseTerminateSubscriber implements EventSubscriberInterface {

  /**
   * The Langfuse tracer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\LangfuseTracer
   */
  protected LangfuseTracer $tracer;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Whether the current request trace has already been flushed or dropped.
   *
   * @var bool
   */
  protected bool $flushed = FALSE;

  /**
   * Constructs a LangfuseTerminateSubscriber.
   *
   * @param \Drupal\ilas_site_assistant\Service\LangfuseTracer $tracer
   *   The Langfuse tracer.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    LangfuseTracer $tracer,
    QueueFactory $queue_factory,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->tracer = $tracer;
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', 0],
      KernelEvents::TERMINATE => ['onTerminate', 0],
    ];
  }

  /**
   * Enqueues trace data during the response event when available.
   */
  public function onResponse(): void {
    $this->flushTracePayload(FALSE);
  }

  /**
   * Falls back to terminate-time enqueue if response-time flush did not occur.
   */
  public function onTerminate(): void {
    $this->flushTracePayload(TRUE);
  }

  /**
   * Flushes the current trace payload into the export queue once per request.
   *
   * @param bool $finalAttempt
   *   TRUE when this is the terminate-time fallback. Only final failures are
   *   counted as queue loss so response-time failures can still recover.
   */
  private function flushTracePayload(bool $finalAttempt): void {
    if ($this->flushed || !$this->tracer->isActive()) {
      return;
    }

    $payload = NULL;

    try {
      $payload = $this->tracer->getTracePayload();
      if ($payload === NULL) {
        return;
      }

      $queue = $this->queueFactory->get('ilas_langfuse_export');

      // Guard against unbounded queue growth during Langfuse outages.
      $config = $this->configFactory->get('ilas_site_assistant.settings');
      $maxDepth = (int) ($config->get('langfuse.max_queue_depth') ?? 10000);
      $currentDepth = $queue->numberOfItems();
      if ($currentDepth >= $maxDepth) {
        $this->logger->warning('Langfuse queue depth @current >= @max, dropping trace batch (@events events).', [
          '@current' => $currentDepth,
          '@max' => $maxDepth,
          '@events' => count($payload['batch'] ?? []),
        ]);
        $this->recordOutcome('drop_max_depth', [
          'queue_depth' => $currentDepth,
          'max_depth' => $maxDepth,
          'event_count' => count($payload['batch'] ?? []),
        ]);
        $this->flushed = TRUE;
        return;
      }

      // Stamp enqueue time so the worker can discard stale items.
      $payload['enqueued_at'] = time();

      $queue->createItem($payload);
      $this->recordEnqueue((int) $payload['enqueued_at'], $currentDepth);
      $this->flushed = TRUE;
    }
    catch (\Throwable $e) {
      // Never let queue failures propagate — trace data is best-effort.
      $this->logger->warning('Langfuse queue enqueue failed: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);

      if ($finalAttempt) {
        $this->recordOutcome('drop_enqueue_failure', [
          'event_count' => is_array($payload['batch'] ?? NULL) ? count($payload['batch']) : 0,
          'flush_stage' => 'terminate',
        ]);
        $this->flushed = TRUE;
      }
    }
  }

  /**
   * Records enqueue metadata for queue-age SLO checks when available.
   */
  private function recordEnqueue(int $enqueuedAt, int $depthBeforeEnqueue): void {
    try {
      $container = \Drupal::getContainer();
      if ($container && $container->has('ilas_site_assistant.queue_health_monitor')) {
        $container->get('ilas_site_assistant.queue_health_monitor')
          ->recordEnqueue($enqueuedAt, $depthBeforeEnqueue);
      }
    }
    catch (\Throwable $e) {
      // Never block response termination for monitoring side-effects.
    }
  }

  /**
   * Records an export outcome if the monitor service is available.
   *
   * @param string $outcome
   *   Outcome key.
   * @param array<string, mixed> $metadata
   *   Scalar-safe metadata.
   */
  private function recordOutcome(string $outcome, array $metadata = []): void {
    try {
      $container = \Drupal::getContainer();
      if ($container && $container->has('ilas_site_assistant.queue_health_monitor')) {
        $container->get('ilas_site_assistant.queue_health_monitor')
          ->recordOutcome($outcome, $metadata);
      }
    }
    catch (\Throwable $e) {
      // Never block response termination for monitoring side-effects.
    }
  }

}
