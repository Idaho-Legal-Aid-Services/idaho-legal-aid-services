<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commands for Langfuse operationalization probes (PHARD-02).
 */
class LangfuseProbeCommands extends DrushCommands {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * Constructs a LangfuseProbeCommands.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    QueueFactory $queue_factory,
    ClientInterface $http_client,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->queueFactory = $queue_factory;
    $this->httpClient = $http_client;
  }

  /**
   * Send a synthetic probe trace to Langfuse for operationalization verification.
   *
   * Builds a deterministic, PII-free synthetic trace batch matching the
   * LangfuseTracer ingestion format and either posts it directly to Langfuse
   * or enqueues it for async export. Used to verify live capture, payload
   * shape, and redaction pipeline end-to-end (PHARD-02).
   *
   * @param array $options
   *   Command options.
   *
   * @command ilas:langfuse-probe
   * @aliases langfuse-probe
   * @option direct POST directly to Langfuse API instead of enqueuing.
   * @usage ilas:langfuse-probe
   *   Enqueue a synthetic probe trace and print the trace ID.
   * @usage ilas:langfuse-probe --direct
   *   POST a synthetic probe trace directly to the Langfuse API.
   */
  public function langfuseProbe(array $options = ['direct' => FALSE]): int {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Guard: Langfuse must be enabled.
    if (!$config->get('langfuse.enabled')) {
      $this->logger()->error('Langfuse is not enabled. Set langfuse.enabled=true in ilas_site_assistant.settings.');
      return 1;
    }

    // Guard: credentials must be present.
    $publicKey = $config->get('langfuse.public_key') ?? '';
    $secretKey = $config->get('langfuse.secret_key') ?? '';
    if ($publicKey === '' || $secretKey === '') {
      $this->logger()->error('Langfuse credentials not configured. Set langfuse.public_key and langfuse.secret_key.');
      return 1;
    }

    $batch = $this->buildSyntheticBatch();
    $traceId = $batch['trace_id'];
    $payload = $batch['payload'];

    if ($options['direct']) {
      return $this->sendDirect($payload, $traceId, $config);
    }

    return $this->enqueuePayload($payload, $traceId);
  }

  /**
   * Builds a deterministic, PII-free synthetic trace batch.
   *
   * @return array
   *   Associative array with 'trace_id' and 'payload' keys.
   */
  public function buildSyntheticBatch(): array {
    $uuidGenerator = new UuidGenerator();
    $traceId = $uuidGenerator->generate();
    $spanId = $uuidGenerator->generate();
    $eventBodyId = $uuidGenerator->generate();
    $timestamp = gmdate('Y-m-d\TH:i:s.000000\Z');

    $environment = getenv('PANTHEON_ENVIRONMENT') ?: 'local';

    $batch = [
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'trace-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $traceId,
          'timestamp' => $timestamp,
          'name' => 'phard-02.synthetic_probe',
          'input' => 'hash=synthetic0001 len=1-24 redact=none',
          'output' => 'type=probe_complete reason=none hash=synthetic9999 len=1-24',
          'metadata' => [
            'environment' => $environment,
            'probe_timestamp' => $timestamp,
            'input_hash' => str_repeat('1', 64),
            'input_length_bucket' => '1-24',
            'input_redaction_profile' => 'none',
            'output_hash' => str_repeat('9', 64),
            'output_length_bucket' => '1-24',
            'output_redaction_profile' => 'none',
            'response_type' => 'probe_complete',
            'reason_code' => 'none',
          ],
        ],
      ],
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'span-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $spanId,
          'traceId' => $traceId,
          'name' => 'probe.synthetic_span',
          'startTime' => $timestamp,
          'endTime' => $timestamp,
        ],
      ],
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'event-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $eventBodyId,
          'traceId' => $traceId,
          'name' => 'probe.verification_marker',
          'startTime' => $timestamp,
        ],
      ],
    ];

    $payload = [
      'batch' => $batch,
      'metadata' => [
        'batch_size' => count($batch),
        'sdk_name' => 'ilas-langfuse-tracer',
        'sdk_version' => '1.0.0',
      ],
    ];

    return [
      'trace_id' => $traceId,
      'payload' => $payload,
    ];
  }

  /**
   * POST payload directly to Langfuse API.
   */
  protected function sendDirect(array $payload, string $traceId, $config): int {
    $host = $config->get('langfuse.host') ?? 'https://cloud.langfuse.com';
    $publicKey = $config->get('langfuse.public_key');
    $secretKey = $config->get('langfuse.secret_key');
    $url = rtrim($host, '/') . '/api/public/ingestion';

    try {
      $response = $this->httpClient->request('POST', $url, [
        'json' => $payload,
        'auth' => [$publicKey, $secretKey],
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'timeout' => 10,
      ]);

      $statusCode = $response->getStatusCode();
      $this->logger()->success(sprintf('Langfuse probe sent directly. Trace ID: %s, HTTP status: %d', $traceId, $statusCode));
    }
    catch (\Throwable $e) {
      $this->logger()->error(sprintf('Langfuse direct probe failed: %s', $e->getMessage()));
      $this->logger()->notice(sprintf('Trace ID: %s', $traceId));
      return 1;
    }

    $this->logger()->notice(sprintf('Trace ID: %s', $traceId));
    $this->logger()->notice(sprintf('Langfuse URL: %s', rtrim($host, '/')));

    return 0;
  }

  /**
   * Enqueue payload to the Langfuse export queue.
   */
  protected function enqueuePayload(array $payload, string $traceId): int {
    $queue = $this->queueFactory->get('ilas_langfuse_export');
    $depthBefore = $queue->numberOfItems();

    $item = [
      'payload' => $payload,
      'enqueued_at' => time(),
    ];

    if (!$queue->createItem($item)) {
      $this->logger()->error('Failed to enqueue Langfuse probe payload.');
      return 1;
    }

    $depthAfter = $queue->numberOfItems();

    $this->logger()->success(sprintf('Langfuse probe enqueued. Trace ID: %s', $traceId));
    $this->logger()->notice(sprintf('Queue depth: %d -> %d', $depthBefore, $depthAfter));

    return 0;
  }

}
