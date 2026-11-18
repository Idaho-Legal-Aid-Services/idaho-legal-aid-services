<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Http\VendorDeprecationMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests logging of vendor deprecation headers.
 */
#[Group('ilas_site_assistant')]
final class VendorDeprecationMiddlewareTest extends TestCase {

  /**
   * State values written during a test.
   *
   * @var array<string, mixed>
   */
  private array $stateStore = [];

  /**
   * The current time reported to the middleware.
   */
  private int $now = 1795000000;

  /**
   * Builds a Guzzle client whose stack includes the middleware.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   Queued responses.
   */
  private function buildClient(LoggerInterface $logger, array $responses, ?StateInterface $state = NULL): Client {
    if ($state === NULL) {
      $state = $this->createStub(StateInterface::class);
      $state->method('get')->willReturnCallback(fn($key, $default = NULL) => $this->stateStore[$key] ?? $default);
      $state->method('set')->willReturnCallback(function ($key, $value): void {
        $this->stateStore[$key] = $value;
      });
    }
    $time = $this->createStub(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);

    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push((new VendorDeprecationMiddleware($logger, $state, $time))(), 'vendor_deprecation');

    return new Client(['handler' => $stack]);
  }

  /**
   * A Sunset header from a vendor is logged once, then quiet for a day.
   */
  public function testDeprecationHeadersAreLoggedOncePerDayPerEndpoint(): void {
    $logged = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))
      ->method('warning')
      ->willReturnCallback(function (string $message, array $context) use (&$logged): void {
        $logged[] = [$message, $context];
      });

    $deprecated = fn(): Response => new Response(207, [
      'Deprecation' => '@1788220800',
      'Sunset' => 'Mon, 16 Nov 2026 00:00:00 GMT',
      'Link' => '<https://langfuse.com/docs/migration>; rel="sunset"',
    ], '{}');
    $client = $this->buildClient($logger, [$deprecated(), $deprecated(), $deprecated()]);
    $url = 'https://us.cloud.langfuse.com/api/public/ingestion';

    $client->post($url);
    $client->post($url);
    $this->assertCount(1, $logged);
    $this->assertStringContainsString('us.cloud.langfuse.com', $logged[0][0]);
    $this->assertSame('/api/public/ingestion', $logged[0][1]['@path']);
    $this->assertStringContainsString('Sunset: Mon, 16 Nov 2026', $logged[0][1]['@notices']);
    $this->assertStringContainsString('Deprecation: @1788220800', $logged[0][1]['@notices']);
    $this->assertStringContainsString('rel="sunset"', $logged[0][1]['@link']);

    $this->now += 86401;
    $client->post($url);
    $this->assertCount(2, $logged);
  }

  /**
   * Ordinary vendor responses and non-vendor hosts log nothing.
   */
  public function testNothingIsLoggedWithoutAHeaderOrForOtherHosts(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $client = $this->buildClient($logger, [
      new Response(200, [], '{}'),
      new Response(200, ['Sunset' => 'Mon, 16 Nov 2026 00:00:00 GMT'], '{}'),
    ]);

    $client->post('https://api.cohere.com/v2/chat');
    $client->get('https://updates.drupal.org/release-history');

    $this->assertSame([], $this->stateStore);
  }

  /**
   * A failure while recording the notice never breaks the watched request.
   */
  public function testFailureInsideTheMiddlewareDoesNotBreakTheRequest(): void {
    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willThrowException(new \RuntimeException('state unavailable'));
    $logger = $this->createStub(LoggerInterface::class);

    $client = $this->buildClient($logger, [new Response(200, ['Sunset' => 'soon'], 'body')], $state);
    $response = $client->get('https://api.voyageai.com/v1/rerank');

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('body', (string) $response->getBody());
  }

  /**
   * Host matching is by suffix on a label boundary.
   */
  public function testVendorHostMatching(): void {
    $this->assertTrue(VendorDeprecationMiddleware::isVendorHost('us.cloud.langfuse.com'));
    $this->assertTrue(VendorDeprecationMiddleware::isVendorHost('api.cohere.com'));
    $this->assertTrue(VendorDeprecationMiddleware::isVendorHost('API.VOYAGEAI.COM'));
    $this->assertTrue(VendorDeprecationMiddleware::isVendorHost('index-abc.svc.aped-1.pinecone.io'));
    $this->assertFalse(VendorDeprecationMiddleware::isVendorHost('notlangfuse.com'));
    $this->assertFalse(VendorDeprecationMiddleware::isVendorHost('langfuse.com.evil.example'));
    $this->assertFalse(VendorDeprecationMiddleware::isVendorHost('idaholegalaid.org'));
  }

}
