<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Enforces bounded abuse controls for public assistant read endpoints.
 */
final class AssistantReadEndpointGuard {

  /**
   * Safe defaults when config is absent.
   *
   * @var array<string, array{rate_limit_per_minute: int, rate_limit_per_hour: int}>
   */
  private const DEFAULT_LIMITS = [
    'suggest' => [
      'rate_limit_per_minute' => 120,
      'rate_limit_per_hour' => 1200,
    ],
    'faq' => [
      'rate_limit_per_minute' => 60,
      'rate_limit_per_hour' => 600,
    ],
  ];

  /**
   * Flood event names by endpoint and window.
   *
   * @var array<string, array{minute: string, hour: string}>
   */
  private const FLOOD_EVENTS = [
    'suggest' => [
      'minute' => 'ilas_assistant_suggest_min',
      'hour' => 'ilas_assistant_suggest_hour',
    ],
    'faq' => [
      'minute' => 'ilas_assistant_faq_min',
      'hour' => 'ilas_assistant_faq_hour',
    ],
  ];

  /**
   * Constructs an AssistantReadEndpointGuard object.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly RequestTrustInspector $requestTrustInspector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluates whether a public read request should be allowed.
   *
   * @return array{
   *   allowed: bool,
   *   endpoint: string,
   *   retry_after: int|null,
   *   effective_client_ip: string,
   *   trust_status: string,
   *   thresholds: array{
   *     rate_limit_per_minute: int,
   *     rate_limit_per_hour: int
   *   }
   * }
   *   Guard decision payload.
   */
  public function evaluate(Request $request, string $endpoint): array {
    if (!isset(self::FLOOD_EVENTS[$endpoint])) {
      throw new \InvalidArgumentException(sprintf('Unsupported read endpoint "%s".', $endpoint));
    }

    $thresholds = $this->getThresholds($endpoint);
    $trust_context = $this->resolveTrustContext($request);
    $effective_client_ip = $trust_context['effective_client_ip'];
    $trust_status = $trust_context['trust_status'];
    $identifier = sprintf('ilas_assistant_%s:%s', $endpoint, $effective_client_ip);
    $events = self::FLOOD_EVENTS[$endpoint];

    if (!$this->flood->isAllowed($events['minute'], $thresholds['rate_limit_per_minute'], 60, $identifier)) {
      $this->logDenied($request, $endpoint, 'minute_limit', 60, $effective_client_ip, $trust_status);
      return [
        'allowed' => FALSE,
        'endpoint' => $endpoint,
        'retry_after' => 60,
        'effective_client_ip' => $effective_client_ip,
        'trust_status' => $trust_status,
        'thresholds' => $thresholds,
      ];
    }

    if (!$this->flood->isAllowed($events['hour'], $thresholds['rate_limit_per_hour'], 3600, $identifier)) {
      $this->logDenied($request, $endpoint, 'hour_limit', 3600, $effective_client_ip, $trust_status);
      return [
        'allowed' => FALSE,
        'endpoint' => $endpoint,
        'retry_after' => 3600,
        'effective_client_ip' => $effective_client_ip,
        'trust_status' => $trust_status,
        'thresholds' => $thresholds,
      ];
    }

    $this->flood->register($events['minute'], 60, $identifier);
    $this->flood->register($events['hour'], 3600, $identifier);

    return [
      'allowed' => TRUE,
      'endpoint' => $endpoint,
      'retry_after' => NULL,
      'effective_client_ip' => $effective_client_ip,
      'trust_status' => $trust_status,
      'thresholds' => $thresholds,
    ];
  }

  /**
   * Returns normalized threshold values for every governed read endpoint.
   *
   * @return array<string, array{rate_limit_per_minute: int, rate_limit_per_hour: int}>
   *   Threshold summary keyed by endpoint.
   */
  public function getThresholdSummary(): array {
    $summary = [];
    foreach (array_keys(self::FLOOD_EVENTS) as $endpoint) {
      $summary[$endpoint] = $this->getThresholds($endpoint);
    }

    return $summary;
  }

  /**
   * Returns active threshold values for the provided endpoint.
   *
   * @return array{rate_limit_per_minute: int, rate_limit_per_hour: int}
   *   Normalized thresholds.
   */
  private function getThresholds(string $endpoint): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $limits = $config->get('read_endpoint_rate_limits');
    $limits = is_array($limits) ? $limits : [];
    $endpoint_limits = $limits[$endpoint] ?? [];
    $endpoint_limits = is_array($endpoint_limits) ? $endpoint_limits : [];
    $defaults = self::DEFAULT_LIMITS[$endpoint];

    return [
      'rate_limit_per_minute' => max(1, (int) ($endpoint_limits['rate_limit_per_minute'] ?? $defaults['rate_limit_per_minute'])),
      'rate_limit_per_hour' => max(1, (int) ($endpoint_limits['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour'])),
    ];
  }

  /**
   * Resolves trust data used for flood identity.
   *
   * @return array{effective_client_ip: string, trust_status: string}
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
      'trust_status' => (string) ($trust_context['status'] ?? RequestTrustInspector::STATUS_DIRECT_REMOTE_ADDR),
    ];
  }

  /**
   * Logs a throttled read-path decision.
   */
  private function logDenied(
    Request $request,
    string $endpoint,
    string $reason,
    int $retry_after,
    string $effective_client_ip,
    string $trust_status,
  ): void {
    $this->logger->notice(
      'event={event} endpoint={endpoint} reason={reason} retry_after={retry_after} path={path} effective_client_ip={effective_client_ip} trust_status={trust_status}',
      [
        'event' => 'assistant_read_rate_limit_denied',
        'endpoint' => $endpoint,
        'reason' => $reason,
        'retry_after' => $retry_after,
        'path' => $request->getPathInfo(),
        'effective_client_ip' => $effective_client_ip,
        'trust_status' => $trust_status,
      ],
    );
  }

}
