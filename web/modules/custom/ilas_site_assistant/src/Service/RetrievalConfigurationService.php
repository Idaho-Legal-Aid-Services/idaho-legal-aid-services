<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;

/**
 * Resolves retrieval identifiers and canonical URLs from config/runtime.
 */
final class RetrievalConfigurationService {

  /**
   * Governed retrieval index dependencies.
   */
  private const RETRIEVAL_INDEX_DEPENDENCIES = [
    'faq_index' => [
      'config_key' => 'faq_index_id',
      'classification' => 'required',
      'active_callback' => 'isFaqEnabled',
      'allowed_degraded_mode' => 'hard_fail_read_endpoint',
      'server_id' => 'database',
    ],
    'resource_index' => [
      'config_key' => 'resource_index_id',
      'classification' => 'required',
      'active_callback' => 'isResourceEnabled',
      'allowed_degraded_mode' => 'fallback_index_only',
      'server_id' => 'database',
    ],
    'resource_fallback_index' => [
      'config_key' => 'resource_fallback_index_id',
      'classification' => 'optional',
      'active_callback' => 'isResourceEnabled',
      'allowed_degraded_mode' => 'explicit_content_fallback',
      'server_id' => 'database',
    ],
    'faq_vector_index' => [
      'config_key' => 'faq_vector_index_id',
      'classification' => 'feature_gated',
      'active_callback' => 'isVectorSearchEnabled',
      'allowed_degraded_mode' => 'lexical_only',
      'server_id' => 'pinecone_vector_faq',
    ],
    'resource_vector_index' => [
      'config_key' => 'resource_vector_index_id',
      'classification' => 'feature_gated',
      'active_callback' => 'isVectorSearchEnabled',
      'allowed_degraded_mode' => 'lexical_only',
      'server_id' => 'pinecone_vector_resources',
    ],
  ];

  /**
   * Governed Search API server dependencies.
   */
  private const SEARCH_API_SERVER_DEPENDENCIES = [
    'database_server' => [
      'server_id' => 'database',
      'classification' => 'required',
      'active_callback' => 'isLexicalRetrievalActive',
      'allowed_degraded_mode' => 'required_lexical_retrieval',
    ],
    'pinecone_vector_faq_server' => [
      'server_id' => 'pinecone_vector_faq',
      'classification' => 'feature_gated',
      'active_callback' => 'isVectorSearchEnabled',
      'allowed_degraded_mode' => 'lexical_only',
    ],
    'pinecone_vector_resources_server' => [
      'server_id' => 'pinecone_vector_resources',
      'classification' => 'feature_gated',
      'active_callback' => 'isVectorSearchEnabled',
      'allowed_degraded_mode' => 'lexical_only',
    ],
  ];

  /**
   * Required service-area URL keys.
   */
  private const REQUIRED_SERVICE_AREAS = [
    'housing',
    'family',
    'seniors',
    'health',
    'consumer',
    'civil_rights',
  ];

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the retrieval configuration service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the effective retrieval config.
   */
  public function getRetrievalConfig(): array {
    $config = $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('retrieval');

    return is_array($config) ? $config : [];
  }

  /**
   * Returns the configured lexical FAQ Search API index ID.
   */
  public function getFaqIndexId(): ?string {
    return $this->getRetrievalValue('faq_index_id');
  }

  /**
   * Returns the configured lexical resource Search API index ID.
   */
  public function getResourceIndexId(): ?string {
    return $this->getRetrievalValue('resource_index_id');
  }

  /**
   * Returns the configured lexical resource fallback Search API index ID.
   */
  public function getResourceFallbackIndexId(): ?string {
    return $this->getRetrievalValue('resource_fallback_index_id');
  }

  /**
   * Returns the configured vector FAQ Search API index ID.
   */
  public function getFaqVectorIndexId(): ?string {
    return $this->getRetrievalValue('faq_vector_index_id');
  }

  /**
   * Returns the configured vector resource Search API index ID.
   */
  public function getResourceVectorIndexId(): ?string {
    return $this->getRetrievalValue('resource_vector_index_id');
  }

  /**
   * Returns TRUE when FAQ retrieval is enabled.
   */
  public function isFaqEnabled(): bool {
    return (bool) ($this->configFactory->get('ilas_site_assistant.settings')->get('enable_faq') ?? TRUE);
  }

  /**
   * Returns TRUE when resource retrieval is enabled.
   */
  public function isResourceEnabled(): bool {
    return (bool) ($this->configFactory->get('ilas_site_assistant.settings')->get('enable_resources') ?? TRUE);
  }

