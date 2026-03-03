<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the UI troubleshooting detection in IntentRouter.
 */
#[Group('ilas_site_assistant')]
class UiTroubleshootingTest extends TestCase {

  /**
   * The IntentRouter instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\IntentRouter
   */
  protected $router;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    // Create mock config factory.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    // Create mock TopicResolver.
    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);

    // Create mock KeywordExtractor.
    $keywordExtractor = $this->createMock(KeywordExtractor::class);
    $keywordExtractor->method('extract')->willReturnCallback(function (string $message): array {
      return [
        'original' => $message,
        'normalized' => mb_strtolower(trim($message)),
        'high_risk' => NULL,
        'out_of_scope' => FALSE,
      ];
    });

    $this->router = new IntentRouter($configFactory, $topicResolver, $keywordExtractor);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests that UI complaint messages are detected.
   */
  #[DataProvider('uiComplaintProvider')]
  public function testUiComplaintDetected(string $input): void {
    $result = $this->router->route($input);
    $this->assertNotNull($result, "Expected result for '$input'");
    $this->assertEquals('ui_troubleshooting', $result['type'],
      "Expected ui_troubleshooting for '$input', got '{$result['type']}'");
    $this->assertGreaterThanOrEqual(0.85, $result['confidence']);
  }

  public static function uiComplaintProvider(): array {
    return [
      // English negation + display verb.
      ["the categories aren't showing up"],
      ["buttons aren't showing"],
      ["options aren't loading"],
      ["the links don't show"],
      ["nothing is showing"],
      ["it isn't displaying"],
      ["the options can't load"],
      ["chips dont show"],
      // English UI element + missing/gone.
      ["buttons are missing"],
      ["the options disappeared"],
      ["categories gone"],
      ["menu is broken"],
      // English can't see + UI element.
      ["I can't see any options"],
      ["I can't see the buttons"],
      ["I don't see any categories"],
      ["I cant see the choices"],
      // English nothing + verb.
      ["nothing happens when I click"],
      ["nothing showed up"],
      // Spanish.
      ["no se muestra nada"],
      ["no aparece nada"],
      ["no funciona"],
      ["no carga la pagina"],
      ["no se ven los botones"],
    ];
  }

  /**
   * Tests that normal legal queries are NOT detected as UI complaints.
   */
  #[DataProvider('nonUiComplaintProvider')]
  public function testNonUiComplaintNotDetected(string $input): void {
    $result = $this->router->route($input);
    if ($result) {
      $this->assertNotEquals('ui_troubleshooting', $result['type'],
        "Should NOT detect '$input' as ui_troubleshooting");
    }
  }

  public static function nonUiComplaintProvider(): array {
    return [
      ['I need help with custody'],
      ['eviction forms'],
      ['how do I apply'],
      ['where is the Boise office'],
      ['divorce'],
      ['hello'],
    ];
  }

}
