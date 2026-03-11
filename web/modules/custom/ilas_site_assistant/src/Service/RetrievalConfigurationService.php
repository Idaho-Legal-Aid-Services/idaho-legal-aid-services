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
   * Required retrieval config keys.
   */
  private const REQUIRED_RETRIEVAL_KEYS = [
    'faq_index_id',
    'resource_index_id',
    'resource_fallback_index_id',
    'faq_vector_index_id',
    'resource_vector_index_id',
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
      'retrieval' => [
        'faq_index' => $this->buildIndexCheck($this->getFaqIndexId()),
        'resource_index' => $this->buildIndexCheck($this->getResourceIndexId()),
        'resource_fallback_index' => $this->buildIndexCheck($this->getResourceFallbackIndexId()),
        'faq_vector_index' => $this->buildIndexCheck($this->getFaqVectorIndexId()),
        'resource_vector_index' => $this->buildIndexCheck($this->getResourceVectorIndexId()),
      ],
      'canonical_urls' => [
        'service_areas' => $this->buildServiceAreaCheck(),
        'legalserver_intake_url' => $this->buildLegalServerCheck(),
      ],
    ];

    foreach ($snapshot['retrieval'] as $check) {
      if (($check['status'] ?? 'degraded') !== 'healthy') {
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
   * Builds one index validation payload.
   */
  private function buildIndexCheck(?string $index_id): array {
    $check = [
      'configured' => $index_id !== NULL,
      'index_id' => $index_id ?? '',
      'machine_name_valid' => FALSE,
      'exists' => FALSE,
      'enabled' => FALSE,
      'status' => 'degraded',
    ];

    if ($index_id === NULL) {
      return $check;
    }

    $check['machine_name_valid'] = (bool) preg_match('/^[a-z0-9_]+$/', $index_id);
    if (!$check['machine_name_valid']) {
      return $check;
    }

    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($index_id);

    if (!is_object($index)) {
      return $check;
    }

    $check['exists'] = TRUE;
    $check['enabled'] = method_exists($index, 'status') ? (bool) $index->status() : FALSE;
    $check['status'] = $check['enabled'] ? 'healthy' : 'degraded';
    return $check;
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
