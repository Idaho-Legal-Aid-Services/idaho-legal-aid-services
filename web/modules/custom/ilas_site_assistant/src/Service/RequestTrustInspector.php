<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Inspects request trust inputs for client-IP resolution and flood keys.
 */
final class RequestTrustInspector {

  /**
   * Request uses REMOTE_ADDR directly.
   */
  public const STATUS_DIRECT_REMOTE_ADDR = 'direct_remote_addr';

  /**
   * Request uses a trusted forwarded chain.
   */
  public const STATUS_TRUSTED_FORWARDED_CHAIN = 'trusted_forwarded_chain';

  /**
   * Forwarded headers are present but currently untrusted.
   */
  public const STATUS_FORWARDED_HEADERS_UNTRUSTED = 'forwarded_headers_untrusted';

  /**
   * Forwarded headers repeat REMOTE_ADDR and do not alter client identity.
   */
  public const STATUS_REDUNDANT_SELF_FORWARDED_CHAIN = 'redundant_self_forwarded_chain';

  /**
   * Forwarded headers are present but the sender is not on the proxy allowlist.
   */
  public const STATUS_TRUSTED_PROXY_MISMATCH = 'trusted_proxy_mismatch';

  /**
   * Forwarded headers to surface in diagnostics.
   */
  private const HEADER_MAP = [
    'x_forwarded_for' => 'X-Forwarded-For',
    'x_forwarded_host' => 'X-Forwarded-Host',
    'x_forwarded_port' => 'X-Forwarded-Port',
    'x_forwarded_proto' => 'X-Forwarded-Proto',
    'forwarded' => 'Forwarded',
  ];

  /**
   * Returns normalized trust diagnostics for the supplied request.
   */
  public function inspectRequest(Request $request): array {
    $remote_addr = (string) ($request->server->get('REMOTE_ADDR') ?? '');
    $configured_trusted_proxies = $this->normalizeProxyList(Settings::get('reverse_proxy_addresses', []));
    $runtime_trusted_proxies = $this->normalizeProxyList(Request::getTrustedProxies());
    $forwarded_headers = $this->collectForwardedHeaders($request);
    $forwarded_header_present = $this->hasForwardedHeaders($forwarded_headers);
    $forwarded_for_chain = $this->parseForwardedForChain($forwarded_headers['x_forwarded_for']);
    $effective_client_ips = $this->normalizeIpList($request->getClientIps());
    $effective_client_ip = (string) ($request->getClientIp() ?? '');
    if ($effective_client_ip === '') {
      $effective_client_ip = $remote_addr;
    }
    if ($effective_client_ips === [] && $effective_client_ip !== '') {
      $effective_client_ips = [$effective_client_ip];
    }

    $reverse_proxy_enabled = (bool) Settings::get('reverse_proxy', FALSE);
    $configured_header_set = Settings::get('reverse_proxy_trusted_headers', NULL);
    $remote_addr_is_configured_proxy = $this->ipMatchesAny($remote_addr, $configured_trusted_proxies);
    $remote_addr_is_runtime_trusted_proxy = $this->ipMatchesAny($remote_addr, $runtime_trusted_proxies);
    $redundant_self_forwarded_chain = $this->isRedundantSelfForwardedChain(
      $remote_addr,
      $effective_client_ip,
      $effective_client_ips,
      $forwarded_for_chain,
    );

    $status = self::STATUS_DIRECT_REMOTE_ADDR;
    if ($forwarded_header_present) {
      if ($redundant_self_forwarded_chain) {
        $status = self::STATUS_REDUNDANT_SELF_FORWARDED_CHAIN;
      }
      elseif (!$reverse_proxy_enabled || $configured_trusted_proxies === []) {
        $status = self::STATUS_FORWARDED_HEADERS_UNTRUSTED;
      }
      elseif (!$remote_addr_is_configured_proxy) {
        $status = self::STATUS_TRUSTED_PROXY_MISMATCH;
      }
      else {
        $status = self::STATUS_TRUSTED_FORWARDED_CHAIN;
      }
    }

    return [
      'status' => $status,
      'effective_client_ip' => $effective_client_ip,
      'effective_client_ip_chain' => $effective_client_ips,
      'forwarded_for_chain' => $forwarded_for_chain,
      'remote_addr' => $remote_addr,
      'reverse_proxy_enabled' => $reverse_proxy_enabled,
      'configured_trusted_proxies' => $configured_trusted_proxies,
      'configured_trusted_headers' => $configured_header_set,
      'runtime_trusted_proxies' => $runtime_trusted_proxies,
      'runtime_trusted_header_set' => Request::getTrustedHeaderSet(),
      'forwarded_header_present' => $forwarded_header_present,
      'forwarded_headers' => $forwarded_headers,
      'remote_addr_is_configured_proxy' => $remote_addr_is_configured_proxy,
      'remote_addr_is_runtime_trusted_proxy' => $remote_addr_is_runtime_trusted_proxy,
      'redundant_self_forwarded_chain' => $redundant_self_forwarded_chain,
      'invalid_configured_proxy_entries' => $this->normalizeProxyList(Settings::get('ilas_trusted_proxy_addresses_invalid', [])),
    ];
  }

