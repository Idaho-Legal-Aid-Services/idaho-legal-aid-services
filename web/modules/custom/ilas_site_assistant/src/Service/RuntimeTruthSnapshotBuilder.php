<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;

/**
 * Builds a sanitized snapshot of AILA runtime truth versus stored config.
 */
class RuntimeTruthSnapshotBuilder {

  /**
   * Constructs a runtime-truth snapshot builder.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StorageInterface $configSyncStorage,
  ) {}

  /**
   * Builds the sanitized runtime-truth snapshot.
   *
   * @return array<string, mixed>
   *   A machine-readable runtime-truth snapshot.
   */
  public function buildSnapshot(): array {
    $environment = $this->buildEnvironment();
    $exportedStorage = $this->buildExportedStorage();
    $effectiveRuntime = $this->buildEffectiveRuntime();
    $runtimeSiteSettings = $this->buildRuntimeSiteSettings();

    return [
      'environment' => $environment,
      'exported_storage' => $exportedStorage,
      'effective_runtime' => $effectiveRuntime,
      'runtime_site_settings' => $runtimeSiteSettings,
      'browser_expected' => $this->buildBrowserExpected($environment, $effectiveRuntime),
      'override_channels' => $this->buildOverrideChannels(),
      'divergences' => $this->buildDivergences($exportedStorage, $effectiveRuntime),
    ];
  }

  /**
   * Builds the sanitized environment summary.
   *
   * @return array<string, bool|string>
   *   The current environment summary.
   */
  public function buildEnvironment(): array {
    $observability = $this->getObservabilitySettings();
    $pantheonEnvironment = $this->stringValue($observability['pantheon_environment'] ?? getenv('PANTHEON_ENVIRONMENT') ?: '');

    return [
      'effective_environment' => $this->stringValue($observability['environment'] ?? ($pantheonEnvironment !== '' ? $pantheonEnvironment : 'local')),
      'pantheon_environment' => $pantheonEnvironment,
      'multidev_name' => $this->stringValue($observability['multidev_name'] ?? ''),
      'release' => $this->stringValue($observability['release'] ?? ''),
      'git_sha' => $this->stringValue($observability['git_sha'] ?? ''),
      'site_name' => $this->stringValue($observability['pantheon_site_name'] ?? ($observability['site_name'] ?? '')),
      'site_id' => $this->stringValue($observability['pantheon_site_id'] ?? ($observability['site_id'] ?? '')),
      'public_site_url_present' => $this->valuePresent($observability['public_site_url'] ?? NULL),
    ];
  }

  /**
   * Builds the exported-storage view from config sync.
   *
   * @return array<string, mixed>
   *   The sanitized stored-config snapshot.
   */
  public function buildExportedStorage(): array {
    $assistant = $this->readRequiredSyncConfig('ilas_site_assistant.settings');
    $pinecone = $this->readRequiredSyncConfig('key.key.pinecone_api_key');
    $raven = $this->readOptionalSyncConfig('raven.settings');

    return [
      'llm' => [
        'enabled' => (bool) ($assistant['llm']['enabled'] ?? FALSE),
      ],
      'vector_search' => [
        'enabled' => (bool) ($assistant['vector_search']['enabled'] ?? FALSE),
      ],
      'langfuse' => [
        'enabled' => (bool) ($assistant['langfuse']['enabled'] ?? FALSE),
        'public_key_present' => $this->valuePresent($assistant['langfuse']['public_key'] ?? NULL),
        'secret_key_present' => $this->valuePresent($assistant['langfuse']['secret_key'] ?? NULL),
        'environment' => $this->stringValue($assistant['langfuse']['environment'] ?? ''),
        'sample_rate' => (float) ($assistant['langfuse']['sample_rate'] ?? 0.0),
      ],
      'sentry' => [
        'config_file_present' => $raven !== [],
        'client_key_present' => $this->valuePresent($raven['client_key'] ?? NULL),
        'public_dsn_present' => $this->valuePresent($raven['public_dsn'] ?? NULL),
        'environment' => $this->stringValue($raven['environment'] ?? ''),
        'release' => $this->stringValue($raven['release'] ?? ''),
      ],
      'pinecone' => [
        'key_present' => $this->keyValuePresent($pinecone['key_provider_settings'] ?? []),
      ],
      'google_analytics' => [
        'tag_present' => FALSE,
      ],
    ];
  }

