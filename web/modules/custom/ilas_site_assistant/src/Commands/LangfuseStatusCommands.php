<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use Drush\Commands\DrushCommands;

/**
 * Drush command for Langfuse runtime status reporting.
 */
class LangfuseStatusCommands extends DrushCommands {

  /**
   * Constructs a LangfuseStatusCommands.
   */
  public function __construct(
    protected RuntimeTruthSnapshotBuilder $snapshotBuilder,
    protected QueueHealthMonitor $queueHealthMonitor,
    protected SloDefinitions $sloDefinitions,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Report the full Langfuse runtime status without exposing secrets.
   *
   * Shows the effective Langfuse enablement state, credential presence,
   * environment label, sample rate, queue health, and any stored-vs-effective
   * divergences. Designed for admins and auditors who need to verify Langfuse
   * operational state without Langfuse dashboard access.
   *
   * @command ilas:langfuse-status
   * @aliases langfuse-status
   * @usage ilas:langfuse-status
   *   Print the Langfuse runtime status as JSON.
   */
  public function langfuseStatus(): int {
    try {
      $snapshot = $this->snapshotBuilder->buildSnapshot();
    }
    catch (\Throwable $e) {
      fwrite(STDERR, sprintf("Failed to build runtime truth snapshot: %s\n", $e->getMessage()));
      return 1;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Build the focused Langfuse status report.
    $stored = $snapshot['exported_storage']['langfuse'] ?? [];
    $effective = $snapshot['effective_runtime']['langfuse'] ?? [];
    $environment = $snapshot['environment'] ?? [];

    $status = [
      'langfuse' => [
        'stored_config' => $stored,
        'effective_runtime' => $effective,
        'environment_label' => $effective['environment'] ?? 'unknown',
        'host' => $config->get('langfuse.host') ?? 'https://us.cloud.langfuse.com',
        'timeout' => (float) ($config->get('langfuse.timeout') ?? 5.0),
      ],
      'queue' => $this->buildQueueStatus(),
      'export' => $this->queueHealthMonitor->getExportOutcomeSummary(),
      'divergences' => $this->filterLangfuseDivergences($snapshot['divergences'] ?? []),
      'override_channels' => [
        'langfuse.enabled' => $snapshot['override_channels']['langfuse.enabled'] ?? 'config export',
        'langfuse.public_key_present' => $snapshot['override_channels']['langfuse.public_key_present'] ?? 'config export',
        'langfuse.secret_key_present' => $snapshot['override_channels']['langfuse.secret_key_present'] ?? 'config export',
        'langfuse.environment' => $snapshot['override_channels']['langfuse.environment'] ?? 'config export',
        'langfuse.sample_rate' => $snapshot['override_channels']['langfuse.sample_rate'] ?? 'config export',
      ],
      'environment' => $environment,
    ];

    print json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Builds the queue health status section.
   *
   * @return array<string, mixed>
   *   Queue health status.
   */
  protected function buildQueueStatus(): array {
    try {
      $health = $this->queueHealthMonitor->getQueueHealthStatus($this->sloDefinitions);
      $health['total_drained'] = $this->queueHealthMonitor->getTotalDrained();
      return $health;
    }
    catch (\Throwable $e) {
      return [
        'status' => 'error',
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Filters divergences to only Langfuse-related fields.
   *
   * @param array<int, array<string, mixed>> $divergences
   *   All divergences from the snapshot.
   *
   * @return array<int, array<string, mixed>>
   *   Only Langfuse-related divergences.
   */
  protected function filterLangfuseDivergences(array $divergences): array {
    return array_values(array_filter($divergences, function (array $divergence): bool {
      $field = $divergence['field'] ?? '';
      return str_starts_with($field, 'langfuse.');
    }));
  }

}
