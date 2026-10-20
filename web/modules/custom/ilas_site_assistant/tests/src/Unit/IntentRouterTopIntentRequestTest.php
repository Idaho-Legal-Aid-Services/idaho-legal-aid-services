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
use Drupal\ilas_site_assistant\Service\TypoCorrector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Covers explicit topic requests that should bypass bare-topic clarification.
 */
#[Group('ilas_site_assistant')]
final class IntentRouterTopIntentRequestTest extends TestCase {

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
   * Multi-word help/correction messages route to the explicit topic intent.
   */
  public function testExplicitTopicRequestsRouteToTopIntent(): void {
    $router = $this->buildRouter();

    $this->assertSame('topic_family_custody', $router->route('I need custody help.')['type'] ?? NULL);
    $this->assertSame('topic_family_divorce', $router->route('Actually divorce.')['type'] ?? NULL);
    $this->assertSame('topic_housing_eviction', $router->route('I got an eviction notice.')['type'] ?? NULL);
    $this->assertSame('topic_housing_eviction', $router->route('Necesito ayuda con desalojo.')['type'] ?? NULL);
  }

  /**
   * Bare topics still ask for the user's desired action.
   */
  public function testBareTopicStillDisambiguates(): void {
    $intent = $this->buildRouter()->route('divorce');

    $this->assertSame('disambiguation', $intent['type'] ?? NULL);
    $this->assertNotEmpty($intent['options'] ?? []);
  }

  /**
   * Bare service-area words keep the area-level clarifier.
   */
  public function testBareAreaWordStillDisambiguates(): void {
    $intent = $this->buildRouter()->route('housing');

    $this->assertSame('disambiguation', $intent['type'] ?? NULL);
    $this->assertSame('housing', $intent['area'] ?? NULL);
  }

  /**
   * Short sub-topic queries answer the sub-topic instead of asking which.
   *
   * Regression for conversation 7c10da94: "Tenet rights" and "Entering a
   * residence" both fell to the generic clarify.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('subTopicCarryThroughProvider')]
  public function testSubTopicCarryThrough(string $message, string $expected_intent, string $expected_area): void {
    $intent = $this->buildRouter()->route($message);

    $this->assertSame($expected_intent, $intent['type'] ?? NULL, "Intent mismatch for '$message'");
    $this->assertSame($expected_area, $intent['area'] ?? NULL, "Area mismatch for '$message'");
    $this->assertNotEmpty($intent['pack_entry']['primary_action']['url'] ?? '', 'Pack entry must carry a landing URL');
  }

  /**
   *
   */
  public static function subTopicCarryThroughProvider(): array {
    return [
      'misspelled short query' => ['Tenet rights', 'topic_housing_landlord_tenant', 'housing'],
      'short query' => ['tenant rights', 'topic_housing_landlord_tenant', 'housing'],
      'phrase query' => ['Entering a residence', 'topic_housing_landlord_tenant', 'housing'],
      'long sentence' => ['My landlord tried to enter my home without notice', 'topic_housing_landlord_tenant', 'housing'],
      'retaliation sentence' => ['my landlord is not renewing my lease because i complained', 'topic_housing_landlord_tenant', 'housing'],
      'eviction wins over landlord' => ['My landlord gave me an eviction notice', 'topic_housing_eviction', 'housing'],
      'misspelled eviction' => ['evicton notice', 'topic_housing_eviction', 'housing'],
      'family sub-topic' => ['i want a divorce', 'topic_family_divorce', 'family'],
    ];
  }

  /**
   * A lone sub-topic without its own pack answer gets the area answer.
   *
   * "security deposits" resolves to housing_security_deposit, which has no
   * pack entry. It used to fall to "I can help with Housing issues. What
   * would you like to do?" — a route that also skipped the FAQ search, so
   * the site's Security Deposits content was never offered. Now the area
   * pack answer is returned with the user's own term echoed so retrieve-first
   * searches for it.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('subTopicWithoutPackProvider')]
  public function testLoneSubTopicWithoutPackAnswersWithArea(string $message, string $expected_intent, string $expected_area, string $expected_term, string $expected_sub_topic): void {
    $intent = $this->buildRouter()->route($message);

    $this->assertSame($expected_intent, $intent['type'] ?? NULL, "Intent mismatch for '$message'");
    $this->assertSame($expected_area, $intent['area'] ?? NULL, "Area mismatch for '$message'");
    $this->assertSame($expected_term, $intent['matched_synonym'] ?? NULL, 'The user\'s own term is echoed for retrieval');
    $this->assertSame($expected_sub_topic, $intent['sub_topic_key'] ?? NULL);
    $this->assertSame('topic_router_subtopic_area_fallback', $intent['source'] ?? NULL);
    $this->assertNotEmpty($intent['pack_entry']['primary_action']['url'] ?? '', 'Area pack entry must carry a landing URL');
  }

  /**
   *
   */
  public static function subTopicWithoutPackProvider(): array {
    return [
      // The matched alias (singular, word-bounded inside the plural) is what
      // gets echoed; it is still the user's phrase, not invented copy.
      'plural' => ['security deposits', 'topic_housing', 'housing', 'security deposit', 'housing_security_deposit'],
      'singular alias' => ['security deposit', 'topic_housing', 'housing', 'security deposit', 'housing_security_deposit'],
      'three words with stop word' => ['power of attorney', 'topic_seniors', 'seniors', 'power of attorney', 'seniors_power_of_attorney'],
      'consumer sub-topic' => ['wage garnishment', 'topic_consumer', 'consumer', 'wage garnishment', 'consumer_garnishment'],
      'new alias' => ['mobile home', 'topic_housing', 'housing', 'mobile home', 'housing_mobile_home'],
    ];
  }