  /**
   * Returns TRUE when vector search is effectively enabled.
   */
  public function isVectorSearchEnabled(): bool {
    return (bool) ($this->configFactory->get('ilas_site_assistant.settings')->get('vector_search.enabled') ?? FALSE);
  }

  /**
   * Returns TRUE when any lexical retrieval dependency is active.
   */
  public function isLexicalRetrievalActive(): bool {
    return $this->isFaqEnabled() || $this->isResourceEnabled();
  }

  /**
   * Returns the governed retrieval dependency matrix.
   *
   * @return array<string, array<string, mixed>>
   *   Retrieval dependency snapshots keyed by dependency name.
   */
  public function getRetrievalDependencyMatrix(): array {
    $checks = [];

    foreach (self::RETRIEVAL_INDEX_DEPENDENCIES as $key => $definition) {
      $checks[$key] = $this->buildIndexDependencyCheck($key, $definition);
    }
    foreach (self::SEARCH_API_SERVER_DEPENDENCIES as $key => $definition) {
      $checks[$key] = $this->buildServerDependencyCheck($key, $definition);
    }

    return $checks;
  }

  /**
   * Returns one governed retrieval dependency snapshot.
   *
   * @return array<string, mixed>
   *   Dependency snapshot.
   */
  public function getRetrievalDependency(string $dependency_key): array {
    return $this->getRetrievalDependencyMatrix()[$dependency_key] ?? [
      'dependency_key' => $dependency_key,
      'dependency_type' => 'unknown',
      'classification' => 'required',
      'allowed_degraded_mode' => 'unknown',
      'active' => TRUE,
      'configured' => FALSE,
      'status' => 'degraded',
      'failure_code' => 'unknown_dependency',
    ];
  }

  /**
   * Returns effective canonical URLs with runtime-only LegalServer URL.
   */
  public function getCanonicalUrls(): array {
    $urls = $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('canonical_urls');

    $urls = is_array($urls) ? $urls : [];
    $online_application = $this->getLegalServerOnlineApplicationUrl();
    if ($online_application !== NULL) {
      $urls['online_application'] = $online_application;
    }

    return $urls;
  }

  /**
   * Returns the runtime-only LegalServer online application URL.
   */
  public function getLegalServerOnlineApplicationUrl(): ?string {
    $value = Settings::get('ilas_site_assistant_legalserver_online_application_url', '');
    if (!is_string($value)) {
      return NULL;
    }

    $value = trim($value);
    return $value !== '' ? $value : NULL;
  }

  /**
   * Returns a health snapshot for retrieval configuration governance.
   */
  public function getHealthSnapshot(): array {
    $snapshot = [
      'status' => 'healthy',
      'retrieval' => $this->getRetrievalDependencyMatrix(),
      'canonical_urls' => [
        'service_areas' => $this->buildServiceAreaCheck(),
        'legalserver_intake_url' => $this->buildLegalServerCheck(),
      ],
    ];

    foreach ($snapshot['retrieval'] as $check) {
      if (!empty($check['active']) && ($check['status'] ?? 'degraded') === 'degraded') {
        $snapshot['status'] = 'degraded';
        break;
      }
    }

    if (($snapshot['canonical_urls']['service_areas']['status'] ?? 'degraded') !== 'healthy') {
      $snapshot['status'] = 'degraded';
    }
    if (($snapshot['canonical_urls']['legalserver_intake_url']['status'] ?? 'degraded') !== 'healthy') {
      $snapshot['status'] = 'degraded';
    }

    return $snapshot;
  }

  /**
   * Returns one retrieval config value when present.
   */
  private function getRetrievalValue(string $key): ?string {
    $value = $this->getRetrievalConfig()[$key] ?? NULL;
    if (!is_string($value)) {
      return NULL;
    }

    $value = trim($value);
    return $value !== '' ? $value : NULL;
  }

