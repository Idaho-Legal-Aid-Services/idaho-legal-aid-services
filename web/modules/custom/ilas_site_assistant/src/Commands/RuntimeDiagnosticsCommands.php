<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\ObservabilityProofTaxonomy;
use Drupal\ilas_site_assistant\Service\RuntimeDiagnosticsMatrixBuilder;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for unified runtime diagnostics with proof-level annotations.
 */
class RuntimeDiagnosticsCommands extends DrushCommands {

  /**
   * Runtime diagnostics builder service id.
   */
  private const MATRIX_BUILDER_SERVICE = 'ilas_site_assistant.runtime_diagnostics_matrix_builder';

  /**
   * Valid --section values mapped to output keys.
   */
  private const SECTION_MAP = [
    'matrix' => 'diagnostics_matrix',
    'integrations' => 'integration_status',
    'credentials' => 'credential_inventory',
    'retrieval' => 'retrieval_inventory',
    'degraded' => 'degraded_mode_state',
    'commands' => 'verification_commands',
  ];

  public function __construct(
    protected ContainerInterface $container,
  ) {
    parent::__construct();
  }

  /**
   * Print a unified runtime diagnostics artifact with proof-level annotations.
   *
   * Combines runtime truth, retrieval health, credential presence, and
   * proof-level annotations into a single jq-parseable JSON artifact. Each
   * runtime fact gets a machine-checkable assertion (pass/fail/degraded/skipped).
   *
   * Proof level: {@see ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED}
   * (config inspection only — no probes sent).
   *
   * @command ilas:runtime-diagnostics
   * @aliases runtime-diagnostics
   * @option section Print only a specific section (matrix, integrations, credentials, retrieval, degraded, commands).
   * @usage ilas:runtime-diagnostics
   *   Print the full diagnostics artifact as JSON.
   * @usage ilas:runtime-diagnostics --section=matrix
   *   Print only the diagnostics matrix.
   * @usage ilas:runtime-diagnostics | jq '.diagnostics_matrix[] | select(.assertion == "fail")'
   *   List all failing diagnostic facts.
   */
  public function runtimeDiagnostics(array $options = ['section' => NULL]): int {
    $matrixBuilder = $this->resolveMatrixBuilder();
    if ($matrixBuilder === NULL) {
      return self::EXIT_FAILURE;
    }

    try {
      $diagnostics = $matrixBuilder->buildDiagnostics();
    }
    catch (\Throwable $e) {
      $this->logger()?->error('Runtime diagnostics build failed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return self::EXIT_FAILURE;
    }

    $section = $options['section'] ?? NULL;

    if ($section !== NULL) {
      $outputKey = self::SECTION_MAP[$section] ?? NULL;
      if ($outputKey === NULL) {
        $this->logger()->error('Unknown section: {section}. Valid: {valid}', [
          'section' => $section,
          'valid' => implode(', ', array_keys(self::SECTION_MAP)),
        ]);
        return self::EXIT_FAILURE;
      }

      $output = $diagnostics[$outputKey] ?? [];
      $this->output()->writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

      return $this->hasFailures($diagnostics) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
    }

    $this->output()->writeln(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $this->hasFailures($diagnostics) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Resolves the diagnostics builder without making command discovery brittle.
   */
  private function resolveMatrixBuilder(): ?RuntimeDiagnosticsMatrixBuilder {
    try {
      if (!$this->container->has(self::MATRIX_BUILDER_SERVICE)) {
        $this->logger()?->error('Runtime diagnostics unavailable: missing service {service}. Rebuild Drupal caches (`drush cr`) and retry.', [
          'service' => self::MATRIX_BUILDER_SERVICE,
        ]);
        return NULL;
      }

      $builder = $this->container->get(self::MATRIX_BUILDER_SERVICE);
    }
    catch (\Throwable $e) {
      $this->logger()?->error('Runtime diagnostics unavailable: could not resolve service {service}. Rebuild Drupal caches (`drush cr`) and retry. Error: {message}', [
        'service' => self::MATRIX_BUILDER_SERVICE,
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }

    if (!$builder instanceof RuntimeDiagnosticsMatrixBuilder) {
      $this->logger()?->error('Runtime diagnostics unavailable: service {service} returned an unexpected object. Rebuild Drupal caches (`drush cr`) and retry.', [
        'service' => self::MATRIX_BUILDER_SERVICE,
      ]);
      return NULL;
    }

    return $builder;
  }

  /**
   * Checks if any diagnostic matrix row has a 'fail' assertion.
   */
  private function hasFailures(array $diagnostics): bool {
    foreach ($diagnostics['diagnostics_matrix'] ?? [] as $row) {
      if (($row['assertion'] ?? '') === ObservabilityProofTaxonomy::ASSERTION_FAIL) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
