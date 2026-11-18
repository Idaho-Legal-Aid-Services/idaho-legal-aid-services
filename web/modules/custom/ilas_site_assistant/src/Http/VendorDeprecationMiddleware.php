<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Http;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs deprecation notices that outside APIs attach to their responses.
 *
 * Providers that retire an endpoint commonly announce it for months in the
 * Deprecation and Sunset response headers (RFC 9745, RFC 8594) before the
 * endpoint stops working. Nothing read them here, so a planned removal was
 * indistinguishable from a sudden outage. This logs a warning, which reaches
 * Sentry, the first time each day a vendor host sends one.
 *
 * It only observes responses: it never changes a request or a response, and
 * a failure inside it is swallowed so it cannot break the call it watches.
 */
final class VendorDeprecationMiddleware {

  /**
   * Hosts whose responses are inspected (suffix match).
   */
  private const VENDOR_HOSTS = [
    'langfuse.com',
    'cohere.com',
    'cohere.ai',
    'voyageai.com',
    'pinecone.io',
  ];

  private const HEADERS = ['Deprecation', 'Sunset'];

  private const STATE_PREFIX = 'ilas_site_assistant.vendor_deprecation.';

  private const QUIET_SECONDS = 86400;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the Guzzle middleware.
   */
  public function __invoke(): callable {
    return function (callable $handler): callable {
      return function (RequestInterface $request, array $options) use ($handler) {
        $promise = $handler($request, $options);
        if (!$promise instanceof PromiseInterface || !self::isVendorHost($request->getUri()->getHost())) {
          return $promise;
        }

        return $promise->then(function ($response) use ($request) {
          if ($response instanceof ResponseInterface) {
            $this->inspect($request, $response);
          }
          return $response;
        });
      };
    };
  }

  /**
   * Returns TRUE for a host belonging to one of the watched vendors.
   */
  public static function isVendorHost(string $host): bool {
    $host = strtolower($host);
    foreach (self::VENDOR_HOSTS as $vendor) {
      if ($host === $vendor || str_ends_with($host, '.' . $vendor)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Logs the response's deprecation headers, at most once a day per endpoint.
   */
  private function inspect(RequestInterface $request, ResponseInterface $response): void {
    try {
      $notices = [];
      foreach (self::HEADERS as $header) {
        if ($response->hasHeader($header)) {
          $notices[] = $header . ': ' . self::clean($response->getHeaderLine($header));
        }
      }
      if ($notices === []) {
        return;
      }

      $host = strtolower($request->getUri()->getHost());
      $path = self::clean($request->getUri()->getPath());
      $key = self::STATE_PREFIX . hash('sha256', $host . '|' . $path);
      $now = $this->time->getCurrentTime();
      if (($now - (int) $this->state->get($key, 0)) < self::QUIET_SECONDS) {
        return;
      }
      $this->state->set($key, $now);

      $link = $response->hasHeader('Link') ? self::clean($response->getHeaderLine('Link')) : 'none';
      $this->logger->warning('Vendor API deprecation notice from ' . $host . ' for @method @path: @notices (Link: @link). Plan the migration before the endpoint is removed.', [
        '@method' => $request->getMethod(),
        '@path' => $path,
        '@notices' => implode('; ', $notices),
        '@link' => $link,
      ]);
    }
    catch (\Throwable $e) {
      // Observation only: never let this affect the request it is watching.
    }
  }

  /**
   * Makes a provider-supplied string safe and short enough to log.
   */
  private static function clean(string $value): string {
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

    return substr($value, 0, 200);
  }

}