  /**
   * Builds one retrieval index dependency payload.
   */
  private function buildIndexDependencyCheck(string $dependency_key, array $definition): array {
    $index_id = $this->getRetrievalValue((string) $definition['config_key']);
    $active = $this->isDependencyActive($definition);
    $expected_server_id = (string) ($definition['server_id'] ?? '');
    $server_check = $expected_server_id !== ''
      ? $this->buildRawServerAvailability($expected_server_id)
      : [
        'entity_type_available' => TRUE,
        'exists' => FALSE,
        'enabled' => FALSE,
      ];
    $check = [
      'dependency_key' => $dependency_key,
      'dependency_type' => 'index',
      'classification' => (string) ($definition['classification'] ?? 'required'),
      'allowed_degraded_mode' => (string) ($definition['allowed_degraded_mode'] ?? 'unknown'),
      'active' => $active,
      'configured' => $index_id !== NULL,
      'index_id' => $index_id ?? '',
      'machine_name_valid' => FALSE,
      'index_entity_type_available' => TRUE,
      'exists' => FALSE,
      'enabled' => FALSE,
      'expected_server_id' => $expected_server_id,
      'resolved_server_id' => '',
      'server_id' => $expected_server_id,
      'server_entity_type_available' => (bool) ($server_check['entity_type_available'] ?? TRUE),
      'server_exists' => (bool) ($server_check['exists'] ?? FALSE),
      'server_enabled' => (bool) ($server_check['enabled'] ?? FALSE),
      'failure_code' => NULL,
      'status' => $active ? 'degraded' : 'skipped',
    ];

    if ($index_id === NULL) {
      if ($active) {
        $check['failure_code'] = 'index_id_unconfigured';
      }
      return $check;
    }

    $check['machine_name_valid'] = (bool) preg_match('/^[a-z0-9_]+$/', $index_id);
    if (!$check['machine_name_valid']) {
      if ($active) {
        $check['failure_code'] = 'index_id_invalid';
      }
      return $check;
    }

    $index_check = $this->buildRawIndexAvailability($index_id);
    $check['index_entity_type_available'] = (bool) ($index_check['entity_type_available'] ?? TRUE);
    if (!$check['index_entity_type_available']) {
      if ($active) {
        $check['failure_code'] = 'index_entity_type_missing';
      }
      return $check;
    }

    $index = $index_check['entity'] ?? NULL;

    if (!is_object($index)) {
      if ($active) {
        $check['failure_code'] = 'index_missing';
      }
      return $check;
    }

    $check['exists'] = TRUE;
    $check['enabled'] = method_exists($index, 'status') ? (bool) $index->status() : FALSE;
    if (method_exists($index, 'getServerId')) {
      $index_server_id = $index->getServerId();
      if (is_string($index_server_id) && $index_server_id !== '') {
        $check['resolved_server_id'] = $index_server_id;
        $check['server_id'] = $index_server_id;
        $server_check = $this->buildRawServerAvailability($index_server_id);
        $check['server_entity_type_available'] = (bool) ($server_check['entity_type_available'] ?? TRUE);
        $check['server_exists'] = (bool) ($server_check['exists'] ?? FALSE);
        $check['server_enabled'] = (bool) ($server_check['enabled'] ?? FALSE);
      }
    }

    if (!$active) {
      $check['status'] = 'skipped';
      return $check;
    }
    if (!$check['enabled']) {
      $check['failure_code'] = 'index_disabled';
      return $check;
    }
    if (
      $check['expected_server_id'] !== ''
      && $check['resolved_server_id'] !== ''
      && $check['resolved_server_id'] !== $check['expected_server_id']
    ) {
      $check['failure_code'] = 'server_mismatch';
      return $check;
    }
    if ($check['server_id'] !== '' && !$check['server_entity_type_available']) {
      $check['failure_code'] = 'server_entity_type_missing';
      return $check;
    }
    if ($check['server_id'] !== '' && !$check['server_exists']) {
      $check['failure_code'] = 'server_missing';
      return $check;
    }
    if ($check['server_id'] !== '' && !$check['server_enabled']) {
      $check['failure_code'] = 'server_disabled';
      return $check;
    }

    $check['status'] = 'healthy';
    return $check;
  }

  /**
   * Builds one Search API server dependency payload.
   */
  private function buildServerDependencyCheck(string $dependency_key, array $definition): array {
    $server_id = (string) ($definition['server_id'] ?? '');
    $active = $this->isDependencyActive($definition);
    $availability = $this->buildRawServerAvailability($server_id);

    $check = [
      'dependency_key' => $dependency_key,
      'dependency_type' => 'server',
      'classification' => (string) ($definition['classification'] ?? 'required'),
      'allowed_degraded_mode' => (string) ($definition['allowed_degraded_mode'] ?? 'unknown'),
      'active' => $active,
      'configured' => $server_id !== '',
      'server_id' => $server_id,
      'entity_type_available' => (bool) ($availability['entity_type_available'] ?? TRUE),
      'exists' => (bool) ($availability['exists'] ?? FALSE),
      'enabled' => (bool) ($availability['enabled'] ?? FALSE),
      'failure_code' => NULL,
      'status' => $active ? 'degraded' : 'skipped',
    ];

    if (!$active) {
      return $check;
    }
    if ($server_id === '') {
      $check['failure_code'] = 'server_id_unconfigured';
      return $check;
    }
    if (!$check['entity_type_available']) {
      $check['failure_code'] = 'server_entity_type_missing';
      return $check;
    }
    if (!$check['exists']) {
      $check['failure_code'] = 'server_missing';
      return $check;
    }
    if (!$check['enabled']) {
      $check['failure_code'] = 'server_disabled';
      return $check;
    }

    $check['status'] = 'healthy';
    return $check;
  }

