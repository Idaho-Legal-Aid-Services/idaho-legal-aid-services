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
    protected ?RetrievalConfigurationService $retrievalConfiguration = NULL,
    protected ?AssistantSessionBootstrapGuard $sessionBootstrapGuard = NULL,
    protected ?AssistantReadEndpointGuard $readEndpointGuard = NULL,
    protected ?ConversationLogger $conversationLogger = NULL,
    protected ?LlmEnhancer $llmEnhancer = NULL,
    protected ?VoyageReranker $voyageReranker = NULL,
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
    $conversationLogging = is_array($assistant['conversation_logging'] ?? NULL) ? $assistant['conversation_logging'] : [];
    $llm = is_array($assistant['llm'] ?? NULL) ? $assistant['llm'] : [];
    $sessionBootstrap = is_array($assistant['session_bootstrap'] ?? NULL) ? $assistant['session_bootstrap'] : [];
    $readEndpointRateLimits = is_array($assistant['read_endpoint_rate_limits'] ?? NULL) ? $assistant['read_endpoint_rate_limits'] : [];
    $costControl = is_array($assistant['cost_control'] ?? NULL) ? $assistant['cost_control'] : [];
    $retrieval = is_array($assistant['retrieval'] ?? NULL) ? $assistant['retrieval'] : [];
    $canonicalUrls = is_array($assistant['canonical_urls'] ?? NULL) ? $assistant['canonical_urls'] : [];
    $voyage = is_array($assistant['voyage'] ?? NULL) ? $assistant['voyage'] : [];

    return [
      'rate_limit_per_minute' => (int) ($assistant['rate_limit_per_minute'] ?? 0),
      'rate_limit_per_hour' => (int) ($assistant['rate_limit_per_hour'] ?? 0),
      'session_bootstrap' => [
        'rate_limit_per_minute' => (int) ($sessionBootstrap['rate_limit_per_minute'] ?? 0),
        'rate_limit_per_hour' => (int) ($sessionBootstrap['rate_limit_per_hour'] ?? 0),
        'observation_window_hours' => (int) ($sessionBootstrap['observation_window_hours'] ?? 0),
      ],
      'read_endpoint_rate_limits' => [
        'suggest' => [
          'rate_limit_per_minute' => (int) (($readEndpointRateLimits['suggest']['rate_limit_per_minute'] ?? 0)),
          'rate_limit_per_hour' => (int) (($readEndpointRateLimits['suggest']['rate_limit_per_hour'] ?? 0)),
        ],
        'faq' => [
          'rate_limit_per_minute' => (int) (($readEndpointRateLimits['faq']['rate_limit_per_minute'] ?? 0)),
          'rate_limit_per_hour' => (int) (($readEndpointRateLimits['faq']['rate_limit_per_hour'] ?? 0)),
        ],
      ],
      'conversation_logging' => [
        'enabled' => (bool) ($conversationLogging['enabled'] ?? FALSE),
        'retention_hours' => (int) ($conversationLogging['retention_hours'] ?? 0),
        'redact_pii' => (bool) ($conversationLogging['redact_pii'] ?? FALSE),
        'show_user_notice' => (bool) ($conversationLogging['show_user_notice'] ?? FALSE),
      ],
      'llm' => [
        'enabled' => (bool) ($llm['enabled'] ?? FALSE),
        'provider' => $this->stringValue($llm['provider'] ?? ''),
        'model' => $this->stringValue($llm['model'] ?? ''),
        'fallback_on_error' => (bool) ($llm['fallback_on_error'] ?? TRUE),
        'global_rate_limit' => [
          'max_per_hour' => (int) (($llm['global_rate_limit']['max_per_hour'] ?? 0)),
          'window_seconds' => (int) (($llm['global_rate_limit']['window_seconds'] ?? 0)),
        ],
        'cost_control' => $this->sanitizeCostControlConfig($costControl),
        'gemini_api_key_present' => $this->valuePresent($llm['api_key'] ?? NULL),
        'vertex_project_id_present' => $this->valuePresent($llm['project_id'] ?? NULL),
        'vertex_location_present' => $this->valuePresent($llm['location'] ?? NULL),
        'vertex_service_account_present' => FALSE,
        'runtime_ready' => $this->buildStoredLlmRuntimeReady($llm),
      ],
      'vector_search' => [
        'enabled' => (bool) ($assistant['vector_search']['enabled'] ?? FALSE),
      ],
      'retrieval' => [
        'faq_index_id' => $this->stringValue($retrieval['faq_index_id'] ?? ''),
        'resource_index_id' => $this->stringValue($retrieval['resource_index_id'] ?? ''),
        'resource_fallback_index_id' => $this->stringValue($retrieval['resource_fallback_index_id'] ?? ''),
        'faq_vector_index_id' => $this->stringValue($retrieval['faq_vector_index_id'] ?? ''),
        'resource_vector_index_id' => $this->stringValue($retrieval['resource_vector_index_id'] ?? ''),
        'service_area_urls' => $this->buildStoredServiceAreaSummary($canonicalUrls),
        'legalserver_online_application_url' => $this->buildStoredLegalServerSummary(),
        'health' => $this->buildStoredRetrievalHealthSummary($retrieval, $canonicalUrls),
      ],
      'voyage' => [
        'enabled' => (bool) ($voyage['enabled'] ?? FALSE),
        'rerank_model' => $this->stringValue($voyage['rerank_model'] ?? ''),
        'api_timeout' => (float) ($voyage['api_timeout'] ?? 0.0),
        'max_candidates' => (int) ($voyage['max_candidates'] ?? 0),
        'top_k' => (int) ($voyage['top_k'] ?? 0),
        'min_results_to_rerank' => (int) ($voyage['min_results_to_rerank'] ?? 0),
        'fallback_on_error' => (bool) ($voyage['fallback_on_error'] ?? TRUE),
        'api_key_present' => FALSE,
        'runtime_ready' => FALSE,
        'circuit_breaker' => [
          'failure_threshold' => (int) (($voyage['circuit_breaker']['failure_threshold'] ?? 0)),
          'cooldown_seconds' => (int) (($voyage['circuit_breaker']['cooldown_seconds'] ?? 0)),
        ],
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
    $retrieval = $this->buildEffectiveRetrievalSummary($assistant);
    $sessionBootstrap = $this->buildEffectiveSessionBootstrapSummary($assistant);
    $readEndpointRateLimits = $this->buildEffectiveReadEndpointRateLimitSummary($assistant);
    $conversationLogging = $this->buildEffectiveConversationLoggingSummary($assistant);
    $llm = $this->buildEffectiveLlmSummary($assistant);
    $voyage = $this->buildEffectiveVoyageSummary($assistant);
    $publicDsn = $this->stringValue($raven->get('public_dsn') ?? '');
    $browserEnabled = $publicDsn !== '' && (
      (bool) $raven->get('javascript_error_handler')
      || (float) ($raven->get('browser_traces_sample_rate') ?? 0.0) > 0
    );

    return [
      'rate_limit_per_minute' => (int) ($assistant->get('rate_limit_per_minute') ?? 0),
      'rate_limit_per_hour' => (int) ($assistant->get('rate_limit_per_hour') ?? 0),
      'session_bootstrap' => $sessionBootstrap,
      'read_endpoint_rate_limits' => $readEndpointRateLimits,
      'conversation_logging' => $conversationLogging,
      'retrieval' => $retrieval,
      'voyage' => $voyage,
      'llm' => [
        'enabled' => (bool) $assistant->get('llm.enabled'),
        'provider' => $this->stringValue($assistant->get('llm.provider')),
        'model' => $this->stringValue($assistant->get('llm.model')),
        'fallback_on_error' => (bool) ($assistant->get('llm.fallback_on_error') ?? TRUE),
        'global_rate_limit' => [
          'max_per_hour' => (int) ($assistant->get('llm.global_rate_limit.max_per_hour') ?? 0),
          'window_seconds' => (int) ($assistant->get('llm.global_rate_limit.window_seconds') ?? 0),
        ],
        'cost_control' => $this->sanitizeCostControlConfig($assistant->get('cost_control')),
        'gemini_api_key_present' => $this->valuePresent(Settings::get('ilas_gemini_api_key')) || $this->valuePresent($assistant->get('llm.api_key')),
        'vertex_project_id_present' => $this->valuePresent($assistant->get('llm.project_id')),
        'vertex_location_present' => $this->valuePresent($assistant->get('llm.location')),
        'vertex_service_account_present' => $this->valuePresent(Settings::get('ilas_vertex_sa_json')),
        'runtime_ready' => $llm['runtime_ready'] ?? FALSE,
        'cost_control_summary' => $llm['cost_control_summary'] ?? [],
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
      'voyage_api_key_present' => $this->valuePresent(Settings::get('ilas_voyage_api_key')),
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
      'rate_limit_per_minute' => 'settings.php live branch',
      'rate_limit_per_hour' => 'settings.php live branch',
      'session_bootstrap.rate_limit_per_minute' => 'config export',
      'session_bootstrap.rate_limit_per_hour' => 'config export',
      'session_bootstrap.observation_window_hours' => 'config export',
      'read_endpoint_rate_limits.suggest.rate_limit_per_minute' => 'config export',
      'read_endpoint_rate_limits.suggest.rate_limit_per_hour' => 'config export',
      'read_endpoint_rate_limits.faq.rate_limit_per_minute' => 'config export',
      'read_endpoint_rate_limits.faq.rate_limit_per_hour' => 'config export',
      'conversation_logging.enabled' => 'config export',
      'conversation_logging.retention_hours' => 'ConversationLogger retention cap',
      'conversation_logging.redact_pii' => 'ConversationLogger privacy invariants',
      'conversation_logging.show_user_notice' => 'ConversationLogger privacy invariants',
      'llm.enabled' => 'settings.php live branch',
      'llm.provider' => 'config export',
      'llm.model' => 'config export',
      'llm.fallback_on_error' => 'config export',
      'llm.global_rate_limit.max_per_hour' => 'config export',
      'llm.global_rate_limit.window_seconds' => 'config export',
      'llm.cost_control.daily_call_limit' => 'config export',
      'llm.cost_control.monthly_call_limit' => 'config export',
      'llm.cost_control.per_ip_hourly_call_limit' => 'config export',
      'llm.cost_control.per_ip_window_seconds' => 'config export',
      'llm.cost_control.sample_rate' => 'config export',
      'llm.cost_control.cache_hit_rate_target' => 'config export',
      'llm.cost_control.cache_stats_window_seconds' => 'config export',
      'llm.cost_control.manual_kill_switch' => 'state + CostControlPolicy summary',
      'llm.cost_control.alert_cooldown_minutes' => 'config export',
      'llm.gemini_api_key_present' => 'settings.php runtime site setting',
      'llm.vertex_project_id_present' => 'config export',
      'llm.vertex_location_present' => 'config export',
      'llm.vertex_service_account_present' => 'settings.php runtime site setting',
      'llm.runtime_ready' => 'LlmEnhancer::isEnabled()',
      'vector_search.enabled' => $vectorSearchOverrideChannel,
      'retrieval.faq_index_id' => 'config export',
      'retrieval.resource_index_id' => 'config export',
      'retrieval.resource_fallback_index_id' => 'config export',
      'retrieval.faq_vector_index_id' => 'config export',
      'retrieval.resource_vector_index_id' => 'config export',
      'retrieval.service_area_urls.status' => 'config export',
      'retrieval.legalserver_online_application_url.present' => 'settings.php runtime site setting',
      'retrieval.legalserver_online_application_url.status' => 'RetrievalConfigurationService runtime resolution',
      'retrieval.health.status' => 'RetrievalConfigurationService runtime resolution',
      'voyage.enabled' => 'settings.php runtime toggle -> getenv/pantheon_get_secret',
      'voyage.api_key_present' => 'settings.php runtime site setting',
      'voyage.runtime_ready' => 'VoyageReranker::isEnabled()',
      'voyage.rerank_model' => 'config export',
      'voyage.api_timeout' => 'config export',
      'voyage.max_candidates' => 'config export',
      'voyage.top_k' => 'config export',
      'voyage.min_results_to_rerank' => 'config export',
      'voyage.fallback_on_error' => 'config export',
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
      'rate_limit_per_minute' => [
        'stored' => $exportedStorage['rate_limit_per_minute'] ?? 0,
        'effective' => $effectiveRuntime['rate_limit_per_minute'] ?? 0,
      ],
      'rate_limit_per_hour' => [
        'stored' => $exportedStorage['rate_limit_per_hour'] ?? 0,
        'effective' => $effectiveRuntime['rate_limit_per_hour'] ?? 0,
      ],
      'session_bootstrap.rate_limit_per_minute' => [
        'stored' => $exportedStorage['session_bootstrap']['rate_limit_per_minute'] ?? 0,
        'effective' => $effectiveRuntime['session_bootstrap']['rate_limit_per_minute'] ?? 0,
      ],
      'session_bootstrap.rate_limit_per_hour' => [
        'stored' => $exportedStorage['session_bootstrap']['rate_limit_per_hour'] ?? 0,
        'effective' => $effectiveRuntime['session_bootstrap']['rate_limit_per_hour'] ?? 0,
      ],
      'session_bootstrap.observation_window_hours' => [
        'stored' => $exportedStorage['session_bootstrap']['observation_window_hours'] ?? 0,
        'effective' => $effectiveRuntime['session_bootstrap']['observation_window_hours'] ?? 0,
      ],
      'read_endpoint_rate_limits.suggest.rate_limit_per_minute' => [
        'stored' => $exportedStorage['read_endpoint_rate_limits']['suggest']['rate_limit_per_minute'] ?? 0,
        'effective' => $effectiveRuntime['read_endpoint_rate_limits']['suggest']['rate_limit_per_minute'] ?? 0,
      ],
      'read_endpoint_rate_limits.suggest.rate_limit_per_hour' => [
        'stored' => $exportedStorage['read_endpoint_rate_limits']['suggest']['rate_limit_per_hour'] ?? 0,
        'effective' => $effectiveRuntime['read_endpoint_rate_limits']['suggest']['rate_limit_per_hour'] ?? 0,
      ],
      'read_endpoint_rate_limits.faq.rate_limit_per_minute' => [
        'stored' => $exportedStorage['read_endpoint_rate_limits']['faq']['rate_limit_per_minute'] ?? 0,
        'effective' => $effectiveRuntime['read_endpoint_rate_limits']['faq']['rate_limit_per_minute'] ?? 0,
      ],
      'read_endpoint_rate_limits.faq.rate_limit_per_hour' => [
        'stored' => $exportedStorage['read_endpoint_rate_limits']['faq']['rate_limit_per_hour'] ?? 0,
        'effective' => $effectiveRuntime['read_endpoint_rate_limits']['faq']['rate_limit_per_hour'] ?? 0,
      ],
      'conversation_logging.enabled' => [
        'stored' => $exportedStorage['conversation_logging']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['conversation_logging']['enabled'] ?? FALSE,
      ],
      'conversation_logging.retention_hours' => [
        'stored' => $exportedStorage['conversation_logging']['retention_hours'] ?? 0,
        'effective' => $effectiveRuntime['conversation_logging']['retention_hours'] ?? 0,
      ],
      'conversation_logging.redact_pii' => [
        'stored' => $exportedStorage['conversation_logging']['redact_pii'] ?? FALSE,
        'effective' => $effectiveRuntime['conversation_logging']['redact_pii'] ?? FALSE,
      ],
      'conversation_logging.show_user_notice' => [
        'stored' => $exportedStorage['conversation_logging']['show_user_notice'] ?? FALSE,
        'effective' => $effectiveRuntime['conversation_logging']['show_user_notice'] ?? FALSE,
      ],
      'llm.enabled' => [
        'stored' => $exportedStorage['llm']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['enabled'] ?? FALSE,
      ],
      'llm.provider' => [
        'stored' => $exportedStorage['llm']['provider'] ?? '',
        'effective' => $effectiveRuntime['llm']['provider'] ?? '',
      ],
      'llm.model' => [
        'stored' => $exportedStorage['llm']['model'] ?? '',
        'effective' => $effectiveRuntime['llm']['model'] ?? '',
      ],
      'llm.fallback_on_error' => [
        'stored' => $exportedStorage['llm']['fallback_on_error'] ?? TRUE,
        'effective' => $effectiveRuntime['llm']['fallback_on_error'] ?? TRUE,
      ],
      'llm.global_rate_limit.max_per_hour' => [
        'stored' => $exportedStorage['llm']['global_rate_limit']['max_per_hour'] ?? 0,
        'effective' => $effectiveRuntime['llm']['global_rate_limit']['max_per_hour'] ?? 0,
      ],
      'llm.global_rate_limit.window_seconds' => [
        'stored' => $exportedStorage['llm']['global_rate_limit']['window_seconds'] ?? 0,
        'effective' => $effectiveRuntime['llm']['global_rate_limit']['window_seconds'] ?? 0,
      ],
      'llm.cost_control.daily_call_limit' => [
        'stored' => $exportedStorage['llm']['cost_control']['daily_call_limit'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['daily_call_limit'] ?? 0,
      ],
      'llm.cost_control.monthly_call_limit' => [
        'stored' => $exportedStorage['llm']['cost_control']['monthly_call_limit'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['monthly_call_limit'] ?? 0,
      ],
      'llm.cost_control.per_ip_hourly_call_limit' => [
        'stored' => $exportedStorage['llm']['cost_control']['per_ip_hourly_call_limit'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['per_ip_hourly_call_limit'] ?? 0,
      ],
      'llm.cost_control.per_ip_window_seconds' => [
        'stored' => $exportedStorage['llm']['cost_control']['per_ip_window_seconds'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['per_ip_window_seconds'] ?? 0,
      ],
      'llm.cost_control.sample_rate' => [
        'stored' => $exportedStorage['llm']['cost_control']['sample_rate'] ?? 0.0,
        'effective' => $effectiveRuntime['llm']['cost_control']['sample_rate'] ?? 0.0,
      ],
      'llm.cost_control.cache_hit_rate_target' => [
        'stored' => $exportedStorage['llm']['cost_control']['cache_hit_rate_target'] ?? 0.0,
        'effective' => $effectiveRuntime['llm']['cost_control']['cache_hit_rate_target'] ?? 0.0,
      ],
      'llm.cost_control.cache_stats_window_seconds' => [
        'stored' => $exportedStorage['llm']['cost_control']['cache_stats_window_seconds'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['cache_stats_window_seconds'] ?? 0,
      ],
      'llm.cost_control.manual_kill_switch' => [
        'stored' => $exportedStorage['llm']['cost_control']['manual_kill_switch'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['cost_control']['manual_kill_switch'] ?? FALSE,
      ],
      'llm.cost_control.alert_cooldown_minutes' => [
        'stored' => $exportedStorage['llm']['cost_control']['alert_cooldown_minutes'] ?? 0,
        'effective' => $effectiveRuntime['llm']['cost_control']['alert_cooldown_minutes'] ?? 0,
      ],
      'llm.gemini_api_key_present' => [
        'stored' => $exportedStorage['llm']['gemini_api_key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['gemini_api_key_present'] ?? FALSE,
      ],
      'llm.vertex_project_id_present' => [
        'stored' => $exportedStorage['llm']['vertex_project_id_present'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['vertex_project_id_present'] ?? FALSE,
      ],
      'llm.vertex_location_present' => [
        'stored' => $exportedStorage['llm']['vertex_location_present'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['vertex_location_present'] ?? FALSE,
      ],
      'llm.vertex_service_account_present' => [
        'stored' => $exportedStorage['llm']['vertex_service_account_present'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['vertex_service_account_present'] ?? FALSE,
      ],
      'llm.runtime_ready' => [
        'stored' => $exportedStorage['llm']['runtime_ready'] ?? FALSE,
        'effective' => $effectiveRuntime['llm']['runtime_ready'] ?? FALSE,
      ],
      'vector_search.enabled' => [
        'stored' => $exportedStorage['vector_search']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['vector_search']['enabled'] ?? FALSE,
      ],
      'retrieval.faq_index_id' => [
        'stored' => $exportedStorage['retrieval']['faq_index_id'] ?? '',
        'effective' => $effectiveRuntime['retrieval']['faq_index_id'] ?? '',
      ],
      'retrieval.resource_index_id' => [
        'stored' => $exportedStorage['retrieval']['resource_index_id'] ?? '',
        'effective' => $effectiveRuntime['retrieval']['resource_index_id'] ?? '',
      ],
      'retrieval.resource_fallback_index_id' => [
        'stored' => $exportedStorage['retrieval']['resource_fallback_index_id'] ?? '',
        'effective' => $effectiveRuntime['retrieval']['resource_fallback_index_id'] ?? '',
      ],
      'retrieval.faq_vector_index_id' => [
        'stored' => $exportedStorage['retrieval']['faq_vector_index_id'] ?? '',
        'effective' => $effectiveRuntime['retrieval']['faq_vector_index_id'] ?? '',
      ],
      'retrieval.resource_vector_index_id' => [
        'stored' => $exportedStorage['retrieval']['resource_vector_index_id'] ?? '',
        'effective' => $effectiveRuntime['retrieval']['resource_vector_index_id'] ?? '',
      ],
      'retrieval.service_area_urls.status' => [
        'stored' => $exportedStorage['retrieval']['service_area_urls']['status'] ?? 'degraded',
        'effective' => $effectiveRuntime['retrieval']['service_area_urls']['status'] ?? 'degraded',
      ],
      'retrieval.legalserver_online_application_url.present' => [
        'stored' => $exportedStorage['retrieval']['legalserver_online_application_url']['present'] ?? FALSE,
        'effective' => $effectiveRuntime['retrieval']['legalserver_online_application_url']['present'] ?? FALSE,
      ],
      'retrieval.legalserver_online_application_url.status' => [
        'stored' => $exportedStorage['retrieval']['legalserver_online_application_url']['status'] ?? 'degraded',
        'effective' => $effectiveRuntime['retrieval']['legalserver_online_application_url']['status'] ?? 'degraded',
      ],
      'retrieval.health.status' => [
        'stored' => $exportedStorage['retrieval']['health']['status'] ?? 'degraded',
        'effective' => $effectiveRuntime['retrieval']['health']['status'] ?? 'degraded',
      ],
      'voyage.enabled' => [
        'stored' => $exportedStorage['voyage']['enabled'] ?? FALSE,
        'effective' => $effectiveRuntime['voyage']['enabled'] ?? FALSE,
      ],
      'voyage.api_key_present' => [
        'stored' => $exportedStorage['voyage']['api_key_present'] ?? FALSE,
        'effective' => $effectiveRuntime['voyage']['api_key_present'] ?? FALSE,
      ],
      'voyage.runtime_ready' => [
        'stored' => $exportedStorage['voyage']['runtime_ready'] ?? FALSE,
        'effective' => $effectiveRuntime['voyage']['runtime_ready'] ?? FALSE,
      ],
      'voyage.rerank_model' => [
        'stored' => $exportedStorage['voyage']['rerank_model'] ?? '',
        'effective' => $effectiveRuntime['voyage']['rerank_model'] ?? '',
      ],
      'voyage.api_timeout' => [
        'stored' => $exportedStorage['voyage']['api_timeout'] ?? 0.0,
        'effective' => $effectiveRuntime['voyage']['api_timeout'] ?? 0.0,
      ],
      'voyage.max_candidates' => [
        'stored' => $exportedStorage['voyage']['max_candidates'] ?? 0,
        'effective' => $effectiveRuntime['voyage']['max_candidates'] ?? 0,
      ],
      'voyage.top_k' => [
        'stored' => $exportedStorage['voyage']['top_k'] ?? 0,
        'effective' => $effectiveRuntime['voyage']['top_k'] ?? 0,
      ],
      'voyage.min_results_to_rerank' => [
        'stored' => $exportedStorage['voyage']['min_results_to_rerank'] ?? 0,
        'effective' => $effectiveRuntime['voyage']['min_results_to_rerank'] ?? 0,
      ],
      'voyage.fallback_on_error' => [
        'stored' => $exportedStorage['voyage']['fallback_on_error'] ?? TRUE,
        'effective' => $effectiveRuntime['voyage']['fallback_on_error'] ?? TRUE,
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

  /**
   * Builds the effective session-bootstrap summary.
   *
   * @return array<string, int|bool>
   *   The normalized bootstrap summary.
   */
  protected function buildEffectiveSessionBootstrapSummary(object $assistant): array {
    $snapshot = $this->sessionBootstrapGuard?->getSnapshot();
    if (is_array($snapshot)) {
      $thresholds = is_array($snapshot['thresholds'] ?? NULL) ? $snapshot['thresholds'] : [];
      return [
        'rate_limit_per_minute' => (int) ($thresholds['rate_limit_per_minute'] ?? 0),
        'rate_limit_per_hour' => (int) ($thresholds['rate_limit_per_hour'] ?? 0),
        'observation_window_hours' => (int) ($thresholds['observation_window_hours'] ?? 0),
        'snapshot_window_started_at_present' => $this->valuePresent($snapshot['window_started_at'] ?? NULL),
        'new_session_requests' => (int) ($snapshot['new_session_requests'] ?? 0),
        'rate_limited_requests' => (int) ($snapshot['rate_limited_requests'] ?? 0),
      ];
    }

    return [
      'rate_limit_per_minute' => max(1, (int) ($assistant->get('session_bootstrap.rate_limit_per_minute') ?? 60)),
      'rate_limit_per_hour' => max(1, (int) ($assistant->get('session_bootstrap.rate_limit_per_hour') ?? 600)),
      'observation_window_hours' => max(1, (int) ($assistant->get('session_bootstrap.observation_window_hours') ?? 24)),
      'snapshot_window_started_at_present' => FALSE,
      'new_session_requests' => 0,
      'rate_limited_requests' => 0,
    ];
  }

  /**
   * Builds the effective read-endpoint rate-limit summary.
   */
  protected function buildEffectiveReadEndpointRateLimitSummary(object $assistant): array {
    $summary = $this->readEndpointGuard?->getThresholdSummary();
    if (is_array($summary) && $summary !== []) {
      return [
        'suggest' => [
          'rate_limit_per_minute' => (int) (($summary['suggest']['rate_limit_per_minute'] ?? 0)),
          'rate_limit_per_hour' => (int) (($summary['suggest']['rate_limit_per_hour'] ?? 0)),
        ],
        'faq' => [
          'rate_limit_per_minute' => (int) (($summary['faq']['rate_limit_per_minute'] ?? 0)),
          'rate_limit_per_hour' => (int) (($summary['faq']['rate_limit_per_hour'] ?? 0)),
        ],
      ];
    }

    return [
      'suggest' => [
        'rate_limit_per_minute' => max(1, (int) ($assistant->get('read_endpoint_rate_limits.suggest.rate_limit_per_minute') ?? 120)),
        'rate_limit_per_hour' => max(1, (int) ($assistant->get('read_endpoint_rate_limits.suggest.rate_limit_per_hour') ?? 1200)),
      ],
      'faq' => [
        'rate_limit_per_minute' => max(1, (int) ($assistant->get('read_endpoint_rate_limits.faq.rate_limit_per_minute') ?? 60)),
        'rate_limit_per_hour' => max(1, (int) ($assistant->get('read_endpoint_rate_limits.faq.rate_limit_per_hour') ?? 600)),
      ],
    ];
  }

  /**
   * Builds the effective conversation-logging summary.
   */
  protected function buildEffectiveConversationLoggingSummary(object $assistant): array {
    $summary = $this->conversationLogger?->getResolvedConfig();
    if (is_array($summary)) {
      return [
        'enabled' => (bool) ($summary['enabled'] ?? FALSE),
        'retention_hours' => (int) ($summary['retention_hours'] ?? 0),
        'redact_pii' => (bool) ($summary['redact_pii'] ?? FALSE),
        'show_user_notice' => (bool) ($summary['show_user_notice'] ?? FALSE),
      ];
    }

    $enabled = (bool) ($assistant->get('conversation_logging.enabled') ?? FALSE);
    $retentionHours = min((int) ($assistant->get('conversation_logging.retention_hours') ?? 72), ConversationLogger::MAX_RETENTION_HOURS);
    $redactPii = (bool) ($assistant->get('conversation_logging.redact_pii') ?? TRUE);
    $showUserNotice = (bool) ($assistant->get('conversation_logging.show_user_notice') ?? TRUE);
    if ($enabled) {
      $redactPii = TRUE;
      $showUserNotice = TRUE;
    }

    return [
      'enabled' => $enabled,
      'retention_hours' => $retentionHours,
      'redact_pii' => $redactPii,
      'show_user_notice' => $showUserNotice,
    ];
  }

  /**
   * Builds the effective LLM summary.
   *
   * @return array<string, mixed>
   *   Safe LLM summary.
   */
  protected function buildEffectiveLlmSummary(object $assistant): array {
    return [
      'runtime_ready' => $this->llmEnhancer?->isEnabled() ?? $this->buildFallbackLlmRuntimeReady($assistant),
      'cost_control_summary' => $this->llmEnhancer?->getCostControlSummary() ?? [],
    ];
  }

  /**
   * Builds the effective retrieval summary.
   *
   * @return array<string, mixed>
   *   Safe retrieval summary.
   */
  protected function buildEffectiveRetrievalSummary(object $assistant): array {
    if ($this->retrievalConfiguration !== NULL) {
      $retrieval = $this->retrievalConfiguration->getRetrievalConfig();
      $health = $this->sanitizeRetrievalHealthSummary($this->retrievalConfiguration->getHealthSnapshot());

      return [
        'faq_index_id' => $this->stringValue($retrieval['faq_index_id'] ?? ''),
        'resource_index_id' => $this->stringValue($retrieval['resource_index_id'] ?? ''),
        'resource_fallback_index_id' => $this->stringValue($retrieval['resource_fallback_index_id'] ?? ''),
        'faq_vector_index_id' => $this->stringValue($retrieval['faq_vector_index_id'] ?? ''),
        'resource_vector_index_id' => $this->stringValue($retrieval['resource_vector_index_id'] ?? ''),
        'service_area_urls' => $health['canonical_urls']['service_areas'] ?? ['status' => 'degraded'],
        'legalserver_online_application_url' => $health['canonical_urls']['legalserver_intake_url'] ?? ['present' => FALSE, 'status' => 'degraded'],
        'health' => $health,
      ];
    }

    $retrieval = [
      'faq_index_id' => $this->stringValue($assistant->get('retrieval.faq_index_id')),
      'resource_index_id' => $this->stringValue($assistant->get('retrieval.resource_index_id')),
      'resource_fallback_index_id' => $this->stringValue($assistant->get('retrieval.resource_fallback_index_id')),
      'faq_vector_index_id' => $this->stringValue($assistant->get('retrieval.faq_vector_index_id')),
      'resource_vector_index_id' => $this->stringValue($assistant->get('retrieval.resource_vector_index_id')),
    ];
    $serviceAreas = $this->buildStoredServiceAreaSummary([
      'service_areas' => $assistant->get('canonical_urls.service_areas') ?? [],
    ]);
    $legalServer = [
      'present' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')),
      'status' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')) ? 'healthy' : 'degraded',
      'source' => 'settings',
      'absolute' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')),
      'https' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')),
      'required_query_keys' => ['pid' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url')), 'h' => $this->valuePresent(Settings::get('ilas_site_assistant_legalserver_online_application_url'))],
      'probe_status' => 'skipped',
    ];

    return $retrieval + [
      'service_area_urls' => $serviceAreas,
      'legalserver_online_application_url' => $legalServer,
      'health' => [
        'status' => ($serviceAreas['status'] === 'healthy' && $legalServer['status'] === 'healthy') ? 'healthy' : 'degraded',
        'retrieval' => [],
        'canonical_urls' => [
          'service_areas' => $serviceAreas,
          'legalserver_intake_url' => $legalServer,
        ],
      ],
    ];
  }

  /**
   * Builds the effective Voyage summary.
   */
  protected function buildEffectiveVoyageSummary(object $assistant): array {
    if ($this->voyageReranker !== NULL) {
      return $this->voyageReranker->getRuntimeSummary();
    }

    $key = Settings::get('ilas_voyage_api_key', '');
    $enabled = (bool) ($assistant->get('voyage.enabled') ?? FALSE);

    return [
      'enabled' => $enabled,
      'rerank_model' => $this->stringValue($assistant->get('voyage.rerank_model')),
      'api_timeout' => (float) ($assistant->get('voyage.api_timeout') ?? 0.0),
      'max_candidates' => (int) ($assistant->get('voyage.max_candidates') ?? 0),
      'top_k' => (int) ($assistant->get('voyage.top_k') ?? 0),
      'min_results_to_rerank' => (int) ($assistant->get('voyage.min_results_to_rerank') ?? 0),
      'fallback_on_error' => (bool) ($assistant->get('voyage.fallback_on_error') ?? TRUE),
      'api_key_present' => $this->valuePresent($key),
      'runtime_ready' => $enabled && $this->valuePresent($key),
      'circuit_breaker' => [
        'failure_threshold' => (int) ($assistant->get('voyage.circuit_breaker.failure_threshold') ?? 0),
        'cooldown_seconds' => (int) ($assistant->get('voyage.circuit_breaker.cooldown_seconds') ?? 0),
        'state' => 'closed',
        'consecutive_failures' => 0,
      ],
    ];
  }

  /**
   * Returns a sanitized cost-control config block.
   *
   * @param mixed $costControl
   *   The raw cost-control config.
   *
   * @return array<string, bool|float|int|string>
   *   Safe config summary.
   */
  protected function sanitizeCostControlConfig(mixed $costControl): array {
    $costControl = is_array($costControl) ? $costControl : [];
    $pricing = is_array($costControl['pricing'] ?? NULL) ? $costControl['pricing'] : [];

    return [
      'daily_call_limit' => (int) ($costControl['daily_call_limit'] ?? 0),
      'monthly_call_limit' => (int) ($costControl['monthly_call_limit'] ?? 0),
      'per_ip_hourly_call_limit' => (int) ($costControl['per_ip_hourly_call_limit'] ?? 0),
      'per_ip_window_seconds' => (int) ($costControl['per_ip_window_seconds'] ?? 0),
      'sample_rate' => (float) ($costControl['sample_rate'] ?? 0.0),
      'cache_hit_rate_target' => (float) ($costControl['cache_hit_rate_target'] ?? 0.0),
      'cache_stats_window_seconds' => (int) ($costControl['cache_stats_window_seconds'] ?? 0),
      'manual_kill_switch' => (bool) ($costControl['manual_kill_switch'] ?? FALSE),
      'alert_cooldown_minutes' => (int) ($costControl['alert_cooldown_minutes'] ?? 0),
      'pricing_model' => $this->stringValue($pricing['model'] ?? ''),
    ];
  }

  /**
   * Returns the stored service-area URL summary.
   */
  protected function buildStoredServiceAreaSummary(array $canonicalUrls): array {
    $serviceAreas = $canonicalUrls['service_areas'] ?? [];
    $serviceAreas = is_array($serviceAreas) ? $serviceAreas : [];
    $required = ['housing', 'family', 'seniors', 'health', 'consumer', 'civil_rights'];
    $missing = 0;
    $invalid = 0;

    foreach ($required as $key) {
      $value = $serviceAreas[$key] ?? NULL;
      if (!is_string($value) || trim($value) === '') {
        $missing++;
        continue;
      }
      if (!str_starts_with($value, '/')) {
        $invalid++;
      }
    }

    return [
      'configured_count' => count($serviceAreas),
      'missing_count' => $missing,
      'invalid_count' => $invalid,
      'status' => ($missing === 0 && $invalid === 0) ? 'healthy' : 'degraded',
    ];
  }

  /**
   * Returns the stored LegalServer summary.
   */
  protected function buildStoredLegalServerSummary(): array {
    return [
      'present' => FALSE,
      'source' => 'settings',
      'absolute' => FALSE,
      'https' => FALSE,
      'required_query_keys' => [
        'pid' => FALSE,
        'h' => FALSE,
      ],
      'probe_status' => 'skipped',
      'status' => 'degraded',
    ];
  }

  /**
   * Returns the stored retrieval health summary.
   */
  protected function buildStoredRetrievalHealthSummary(array $retrieval, array $canonicalUrls): array {
    $serviceAreas = $this->buildStoredServiceAreaSummary($canonicalUrls);
    $legalServer = $this->buildStoredLegalServerSummary();
    $requiredIds = [
      'faq_index_id',
      'resource_index_id',
      'resource_fallback_index_id',
      'faq_vector_index_id',
      'resource_vector_index_id',
    ];
    $retrievalChecks = [];
    $status = 'healthy';
    foreach ($requiredIds as $key) {
      $configured = $this->valuePresent($retrieval[$key] ?? NULL);
      $retrievalChecks[$key] = [
        'configured' => $configured,
        'status' => $configured ? 'unknown' : 'degraded',
      ];
      if (!$configured) {
        $status = 'degraded';
      }
    }

    if ($serviceAreas['status'] !== 'healthy' || $legalServer['status'] !== 'healthy') {
      $status = 'degraded';
    }

    return [
      'status' => $status,
      'retrieval' => $retrievalChecks,
      'canonical_urls' => [
        'service_areas' => $serviceAreas,
        'legalserver_intake_url' => $legalServer,
      ],
    ];
  }

  /**
   * Returns a sanitized retrieval health snapshot.
   *
   * @param array<string, mixed> $health
   *   The raw health snapshot.
   *
   * @return array<string, mixed>
   *   Sanitized summary without raw URLs.
   */
  protected function sanitizeRetrievalHealthSummary(array $health): array {
    $retrievalChecks = [];
    foreach (($health['retrieval'] ?? []) as $key => $check) {
      $check = is_array($check) ? $check : [];
      $sanitized = [
        'dependency_key' => $this->stringValue($check['dependency_key'] ?? $key),
        'dependency_type' => $this->stringValue($check['dependency_type'] ?? 'index'),
        'classification' => $this->stringValue($check['classification'] ?? 'required'),
        'allowed_degraded_mode' => $this->stringValue($check['allowed_degraded_mode'] ?? 'unknown'),
        'active' => (bool) ($check['active'] ?? FALSE),
        'configured' => (bool) ($check['configured'] ?? FALSE),
        'exists' => (bool) ($check['exists'] ?? FALSE),
        'enabled' => (bool) ($check['enabled'] ?? FALSE),
        'status' => $this->stringValue($check['status'] ?? 'degraded'),
      ];

      if (isset($check['failure_code'])) {
        $sanitized['failure_code'] = $this->stringValue($check['failure_code']);
      }
      if (array_key_exists('index_id', $check)) {
        $sanitized['index_id'] = $this->stringValue($check['index_id'] ?? '');
        $sanitized['machine_name_valid'] = (bool) ($check['machine_name_valid'] ?? FALSE);
      }
      if (array_key_exists('server_id', $check)) {
        $sanitized['server_id'] = $this->stringValue($check['server_id'] ?? '');
      }
      if (array_key_exists('server_exists', $check)) {
        $sanitized['server_exists'] = (bool) ($check['server_exists'] ?? FALSE);
      }
      if (array_key_exists('server_enabled', $check)) {
        $sanitized['server_enabled'] = (bool) ($check['server_enabled'] ?? FALSE);
      }

      $retrievalChecks[$key] = $sanitized;
    }

    $serviceAreas = is_array($health['canonical_urls']['service_areas'] ?? NULL) ? $health['canonical_urls']['service_areas'] : [];
    $legalServer = is_array($health['canonical_urls']['legalserver_intake_url'] ?? NULL) ? $health['canonical_urls']['legalserver_intake_url'] : [];

    return [
      'status' => $this->stringValue($health['status'] ?? 'degraded'),
      'retrieval' => $retrievalChecks,
      'canonical_urls' => [
        'service_areas' => [
          'configured_count' => (int) ($serviceAreas['configured_count'] ?? 0),
          'missing_count' => count(is_array($serviceAreas['missing'] ?? NULL) ? $serviceAreas['missing'] : []),
          'invalid_count' => count(is_array($serviceAreas['invalid'] ?? NULL) ? $serviceAreas['invalid'] : []),
          'status' => $this->stringValue($serviceAreas['status'] ?? 'degraded'),
        ],
        'legalserver_intake_url' => [
          'present' => (bool) ($legalServer['configured'] ?? FALSE),
          'source' => $this->stringValue($legalServer['source'] ?? 'settings'),
          'absolute' => (bool) ($legalServer['absolute'] ?? FALSE),
          'https' => (bool) ($legalServer['https'] ?? FALSE),
          'required_query_keys' => [
            'pid' => (bool) ($legalServer['required_query_keys']['pid'] ?? FALSE),
            'h' => (bool) ($legalServer['required_query_keys']['h'] ?? FALSE),
          ],
          'probe_status' => $this->stringValue($legalServer['probe_status'] ?? 'skipped'),
          'status' => $this->stringValue($legalServer['status'] ?? 'degraded'),
        ],
      ],
    ];
  }

  /**
   * Returns TRUE when stored LLM config alone looks runtime-ready.
   */
  protected function buildStoredLlmRuntimeReady(array $llm): bool {
    if (empty($llm['enabled'])) {
      return FALSE;
    }

    $provider = $this->stringValue($llm['provider'] ?? '');
    if ($provider === 'gemini_api') {
      return $this->valuePresent($llm['api_key'] ?? NULL);
    }
    if ($provider === 'vertex_ai') {
      return $this->valuePresent($llm['project_id'] ?? NULL)
        && $this->valuePresent($llm['location'] ?? NULL)
        && FALSE;
    }

    return FALSE;
  }

  /**
   * Fallback runtime-ready check when the real service is unavailable.
   */
  protected function buildFallbackLlmRuntimeReady(object $assistant): bool {
    if ((bool) ($assistant->get('llm.enabled') ?? FALSE) === FALSE) {
      return FALSE;
    }

    $observability = $this->getObservabilitySettings();
    $pantheonEnvironment = $this->stringValue($observability['pantheon_environment'] ?? getenv('PANTHEON_ENVIRONMENT') ?: '');
    if ($pantheonEnvironment === 'live') {
      return FALSE;
    }

    $provider = $this->stringValue($assistant->get('llm.provider'));
    if ($provider === 'gemini_api') {
      return $this->valuePresent(Settings::get('ilas_gemini_api_key')) || $this->valuePresent($assistant->get('llm.api_key'));
    }
    if ($provider === 'vertex_ai') {
      return $this->valuePresent($assistant->get('llm.project_id'))
        && $this->valuePresent($assistant->get('llm.location'))
        && $this->valuePresent(Settings::get('ilas_vertex_sa_json'));
    }

    return FALSE;
  }

}
