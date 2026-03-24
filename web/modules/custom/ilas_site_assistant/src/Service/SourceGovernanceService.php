<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\RetrievalContract;
use Psr\Log\LoggerInterface;

/**
 * Enforces soft governance for retrieval source freshness and provenance.
 */
class SourceGovernanceService {

  /**
   * Allowed absolute hosts for trusted citation URLs.
   */
  private const ALLOWED_CITATION_HOSTS = [
    'idaholegalaid.org',
    'www.idaholegalaid.org',
  ];

  /**
   * State key storing the rolling governance snapshot.
   */
  private const SNAPSHOT_STATE_KEY = 'ilas_site_assistant.source_governance.snapshot';

  /**
   * State key storing stale-ratio alert cooldown timestamp.
   */
  private const ALERT_STATE_KEY = 'ilas_site_assistant.source_governance.last_alert';

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Module logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a source governance service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    LoggerInterface $logger,
  ) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
  }

  /**
   * Sanitizes a citation URL against the ILAS citation allowlist.
   *
   * Allows root-relative ILAS paths and absolute HTTP(S) URLs on the approved
   * ILAS hosts. Rejects unsafe schemes, protocol-relative URLs, bare fragments,
   * malformed URLs, and off-domain hosts.
   *
   * @param string|null $url
   *   Raw citation URL candidate.
   *
   * @return string|null
   *   Sanitized citation URL, or NULL when disallowed.
   */
  public function sanitizeCitationUrl(?string $url): ?string {
    if (!is_string($url)) {
      return NULL;
    }

    $trimmed = trim($url);
    if ($trimmed === '' || preg_match('/\s/', $trimmed)) {
      return NULL;
    }

    if ($trimmed[0] === '#') {
      return NULL;
    }

    if (str_starts_with($trimmed, '//')) {
      return NULL;
    }

    if (str_starts_with($trimmed, '/')) {
      $parts = parse_url($trimmed);
      if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host'])) {
        return NULL;
      }

      return $trimmed;
    }

    $parts = parse_url($trimmed);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return NULL;
    }

    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'https') {
      return NULL;
    }

    $host = strtolower((string) $parts['host']);
    if (!in_array($host, self::ALLOWED_CITATION_HOSTS, TRUE)) {
      return NULL;
    }

    return $trimmed;
  }

  /**
   * Annotates a single retrieval result with provenance/freshness metadata.
   *
   * @param array $item
   *   Retrieval result item.
   * @param string $source_class
   *   Source class key.
   * @param string $retrieval_method
   *   How the data was retrieved ('search_api' or 'entity_query').
   *
   * @return array
   *   Annotated item.
   */
  public function annotateResult(array $item, string $source_class, string $retrieval_method = 'search_api'): array {
    RetrievalContract::assertApprovedSourceClass($source_class);

    $policy = $this->getPolicy();
    $class_policy = $this->getSourceClassPolicy($source_class, $policy);

    $source_url = $item['source_url'] ?? $item['url'] ?? NULL;
    $has_source_url = is_string($source_url) && $source_url !== '';
    $sanitized_source_url = $this->sanitizeCitationUrl($source_url);
    $source_url_allowed = $sanitized_source_url !== NULL;
    $updated_at = $this->resolveUpdatedAt($item);

    $max_age_days = (int) ($class_policy['max_age_days'] ?? 180);
    $age_days = $updated_at !== NULL
      ? (int) floor(max(0, time() - $updated_at) / 86400)
      : NULL;

    if ($updated_at === NULL) {
      $freshness_status = 'unknown';
    }
    else {
      $freshness_status = $age_days > $max_age_days ? 'stale' : 'fresh';
    }

    $flags = [];
    if (!empty($class_policy['require_source_url']) && !$has_source_url) {
      $flags[] = 'missing_source_url';
    }
    if ($has_source_url && !$source_url_allowed) {
      $flags[] = 'invalid_source_url';
    }
    if ($freshness_status === 'stale') {
      $flags[] = 'stale_source';
    }
    if ($freshness_status === 'unknown') {
      $flags[] = 'unknown_freshness';
    }

    $configured_label = (string) ($class_policy['provenance_label'] ?? $source_class);
    $provenance_label = $this->resolveProvenanceLabel($configured_label, $source_class, $retrieval_method);

    $item['source_class'] = $source_class;
    $item['provenance'] = [
      'source_class' => $source_class,
      'provenance_label' => $provenance_label,
      'retrieval_method' => $retrieval_method,
      'owner_role' => (string) ($class_policy['owner_role'] ?? 'Content Operations Lead'),
      'policy_version' => (string) ($policy['policy_version'] ?? 'p2_obj_03_v1'),
      'has_source_url' => $has_source_url,
      'source_url_allowed' => $source_url_allowed,
      'enforcement_mode' => $this->getEnforcementMode(),
      'retrieval_contract_version' => RetrievalContract::POLICY_VERSION,
    ];
    $item['freshness'] = [
      'status' => $freshness_status,
      'updated_at' => $updated_at,
      'age_days' => $age_days,
      'max_age_days' => $max_age_days,
    ];
    $item['governance_flags'] = array_values(array_unique($flags));

    return $item;
  }

  /**
   * Annotates a batch of retrieval results.
   *
   * @param array $items
   *   Retrieval results.
   * @param string $source_class
   *   Source class key.
   * @param string $retrieval_method
   *   How the data was retrieved ('search_api' or 'entity_query').
   *
   * @return array
   *   Annotated batch.
   */
  public function annotateBatch(array $items, string $source_class, string $retrieval_method = 'search_api'): array {
    foreach ($items as $index => $item) {
      if (is_array($item)) {
        $items[$index] = $this->annotateResult($item, $source_class, $retrieval_method);
      }
    }
    return $items;
  }

  /**
   * Records governance observations for a retrieval-result batch.
   *
   * @param array $results
   *   Retrieval results.
   */
  public function recordObservationBatch(array $results): void {
    $policy = $this->getPolicy();
    if (empty($policy['enabled']) || empty($results)) {
      return;
    }

    $now = time();
    $window_seconds = max(1, (int) ($policy['observation_window_hours'] ?? 24)) * 3600;
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);

    if (!is_array($snapshot) || (($snapshot['window_started_at'] ?? 0) + $window_seconds) < $now) {
      $snapshot = $this->newSnapshot($policy, $now);
    }

    foreach ($results as $result) {
      if (!is_array($result)) {
        continue;
      }

      $source_class = $this->inferSourceClass($result);
      $retrieval_method = $result['provenance']['retrieval_method'] ?? 'search_api';
      $annotated = $this->annotateResult($result, $source_class, $retrieval_method);
      $freshness_status = $annotated['freshness']['status'] ?? 'unknown';
      $flags = $annotated['governance_flags'] ?? [];

      $snapshot['total']++;
      if ($freshness_status === 'stale') {
        $snapshot['stale']++;
      }
      if ($freshness_status === 'unknown') {
        $snapshot['unknown']++;
      }
      if (in_array('missing_source_url', $flags, TRUE)) {
        $snapshot['missing_source_url']++;
      }

      if (!isset($snapshot['by_source_class'][$source_class])) {
        $snapshot['by_source_class'][$source_class] = [
          'total' => 0,
          'stale' => 0,
          'unknown' => 0,
          'missing_source_url' => 0,
        ];
      }
      $snapshot['by_source_class'][$source_class]['total']++;
      if ($freshness_status === 'stale') {
        $snapshot['by_source_class'][$source_class]['stale']++;
      }
      if ($freshness_status === 'unknown') {
        $snapshot['by_source_class'][$source_class]['unknown']++;
      }
      if (in_array('missing_source_url', $flags, TRUE)) {
        $snapshot['by_source_class'][$source_class]['missing_source_url']++;
      }

      if (!isset($snapshot['by_retrieval_method'][$retrieval_method])) {
        $snapshot['by_retrieval_method'][$retrieval_method] = [
          'total' => 0,
          'stale' => 0,
          'unknown' => 0,
          'missing_source_url' => 0,
        ];
      }
      $snapshot['by_retrieval_method'][$retrieval_method]['total']++;
      if ($freshness_status === 'stale') {
        $snapshot['by_retrieval_method'][$retrieval_method]['stale']++;
      }
      if ($freshness_status === 'unknown') {
        $snapshot['by_retrieval_method'][$retrieval_method]['unknown']++;
      }
      if (in_array('missing_source_url', $flags, TRUE)) {
        $snapshot['by_retrieval_method'][$retrieval_method]['missing_source_url']++;
      }
    }

    $snapshot['recorded_at'] = $now;
    $snapshot['policy_version'] = (string) ($policy['policy_version'] ?? 'p2_obj_03_v1');
    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy);
    $snapshot['status'] = $this->computeSnapshotStatus($snapshot, $policy);

    $this->state->set(self::SNAPSHOT_STATE_KEY, $snapshot);
    $this->emitStaleRatioAlertIfNeeded($snapshot, $policy);
  }

  /**
   * Returns the current source-governance snapshot for monitoring APIs.
   *
   * @return array
   *   Snapshot metadata.
   */
  public function getSnapshot(): array {
    $policy = $this->getPolicy();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);

    if (!is_array($snapshot)) {
      $snapshot = $this->newSnapshot($policy, time());
    }

    $snapshot += [
      'policy_version' => (string) ($policy['policy_version'] ?? 'p2_obj_03_v1'),
      'total' => 0,
      'stale' => 0,
      'unknown' => 0,
      'missing_source_url' => 0,
      'stale_ratio_pct' => 0.0,
      'unknown_ratio_pct' => 0.0,
      'missing_source_url_ratio_pct' => 0.0,
      'min_observations' => (int) ($policy['min_observations'] ?? 20),
      'min_observations_met' => FALSE,
      'last_alert_at' => NULL,
      'next_alert_eligible_at' => NULL,
      'cooldown_seconds_remaining' => 0,
      'by_source_class' => [],
      'by_retrieval_method' => [],
    ];
    $snapshot = $this->applyDerivedSnapshotFields($snapshot, $policy);
    $snapshot['status'] = $this->computeSnapshotStatus($snapshot, $policy);

    return $snapshot;
  }

  /**
   * Builds default source-governance policy values.
   */
  protected function defaultPolicy(): array {
    $default_class = [
      'owner_role' => 'Content Operations Lead',
      'max_age_days' => 180,
      'require_source_url' => TRUE,
    ];

    return [
      'enabled' => TRUE,
      'policy_version' => 'p2_obj_03_v1',
      'observation_window_hours' => 24,
      'stale_ratio_alert_pct' => 18.0,
      'min_observations' => 20,
      'unknown_ratio_degrade_pct' => 22.0,
      'missing_source_url_ratio_degrade_pct' => 9.0,
      'alert_cooldown_minutes' => 60,
      'source_classes' => [
        'faq_lexical' => $default_class + ['provenance_label' => 'search_api.index.faq_accordion'],
        'faq_vector' => $default_class + ['provenance_label' => 'search_api.index.faq_accordion_vector'],
        'resource_lexical' => $default_class + ['provenance_label' => 'search_api.index.assistant_resources'],
        'resource_vector' => $default_class + ['provenance_label' => 'search_api.index.assistant_resources_vector'],
      ],
    ];
  }

  /**
   * Returns merged policy values from config/defaults.
   */
  protected function getPolicy(): array {
    $policy = $this->defaultPolicy();
    $configured = $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('source_governance');

    if (is_array($configured)) {
      $policy = array_replace_recursive($policy, $configured);
    }

    return $policy;
  }

  /**
   * Resolves source-class policy with fallback defaults.
   */
  protected function getSourceClassPolicy(string $source_class, array $policy): array {
    $classes = $policy['source_classes'] ?? [];
    if (is_array($classes) && isset($classes[$source_class]) && is_array($classes[$source_class])) {
      return $classes[$source_class];
    }

    return [
      'provenance_label' => $source_class,
      'owner_role' => 'Content Operations Lead',
      'max_age_days' => 180,
      'require_source_url' => TRUE,
    ];
  }

  /**
   * Resolves updated timestamp from result metadata.
   */
  protected function resolveUpdatedAt(array $item): ?int {
    $candidates = [
      $item['updated_at'] ?? NULL,
      $item['changed'] ?? NULL,
      $item['modified'] ?? NULL,
      $item['freshness']['updated_at'] ?? NULL,
    ];

    foreach ($candidates as $candidate) {
      if (is_int($candidate) && $candidate > 0) {
        return $candidate;
      }
      if (is_numeric($candidate) && (int) $candidate > 0) {
        return (int) $candidate;
      }
      if (is_string($candidate) && $candidate !== '') {
        $timestamp = strtotime($candidate);
        if ($timestamp !== FALSE && $timestamp > 0) {
          return $timestamp;
        }
      }
    }

    return NULL;
  }

  /**
   * Infers source class when result is missing explicit class metadata.
   */
  protected function inferSourceClass(array $result): string {
    if (!empty($result['source_class']) && is_string($result['source_class'])) {
      return $result['source_class'];
    }

    $source = strtolower((string) ($result['source'] ?? 'lexical'));
    $type = strtolower((string) ($result['type'] ?? ''));
    $id = strtolower((string) ($result['id'] ?? ''));
    $is_faq = str_starts_with($id, 'faq_') || in_array($type, ['faq_item', 'accordion_item'], TRUE);

    if ($is_faq) {
      return $source === 'vector' ? 'faq_vector' : 'faq_lexical';
    }

    return $source === 'vector' ? 'resource_vector' : 'resource_lexical';
  }

  /**
   * Resolves the truthful provenance label based on retrieval method.
   *
   * When retrieval uses a non-index method (e.g., entity_query), the label
   * is corrected to reflect the actual data source rather than falsely
   * claiming Search API provenance.
   *
   * @param string $configured_label
   *   The provenance label from policy config.
   * @param string $source_class
   *   The source class (e.g., faq_lexical).
   * @param string $retrieval_method
   *   The actual retrieval method used.
   *
   * @return string
   *   Truthful provenance label.
   */
  protected function resolveProvenanceLabel(string $configured_label, string $source_class, string $retrieval_method): string {
    if (!in_array($retrieval_method, RetrievalContract::NON_INDEX_RETRIEVAL_METHODS, TRUE)) {
      return $configured_label;
    }

    $entity_type = str_starts_with($source_class, 'faq_') ? 'paragraph' : 'node';
    return $entity_type . '.' . $retrieval_method;
  }

  /**
   * Builds a new empty snapshot.
   */
  protected function newSnapshot(array $policy, int $now): array {
    return [
      'policy_version' => (string) ($policy['policy_version'] ?? 'p2_obj_03_v1'),
      'window_started_at' => $now,
      'recorded_at' => $now,
      'total' => 0,
      'stale' => 0,
      'unknown' => 0,
      'missing_source_url' => 0,
      'stale_ratio_pct' => 0.0,
      'unknown_ratio_pct' => 0.0,
      'missing_source_url_ratio_pct' => 0.0,
      'min_observations' => max(1, (int) ($policy['min_observations'] ?? 20)),
      'min_observations_met' => FALSE,
      'last_alert_at' => NULL,
      'next_alert_eligible_at' => NULL,
      'cooldown_seconds_remaining' => 0,
      'by_source_class' => [],
      'by_retrieval_method' => [],
      'status' => 'unknown',
      'thresholds' => [
        'stale_ratio_alert_pct' => (float) ($policy['stale_ratio_alert_pct'] ?? 18.0),
        'min_observations' => max(1, (int) ($policy['min_observations'] ?? 20)),
        'unknown_ratio_degrade_pct' => (float) ($policy['unknown_ratio_degrade_pct'] ?? 22.0),
        'missing_source_url_ratio_degrade_pct' => (float) ($policy['missing_source_url_ratio_degrade_pct'] ?? 9.0),
        'observation_window_hours' => (int) ($policy['observation_window_hours'] ?? 24),
        'alert_cooldown_minutes' => (int) ($policy['alert_cooldown_minutes'] ?? 60),
      ],
    ];
  }

  /**
   * Computes health status from snapshot counters and thresholds.
   */
  protected function computeSnapshotStatus(array $snapshot, array $policy): string {
    if (empty($policy['enabled'])) {
      return 'unknown';
    }
    if (($snapshot['total'] ?? 0) <= 0) {
      return 'unknown';
    }

    $stale_ratio = (float) ($snapshot['stale_ratio_pct'] ?? 0.0);
    $stale_threshold = (float) ($policy['stale_ratio_alert_pct'] ?? 18.0);

    if ($stale_ratio >= $stale_threshold) {
      return 'degraded';
    }

    $min_observations = max(1, (int) ($policy['min_observations'] ?? 20));
    $min_observations_met = (int) ($snapshot['total'] ?? 0) >= $min_observations;
    if ($min_observations_met) {
      $unknown_ratio = (float) ($snapshot['unknown_ratio_pct'] ?? 0.0);
      $missing_ratio = (float) ($snapshot['missing_source_url_ratio_pct'] ?? 0.0);
      $unknown_threshold = (float) ($policy['unknown_ratio_degrade_pct'] ?? 22.0);
      $missing_threshold = (float) ($policy['missing_source_url_ratio_degrade_pct'] ?? 9.0);

      if ($unknown_ratio >= $unknown_threshold || $missing_ratio >= $missing_threshold) {
        return 'degraded';
      }
    }

    return 'healthy';
  }

  /**
   * Applies derived ratio/threshold/cooldown fields to the snapshot.
   */
  protected function applyDerivedSnapshotFields(array $snapshot, array $policy): array {
    $total = max(0, (int) ($snapshot['total'] ?? 0));
    $stale = max(0, (int) ($snapshot['stale'] ?? 0));
    $unknown = max(0, (int) ($snapshot['unknown'] ?? 0));
    $missing_source_url = max(0, (int) ($snapshot['missing_source_url'] ?? 0));

    $snapshot['stale_ratio_pct'] = $total > 0
      ? round(($stale / $total) * 100, 2)
      : 0.0;
    $snapshot['unknown_ratio_pct'] = $total > 0
      ? round(($unknown / $total) * 100, 2)
      : 0.0;
    $snapshot['missing_source_url_ratio_pct'] = $total > 0
      ? round(($missing_source_url / $total) * 100, 2)
      : 0.0;

    $min_observations = max(1, (int) ($policy['min_observations'] ?? 20));
    $snapshot['min_observations'] = $min_observations;
    $snapshot['min_observations_met'] = $total >= $min_observations;

    $cooldown_seconds = max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)) * 60;
    $last_alert = (int) $this->state->get(self::ALERT_STATE_KEY, 0);
    $next_alert_eligible_at = $last_alert > 0 ? $last_alert + $cooldown_seconds : 0;
    $cooldown_seconds_remaining = max(0, $next_alert_eligible_at - time());

    $snapshot['last_alert_at'] = $last_alert > 0 ? $last_alert : NULL;
    $snapshot['next_alert_eligible_at'] = $next_alert_eligible_at > 0 ? $next_alert_eligible_at : NULL;
    $snapshot['cooldown_seconds_remaining'] = $cooldown_seconds_remaining;
    $snapshot['thresholds'] = [
      'stale_ratio_alert_pct' => (float) ($policy['stale_ratio_alert_pct'] ?? 18.0),
      'min_observations' => $min_observations,
      'unknown_ratio_degrade_pct' => (float) ($policy['unknown_ratio_degrade_pct'] ?? 22.0),
      'missing_source_url_ratio_degrade_pct' => (float) ($policy['missing_source_url_ratio_degrade_pct'] ?? 9.0),
      'observation_window_hours' => (int) ($policy['observation_window_hours'] ?? 24),
      'alert_cooldown_minutes' => (int) ($policy['alert_cooldown_minutes'] ?? 60),
    ];

    return $snapshot;
  }

  /**
   * Emits stale-ratio alert logs with cooldown protection.
   */
  protected function emitStaleRatioAlertIfNeeded(array $snapshot, array $policy): void {
    $threshold = (float) ($policy['stale_ratio_alert_pct'] ?? 18.0);
    $ratio = (float) ($snapshot['stale_ratio_pct'] ?? 0.0);

    if ($ratio < $threshold) {
      return;
    }

    $cooldown_seconds = max(1, (int) ($policy['alert_cooldown_minutes'] ?? 60)) * 60;
    $now = time();
    $last_alert = (int) $this->state->get(self::ALERT_STATE_KEY, 0);
    if (($now - $last_alert) < $cooldown_seconds) {
      return;
    }

    $this->logger->warning(
      'Source governance stale ratio @ratio% exceeds threshold @threshold% (stale @stale / total @total).',
      [
        '@ratio' => $ratio,
        '@threshold' => $threshold,
        '@stale' => (int) ($snapshot['stale'] ?? 0),
        '@total' => (int) ($snapshot['total'] ?? 0),
      ]
    );
    $this->state->set(self::ALERT_STATE_KEY, $now);
  }

  /**
   * Returns the configured enforcement mode for provenance annotations.
   *
   * Reads from retrieval_contract.enforcement_mode in module config.
   * Falls back to 'advisory' when the value is missing or invalid.
   *
   * @return string
   *   One of 'advisory', 'soft', or 'strict'.
   */
  public function getEnforcementMode(): string {
    $configured = $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('retrieval_contract.enforcement_mode');
    if (is_string($configured) && in_array($configured, ['advisory', 'soft', 'strict'], TRUE)) {
      return $configured;
    }
    return 'advisory';
  }

  /**
   * Returns a lightweight governance summary for per-response metadata.
   *
   * @return array
   *   Array with enforcement_mode, policy_version, and current status.
   */
  public function getGovernanceSummary(): array {
    $snapshot = $this->getSnapshot();
    return [
      'enforcement_mode' => $this->getEnforcementMode(),
      'policy_version' => RetrievalContract::POLICY_VERSION,
      'status' => $snapshot['status'] ?? 'unknown',
    ];
  }

}
