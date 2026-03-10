<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Enforces guardrails around anonymous assistant session bootstrap.
 */
final class AssistantSessionBootstrapGuard {

  /**
   * Snapshot state key for bootstrap observability.
   */
  public const SNAPSHOT_STATE_KEY = 'ilas_site_assistant.session_bootstrap.snapshot';

  /**
   * Flood event name for minute-level anonymous bootstrap creation.
   */
  private const FLOOD_EVENT_MINUTE = 'ilas_assistant_session_bootstrap_min';

  /**
   * Flood event name for hour-level anonymous bootstrap creation.
   */
  private const FLOOD_EVENT_HOUR = 'ilas_assistant_session_bootstrap_hour';

  /**
   * Safe defaults when config is absent.
   */
  private const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
  private const DEFAULT_RATE_LIMIT_PER_HOUR = 600;
  private const DEFAULT_OBSERVATION_WINDOW_HOURS = 24;

  /**
   * Constructs an AssistantSessionBootstrapGuard object.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly RequestTrustInspector $requestTrustInspector,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Evaluates whether the bootstrap request should be allowed.
   *
   * Requests with an existing session are always allowed and do not consume the
   * anonymous new-session flood budget.
   *
   * @return array{
   *   allowed: bool,
   *   mode: string,
   *   retry_after: int|null,
   *   effective_client_ip: string,
   *   thresholds: array{
   *     rate_limit_per_minute: int,
   *     rate_limit_per_hour: int,
   *     observation_window_hours: int
   *   }
   * }
   *   Guard decision payload.
   */
  public function evaluate(Request $request): array {
    $thresholds = $this->getThresholds();

    if ($request->hasPreviousSession()) {
      return [
        'allowed' => TRUE,
        'mode' => 'reuse',
        'retry_after' => NULL,
        'effective_client_ip' => '',
        'thresholds' => $thresholds,
      ];
    }

    $trust_context = $this->resolveTrustContext($request);
    $effective_client_ip = $trust_context['effective_client_ip'];
    $flood_id = 'ilas_assistant_session_bootstrap:' . $effective_client_ip;
    if (!$this->flood->isAllowed(self::FLOOD_EVENT_MINUTE, $thresholds['rate_limit_per_minute'], 60, $flood_id)) {
      $this->recordEvent('rate_limited', $thresholds);
      $this->logger->notice(
        'event={event} reason={reason} retry_after={retry_after} path={path} effective_client_ip={effective_client_ip} trust_status={trust_status}',
        [
          'event' => 'session_bootstrap_rate_limit_denied',
          'reason' => 'minute_limit',
          'retry_after' => 60,
          'path' => $request->getPathInfo(),
          'effective_client_ip' => $effective_client_ip,
          'trust_status' => $trust_context['status'],
        ],
      );

      return [
        'allowed' => FALSE,
        'mode' => 'rate_limited',
        'retry_after' => 60,
        'effective_client_ip' => $effective_client_ip,
        'thresholds' => $thresholds,
      ];
    }

    if (!$this->flood->isAllowed(self::FLOOD_EVENT_HOUR, $thresholds['rate_limit_per_hour'], 3600, $flood_id)) {
      $this->recordEvent('rate_limited', $thresholds);
      $this->logger->notice(
        'event={event} reason={reason} retry_after={retry_after} path={path} effective_client_ip={effective_client_ip} trust_status={trust_status}',
        [
          'event' => 'session_bootstrap_rate_limit_denied',
          'reason' => 'hour_limit',
          'retry_after' => 3600,
          'path' => $request->getPathInfo(),
          'effective_client_ip' => $effective_client_ip,
          'trust_status' => $trust_context['status'],
        ],
      );

      return [
        'allowed' => FALSE,
        'mode' => 'rate_limited',
        'retry_after' => 3600,
        'effective_client_ip' => $effective_client_ip,
        'thresholds' => $thresholds,
      ];
    }

    $this->flood->register(self::FLOOD_EVENT_MINUTE, 60, $flood_id);
    $this->flood->register(self::FLOOD_EVENT_HOUR, 3600, $flood_id);
    $this->recordEvent('new_session', $thresholds);

    return [
      'allowed' => TRUE,
      'mode' => 'new_session',
      'retry_after' => NULL,
      'effective_client_ip' => $effective_client_ip,
      'thresholds' => $thresholds,
    ];
  }