  /**
   * Builds the effective-runtime view from Drupal config + settings.
   *
   * @return array<string, mixed>
   *   The sanitized effective-runtime snapshot.
   */
  public function buildEffectiveRuntime(): array {
    $assistant = $this->configFactory->get('ilas_site_assistant.settings');
    $raven = $this->configFactory->get('raven.settings');
    $pinecone = $this->configFactory->get('key.key.pinecone_api_key');
    $publicDsn = $this->stringValue($raven->get('public_dsn') ?? '');
    $browserEnabled = $publicDsn !== '' && (
      (bool) $raven->get('javascript_error_handler')
      || (float) ($raven->get('browser_traces_sample_rate') ?? 0.0) > 0
    );

    return [
      'llm' => [
        'enabled' => (bool) $assistant->get('llm.enabled'),
      ],
      'vector_search' => [
        'enabled' => (bool) $assistant->get('vector_search.enabled'),
      ],
      'langfuse' => [
        'enabled' => (bool) $assistant->get('langfuse.enabled'),
        'public_key_present' => $this->valuePresent($assistant->get('langfuse.public_key')),
        'secret_key_present' => $this->valuePresent($assistant->get('langfuse.secret_key')),
        'environment' => $this->stringValue($assistant->get('langfuse.environment')),
        'sample_rate' => (float) ($assistant->get('langfuse.sample_rate') ?? 0.0),
      ],
      'sentry' => [
        'enabled' => $this->valuePresent($raven->get('client_key')),
        'client_key_present' => $this->valuePresent($raven->get('client_key')),
        'public_dsn_present' => $this->valuePresent($publicDsn),
        'environment' => $this->stringValue($raven->get('environment')),
        'release' => $this->stringValue($raven->get('release')),
        'browser_enabled' => $browserEnabled,
        'show_report_dialog' => (bool) $raven->get('show_report_dialog'),
      ],
      'pinecone' => [
        'key_present' => $this->keyValuePresent($pinecone->get('key_provider_settings') ?? []),
      ],
      'google_analytics' => [
        'tag_present' => $this->valuePresent(Settings::get('google_tag_id')),
      ],
    ];
  }

  /**
   * Builds the site-setting-only runtime surfaces.
   *
   * @return array<string, bool>
   *   The sanitized runtime-site-settings snapshot.
   */
  public function buildRuntimeSiteSettings(): array {
    return [
      'gemini_api_key_present' => $this->valuePresent(Settings::get('ilas_gemini_api_key')),
      'vertex_service_account_present' => $this->valuePresent(Settings::get('ilas_vertex_sa_json')),
      'legalserver_online_application_url_present' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')),
      'diagnostics_token_present' => $this->valuePresent(Settings::get('ilas_assistant_diagnostics_token')),
      'google_tag_id_present' => $this->valuePresent(Settings::get('google_tag_id')),
      'debug_metadata_force_disable' => (bool) Settings::get('ilas_site_assistant_debug_metadata_force_disable', FALSE),
    ];
  }

  /**
   * Builds the browser-runtime expectations from current runtime inputs.
   *
   * @param array<string, bool|string> $environment
   *   The sanitized environment summary.
   * @param array<string, mixed> $effectiveRuntime
   *   The sanitized effective-runtime snapshot.
   *
   * @return array<string, mixed>
   *   The expected browser-runtime markers.
   */
  public function buildBrowserExpected(array $environment, array $effectiveRuntime): array {
    $observability = $this->getObservabilitySettings();
    $replaySessionSampleRate = (float) ($observability['sentry']['browser']['replay_session_sample_rate'] ?? 0.0);
    $replayOnErrorSampleRate = (float) ($observability['sentry']['browser']['replay_on_error_sample_rate'] ?? 0.0);
    $gaTagPresent = (bool) ($effectiveRuntime['google_analytics']['tag_present'] ?? FALSE);

    return [
      'environment' => $environment['effective_environment'] ?? 'local',
      'release' => $environment['release'] ?? '',
      'sentry' => [
        'enabled' => (bool) ($effectiveRuntime['sentry']['enabled'] ?? FALSE),
        'browser_enabled' => (bool) ($effectiveRuntime['sentry']['browser_enabled'] ?? FALSE),
        'show_report_dialog' => (bool) ($effectiveRuntime['sentry']['show_report_dialog'] ?? FALSE),
        'replay_enabled' => $replaySessionSampleRate > 0 || $replayOnErrorSampleRate > 0,
        'replay_session_sample_rate' => $replaySessionSampleRate,
        'replay_on_error_sample_rate' => $replayOnErrorSampleRate,
      ],
      'google_analytics' => [
        'tag_present' => $gaTagPresent,
        'loader_expected' => $gaTagPresent,
        'data_layer_expected' => $gaTagPresent,
        'assistant_page_suppressed' => TRUE,
        'assistant_page_loader_expected' => FALSE,
        'assistant_page_data_layer_expected' => FALSE,
      ],
    ];
  }

