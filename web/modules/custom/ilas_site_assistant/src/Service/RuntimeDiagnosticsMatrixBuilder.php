<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Builds a unified runtime-diagnostics artifact with proof-level annotations.
 *
 * Composes RuntimeTruthSnapshotBuilder, RetrievalConfigurationService, and
 * ObservabilityProofTaxonomy into a single machine-checkable JSON artifact.
 * Each runtime fact gets a pass/fail/degraded/skipped assertion and proof-level
 * annotation so operators and automation can answer "is this environment
 * healthy, and what proof do I have?" from a single command.
 *
 * Design: read-only composition layer. Sends no probes, modifies no state.
 * All proof_level fields default to L0:Unverified because this command
 * inspects config and service state — it does not execute connectivity probes.
 */
final class RuntimeDiagnosticsMatrixBuilder {

  public const SCHEMA_VERSION = '1.0.0';

  public function __construct(
    protected RuntimeTruthSnapshotBuilder $snapshotBuilder,
    protected RetrievalConfigurationService $retrievalConfiguration,
    protected ?QueueHealthMonitor $queueHealthMonitor = NULL,
    protected ?CronHealthTracker $cronHealthTracker = NULL,
    protected ?SloDefinitions $sloDefinitions = NULL,
  ) {}

  /**
   * Builds the unified runtime diagnostics artifact.
   *
   * @return array<string, mixed>
   *   A machine-readable diagnostics artifact with proof-level annotations.
   */
  public function buildDiagnostics(): array {
    $snapshot = $this->snapshotBuilder->buildSnapshot();
    $retrievalHealth = $this->retrievalConfiguration->getHealthSnapshot();

    $matrix = $this->buildDiagnosticsMatrix($snapshot, $retrievalHealth);

    return [
      'schema_version' => self::SCHEMA_VERSION,
      'timestamp' => gmdate('Y-m-d\TH:i:s+00:00'),
      'environment' => $this->buildEnvironmentSummary($snapshot),
      'runtime_truth_summary' => $this->buildRuntimeTruthSummary($snapshot),
      'diagnostics_matrix' => $matrix,
      'integration_status' => $this->buildIntegrationStatus($snapshot),
      'credential_inventory' => $this->buildCredentialInventory($snapshot),
      'retrieval_inventory' => $this->buildRetrievalInventory($retrievalHealth),
      'degraded_mode_state' => $this->buildDegradedModeState($snapshot, $retrievalHealth, $matrix),
      'verification_commands' => self::buildVerificationCommands(),
    ];
  }

  /**
   * Builds the environment classification summary.
   */
  private function buildEnvironmentSummary(array $snapshot): array {
    $env = $snapshot['environment'] ?? [];
    $effective = (string) ($env['effective_environment'] ?? 'local');

    return [
      'effective_environment' => $effective,
      'pantheon_environment' => (string) ($env['pantheon_environment'] ?? ''),
      'classification' => in_array($effective, ['pantheon-live', 'live'], TRUE) ? 'production' : 'non-production',
    ];
  }

  /**
   * Builds the runtime-truth divergence summary.
   */
  private function buildRuntimeTruthSummary(array $snapshot): array {
    $divergences = $snapshot['divergences'] ?? [];

    return [
      'divergence_count' => count($divergences),
      'divergence_fields' => array_column($divergences, 'field'),
    ];
  }