  /**
   * Returns raw Search API index availability.
   *
   * @return array{entity_type_available: bool, exists: bool, enabled: bool, entity: object|null}
   *   Index availability snapshot.
   */
  private function buildRawIndexAvailability(string $index_id): array {
    return $this->buildRawSearchApiEntityAvailability('search_api_index', $index_id);
  }

  /**
   * Returns raw Search API server availability.
   *
   * @return array{entity_type_available: bool, exists: bool, enabled: bool, entity: object|null}
   *   Server availability snapshot.
   */
  private function buildRawServerAvailability(string $server_id): array {
    return $this->buildRawSearchApiEntityAvailability('search_api_server', $server_id);
  }

  /**
   * Safely loads one Search API config entity without fatalling diagnostics.
   *
   * @return array{entity_type_available: bool, exists: bool, enabled: bool, entity: object|null}
   *   Entity availability snapshot.
   */
  private function buildRawSearchApiEntityAvailability(string $entity_type_id, string $entity_id): array {
    if ($entity_id === '') {
      return [
        'entity_type_available' => TRUE,
        'exists' => FALSE,
        'enabled' => FALSE,
        'entity' => NULL,
      ];
    }

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
    }
    catch (\Throwable) {
      return [
        'entity_type_available' => FALSE,
        'exists' => FALSE,
        'enabled' => FALSE,
        'entity' => NULL,
      ];
    }

    try {
      $entity = $storage->load($entity_id);
    }
    catch (\Throwable) {
      $entity = NULL;
    }

    if (!is_object($entity)) {
      return [
        'entity_type_available' => TRUE,
        'exists' => FALSE,
        'enabled' => FALSE,
        'entity' => NULL,
      ];
    }

    return [
      'entity_type_available' => TRUE,
      'exists' => TRUE,
      'enabled' => method_exists($entity, 'status') ? (bool) $entity->status() : FALSE,
      'entity' => $entity,
    ];
  }

  /**
   * Returns TRUE when a governed dependency is active.
   */
  private function isDependencyActive(array $definition): bool {
    $callback = (string) ($definition['active_callback'] ?? '');
    if ($callback !== '' && method_exists($this, $callback)) {
      return (bool) $this->{$callback}();
    }

    return TRUE;
  }

  /**
   * Builds service-area URL validation payload.
   */
  private function buildServiceAreaCheck(): array {
    $service_areas = $this->getCanonicalUrls()['service_areas'] ?? [];
    $service_areas = is_array($service_areas) ? $service_areas : [];

    $missing = [];
    $invalid = [];
    foreach (self::REQUIRED_SERVICE_AREAS as $key) {
      $value = $service_areas[$key] ?? NULL;
      if (!is_string($value) || trim($value) === '') {
        $missing[] = $key;
        continue;
      }
      if (!str_starts_with($value, '/')) {
        $invalid[] = $key;
      }
    }

    return [
      'configured_count' => count($service_areas),
      'missing' => $missing,
      'invalid' => $invalid,
      'status' => ($missing === [] && $invalid === []) ? 'healthy' : 'degraded',
    ];
  }

  /**
   * Builds the LegalServer URL validation payload.
   */
  private function buildLegalServerCheck(): array {
    $url = $this->getLegalServerOnlineApplicationUrl();
    $check = [
      'source' => 'settings',
      'configured' => $url !== NULL,
      'absolute' => FALSE,
      'https' => FALSE,
      'host' => NULL,
      'path' => NULL,
      'required_query_keys' => [
        'pid' => FALSE,
        'h' => FALSE,
      ],
      'probe_status' => 'skipped',
      'status' => 'degraded',
    ];

    if ($url === NULL) {
      return $check;
    }

    $parts = parse_url($url);
    if ($parts === FALSE) {
      return $check;
    }

    $check['absolute'] = !empty($parts['scheme']) && !empty($parts['host']);
    $check['https'] = strtolower((string) ($parts['scheme'] ?? '')) === 'https';
    $check['host'] = isset($parts['host']) ? strtolower((string) $parts['host']) : NULL;
    $check['path'] = isset($parts['path']) ? (string) $parts['path'] : NULL;

    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $check['required_query_keys']['pid'] = isset($query['pid']) && (string) $query['pid'] !== '';
    $check['required_query_keys']['h'] = isset($query['h']) && (string) $query['h'] !== '';

    if (
      $check['absolute'] &&
      $check['https'] &&
      $check['required_query_keys']['pid'] &&
      $check['required_query_keys']['h']
    ) {
      $check['status'] = 'healthy';
    }

    return $check;
  }

}