  /**
   * Builds the authoritative override-channel labels.
   *
   * @return array<string, string>
   *   The override-channel labels by field path.
   */
  public function buildOverrideChannels(): array {
    $vectorSearchOverrideChannel = $this->resolveVectorSearchOverrideChannel();

    return [
      'llm.enabled' => 'settings.php live branch',
      'vector_search.enabled' => $vectorSearchOverrideChannel,
      'langfuse.enabled' => 'settings.php secret -> getenv/pantheon_get_secret',
      'langfuse.public_key_present' => 'settings.php secret -> getenv/pantheon_get_secret',
      'langfuse.secret_key_present' => 'settings.php secret -> getenv/pantheon_get_secret',
      'langfuse.environment' => 'settings.php secret -> getenv/pantheon_get_secret',
      'langfuse.sample_rate' => 'config export',
      'raven.settings.client_key_present' => 'settings.php secret -> getenv/pantheon_get_secret',
      'raven.settings.public_dsn_present' => 'settings.php secret -> getenv/pantheon_get_secret',
      'raven.settings.environment' => 'settings.php secret -> getenv/pantheon_get_secret',
      'raven.settings.release' => 'settings.php secret -> getenv/pantheon_get_secret',
      'key.key.pinecone_api_key.key_present' => 'settings.php secret -> getenv/pantheon_get_secret',
      'ilas_gemini_api_key' => 'settings.php runtime site setting',
      'ilas_vertex_sa_json' => 'settings.php runtime site setting',
      'ilas_site_assistant_legalserver_online_application_url' => 'settings.php runtime site setting',
      'ilas_assistant_diagnostics_token' => 'settings.php runtime site setting',
      'google_tag_id' => 'settings.php live branch',
      'ilas_site_assistant_debug_metadata_force_disable' => 'settings.php live branch',
    ];
  }