  /**
   * Builds the diagnostics fact matrix.
   *
   * @return array<int, array<string, mixed>>
   *   Ordered list of diagnostic fact rows.
   */
  private function buildDiagnosticsMatrix(array $snapshot, array $retrievalHealth): array {
    $effective = $snapshot['effective_runtime'] ?? [];
    $overrides = $snapshot['override_channels'] ?? [];
    $runtimeSettings = $snapshot['runtime_site_settings'] ?? [];
    $rows = [];

    // -- Toggle facts --
    $rows[] = $this->toggleFact('llm.enabled', $effective['llm']['enabled'] ?? FALSE, $overrides['llm.enabled'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->toggleFact('llm.runtime_ready', $effective['llm']['runtime_ready'] ?? FALSE, $overrides['llm.runtime_ready'] ?? 'LlmEnhancer::isEnabled()', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->toggleFact('vector_search.enabled', $effective['vector_search']['enabled'] ?? FALSE, $overrides['vector_search.enabled'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->toggleFact('langfuse.enabled', $effective['langfuse']['enabled'] ?? FALSE, $overrides['langfuse.enabled'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE, 'VC-LANGFUSE-PROBE-DIRECT');
    $rows[] = $this->toggleFact('voyage.enabled', $effective['voyage']['enabled'] ?? FALSE, $overrides['voyage.enabled'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->toggleFact('voyage.runtime_ready', $effective['voyage']['runtime_ready'] ?? FALSE, $overrides['voyage.runtime_ready'] ?? 'VoyageReranker::isEnabled()', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');

    // -- Credential facts --
    $rows[] = $this->credentialFact('llm.gemini_api_key_present', $effective['llm']['gemini_api_key_present'] ?? FALSE, $overrides['llm.gemini_api_key_present'] ?? 'settings.php runtime site setting', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->credentialFact('llm.vertex_service_account_present', $effective['llm']['vertex_service_account_present'] ?? FALSE, $overrides['llm.vertex_service_account_present'] ?? 'settings.php runtime site setting', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->credentialFact('langfuse.public_key_present', $effective['langfuse']['public_key_present'] ?? FALSE, $overrides['langfuse.public_key_present'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE, 'VC-LANGFUSE-PROBE-DIRECT');
    $rows[] = $this->credentialFact('langfuse.secret_key_present', $effective['langfuse']['secret_key_present'] ?? FALSE, $overrides['langfuse.secret_key_present'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE, 'VC-LANGFUSE-PROBE-DIRECT');
    $rows[] = $this->credentialFact('sentry.client_key_present', $effective['sentry']['client_key_present'] ?? FALSE, $overrides['raven.settings.client_key_present'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT, 'VC-SENTRY-PROBE');
    $rows[] = $this->credentialFact('sentry.public_dsn_present', $effective['sentry']['public_dsn_present'] ?? FALSE, $overrides['raven.settings.public_dsn_present'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT, 'VC-SENTRY-PROBE');
    $rows[] = $this->credentialFact('pinecone.api_key_present', $effective['pinecone']['key_present'] ?? FALSE, $overrides['key.key.pinecone_api_key.key_present'] ?? 'config export', ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT, 'VC-SEARCHAPI-INVENTORY');
    $rows[] = $this->credentialFact('voyage.api_key_present', $effective['voyage']['api_key_present'] ?? FALSE, $overrides['voyage.api_key_present'] ?? 'settings.php runtime site setting', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');
    $rows[] = $this->credentialFact('diagnostics_token_present', $runtimeSettings['diagnostics_token_present'] ?? FALSE, $overrides['ilas_assistant_diagnostics_token'] ?? 'settings.php runtime site setting', ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED, 'VC-RUNTIME-TRUTH');

    // -- Retrieval index/server facts --
    $retrievalDeps = $retrievalHealth['retrieval'] ?? [];
    foreach ($retrievalDeps as $depKey => $dep) {
      $depType = (string) ($dep['dependency_type'] ?? 'index');
      $category = $depType === 'server' ? 'server' : 'index';
      $rows[] = $this->retrievalFact('retrieval.' . $depKey, $category, $dep);
    }

    // -- URL facts --
    $serviceAreas = $retrievalHealth['canonical_urls']['service_areas'] ?? [];
    $rows[] = $this->urlFact('retrieval.service_area_urls', $serviceAreas);

    $legalServer = $retrievalHealth['canonical_urls']['legalserver_intake_url'] ?? [];
    $rows[] = $this->urlFact('retrieval.legalserver_url', $legalServer);

    // -- SLO facts --
    $rows[] = $this->sloFact('cron.health', $this->getCronHealthStatus());
    $rows[] = $this->sloFact('queue.health', $this->getQueueHealthStatus());

    return $rows;
  }

  /**
   * Builds a toggle fact row.
   */
  private function toggleFact(string $factKey, bool $value, string $source, string $proofCeiling, string $verificationCommand): array {
    return [
      'fact_key' => $factKey,
      'category' => 'toggle',
      'current_value' => $value,
      'source' => $source,
      'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
      'static_proof_ceiling' => $proofCeiling,
      'verification_command' => $verificationCommand,
      'assertion' => ObservabilityProofTaxonomy::ASSERTION_PASS,
    ];
  }

  /**
   * Builds a credential fact row.
   */
  private function credentialFact(string $factKey, bool $present, string $source, string $proofCeiling, string $verificationCommand): array {
    return [
      'fact_key' => $factKey,
      'category' => 'credential',
      'current_value' => $present,
      'source' => $source,
      'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
      'static_proof_ceiling' => $proofCeiling,
      'verification_command' => $verificationCommand,
      'assertion' => $present ? ObservabilityProofTaxonomy::ASSERTION_PASS : ObservabilityProofTaxonomy::ASSERTION_DEGRADED,
    ];
  }

  /**
   * Builds a retrieval index/server fact row from a dependency snapshot.
   */
  private function retrievalFact(string $factKey, string $category, array $dep): array {
    $active = (bool) ($dep['active'] ?? TRUE);
    $status = (string) ($dep['status'] ?? 'degraded');
    $classification = (string) ($dep['classification'] ?? 'required');

    if (!$active) {
      $assertion = ObservabilityProofTaxonomy::ASSERTION_SKIPPED;
    }
    elseif ($status === 'healthy') {
      $assertion = ObservabilityProofTaxonomy::ASSERTION_PASS;
    }
    elseif ($classification === 'optional' || $classification === 'feature_gated') {
      $assertion = ObservabilityProofTaxonomy::ASSERTION_DEGRADED;
    }
    else {
      $assertion = ObservabilityProofTaxonomy::ASSERTION_FAIL;
    }

    return [
      'fact_key' => $factKey,
      'category' => $category,
      'current_value' => $status,
      'source' => 'RetrievalConfigurationService runtime resolution',
      'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
      'static_proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
      'verification_command' => 'VC-SEARCHAPI-INVENTORY',
      'assertion' => $assertion,
    ];
  }

  /**
   * Builds a URL fact row from a canonical URL check result.
   */
  private function urlFact(string $factKey, array $check): array {
    $status = (string) ($check['status'] ?? 'degraded');

    return [
      'fact_key' => $factKey,
      'category' => 'url',
      'current_value' => $status,
      'source' => 'RetrievalConfigurationService runtime resolution',
      'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
      'static_proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'verification_command' => 'VC-RUNTIME-TRUTH',
      'assertion' => $status === 'healthy' ? ObservabilityProofTaxonomy::ASSERTION_PASS : ObservabilityProofTaxonomy::ASSERTION_DEGRADED,
    ];
  }

  /**
   * Builds an SLO fact row from a health status array.
   */
  private function sloFact(string $factKey, ?array $healthStatus): array {
    if ($healthStatus === NULL) {
      return [
        'fact_key' => $factKey,
        'category' => 'slo',
        'current_value' => 'unavailable',
        'source' => 'service not available',
        'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
        'static_proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'verification_command' => 'VC-RUNTIME-LOCAL-SAFE',
        'assertion' => ObservabilityProofTaxonomy::ASSERTION_SKIPPED,
      ];
    }

    $status = (string) ($healthStatus['status'] ?? 'unknown');
    $assertion = $status === 'healthy'
      ? ObservabilityProofTaxonomy::ASSERTION_PASS
      : ObservabilityProofTaxonomy::ASSERTION_DEGRADED;

    return [
      'fact_key' => $factKey,
      'category' => 'slo',
      'current_value' => $status,
      'source' => 'runtime state',
      'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'proof_level_label' => ObservabilityProofTaxonomy::proofStrengthLabel(ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED),
      'static_proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      'verification_command' => 'VC-RUNTIME-LOCAL-SAFE',
      'assertion' => $assertion,
    ];
  }

  /**
   * Builds the integration status section.
   */
  private function buildIntegrationStatus(array $snapshot): array {
    $effective = $snapshot['effective_runtime'] ?? [];

    return [
      'sentry' => [
        'enabled' => (bool) ($effective['sentry']['enabled'] ?? FALSE),
        'credential_present' => (bool) ($effective['sentry']['client_key_present'] ?? FALSE),
        'achieved_proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
        'verification_command' => 'VC-SENTRY-PROBE',
      ],
      'langfuse' => [
        'enabled' => (bool) ($effective['langfuse']['enabled'] ?? FALSE),
        'credential_present' => (bool) ($effective['langfuse']['public_key_present'] ?? FALSE) && (bool) ($effective['langfuse']['secret_key_present'] ?? FALSE),
        'achieved_proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L3_PAYLOAD_ACCEPTANCE,
        'verification_command' => 'VC-LANGFUSE-PROBE-DIRECT',
      ],
      'pinecone' => [
        'enabled' => (bool) ($effective['vector_search']['enabled'] ?? FALSE),
        'credential_present' => (bool) ($effective['pinecone']['key_present'] ?? FALSE),
        'achieved_proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L1_TRANSPORT,
        'verification_command' => 'VC-SEARCHAPI-INVENTORY',
      ],
      'voyage' => [
        'enabled' => (bool) ($effective['voyage']['enabled'] ?? FALSE),
        'credential_present' => (bool) ($effective['voyage']['api_key_present'] ?? FALSE),
        'achieved_proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'proof_ceiling' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
        'verification_command' => 'VC-RUNTIME-TRUTH',
      ],
    ];
  }

  /**
   * Builds the credential inventory (boolean-only, no secret values).
   */
  private function buildCredentialInventory(array $snapshot): array {
    $effective = $snapshot['effective_runtime'] ?? [];
    $runtimeSettings = $snapshot['runtime_site_settings'] ?? [];

    return [
      'gemini_api_key' => (bool) ($effective['llm']['gemini_api_key_present'] ?? FALSE),
      'vertex_service_account' => (bool) ($effective['llm']['vertex_service_account_present'] ?? FALSE),
      'langfuse_public_key' => (bool) ($effective['langfuse']['public_key_present'] ?? FALSE),
      'langfuse_secret_key' => (bool) ($effective['langfuse']['secret_key_present'] ?? FALSE),
      'sentry_client_key' => (bool) ($effective['sentry']['client_key_present'] ?? FALSE),
      'sentry_public_dsn' => (bool) ($effective['sentry']['public_dsn_present'] ?? FALSE),
      'pinecone_api_key' => (bool) ($effective['pinecone']['key_present'] ?? FALSE),
      'voyage_api_key' => (bool) ($effective['voyage']['api_key_present'] ?? FALSE),
      'diagnostics_token' => (bool) ($runtimeSettings['diagnostics_token_present'] ?? FALSE),
      'legalserver_url' => (bool) ($runtimeSettings['legalserver_online_application_url_present'] ?? FALSE),
    ];
  }

  /**
   * Builds the retrieval inventory section.
   */
  private function buildRetrievalInventory(array $retrievalHealth): array {
    $inventory = [];

    foreach (($retrievalHealth['retrieval'] ?? []) as $depKey => $dep) {
      $entry = [
        'classification' => (string) ($dep['classification'] ?? 'required'),
        'active' => (bool) ($dep['active'] ?? TRUE),
        'status' => (string) ($dep['status'] ?? 'degraded'),
        'proof_level' => ObservabilityProofTaxonomy::LEVEL_L0_UNVERIFIED,
      ];

      if (($dep['dependency_type'] ?? '') === 'index') {
        $entry['index_id'] = (string) ($dep['index_id'] ?? '');
        $entry['exists'] = (bool) ($dep['exists'] ?? FALSE);
        $entry['enabled'] = (bool) ($dep['enabled'] ?? FALSE);
        $entry['server_id'] = (string) ($dep['server_id'] ?? '');
        $entry['server_exists'] = (bool) ($dep['server_exists'] ?? FALSE);
        $entry['server_enabled'] = (bool) ($dep['server_enabled'] ?? FALSE);
      }
      else {
        $entry['server_id'] = (string) ($dep['server_id'] ?? '');
        $entry['exists'] = (bool) ($dep['exists'] ?? FALSE);
        $entry['enabled'] = (bool) ($dep['enabled'] ?? FALSE);
      }

      $inventory[$depKey] = $entry;
    }

    return $inventory;
  }

  /**
   * Builds the degraded-mode state summary.
   */
  private function buildDegradedModeState(array $snapshot, array $retrievalHealth, array $matrix): array {
    $effective = $snapshot['effective_runtime'] ?? [];
    $activeDegradations = [];
    $failedFacts = [];
    $featureGatesOff = [];
    $missingCredentials = [];

    // Collect feature gates that are off.
    if (!($effective['vector_search']['enabled'] ?? FALSE)) {
      $featureGatesOff[] = 'vector_search';
    }
    if (!($effective['llm']['enabled'] ?? FALSE)) {
      $featureGatesOff[] = 'llm';
    }

    // Collect missing credentials from inventory.
    $credentials = $this->buildCredentialInventory($snapshot);
    foreach ($credentials as $credKey => $present) {
      if (!$present) {
        $missingCredentials[] = $credKey;
      }
    }

    // Collect degraded retrieval dependencies.
    foreach (($retrievalHealth['retrieval'] ?? []) as $depKey => $dep) {
      if (!empty($dep['active']) && ($dep['status'] ?? 'degraded') === 'degraded') {
        $activeDegradations[] = $depKey . ': ' . ($dep['failure_code'] ?? 'unknown');
      }
    }

    // Determine overall status from matrix assertions.
    $hasFailures = FALSE;
    foreach ($matrix as $row) {
      if (($row['assertion'] ?? '') === ObservabilityProofTaxonomy::ASSERTION_FAIL) {
        $hasFailures = TRUE;
        $failedFacts[] = $row['fact_key'];
      }
    }

    $hasDegradations = count($activeDegradations) > 0;

    if ($hasFailures) {
      $overallStatus = 'failing';
    }
    elseif ($hasDegradations) {
      $overallStatus = 'degraded';
    }
    else {
      $overallStatus = 'healthy';
    }

    return [
      'overall_status' => $overallStatus,
      'active_degradations' => $activeDegradations,
      'failed_facts' => $failedFacts,
      'feature_gates_off' => $featureGatesOff,
      'missing_credentials' => $missingCredentials,
    ];
  }

  /**
   * Returns the verification command aliases.
   */
  private static function buildVerificationCommands(): array {
    return [
      'VC-RUNTIME-TRUTH' => 'drush ilas:runtime-truth',
      'VC-RUNTIME-DIAGNOSTICS' => 'drush ilas:runtime-diagnostics',
      'VC-SENTRY-PROBE' => 'drush ilas:sentry-probe',
      'VC-LANGFUSE-PROBE-DIRECT' => 'drush ilas:langfuse-probe --direct',
      'VC-LANGFUSE-PROBE-QUEUED' => 'drush ilas:langfuse-probe',
      'VC-LANGFUSE-STATUS' => 'drush ilas:langfuse-status',
      'VC-LANGFUSE-LOOKUP' => 'drush ilas:langfuse-lookup <trace_id>',
      'VC-SEARCHAPI-INVENTORY' => 'drush search-api:server-list && drush search-api:list && drush search-api:status',
      'VC-RUNTIME-LOCAL-SAFE' => 'drush ilas:runtime-truth && drush ilas:langfuse-status',
    ];
  }

  /**
   * Returns cron health status if the tracker is available.
   */
  private function getCronHealthStatus(): ?array {
    if ($this->cronHealthTracker === NULL || $this->sloDefinitions === NULL) {
      return NULL;
    }

    return $this->cronHealthTracker->getHealthStatus($this->sloDefinitions);
  }

  /**
   * Returns queue health status if the monitor is available.
   */
  private function getQueueHealthStatus(): ?array {
    if ($this->queueHealthMonitor === NULL || $this->sloDefinitions === NULL) {
      return NULL;
    }

    return $this->queueHealthMonitor->getQueueHealthStatus($this->sloDefinitions);
  }

}
