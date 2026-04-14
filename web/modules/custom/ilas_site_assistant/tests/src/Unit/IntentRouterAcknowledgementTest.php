<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\ilas_site_assistant\Service\NavigationIntent;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Covers gratitude / closure routing regressions.
 */
#[Group('ilas_site_assistant')]
final class IntentRouterAcknowledgementTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $configStub = $this->createStub(ImmutableConfig::class);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translationStub);
    $container->set('config.factory', $configFactory);

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
   * Common gratitude typos should route to the thanks intent.
   */
  public function testThankYoiuRoutesToThanks(): void {
    $intent = $this->buildRouter()->route('Thank yoiu');

    $this->assertSame('thanks', $intent['type'] ?? NULL);
  }

  /**
   * Punctuation-only gratitude should still short-circuit to thanks.
   */
  public function testThanksWithPunctuationRoutesToThanks(): void {
    $intent = $this->buildRouter()->route('thanks.');

    $this->assertSame('thanks', $intent['type'] ?? NULL);
  }

  /**
   * Builds a router with minimal deterministic stubs.
   */
  private function buildRouter(): IntentRouter {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);

    $keywordExtractor = $this->createMock(KeywordExtractor::class);
    $keywordExtractor->method('extract')->willReturnCallback(static function (string $message): array {
      return [
        'original' => $message,
        'normalized' => mb_strtolower(trim($message)),
        'keywords' => [],
        'phrases_found' => [],
        'synonyms_applied' => [],
      ];
    });
    $keywordExtractor->method('hasNegativeKeyword')->willReturn(FALSE);

    $topicRouter = $this->createMock(TopicRouter::class);
    $topicRouter->method('route')->willReturn(NULL);

    $navigationIntent = $this->createMock(NavigationIntent::class);
    $navigationIntent->method('detect')->willReturn(NULL);

    $disambiguator = $this->createMock(Disambiguator::class);
    $disambiguator->method('check')->willReturn(NULL);

    return new IntentRouter(
      $configFactory,
      $topicResolver,
      $keywordExtractor,
      $topicRouter,
      $navigationIntent,
      $disambiguator,
      new TopIntentsPack(NULL),
    );
  }

}
