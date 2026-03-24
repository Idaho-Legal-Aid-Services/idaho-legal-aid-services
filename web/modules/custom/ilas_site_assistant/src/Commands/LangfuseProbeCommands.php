<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
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
   * The runtime truth snapshot builder.
   */
  protected ?RuntimeTruthSnapshotBuilder $snapshotBuilder;

  /**
   * The queue health monitor.
   */
  protected ?QueueHealthMonitor $queueHealthMonitor;

  /**
   * The SLO definitions.
   */
  protected ?SloDefinitions $sloDefinitions;

  /**
   * Constructs a LangfuseProbeCommands.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    QueueFactory $queue_factory,
    ClientInterface $http_client,
    ?RuntimeTruthSnapshotBuilder $snapshot_builder = NULL,
    ?QueueHealthMonitor $queue_health_monitor = NULL,
    ?SloDefinitions $slo_definitions = NULL,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->queueFactory = $queue_factory;
    $this->httpClient = $http_client;
    $this->snapshotBuilder = $snapshot_builder;
    $this->queueHealthMonitor = $queue_health_monitor;
    $this->sloDefinitions = $slo_definitions;
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
   * @option diagnose Print Langfuse readiness diagnostic without sending a probe.
   * @usage ilas:langfuse-probe
   *   Enqueue a synthetic probe trace and print the trace ID.
   * @usage ilas:langfuse-probe --direct
   *   POST a synthetic probe trace directly to the Langfuse API.
   * @usage ilas:langfuse-probe --diagnose
   *   Print Langfuse readiness verdict as JSON (no probe sent).
   */
  public function langfuseProbe(array $options = ['direct' => FALSE, 'diagnose' => FALSE]): int {
    if ($options['diagnose']) {
      return $this->printDiagnosis();
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Guard: Langfuse must be enabled.
    if (!$config->get('langfuse.enabled')) {
      $this->logger()?->error('Langfuse is runtime-disabled. Enablement depends on LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY secrets being present via settings.php. Run `drush ilas:langfuse-status` for the full stored-vs-effective picture.');
      return 1;
    }

    // Guard: credentials must be present.
    $publicKey = $config->get('langfuse.public_key') ?? '';
    $secretKey = $config->get('langfuse.secret_key') ?? '';
    if ($publicKey === '' || $secretKey === '') {
      $this->logger()?->error('Langfuse is enabled but credentials are absent at runtime. Ensure LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY are available via Pantheon secrets or environment variables. Run `drush ilas:langfuse-status` to inspect override channels.');
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
    $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
    $publicKey = $config->get('langfuse.public_key');
    $secretKey = $config->get('langfuse.secret_key');
    $timeout = (float) ($config->get('langfuse.timeout') ?? 5.0);
    $url = $host . '/api/public/ingestion';

    try {
      $response = $this->httpClient->request('POST', $url, [
        'json' => $payload,
        'auth' => [$publicKey, $secretKey],
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode === 207) {
        $summary = $this->decodeIngestionResponse((string) $response->getBody());
        $this->logger()?->success(sprintf('Langfuse probe sent directly. Trace ID: %s, HTTP status: %d', $traceId, $statusCode));
        $this->logger()?->notice(sprintf('Langfuse direct probe partial success: %d succeeded, %d errors', $summary['successes'], $summary['errors']));
      }
      elseif ($statusCode >= 200 && $statusCode < 300) {
        $this->logger()?->success(sprintf('Langfuse probe sent directly. Trace ID: %s, HTTP status: %d', $traceId, $statusCode));
      }
      else {
        $this->logger()?->error(sprintf('Langfuse direct probe returned unexpected HTTP status: %d', $statusCode));
        $this->logger()?->notice(sprintf('Trace ID: %s', $traceId));
        return 1;
      }
    }
    catch (\Throwable $e) {
      $this->logger()?->error(sprintf('Langfuse direct probe failed: %s', $e->getMessage()));
      $this->logger()?->notice(sprintf('Trace ID: %s', $traceId));
      return 1;
    }

    $this->logger()?->notice(sprintf('Trace ID: %s', $traceId));
    $this->logger()?->notice(sprintf('Langfuse URL: %s', $host));

    return 0;
  }

  /**
   * Enqueue payload to the Langfuse export queue.
   */
  protected function enqueuePayload(array $payload, string $traceId): int {
    $queue = $this->queueFactory->get('ilas_langfuse_export');
    $depthBefore = $queue->numberOfItems();

    $item = $payload;
    $item['enqueued_at'] = time();

    if ($queue->createItem($item) === FALSE) {
      $this->logger()?->error('Failed to enqueue Langfuse probe payload.');
      return 1;
    }

    $depthAfter = $queue->numberOfItems();

    $this->logger()?->success(sprintf('Langfuse probe enqueued. Trace ID: %s', $traceId));
    $this->logger()?->notice(sprintf('Queue depth: %d -> %d', $depthBefore, $depthAfter));

    return 0;
  }

  /**
   * Extracts success and error counts from a Langfuse ingestion response body.
   *
   * @return array{successes:int,errors:int}
   *   Count summary for a probe response.
   */
  private function decodeIngestionResponse(string $body): array {
    $decoded = json_decode($body, TRUE);

    return [
      'successes' => is_array($decoded['successes'] ?? NULL) ? count($decoded['successes']) : 0,
      'errors' => is_array($decoded['errors'] ?? NULL) ? count($decoded['errors']) : 0,
    ];
  }

  /**
   * Prints a Langfuse readiness diagnostic as JSON.
   *
   * Reports the effective enablement state, credential presence, environment,
   * sample rate, queue health, and a human-readable verdict without sending
   * any probe or leaking secrets.
   *
   * @return int
   *   Exit code 0 (always succeeds; the verdict itself may be non-ready).
   */
  protected function printDiagnosis(): int {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    $effectiveEnabled = (bool) $config->get('langfuse.enabled');
    $publicKeyPresent = ($config->get('langfuse.public_key') ?? '') !== '';
    $secretKeyPresent = ($config->get('langfuse.secret_key') ?? '') !== '';
    $sampleRate = (float) ($config->get('langfuse.sample_rate') ?? 1.0);
    $environment = (string) ($config->get('langfuse.environment') ?? 'unknown');

    // Determine stored config state via snapshot builder if available.
    $storedEnabled = FALSE;
    if ($this->snapshotBuilder !== NULL) {
      try {
        $exported = $this->snapshotBuilder->buildExportedStorage();
        $storedEnabled = (bool) ($exported['langfuse']['enabled'] ?? FALSE);
      }
      catch (\Throwable) {
        // Fall through with default.
      }
    }

    // Derive verdict.
    $verdict = $this->deriveVerdict($effectiveEnabled, $publicKeyPresent, $secretKeyPresent);

    // Build queue status.
    $queue = ['depth' => 0, 'status' => 'unknown'];
    try {
      $queueBackend = $this->queueFactory->get('ilas_langfuse_export');
      $queue['depth'] = $queueBackend->numberOfItems();
      if ($this->queueHealthMonitor !== NULL && $this->sloDefinitions !== NULL) {
        $health = $this->queueHealthMonitor->getQueueHealthStatus($this->sloDefinitions);
        $queue['status'] = $health['status'] ?? 'unknown';
      }
    }
    catch (\Throwable) {
      $queue['status'] = 'error';
    }

    // Build export outcomes.
    $exportOutcomes = [];
    if ($this->queueHealthMonitor !== NULL) {
      try {
        $exportOutcomes = $this->queueHealthMonitor->getExportOutcomeSummary();
      }
      catch (\Throwable) {
        // Fall through with empty.
      }
    }

    $suggestion = $this->deriveSuggestion($verdict);

    $report = [
      'verdict' => $verdict,
      'stored_enabled' => $storedEnabled,
      'effective_enabled' => $effectiveEnabled,
      'public_key_present' => $publicKeyPresent,
      'secret_key_present' => $secretKeyPresent,
      'environment' => $environment,
      'sample_rate' => $sampleRate,
      'queue' => $queue,
      'export_outcomes' => $exportOutcomes,
      'suggestion' => $suggestion,
    ];

    print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Derives a readiness verdict from effective Langfuse state.
   *
   * @param bool $effectiveEnabled
   *   Whether Langfuse is enabled in effective runtime config.
   * @param bool $publicKeyPresent
   *   Whether the public key is present.
   * @param bool $secretKeyPresent
   *   Whether the secret key is present.
   *
   * @return string
   *   One of: READY, DISABLED_NO_SECRETS, DISABLED_CONFIG,
   *   ENABLED_NO_CREDENTIALS.
   */
  protected function deriveVerdict(bool $effectiveEnabled, bool $publicKeyPresent, bool $secretKeyPresent): string {
    if (!$effectiveEnabled) {
      // When disabled at runtime, it's almost always because secrets
      // are absent (settings.php only enables when both are present).
      return 'DISABLED_NO_SECRETS';
    }

    if (!$publicKeyPresent || !$secretKeyPresent) {
      return 'ENABLED_NO_CREDENTIALS';
    }

    return 'READY';
  }

  /**
   * Returns an operator-facing suggestion for a non-ready verdict.
   *
   * @param string $verdict
   *   The readiness verdict.
   *
   * @return string|null
   *   A suggestion string, or NULL if ready.
   */
  protected function deriveSuggestion(string $verdict): ?string {
    return match ($verdict) {
      'DISABLED_NO_SECRETS' => 'Langfuse enablement depends on LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY being present via Pantheon secrets or environment variables. Run `drush ilas:langfuse-status` for override channel details.',
      'ENABLED_NO_CREDENTIALS' => 'Langfuse is enabled but credential injection failed. Check that LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY resolve in _ilas_get_secret(). Run `drush ilas:langfuse-status` to inspect.',
      'DISABLED_CONFIG' => 'Langfuse is explicitly disabled in effective config despite secrets being present. Check settings.php override logic.',
      default => NULL,
    };
  }

}
