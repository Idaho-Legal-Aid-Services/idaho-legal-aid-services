<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\ObservabilityProofTaxonomy;
use Drupal\ilas_site_assistant\Service\CohereGenerationProbe;
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
   * Cohere generation probe service id.
   */
  private const COHERE_PROBE_SERVICE = 'ilas_site_assistant.cohere_generation_probe';

  /**
   * Valid --section values mapped to output keys.
   */
  private const SECTION_MAP = [
    'matrix' => 'diagnostics_matrix',
    'integrations' => 'integration_status',
    'credentials' => 'credential_inventory',
    'retrieval' => 'retrieval_inventory',
    'degraded' => 'degraded_mode_state',
    'probes' => 'active_probes',
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
   * by default. With --probe-llm, performs one safe exact-output Cohere probe
   * and annotates the LLM rows at L3 payload-acceptance proof.
   *
   * @command ilas:runtime-diagnostics
   * @aliases runtime-diagnostics
   * @option section Print only a specific section (matrix, integrations, credentials, retrieval, degraded, commands).
   * @option probe-llm Execute one safe active Cohere request-time classification probe.
   * @option probe-generation Execute one grounded-generation probe (VC-GENERATION-PROBE).
   * @usage ilas:runtime-diagnostics
   *   Print the full diagnostics artifact as JSON.
   * @usage ilas:runtime-diagnostics --probe-llm
   *   Print diagnostics plus an active no-cache Cohere probe result.
   * @usage ilas:runtime-diagnostics --section=matrix
   *   Print only the diagnostics matrix.
   * @usage ilas:runtime-diagnostics | jq '.diagnostics_matrix[] | select(.assertion == "fail")'
   *   List all failing diagnostic facts.
   */
  public function runtimeDiagnostics(
    array $options = [
      'section' => NULL,
      'probe-llm' => FALSE,
      'probe-generation' => FALSE,
    ],
  ): int {
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

    if (!empty($options['probe-llm'])) {
      $diagnostics = $this->attachLlmProbe($diagnostics);
    }
    if (!empty($options['probe-generation'])) {
      $diagnostics = $this->attachGenerationProbe($diagnostics);
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
   * Adds an active LLM probe result to diagnostics.
   *
   * @param array<string, mixed> $diagnostics
   *   Existing diagnostics payload.
   *
   * @return array<string, mixed>
   *   Diagnostics with active_probes.llm and updated LLM matrix rows.
   */
  private function attachLlmProbe(array $diagnostics): array {
    $probe = $this->runLlmProbe();
    $diagnostics['active_probes']['llm'] = $probe;
    $diagnostics['active_probes']['cohere'] = $probe;
    $diagnostics['verification_commands']['VC-LLM-PROBE'] = 'drush ilas:runtime-diagnostics --probe-llm';
    $diagnostics['verification_commands']['VC-COHERE-PROBE'] = 'drush ilas:cohere-probe';

    $success = !empty($probe['generation_probe_passed']);
    $reachable = !empty($probe['request_time_generation_reachable']);
    foreach (($diagnostics['diagnostics_matrix'] ?? []) as &$row) {
      if (!is_array($row)) {
        continue;
      }
      if (($row['fact_key'] ?? NULL) === 'llm.request_time_generation_reachable') {
        $row['current_value'] = $reachable;
        $row['source'] = 'CohereGenerationProbe explicit exact-output probe';
        $row['proof_level'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['proof_level_label'] = ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE);
        $row['static_proof_ceiling'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['verification_command'] = 'VC-COHERE-PROBE';
        $row['assertion'] = $reachable
          ? ObservabilityProofTaxonomy::ASSERTION_PASS
          : ObservabilityProofTaxonomy::ASSERTION_FAIL;
      }
      if (($row['fact_key'] ?? NULL) === 'llm.generation_probe_passed') {
        $row['current_value'] = $success;
        $row['source'] = 'CohereGenerationProbe expected content assertion';
        $row['proof_level'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['proof_level_label'] = ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE);
        $row['static_proof_ceiling'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['verification_command'] = 'VC-COHERE-PROBE';
        $row['assertion'] = $success
          ? ObservabilityProofTaxonomy::ASSERTION_PASS
          : ObservabilityProofTaxonomy::ASSERTION_FAIL;
      }
      if (($row['fact_key'] ?? NULL) === 'llm.generation_attempted') {
        $row['current_value'] = !empty($probe['generation_attempted']);
        $row['source'] = 'CohereGenerationProbe last explicit probe state';
        $row['proof_level'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['proof_level_label'] = ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE);
        $row['static_proof_ceiling'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['verification_command'] = 'VC-COHERE-PROBE';
        $row['assertion'] = !empty($probe['generation_attempted'])
          ? ObservabilityProofTaxonomy::ASSERTION_PASS
          : ObservabilityProofTaxonomy::ASSERTION_DEGRADED;
      }
      if (($row['fact_key'] ?? NULL) === 'llm.last_error') {
        $row['current_value'] = $probe['last_error'] ?? NULL;
        $row['source'] = 'CohereGenerationProbe sanitized last error';
        $row['proof_level'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['proof_level_label'] = ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE);
        $row['static_proof_ceiling'] = ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE;
        $row['verification_command'] = 'VC-COHERE-PROBE';
        $row['assertion'] = empty($probe['last_error'])
          ? ObservabilityProofTaxonomy::ASSERTION_PASS
          : ObservabilityProofTaxonomy::ASSERTION_DEGRADED;
      }
    }
    unset($row);

    if (isset($diagnostics['integration_status']['llm']) && is_array($diagnostics['integration_status']['llm'])) {
      $diagnostics['integration_status']['llm']['active_probe_success'] = $success;
      $diagnostics['integration_status']['llm']['request_time_generation_reachable'] = $reachable;
      $diagnostics['integration_status']['llm']['generation_probe_passed'] = $success;
      $diagnostics['integration_status']['llm']['generation_attempted'] = !empty($probe['generation_attempted']);
      $diagnostics['integration_status']['llm']['last_error'] = $probe['last_error'] ?? NULL;
      $diagnostics['integration_status']['llm']['achieved_proof_level'] = $success
        ? ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE
        : ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED;
      $diagnostics['integration_status']['llm']['verification_command'] = 'VC-COHERE-PROBE';
    }

    return $diagnostics;
  }

  /**
   * Attaches an active grounded-generation probe to the diagnostics payload.
   *
   * @param array<string, mixed> $diagnostics
   *   Existing diagnostics payload.
   *
   * @return array<string, mixed>
   *   Diagnostics with active_probes.grounded_generation.
   */
  private function attachGenerationProbe(array $diagnostics): array {
    $probe = [];
    try {
      if ($this->container->has(self::COHERE_PROBE_SERVICE)) {
        $service = $this->container->get(self::COHERE_PROBE_SERVICE);
        if ($service instanceof CohereGenerationProbe) {
          $probe = $service->probeGrounded();
        }
      }
    }
    catch (\Throwable $e) {
      $probe = [
        'success' => FALSE,
        'grounded_probe_passed' => FALSE,
        'fallback_reason' => 'exception',
        'error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ];
    }
    if ($probe === []) {
      $probe = [
        'success' => FALSE,
        'grounded_probe_passed' => FALSE,
        'fallback_reason' => 'missing_service',
      ];
    }
    $probe['proof_level'] = !empty($probe['grounded_probe_passed'])
      ? ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE
      : ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED;
    $diagnostics['active_probes']['grounded_generation'] = $probe;
    $diagnostics['verification_commands']['VC-GENERATION-PROBE'] = 'drush ilas:cohere-probe --grounded';
    return $diagnostics;
  }

  /**
   * Executes the LLM probe if the service is available.
   *
   * @return array<string, mixed>
   *   Safe probe result.
   */
  private function runLlmProbe(): array {
    try {
      if (!$this->container->has(self::COHERE_PROBE_SERVICE)) {
        return [
          'success' => FALSE,
          'generation_probe_passed' => FALSE,
          'fallback_reason' => 'missing_service',
          'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        ];
      }

      $probe = $this->container->get(self::COHERE_PROBE_SERVICE);
      if (!$probe instanceof CohereGenerationProbe) {
        return [
          'success' => FALSE,
          'generation_probe_passed' => FALSE,
          'fallback_reason' => 'invalid_service',
          'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        ];
      }

      return $probe->probe();
    }
    catch (\Throwable $e) {
      return [
        'success' => FALSE,
        'generation_probe_passed' => FALSE,
        'fallback_reason' => 'exception',
        'error_class' => get_class($e),
        'error_signature' => substr(hash('sha256', $e->getMessage()), 0, 16),
        'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      ];
    }
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