  /**
   * Collects forwarded-header values.
   */
  private function collectForwardedHeaders(Request $request): array {
    $headers = [];
    foreach (self::HEADER_MAP as $key => $header_name) {
      $value = trim((string) $request->headers->get($header_name, ''));
      $headers[$key] = $value === '' ? NULL : $value;
    }
    return $headers;
  }

  /**
   * Returns TRUE when any forwarded header is present.
   */
  private function hasForwardedHeaders(array $forwarded_headers): bool {
    foreach ($forwarded_headers as $value) {
      if ($value !== NULL && $value !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Parses the X-Forwarded-For chain for diagnostics.
   */
  private function parseForwardedForChain(?string $header): array {
    if ($header === NULL || trim($header) === '') {
      return [];
    }
    return $this->normalizeIpList(array_map('trim', explode(',', $header)));
  }

  /**
   * Normalizes lists of IP or CIDR strings.
   */
  private function normalizeProxyList(array $values): array {
    $normalized = [];
    foreach ($values as $value) {
      if (is_string($value) && $value !== '') {
        $normalized[] = $value;
      }
    }
    return array_values(array_unique($normalized));
  }

  /**
   * Normalizes plain IP lists.
   */
  private function normalizeIpList(array $values): array {
    $normalized = [];
    foreach ($values as $value) {
      if (is_string($value) && $value !== '') {
        $normalized[] = $value;
      }
    }
    return array_values(array_unique($normalized));
  }

  /**
   * Returns TRUE when forwarded IPs only repeat REMOTE_ADDR.
   */
  private function isRedundantSelfForwardedChain(string $remote_addr, string $effective_client_ip, array $effective_client_ips, array $forwarded_for_chain): bool {
    if ($remote_addr === '' || $forwarded_for_chain === [] || $effective_client_ip !== $remote_addr) {
      return FALSE;
    }

    foreach ($forwarded_for_chain as $forwarded_ip) {
      if (!is_string($forwarded_ip) || $forwarded_ip === '' || $forwarded_ip !== $remote_addr) {
        return FALSE;
      }
    }

    foreach ($effective_client_ips as $effective_ip) {
      if (!is_string($effective_ip) || $effective_ip === '' || $effective_ip !== $remote_addr) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns TRUE when the candidate IP matches any configured proxy entry.
   */
  private function ipMatchesAny(string $candidate, array $trusted_proxies): bool {
    if ($candidate === '') {
      return FALSE;
    }

    foreach ($trusted_proxies as $trusted_proxy) {
      if (!is_string($trusted_proxy) || $trusted_proxy === '') {
        continue;
      }
      if (IpUtils::checkIp($candidate, $trusted_proxy)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
