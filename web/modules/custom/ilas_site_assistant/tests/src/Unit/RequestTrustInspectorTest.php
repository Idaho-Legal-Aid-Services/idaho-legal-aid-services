<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RequestTrustInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers reverse-proxy trust inspection for flood identity decisions.
 */
#[Group('ilas_site_assistant')]
final class RequestTrustInspectorTest extends TestCase {

  /**
   * Trusted forwarded-header bitmask used by the settings contract.
   */
  private const TRUSTED_HEADERS =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_FORWARDED;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Forwarded headers stay untrusted until an explicit proxy allowlist exists.
   */
  public function testForwardedHeadersRemainUntrustedWithoutProxyConfig(): void {
    new Settings([]);
    Request::setTrustedProxies([], self::TRUSTED_HEADERS);

    $request = Request::create('https://www.example.com/assistant/api/message', 'POST', [], [], [], [
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.10',
    ]);

    $result = (new RequestTrustInspector())->inspectRequest($request);

    $this->assertSame(RequestTrustInspector::STATUS_FORWARDED_HEADERS_UNTRUSTED, $result['status']);
    $this->assertSame('10.0.0.10', $result['effective_client_ip']);
    $this->assertSame('10.0.0.10', $result['remote_addr']);
    $this->assertSame([], $result['configured_trusted_proxies']);
  }

  /**
   * Trusted proxies resolve the effective client IP from the forwarded chain.
   */
  public function testTrustedProxyConfigResolvesForwardedClientIp(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $request = Request::create('https://www.example.com/assistant/api/message', 'POST', [], [], [], [
      'REMOTE_ADDR' => '10.0.0.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.10',
    ]);

    $result = (new RequestTrustInspector())->inspectRequest($request);

    $this->assertSame(RequestTrustInspector::STATUS_TRUSTED_FORWARDED_CHAIN, $result['status']);
    $this->assertSame('198.51.100.7', $result['effective_client_ip']);
    $this->assertSame(['198.51.100.7'], $result['effective_client_ip_chain']);
    $this->assertSame(['198.51.100.7', '10.0.0.10'], $result['forwarded_for_chain']);
  }

  /**
   * Requests from unlisted proxies must ignore forwarded identity headers.
   */
  public function testUnlistedProxyReturnsTrustedProxyMismatch(): void {
    new Settings([
      'reverse_proxy' => TRUE,
      'reverse_proxy_addresses' => ['10.0.0.10'],
      'reverse_proxy_trusted_headers' => self::TRUSTED_HEADERS,
    ]);
    Request::setTrustedProxies(['10.0.0.10'], self::TRUSTED_HEADERS);

    $request = Request::create('https://www.example.com/assistant/api/message', 'POST', [], [], [], [
      'REMOTE_ADDR' => '10.0.0.11',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.11',
    ]);

    $result = (new RequestTrustInspector())->inspectRequest($request);

    $this->assertSame(RequestTrustInspector::STATUS_TRUSTED_PROXY_MISMATCH, $result['status']);
    $this->assertSame('10.0.0.11', $result['effective_client_ip']);
    $this->assertFalse($result['remote_addr_is_configured_proxy']);
  }

}