  /**
   * Returns the current bootstrap observability snapshot.
   *
   * @return array<string, mixed>
   *   Snapshot payload.
   */
  public function getSnapshot(): array {
    $thresholds = $this->getThresholds();
    $now = $this->time->getCurrentTime();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);

    if (!is_array($snapshot) || $this->windowExpired($snapshot, $thresholds, $now)) {
      return $this->newSnapshot($thresholds, $now);
    }

    return $snapshot + $this->newSnapshot($thresholds, $now);
  }

  /**
   * Returns active threshold values.
   *
   * @return array{
   *   rate_limit_per_minute: int,
   *   rate_limit_per_hour: int,
   *   observation_window_hours: int
   * }
   *   Normalized thresholds.
   */
  private function getThresholds(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $bootstrap = $config->get('session_bootstrap');
    $bootstrap = is_array($bootstrap) ? $bootstrap : [];

    return [
      'rate_limit_per_minute' => max(1, (int) ($bootstrap['rate_limit_per_minute'] ?? self::DEFAULT_RATE_LIMIT_PER_MINUTE)),
      'rate_limit_per_hour' => max(1, (int) ($bootstrap['rate_limit_per_hour'] ?? self::DEFAULT_RATE_LIMIT_PER_HOUR)),
      'observation_window_hours' => max(1, (int) ($bootstrap['observation_window_hours'] ?? self::DEFAULT_OBSERVATION_WINDOW_HOURS)),
    ];
  }

  /**
   * Records a bounded bootstrap event in the rolling snapshot.
   */
  private function recordEvent(string $event, array $thresholds): void {
    $now = $this->time->getCurrentTime();
    $snapshot = $this->state->get(self::SNAPSHOT_STATE_KEY);
    if (!is_array($snapshot) || $this->windowExpired($snapshot, $thresholds, $now)) {
      $snapshot = $this->newSnapshot($thresholds, $now);
    }

    $snapshot['recorded_at'] = $now;
    if ($event === 'new_session') {
      $snapshot['new_session_requests']++;
      $snapshot['last_new_session_at'] = $now;
    }
    elseif ($event === 'rate_limited') {
      $snapshot['rate_limited_requests']++;
      $snapshot['last_rate_limited_at'] = $now;
    }

    $snapshot['thresholds'] = $thresholds;
    $this->state->set(self::SNAPSHOT_STATE_KEY, $snapshot);
  }

  /**
   * Returns TRUE when the rolling snapshot window has expired.
   */
  private function windowExpired(array $snapshot, array $thresholds, int $now): bool {
    $window_started_at = (int) ($snapshot['window_started_at'] ?? 0);
    $window_seconds = $thresholds['observation_window_hours'] * 3600;
    return $window_started_at <= 0 || ($window_started_at + $window_seconds) < $now;
  }

  /**
   * Builds a new empty snapshot.
   *
   * @return array<string, mixed>
   *   Snapshot payload.
   */
  private function newSnapshot(array $thresholds, int $now): array {
    return [
      'window_started_at' => $now,
      'recorded_at' => NULL,
      'new_session_requests' => 0,
      'rate_limited_requests' => 0,
      'last_new_session_at' => NULL,
      'last_rate_limited_at' => NULL,
      'thresholds' => $thresholds,
    ];
  }

  /**
   * Resolves trust data used for flood identity.
   *
   * @return array{effective_client_ip: string, status: string}
   *   Normalized trust payload.
   */
  private function resolveTrustContext(Request $request): array {
    $trust_context = $this->requestTrustInspector->inspectRequest($request);
    $effective_client_ip = (string) ($trust_context['effective_client_ip'] ?? '');
    if ($effective_client_ip === '') {
      $effective_client_ip = (string) ($request->getClientIp() ?? $request->server->get('REMOTE_ADDR', 'unknown'));
    }

    return [
      'effective_client_ip' => $effective_client_ip !== '' ? $effective_client_ip : 'unknown',
      'status' => (string) ($trust_context['status'] ?? RequestTrustInspector::STATUS_DIRECT_REMOTE_ADDR),
    ];
  }

}
