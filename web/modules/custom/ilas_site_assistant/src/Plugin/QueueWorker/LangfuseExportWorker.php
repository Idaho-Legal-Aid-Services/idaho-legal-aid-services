<?php

namespace Drupal\ilas_site_assistant\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exports trace batches to the Langfuse ingestion API.
 *
 * @QueueWorker(
 *   id = "ilas_langfuse_export",
 *   title = @Translation("Langfuse trace export"),
 *   cron = {"time" = 60}
 * )
 */
class LangfuseExportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a LangfuseExportWorker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.channel.ilas_site_assistant'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate payload structure.
    if (!is_array($data) || empty($data['batch']) || !is_array($data['batch'])) {
      $this->logger->warning('Langfuse export: invalid queue item, discarding.');
      $this->recordOutcome('discard_invalid_shape', [
        'event_count' => is_array($data['batch'] ?? NULL) ? count($data['batch']) : 0,
      ]);
      $this->recordDrain(1);
      return;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Discard items that are too old or lack a timestamp (pre-upgrade).
    $maxAge = (int) ($config->get('langfuse.max_item_age_seconds') ?? 3600);
    if (!isset($data['enqueued_at'])) {
      $this->logger->notice('Langfuse export: discarding pre-upgrade item without enqueued_at (@count events).', [
        '@count' => count($data['batch']),
      ]);
      $this->recordOutcome('discard_missing_enqueued_at', [
        'event_count' => count($data['batch']),
      ]);
      $this->recordDrain(1);
      return;
    }
    $age = time() - (int) $data['enqueued_at'];
    if ($age > $maxAge) {
      $this->logger->notice('Langfuse export: discarding item aged @age seconds (max @max), @count events.', [
        '@age' => $age,
        '@max' => $maxAge,
        '@count' => count($data['batch']),
      ]);
      $this->recordOutcome('discard_stale', [
        'event_count' => count($data['batch']),
        'item_age_seconds' => $age,
        'max_age_seconds' => $maxAge,
      ]);
      $this->recordDrain(1);
      return;
    }

    // Skip if Langfuse was disabled since the item was enqueued.
    if (!$config->get('langfuse.enabled')) {
      $this->logger->notice('Langfuse export: tracing disabled, discarding @count events.', [
        '@count' => count($data['batch']),
      ]);
      $this->recordOutcome('discard_disabled', [
        'event_count' => count($data['batch']),
      ]);
      $this->recordDrain(1);
      return;
    }

    $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
    $publicKey = $config->get('langfuse.public_key') ?? '';
    $secretKey = $config->get('langfuse.secret_key') ?? '';
    $timeout = (float) ($config->get('langfuse.timeout') ?? 5.0);

    if ($publicKey === '' || $secretKey === '') {
      $this->logger->warning('Langfuse export: credentials not configured, discarding batch.');
      $this->recordOutcome('discard_missing_credentials', [
        'event_count' => count($data['batch']),
      ]);
      $this->recordDrain(1);
      return;
    }

    $url = $host . '/api/public/ingestion';

    $payload = [
      'batch' => $data['batch'],
      'metadata' => $data['metadata'] ?? [],
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'json' => $payload,
        'auth' => [$publicKey, $secretKey],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $statusCode = $response->getStatusCode();

      if ($statusCode === 207) {
        // Partial success — Langfuse returns 207 for batch with some errors.
        $body = json_decode((string) $response->getBody(), TRUE);
        $errors = $body['errors'] ?? [];
        $successes = $body['successes'] ?? [];
        $this->logger->notice('Langfuse export: partial success. @ok succeeded, @err errors.', [
          '@ok' => count($successes),
          '@err' => count($errors),
        ]);
        $this->recordOutcome('send_partial_207', [
          'event_count' => count($data['batch']),
          'http_status' => $statusCode,
          'success_count' => count($successes),
          'error_count' => count($errors),
        ]);
        $this->recordDrain(1);
      }
      elseif ($statusCode >= 200 && $statusCode < 300) {
        $this->logger->info('Langfuse export: sent @count events successfully.', [
          '@count' => count($data['batch']),
        ]);
        $this->recordOutcome('send_success', [
          'event_count' => count($data['batch']),
          'success_count' => count($data['batch']),
          'http_status' => $statusCode,
        ]);
        $this->recordDrain(1);
      }
    }
    catch (GuzzleException $e) {
      $statusCode = 0;
      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $statusCode = $e->getResponse()->getStatusCode();
      }

      // Client errors (4xx except 429) are not retryable — discard.
      if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
        $this->logger->error('Langfuse export: non-retryable error (HTTP @code), discarding batch: @class @error_signature', [
          '@code' => $statusCode,
          '@class' => get_class($e),
          '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
        ]);
        $this->recordOutcome('discard_non_retryable_http', [
          'event_count' => count($data['batch']),
          'http_status' => $statusCode,
        ]);
        $this->recordDrain(1);
        return;
      }

      // Server errors (5xx) and 429 — suspend the queue for retry on next cron.
      $this->logger->error('Langfuse export: retryable error (HTTP @code): @class @error_signature', [
        '@code' => $statusCode,
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      $this->recordOutcome('retryable_suspend', [
        'event_count' => count($data['batch']),
        'http_status' => $statusCode,
      ]);
      throw new SuspendQueueException('Langfuse API unavailable, will retry on next cron run.');
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse export: unexpected error: @class @error_signature', [
        '@class' => get_class($e),
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ]);
      $this->recordOutcome('retryable_suspend', [
        'event_count' => count($data['batch']),
        'http_status' => 0,
      ]);
      throw new SuspendQueueException('Langfuse export failed unexpectedly, will retry.');
    }
  }

  /**
   * Records a queue drain event if the monitor service is available.
   *
   * @param int $count
   *   Number of items drained.
   */
  private function recordDrain(int $count): void {
    try {
      $container = \Drupal::getContainer();
      if ($container && $container->has('ilas_site_assistant.queue_health_monitor')) {
        $container->get('ilas_site_assistant.queue_health_monitor')->recordDrain($count);
      }
    }
    catch (\Throwable $e) {
      // Never break export pipeline for monitoring.
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
      // Never break export pipeline for monitoring.
    }
  }

}
