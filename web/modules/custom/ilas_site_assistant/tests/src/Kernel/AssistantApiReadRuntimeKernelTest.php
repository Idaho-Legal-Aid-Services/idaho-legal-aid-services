<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Exception\RetrievalDependencyUnavailableException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime integration coverage for degraded FAQ read behavior.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiReadRuntimeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * The real FAQ service hard-fails when the required lexical index is gone.
   */
  public function testFaqServiceFailsClosedWhenRequiredIndexIsMissing(): void {
    $this->deleteFaqIndex();

    $faq_index = $this->container->get('ilas_site_assistant.faq_index');

    try {
      $faq_index->search('eviction', 5);
      $this->fail('Expected FAQ retrieval dependency failure when faq_accordion is missing.');
    }
    catch (RetrievalDependencyUnavailableException $exception) {
      $this->assertSame('faq', $exception->getService());
      $this->assertSame('faq_retrieval_unavailable', $exception->getReasonCode());
      $this->assertSame('faq_index', $exception->getContext()['dependency_key'] ?? NULL);
      $this->assertSame('index_missing', $exception->getContext()['failure_code'] ?? NULL);
    }
  }

  /**
   * Missing FAQ retrieval returns the documented unavailable response bodies.
   */
  #[DataProvider('faqUnavailableProvider')]
  public function testFaqEndpointReturns503UnavailableBodyWhenRequiredIndexIsMissing(
    string $path,
    array $expected,
  ): void {
    $this->deleteFaqIndex();

    $response = $this->controller()->faq(Request::create($path, 'GET', [], [], [], [
      'REMOTE_ADDR' => '127.0.0.1',
    ]));

    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
    $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    $this->assertNotEmpty($response->headers->get('X-Correlation-ID'));

    $body = json_decode($response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    foreach ($expected as $key => $value) {
      $this->assertSame($value, $body[$key]);
    }
    $this->assertSame('FAQ retrieval is temporarily unavailable.', $body['error']);
    $this->assertSame('unavailable', $body['type']);
    $this->assertSame('faq_retrieval_unavailable', $body['error_code']);
    $this->assertSame($body['request_id'], $response->headers->get('X-Correlation-ID'));
  }

  /**
   * Missing FAQ retrieval degrades the health endpoint via retrieval truth.
   */
  public function testHealthEndpointReturns503WhenFaqIndexIsMissing(): void {
    $this->deleteFaqIndex();

    $response = $this->controller()->health();

    $this->assertSame(503, $response->getStatusCode());

    $body = json_decode($response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('degraded', $body['status']);
    $this->assertSame('degraded', $body['checks']['retrieval_configuration']['status']);
    $this->assertSame(
      'index_missing',
      $body['checks']['retrieval_configuration']['retrieval']['faq_index']['failure_code'],
    );
  }

  /**
   * Data provider for FAQ unavailable response shapes.
   */
  public static function faqUnavailableProvider(): array {
    return [
      'query mode' => [
        '/assistant/api/faq?q=eviction',
        [
          'results' => [],
          'count' => 0,
        ],
      ],
      'id mode' => [
        '/assistant/api/faq?id=faq_55',
        [
          'faq' => NULL,
        ],
      ],
      'categories mode' => [
        '/assistant/api/faq',
        [
          'categories' => [],
        ],
      ],
    ];
  }

  /**
   * Deletes the required FAQ lexical Search API index.
   */
  private function deleteFaqIndex(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('search_api_index');
    $index = $storage->load('faq_accordion');

    $this->assertNotNull($index, 'Expected faq_accordion to exist before deletion.');

    $index->delete();
    $this->assertNull($storage->load('faq_accordion'));
  }

  /**
   * Builds the controller with real container services.
   */
  private function controller(): AssistantApiController {
    return AssistantApiController::create($this->container);
  }

}
