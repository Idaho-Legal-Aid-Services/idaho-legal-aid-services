<?php

namespace Drupal\ilas_site_assistant\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
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
 *   cron = {"time" = 30}
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
      return;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Discard items that are too old or lack a timestamp (pre-upgrade).
    $maxAge = (int) ($config->get('langfuse.max_item_age_seconds') ?? 3600);
    if (!isset($data['enqueued_at'])) {
      $this->logger->notice('Langfuse export: discarding pre-upgrade item without enqueued_at (@count events).', [
        '@count' => count($data['batch']),
      ]);
      return;
    }
    $age = time() - (int) $data['enqueued_at'];
    if ($age > $maxAge) {
      $this->logger->notice('Langfuse export: discarding item aged @age seconds (max @max), @count events.', [
        '@age' => $age,
        '@max' => $maxAge,
        '@count' => count($data['batch']),
      ]);
      return;
    }

    // Skip if Langfuse was disabled since the item was enqueued.
    if (!$config->get('langfuse.enabled')) {
      $this->logger->notice('Langfuse export: tracing disabled, discarding @count events.', [
        '@count' => count($data['batch']),
      ]);
      return;
    }

    $host = rtrim($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com', '/');
    $publicKey = $config->get('langfuse.public_key') ?? '';
    $secretKey = $config->get('langfuse.secret_key') ?? '';
    $timeout = (float) ($config->get('langfuse.timeout') ?? 5.0);

    if ($publicKey === '' || $secretKey === '') {
      $this->logger->warning('Langfuse export: credentials not configured, discarding batch.');
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

      if ($statusCode >= 200 && $statusCode < 300) {
        $this->logger->info('Langfuse export: sent @count events successfully.', [
          '@count' => count($data['batch']),
        ]);
      }
      elseif ($statusCode === 207) {
        // Partial success — Langfuse returns 207 for batch with some errors.
        $body = json_decode($response->getBody()->getContents(), TRUE);
        $errors = $body['errors'] ?? [];
        $successes = $body['successes'] ?? [];
        $this->logger->notice('Langfuse export: partial success. @ok succeeded, @err errors.', [
          '@ok' => count($successes),
          '@err' => count($errors),
        ]);
      }
    }
    catch (GuzzleException $e) {
      $statusCode = 0;
      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $statusCode = $e->getResponse()->getStatusCode();
      }

      // Client errors (4xx except 429) are not retryable — discard.
      if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
        $this->logger->error('Langfuse export: non-retryable error (HTTP @code), discarding batch: @message', [
          '@code' => $statusCode,
          '@message' => $e->getMessage(),
        ]);
        return;
      }

      // Server errors (5xx) and 429 — suspend the queue for retry on next cron.
      $this->logger->error('Langfuse export: retryable error (HTTP @code): @message', [
        '@code' => $statusCode,
        '@message' => $e->getMessage(),
      ]);
      throw new SuspendQueueException('Langfuse API unavailable, will retry on next cron run.');
    }
    catch (\Throwable $e) {
      $this->logger->error('Langfuse export: unexpected error: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new SuspendQueueException('Langfuse export failed unexpectedly, will retry.');
    }
  }

}
