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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Spanish office questions must score offices_contact, not fall to unknown.
 *
 * Regression coverage for the weekly hosted eval case
 * "[edge-spanish-full] donde estan sus oficinas": the plural, "están" and
 * "sus" forms matched no office pattern, the router returned unknown, and
 * the follow-up carryover answered a location question with housing
 * resources. NavigationIntent is stubbed to NULL here so the scoring path
 * (patterns + keywords) is what is exercised.
 */
#[Group('ilas_site_assistant')]
final class IntentRouterSpanishOfficesTest extends TestCase {

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
   * Plural, accented, and "cuál es" office questions route to offices_contact.
   */
  #[DataProvider('spanishOfficeQuestions')]
  public function testSpanishOfficeQuestionsRouteToOfficesContact(string $message): void {
    $intent = $this->buildRouter()->route($message);

    $this->assertSame('offices_contact', $intent['type'] ?? NULL, "Failed for: $message");
  }

  /**
   * Spanish office phrasings from the hosted eval and their variants.
   */
  public static function spanishOfficeQuestions(): array {
    return [
      'plural sus' => ['donde estan sus oficinas'],
      'plural accented' => ['dónde están las oficinas'],
      'address of office' => ['cual es la direccion de la oficina'],
      'queda singular' => ['donde queda su oficina'],
      'accented singular' => ['¿Dónde está la oficina?'],
      'hours plural' => ['horarios de la oficina'],
    ];
  }

  /**
   * Bare-city replies and topic requests must not become office searches.
   */
  #[DataProvider('nonOfficeMessages')]
  public function testNonOfficeMessagesDoNotRouteToOfficesContact(string $message): void {
    $intent = $this->buildRouter()->route($message);

    $this->assertNotSame('offices_contact', $intent['type'] ?? NULL, "Unexpected office route for: $message");
  }

  /**
   * Messages that share tokens but are not office questions.
   */
  public static function nonOfficeMessages(): array {
    return [
      'bare city sentence' => ['This is in Boise.'],
      'bare city' => ['Pocatello.'],
      'spanish topic' => ['Necesito ayuda con un desalojo'],
    ];
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