  /**
   * The area fallback must not swallow the cases that still need a question.
   *
   * Watched generic aliases (bias, credit, overtime, paycheck, move out,
   * separated, let go, laid off, insurance, voting, welfare, fmla) DO take
   * the area fallback now; a wrong-area report on one of them is the signal
   * to add a per-alias exclusion here.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('stillClarifiesProvider')]
  public function testBareWordsStillClarify(string $message, string $expected_type): void {
    $intent = $this->buildRouter()->route($message);

    $this->assertSame($expected_type, $intent['type'] ?? NULL, "'$message' must still ask a question");
  }

  /**
   *
   */
  public static function stillClarifiesProvider(): array {
    return [
      // Bare area word: resolveTopicKeys() finds no sub-topic.
      'area word' => ['housing', 'disambiguation'],
      // Disambiguator topic_lexicon triggers intercept before step 5b.
      'lexicon trigger divorce' => ['divorce', 'disambiguation'],
      'lexicon trigger medicaid' => ['medicaid', 'disambiguation'],
      'lexicon trigger guardianship' => ['guardianship', 'disambiguation'],
      // A generic area token that is not itself a sub-topic alias.
      'generic token' => ['deposit', 'disambiguation'],
    ];
  }

  /**
   * Admin topic-alias overrides route long sentences with no regex hit.
   */
  public function testLexiconOverrideAliasRoutesWithoutAreaRegex(): void {
    $factory = LexiconOverridesTest::configFactoryWith([], [
      ['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant'],
    ]);
    $router = $this->buildRouter(new TopicRouter(NULL, $factory));

    $intent = $router->route('there is black mold growing in our unit and nobody fixes it');
    $this->assertSame('topic_housing_landlord_tenant', $intent['type'] ?? NULL);
    $this->assertSame('topic_alias_subtopic', $intent['source'] ?? NULL);
    $this->assertSame('housing', $intent['area'] ?? NULL);

    // Without the override the same sentence stays unknown.
    $this->assertSame('unknown', $this->buildRouter()->route('there is black mold growing in our unit and nobody fixes it')['type'] ?? NULL);
  }

  /**
   * The corrected message is what reaches the short-message matchers.
   */
  public function testTypoCorrectionReachesTopicRouter(): void {
    $intent = $this->buildRouter()->route('lanldord problems');

    $this->assertSame('topic_housing_landlord_tenant', $intent['type'] ?? NULL);
    $this->assertSame('lanldord', $intent['extraction']['typos_corrected'][0]['original'] ?? NULL);
  }

  /**
   * Resource-qualified topic requests keep resource routing precedence.
   */
  public function testResourceQualifiedTopicDoesNotShortCircuitToTopIntent(): void {
    $intent = $this->buildRouter()->route('custody forms');

    $this->assertNotSame('topic_family_custody', $intent['type'] ?? NULL);
  }

  /**
   * Builds a router with deterministic collaborators.
   */
  private function buildRouter(?TopicRouter $topicRouterOverride = NULL): IntentRouter {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);

    $topicRouter = $topicRouterOverride ?? new TopicRouter(NULL);
    $typoCorrector = new TypoCorrector(NULL, $topicRouter);
    $keywordExtractor = $this->createMock(KeywordExtractor::class);
    $keywordExtractor->method('extract')->willReturnCallback(static function (string $message) use ($typoCorrector): array {
      $typo = $typoCorrector->correct(mb_strtolower(trim($message)));
      return [
        'original' => $message,
        'normalized' => $typo['text'],
        'keywords' => [],
        'phrases_found' => [],
        'synonyms_applied' => [],
        'typos_corrected' => $typo['corrections'],
      ];
    });
    $keywordExtractor->method('hasNegativeKeyword')->willReturn(FALSE);

    $navigationIntent = $this->createMock(NavigationIntent::class);
    $navigationIntent->method('detect')->willReturn(NULL);

    return new IntentRouter(
      $configFactory,
      $topicResolver,
      $keywordExtractor,
      $topicRouter,
      $navigationIntent,
      new Disambiguator(),
      new TopIntentsPack(NULL),
    );
  }

}
