<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Contract check for Langfuse: write one synthetic trace, then read it back.
 *
 * Both halves matter. Langfuse accepts an OTLP export with HTTP 200 before it
 * validates anything, so only reading the trace back proves it was stored;
 * and the read API is used by nothing but operator tooling, so when Langfuse
 * removed the v3 read endpoints in November 2026 nothing on the site noticed.
 */
final class LangfuseContractCheck implements VendorContractCheckInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly LangfuseTraceLookupService $traceLookup,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): array {
    return ['langfuse' => $this->check()];
  }

  /**
   * Writes and reads back one synthetic trace.
   *
   * @return array{status: string, reason: string}
   *   The check result.
   */
  private function check(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $publicKey = (string) ($config->get('langfuse.public_key') ?? '');
    $secretKey = (string) ($config->get('langfuse.secret_key') ?? '');
    if (!$config->get('langfuse.enabled') || $publicKey === '' || $secretKey === '') {
      return ['status' => self::SKIPPED, 'reason' => 'disabled'];
    }

    $environment = (string) ($config->get('langfuse.environment') ?? '');
    $synthetic = LangfuseSyntheticTrace::build($environment !== '' ? $environment : 'local');
    $host = rtrim((string) ($config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com'), '/');
    $timeout = (float) ($config->get('langfuse.timeout') ?? 5.0);

    try {
      $response = $this->httpClient->request('POST', $host . LangfusePayloadContract::OTLP_TRACES_PATH, [
        'json' => LangfuseOtlpPayloadBuilder::build($synthetic['payload']['batch'], $environment),
        'auth' => [$publicKey, $secretKey],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
          LangfusePayloadContract::INGESTION_VERSION_HEADER => LangfusePayloadContract::INGESTION_VERSION,
        ],
      ]);
      $status = $response->getStatusCode();
      if ($status < 200 || $status >= 300) {
        return ['status' => self::FAIL, 'reason' => 'export_http_' . $status];
      }
    }
    catch (GuzzleException $e) {
      $status = (method_exists($e, 'getResponse') && $e->getResponse()) ? $e->getResponse()->getStatusCode() : 0;
      return ['status' => self::FAIL, 'reason' => 'export_http_' . $status];
    }
    catch (\Throwable $e) {
      return ['status' => self::FAIL, 'reason' => 'export_exception'];
    }

    try {
      $lookup = $this->traceLookup->lookupTrace($synthetic['trace_id'], 6, 2000, 1);
    }
    catch (\Throwable $e) {
      $reason = preg_match('/HTTP (\d{1,3})/', $e->getMessage(), $match) === 1 ? 'read_http_' . $match[1] : 'read_exception';
      return ['status' => self::FAIL, 'reason' => $reason];
    }

    if (empty($lookup['found'])) {
      return ['status' => self::FAIL, 'reason' => 'accepted_but_not_stored'];
    }

    return ['status' => self::PASS, 'reason' => 'stored_and_read_back'];
  }

}
