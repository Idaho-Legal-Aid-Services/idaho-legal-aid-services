<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Contract check for Cohere, Voyage and Pinecone.
 *
 * Reuses ProviderHealthCheck, which already sends one minimal real request
 * to each provider, and reduces its report to pass / fail / skipped.
 */
final class ProviderHealthContractCheck implements VendorContractCheckInterface {

  public function __construct(
    private readonly ProviderHealthCheck $providerHealthCheck,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): array {
    try {
      return self::mapReport($this->providerHealthCheck->run());
    }
    catch (\Throwable $e) {
      $reason = 'exception:' . (new \ReflectionClass($e))->getShortName();
      return [
        'cohere' => ['status' => self::FAIL, 'reason' => $reason],
        'voyage' => ['status' => self::FAIL, 'reason' => $reason],
        'pinecone' => ['status' => self::FAIL, 'reason' => $reason],
      ];
    }
  }

  /**
   * Reduces a ProviderHealthCheck report to one status per vendor.
   *
   * A provider that is switched off in this runtime is SKIPPED, not failed:
   * the check exists to catch a provider that stopped accepting our requests.
   *
   * @param array<string, mixed> $report
   *   Output of ProviderHealthCheck::run().
   *
   * @return array<string, array{status: string, reason: string}>
   *   Status per vendor.
   */
  public static function mapReport(array $report): array {
    $results = [];

    $cohere = is_array($report['cohere'] ?? NULL) ? $report['cohere'] : [];
    if (!empty($cohere['ok'])) {
      $results['cohere'] = ['status' => self::PASS, 'reason' => 'generation_probe_passed'];
    }
    elseif (empty($cohere['enabled'])) {
      $results['cohere'] = ['status' => self::SKIPPED, 'reason' => 'disabled'];
    }
    else {
      $results['cohere'] = ['status' => self::FAIL, 'reason' => self::token($cohere['reason'] ?? NULL, 'probe_failed')];
    }

    $voyage = is_array($report['voyage'] ?? NULL) ? $report['voyage'] : [];
    if (!empty($voyage['ok'])) {
      $results['voyage'] = ['status' => self::PASS, 'reason' => 'rerank_applied'];
    }
    elseif (empty($voyage['enabled'])) {
      $results['voyage'] = ['status' => self::SKIPPED, 'reason' => 'disabled'];
    }
    else {
      $results['voyage'] = [
        'status' => self::FAIL,
        'reason' => self::token($voyage['reason'] ?? ($voyage['fallback_reason'] ?? NULL), 'rerank_not_applied'),
      ];
    }

    $pinecone = is_array($report['pinecone'] ?? NULL) ? $report['pinecone'] : [];
    $indexes = is_array($pinecone['indexes'] ?? NULL) ? $pinecone['indexes'] : [];
    $probed = array_filter($indexes, static fn($index): bool => is_array($index) && ($index['probe_status'] ?? 'skipped') !== 'skipped');
    if (!empty($pinecone['ok'])) {
      $results['pinecone'] = ['status' => self::PASS, 'reason' => 'query_probe_passed'];
    }
    elseif (!isset($pinecone['error']) && $probed === []) {
      $results['pinecone'] = ['status' => self::SKIPPED, 'reason' => 'vector_search_inactive'];
    }
    else {
      $failed = [];
      foreach ($probed as $index) {
        if (empty($index['reachable'])) {
          $failed[] = self::token($index['key'] ?? NULL, 'index') . ':' . self::token($index['probe_status'] ?? NULL, 'unknown');
        }
      }
      $results['pinecone'] = [
        'status' => self::FAIL,
        'reason' => $failed !== [] ? implode(',', $failed) : 'query_probe_failed',
      ];
    }

    return $results;
  }

  /**
   * Reduces a provider-supplied value to a short log-safe token.
   */
  private static function token(mixed $value, string $fallback): string {
    $token = is_scalar($value) ? preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $value) : '';
    $token = substr((string) $token, 0, 64);

    return $token !== '' ? $token : $fallback;
  }

}
