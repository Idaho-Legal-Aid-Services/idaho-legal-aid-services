<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Langfuse observability tracer for the assistant pipeline.
 *
 * Accumulates trace/span/generation/event data as plain arrays matching the
 * Langfuse ingestion API format. Data is serializable for Drupal Queue export.
 *
 * All public methods are wrapped in try/catch to ensure tracing failures
 * never break the assistant pipeline.
 */
class LangfuseTracer {

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
   * Whether tracing is enabled for this request (config + sample rate).
   *
   * @var bool
   */
  protected bool $enabled = FALSE;

  /**
   * Whether we've already resolved the enabled state.
   *
   * @var bool
   */
  protected bool $enabledResolved = FALSE;

  /**
   * Whether a trace is currently active.
   *
   * @var bool
   */
  protected bool $active = FALSE;

  /**
   * The current trace ID.
   *
   * @var string
   */
  protected string $traceId = '';

  /**
   * Accumulated batch events for Langfuse ingestion API.
   *
   * @var array
   */
  protected array $batch = [];

  /**
   * Stack of open spans for automatic parent resolution.
   *
   * Each entry: ['id' => string, 'name' => string, 'startTime' => string,
   *              'metadata' => array, 'input' => mixed].
   *
   * @var array
   */
  protected array $spanStack = [];

  /**
   * Active generation data (only one at a time).
   *
   * @var array|null
   */
  protected ?array $activeGeneration = NULL;

  /**
   * UUID generator.
   *
   * @var \Drupal\Component\Uuid\Php
   */
  protected UuidGenerator $uuidGenerator;