  /**
   * Builds the stored-versus-effective divergence list.
   *
   * @param array<string, mixed> $exportedStorage
   *   The sanitized stored-config snapshot.
   * @param array<string, mixed> $effectiveRuntime
   *   The sanitized effective-runtime snapshot.
   *
   * @return array<int, array<string, mixed>>
   *   The stored-versus-effective divergence list.
   */
  public function buildDivergences(array $exportedStorage, array $effectiveRuntime): array {
    $comparisons = [
      'llm.enabled' => [
        'stored' => $exportedStorage['llm']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['enabled'] ?? FALSE,
      ],
      'vector_search.enabled' => [
        'stored' => $exportedStorage['vector_search']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['vector_search']['enabled'] ?? FALSE,
      ],
      'langfuse.enabled' => [
        'stored' => $exportedStorage['langfuse']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['langfuse']['enabled'] ?? FALSE,
      ],
      'langfuse.public_key_present' => [
        'stored' => $exportedStorage['langfuse']['public_key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['langfuse']['public_key_present'] ?? FALSE,
      ],
      'langfuse.secret_key_present' => [
        'stored' => $exportedStorage['langfuse']['secret_key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['langfuse']['secret_key_present'] ?? FALSE,
      ],
      'langfuse.environment' => [
        'stored' => $exportedStorage['langfuse']['environment'] ?? '',
        'effective' => $effectiveRuntime['langfuse']['environment'] ?? '',
      ],
      'langfuse.sample_rate' => [
        'stored' => $exportedStorage['langfuse']['sample_rate'] ?? 0.0,
        'effective' => $effectiveRuntime['langfuse']['sample_rate'] ?? 0.0,
      ],
      'raven.settings.client_key_present' => [
        'stored' => $exportedStorage['sentry']['client_key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['sentry']['client_key_present'] ?? FALSE,
      ],
      'raven.settings.public_dsn_present' => [
        'stored' => $exportedStorage['sentry']['public_dsn_present'] ?? FALSE,
        'effective' => $effectiveRuntime['sentry']['public_dsn_present'] ?? FALSE,
      ],
      'raven.settings.environment' => [
        'stored' => $exportedStorage['sentry']['environment'] ?? '',
        'effective' => $effectiveRuntime['sentry']['environment'] ?? '',
      ],
      'raven.settings.release' => [
        'stored' => $exportedStorage['sentry']['release'] ?? '',
        'effective' => $effectiveRuntime['sentry']['release'] ?? '',
      ],
      'key.key.pinecone_api_key.key_present' => [
        'stored' => $exportedStorage['pinecone']['key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['pinecone']['key_present'] ?? FALSE,
      ],
      'google_tag_id' => [
        'stored' => $exportedStorage['google_analytics']['tag_present'] ?? FALSE,
        'effective' => $effectiveRuntime['google_analytics']['tag_present'] ?? FALSE,
      ],
    ];

    $overrideChannels = $this->buildOverrideChannels();
    $divergences = [];

    foreach ($comparisons as $field => $comparison) {
      if ($comparison['stored'] === $comparison['effective']) {
        continue;
      }

      $divergences[] = [
        'field' => $field,
        'stored_value' => $comparison['stored'],
        'effective_value' => $comparison['effective'],
        'authoritative_source' => $overrideChannels[$field] ?? 'config export',
      ];
    }

    return $divergences;
  }

  /**
   * Reads a required config-sync object.
   *
   * @param string $configName
   *   The config object name.
   *
   * @return array<string, mixed>
   *   The raw config data.
   */
  protected function readRequiredSyncConfig(string $configName): array {
    $data = $this->configSyncStorage->read($configName);
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('Required config-sync object "%s" is missing or unreadable.', $configName));
    }
    return $data;
  }

  /**
   * Resolves the authoritative source label for vector enablement.
   */
  protected function resolveVectorSearchOverrideChannel(): string {
    $overrideChannel = Settings::get('ilas_vector_search_override_channel');
    if (is_string($overrideChannel) && $overrideChannel !== '') {
      return $overrideChannel;
    }

    $observability = $this->getObservabilitySettings();
    $pantheonEnvironment = $this->stringValue($observability['pantheon_environment'] ?? getenv('PANTHEON_ENVIRONMENT') ?: '');
    if ($pantheonEnvironment === 'live') {
      return 'settings.php live branch';
    }

    return 'config export';
  }

  /**
   * Reads an optional config-sync object.
   *
   * @param string $configName
   *   The config object name.
   *
   * @return array<string, mixed>
   *   The raw config data, or an empty array when absent.
   */
  protected function readOptionalSyncConfig(string $configName): array {
    $data = $this->configSyncStorage->read($configName);
    return is_array($data) ? $data : [];
  }

  /**
   * Returns the current observability settings array.
   *
   * @return array<string, mixed>
   *   The runtime observability settings.
   */
  protected function getObservabilitySettings(): array {
    $settings = Settings::get('ilas_observability', []);
    return is_array($settings) ? $settings : [];
  }

  /**
   * Returns whether a config key-provider value is present.
   *
   * @param mixed $providerSettings
   *   The provider settings array.
   *
   * @return bool
   *   TRUE when the key value is present.
   */
  protected function keyValuePresent(mixed $providerSettings): bool {
    if (!is_array($providerSettings)) {
      return FALSE;
    }

    return $this->valuePresent($providerSettings['key_value'] ?? NULL);
  }

  /**
   * Returns whether a scalar/array value is present without exposing it.
   *
   * @param mixed $value
   *   The value to inspect.
   *
   * @return bool
   *   TRUE when the value is present.
   */
  protected function valuePresent(mixed $value): bool {
    if (is_string($value)) {
      return trim($value) !== '';
    }

    if (is_array($value)) {
      return $value !== [];
    }

    if (is_bool($value)) {
      return $value;
    }

    return $value !== NULL;
  }

  /**
   * Casts a value to a safe string.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return string
   *   The safe string representation.
   */
  protected function stringValue(mixed $value): string {
    if (!is_scalar($value) && $value !== NULL) {
      return '';
    }

    return trim((string) $value);
  }

}
