<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/controller_test_bootstrap.php';

/**
 * Covers the forms/guides finder branches that log after finding nothing.
 *
 * Both branches called ObservabilityPayloadMinimizer methods that do not
 * exist, so reaching either one threw an Error and the user got the
 * internal-error fallback instead of the "browse all" answer.
 */
#[Group('ilas_site_assistant')]
final class FinderExhaustedLogPathTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($markup) => strtr($markup->getUntranslatedString(), $markup->getArguments())
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Finder types exercised by each branch.
   */
  public static function finderTypes(): array {
    return [
      'forms' => ['forms', 'form'],
      'guides' => ['guides', 'guide'],
    ];
  }

  /**
   * A scoped search that finds nothing answers and logs a hash, not text.
   */
  #[DataProvider('finderTypes')]
  public function testScopedSearchExhaustedAnswersAndLogsMetadataOnly(string $finder_type, string $singular): void {
    $logger = new FinderLogRecorder();
    $controller = $this->buildController($logger);
    $query = 'eviction ' . $finder_type . ' for Jane Doe';

    $response = $this->invokeFinder($controller, $finder_type, $query, ['topic' => 'eviction'], TRUE);

    $this->assertSame('resources', $response['type']);
    $this->assertSame([], $response['results']);
    $this->assertStringContainsString('browse all ' . $finder_type, (string) $response['message']);

    $record = $logger->find('Scoped ' . $singular . ' search exhausted');
    $this->assertNotNull($record, 'the exhausted-scope branch logs once it has answered');
    $this->assertSame(
      ObservabilityPayloadMinimizer::buildTextMetadata($query)['text_hash'],
      $record['context']['@query_hash'],
    );
    $this->assertSame('eviction', $record['context']['@scope']);
    $this->assertStringNotContainsString('Jane', json_encode($record));
  }

  /**
   * A selection recovery that finds nothing answers and logs a hash and bucket.
   */
  #[DataProvider('finderTypes')]
  public function testSelectionRecoveryExhaustedAnswersAndLogsMetadataOnly(string $finder_type, string $singular): void {
    $logger = new FinderLogRecorder();
    $controller = $this->buildController($logger);
    $query = 'Eviction paperwork for Jane Doe';
    $metadata = ObservabilityPayloadMinimizer::buildTextMetadata($query);

    $response = $this->invokeFinder($controller, $finder_type, $query, [], FALSE);

    $this->assertSame('resources', $response['type']);
    $this->assertStringContainsString("couldn't find exact " . $singular . ' matches', (string) $response['message']);

    $record = $logger->find('Structured selection recovery exhausted');
    $this->assertNotNull($record, 'the recovery branch logs once it has answered');
    $this->assertSame($metadata['text_hash'], $record['context']['@query_hash']);
    $this->assertSame($metadata['length_bucket'], $record['context']['@query_length_bucket']);
    $this->assertSame($finder_type, $record['context']['@resource']);
    $this->assertSame('none', $record['context']['@area']);
    $this->assertStringNotContainsString('Jane', json_encode($record));
  }

  /**
   * Builds a controller whose resource searches all come back empty.
   */
  private function buildController(FinderLogRecorder $logger): AssistantApiController {
    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findForms')->willReturn([]);
    $resourceFinder->method('findGuides')->willReturn([]);
    $resourceFinder->method('findResources')->willReturn([]);

    $cache = $this->createStub(CacheBackendInterface::class);

    return new AssistantApiController(
      $this->createStub(ConfigFactoryInterface::class),
      $this->createStub(IntentRouter::class),
      $this->createStub(FaqIndex::class),
      $resourceFinder,
      $this->createStub(PolicyFilter::class),
      $this->createStub(AnalyticsLogger::class),
      $this->createStub(LlmEnhancer::class),
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $cache,
      $logger,
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
    );
  }

  /**
   * Invokes the private buildFinderResponse() method.
   *
   * @param array<string, mixed> $intent
   *   Intent record; `topic` and `area` drive the scoped fallback searches.
   *
   * @return array<string, mixed>
   *   The finder response.
   */
  private function invokeFinder(AssistantApiController $controller, string $finder_type, string $query, array $intent, bool $allow_clarify): array {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'enable_resources' ? TRUE : NULL
    );

    $method = new \ReflectionMethod(AssistantApiController::class, 'buildFinderResponse');
    $method->setAccessible(TRUE);

    return $method->invoke(
      $controller,
      $finder_type,
      $query,
      $intent,
      'test-req-id',
      ilas_site_assistant_get_canonical_urls(),
      $config,
      $allow_clarify,
    );
  }

}

/**
 * Logger double that keeps every record.
 */
final class FinderLogRecorder extends AbstractLogger {

  /**
   * Recorded log calls.
   *
   * @var array<int, array{level: mixed, message: string, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
  }

  /**
   * Returns the first record whose message contains the given text.
   */
  public function find(string $needle): ?array {
    foreach ($this->records as $record) {
      if (str_contains($record['message'], $needle)) {
        return $record;
      }
    }
    return NULL;
  }

}