  /**
   * Constructs a LangfuseTracer.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->uuidGenerator = new UuidGenerator();
  }

  /**
   * Whether Langfuse tracing is enabled for this request.
   *
   * Checks config flag and applies probabilistic sample rate.
   * Result is cached for the request lifetime.
   *
   * @return bool
   *   TRUE if tracing should be active.
   */
  public function isEnabled(): bool {
    if ($this->enabledResolved) {
      return $this->enabled;
    }

    $this->enabledResolved = TRUE;

    try {
      $config = $this->configFactory->get('ilas_site_assistant.settings');
      if (!$config->get('langfuse.enabled')) {
        $this->enabled = FALSE;
        return FALSE;
      }

      // Check that credentials are configured.
      $publicKey = $config->get('langfuse.public_key') ?? '';
      $secretKey = $config->get('langfuse.secret_key') ?? '';
      if ($publicKey === '' || $secretKey === '') {
        $this->enabled = FALSE;
        return FALSE;
      }

      // Apply sample rate.
      $sampleRate = (float) ($config->get('langfuse.sample_rate') ?? 1.0);
      if ($sampleRate < 1.0) {
        $this->enabled = (random_int(1, 10000) / 10000) <= $sampleRate;
      }
      else {
        $this->enabled = TRUE;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: failed to resolve enabled state: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->enabled = FALSE;
    }

    return $this->enabled;
  }

  /**
   * Whether a trace is currently open.
   *
   * @return bool
   *   TRUE if startTrace() was called and trace is active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Starts a new trace.
   *
   * @param string $traceId
   *   The trace ID (typically the correlation/request ID).
   * @param string $name
   *   The trace name (e.g., 'assistant.message').
   * @param array $metadata
   *   Trace-level metadata.
   */
  public function startTrace(string $traceId, string $name, array $metadata = []): void {
    if (!$this->isEnabled()) {
      return;
    }

    try {
      $this->active = TRUE;
      $this->traceId = $traceId;
      $this->batch = [];
      $this->spanStack = [];
      $this->activeGeneration = NULL;

      $body = [
        'id' => $traceId,
        'name' => $name,
        'metadata' => $metadata ?: NULL,
      ];

      $this->batch[] = [
        'id' => $this->uuidGenerator->generate(),
        'type' => 'trace-create',
        'timestamp' => $this->isoNow(),
        'body' => array_filter($body, fn($v) => $v !== NULL),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: startTrace failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->active = FALSE;
    }
  }

  /**
   * Starts a new span.
   *
   * Spans auto-nest: if another span is already open, the new span becomes
   * its child. Use endSpan() to close the most recently opened span.
   *
   * @param string $name
   *   The span name (e.g., 'safety.classify').
   * @param array $metadata
   *   Span metadata.
   * @param mixed $input
   *   Optional input data for the span.
   */
  public function startSpan(string $name, array $metadata = [], mixed $input = NULL): void {
    if (!$this->active) {
      return;
    }

    try {
      $this->spanStack[] = [
        'id' => $this->uuidGenerator->generate(),
        'name' => $name,
        'startTime' => $this->isoNow(),
        'metadata' => $metadata,
        'input' => $input,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: startSpan(@name) failed: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ends the most recently opened span.
   *
   * @param mixed $output
   *   Optional output data.
   * @param array $metadata
   *   Additional metadata to merge with the span's initial metadata.
   */
  public function endSpan(mixed $output = NULL, array $metadata = []): void {
    if (!$this->active || empty($this->spanStack)) {
      return;
    }

    try {
      $span = array_pop($this->spanStack);
      $parentId = !empty($this->spanStack) ? end($this->spanStack)['id'] : NULL;

      $mergedMetadata = array_merge($span['metadata'] ?? [], $metadata);

      $body = [
        'id' => $span['id'],
        'traceId' => $this->traceId,
        'parentObservationId' => $parentId,
        'name' => $span['name'],
        'startTime' => $span['startTime'],
        'endTime' => $this->isoNow(),
        'metadata' => $mergedMetadata ?: NULL,
        'input' => $span['input'],
        'output' => $output,
      ];

      $this->batch[] = [
        'id' => $this->uuidGenerator->generate(),
        'type' => 'span-create',
        'timestamp' => $span['startTime'],
        'body' => array_filter($body, fn($v) => $v !== NULL),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: endSpan failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Starts an LLM generation span.
   *
   * Only one generation can be active at a time. Generations auto-parent
   * to the current span (if any) or directly to the trace.
   *
   * @param string $name
   *   The generation name (e.g., 'llm.classify').
   * @param string $model
   *   The model name (e.g., 'gemini-1.5-flash').
   * @param array $modelParameters
   *   Model parameters (temperature, max_tokens, etc.).
   * @param mixed $input
   *   The prompt/input sent to the LLM (should be PII-redacted).
   */
  public function startGeneration(string $name, string $model, array $modelParameters = [], mixed $input = NULL): void {
    if (!$this->active) {
      return;
    }

    try {
      $parentId = !empty($this->spanStack) ? end($this->spanStack)['id'] : NULL;

      $this->activeGeneration = [
        'id' => $this->uuidGenerator->generate(),
        'name' => $name,
        'model' => $model,
        'modelParameters' => $modelParameters,
        'input' => $input,
        'parentObservationId' => $parentId,
        'startTime' => $this->isoNow(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: startGeneration(@name) failed: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ends the active LLM generation span.
   *
   * @param mixed $output
   *   The LLM response output.
   * @param array $usage
   *   Token usage: ['input' => int, 'output' => int, 'total' => int].
   */
  public function endGeneration(mixed $output = NULL, array $usage = []): void {
    if (!$this->active || $this->activeGeneration === NULL) {
      return;
    }

    try {
      $gen = $this->activeGeneration;
      $this->activeGeneration = NULL;

      $body = [
        'id' => $gen['id'],
        'traceId' => $this->traceId,
        'parentObservationId' => $gen['parentObservationId'],
        'name' => $gen['name'],
        'model' => $gen['model'],
        'modelParameters' => $gen['modelParameters'] ?: NULL,
        'input' => $gen['input'],
        'output' => $output,
        'usage' => $usage ?: NULL,
        'startTime' => $gen['startTime'],
        'endTime' => $this->isoNow(),
      ];

      $this->batch[] = [
        'id' => $this->uuidGenerator->generate(),
        'type' => 'generation-create',
        'timestamp' => $gen['startTime'],
        'body' => array_filter($body, fn($v) => $v !== NULL),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: endGeneration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Adds a point-in-time event to the trace.
   *
   * @param string $name
   *   The event name (e.g., 'request.complete').
   * @param array $metadata
   *   Event metadata.
   * @param string $level
   *   Event level: DEFAULT, DEBUG, WARNING, ERROR.
   */
  public function addEvent(string $name, array $metadata = [], string $level = 'DEFAULT'): void {
    if (!$this->active) {
      return;
    }

    try {
      $parentId = !empty($this->spanStack) ? end($this->spanStack)['id'] : NULL;

      $body = [
        'id' => $this->uuidGenerator->generate(),
        'traceId' => $this->traceId,
        'parentObservationId' => $parentId,
        'name' => $name,
        'level' => $level,
        'metadata' => $metadata ?: NULL,
        'startTime' => $this->isoNow(),
      ];

      $this->batch[] = [
        'id' => $this->uuidGenerator->generate(),
        'type' => 'event-create',
        'timestamp' => $this->isoNow(),
        'body' => array_filter($body, fn($v) => $v !== NULL),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: addEvent(@name) failed: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ends the trace and finalizes all accumulated data.
   *
   * Closes any open spans/generations. After this call, getTracePayload()
   * can be used to retrieve the serialized trace data.
   *
   * @param mixed $output
   *   Trace-level output data.
   * @param array $metadata
   *   Final trace metadata (merged with trace-create metadata).
   */
  public function endTrace(mixed $output = NULL, array $metadata = []): void {
    if (!$this->active) {
      return;
    }

    try {
      // Close any dangling generation.
      if ($this->activeGeneration !== NULL) {
        $this->endGeneration();
      }

      // Close any dangling spans (in reverse order).
      while (!empty($this->spanStack)) {
        $this->endSpan();
      }

      // Add trace-update event with output and final metadata.
      $body = [
        'id' => $this->traceId,
        'output' => $output,
        'metadata' => $metadata ?: NULL,
      ];

      $this->batch[] = [
        'id' => $this->uuidGenerator->generate(),
        'type' => 'trace-update',
        'timestamp' => $this->isoNow(),
        'body' => array_filter($body, fn($v) => $v !== NULL),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse: endTrace failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Returns the serialized trace payload for queue export.
   *
   * @return array|null
   *   The batch payload for the Langfuse ingestion API, or NULL if no data.
   */
  public function getTracePayload(): ?array {
    if (empty($this->batch)) {
      return NULL;
    }

    return [
      'batch' => $this->batch,
      'metadata' => [
        'batch_size' => count($this->batch),
        'sdk_name' => 'ilas-langfuse-tracer',
        'sdk_version' => '1.0.0',
      ],
    ];
  }

  /**
   * Returns the current ISO 8601 timestamp with microseconds.
   *
   * @return string
   *   ISO 8601 timestamp.
   */
  protected function isoNow(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
      ->format('Y-m-d\TH:i:s.u\Z');
  }

}
