<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\LexiconOverrides;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\ilas_site_assistant\Service\TypoCorrector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers parsing, validation and runtime merging of admin lexicon overrides.
 */
#[Group('ilas_site_assistant')]
final class LexiconOverridesTest extends TestCase {

  /**
   * Builds a read-only config factory stub serving the given overrides.
   *
   * Editable config is not supported here; the save tests build mocks.
   */
  public static function configFactoryWith(array $typos, array $aliases): ConfigFactoryInterface {
    $values = [
      'lexicon_overrides.typos' => $typos,
      'lexicon_overrides.topic_aliases' => $aliases,
      'lexicon_overrides' => ['typos' => $typos, 'topic_aliases' => $aliases],
    ];
    $config = new class($values) extends ImmutableConfig {

      /**
       * Constructs the stub.
       */
      public function __construct(protected array $stubValues) {}

      /**
       * {@inheritdoc}
       */
      public function get($key = '') {
        return $this->stubValues[$key] ?? NULL;
      }

    };
    return new class($config) implements ConfigFactoryInterface {

      /**
       * Constructs the stub.
       */
      public function __construct(protected ImmutableConfig $stubConfig) {}

      /**
       * {@inheritdoc}
       */
      public function get($name) {
        return $this->stubConfig;
      }

      /**
       * {@inheritdoc}
       */
      public function getEditable($name) {
        throw new \LogicException('Read-only config factory stub.');
      }

      /**
       * {@inheritdoc}
       */
      public function loadMultiple(array $names) {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function reset($name = NULL) {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function rename($old_name, $new_name) {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheKeys() {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function clearStaticCache() {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function listAll($prefix = '') {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function addOverride(ConfigFactoryOverrideInterface $config_factory_override) {}

    };
  }

  /**
   * Builds a config factory mock whose editable config records what is saved.
   *
   * @param array $saved
   *   Receives the saved key => value pairs by reference.
   */
  private function savingConfigFactory(array $typos, array $aliases, array &$saved): ConfigFactoryInterface {
    $readonly = self::configFactoryWith($typos, $aliases);
    $editable = $this->createMock(Config::class);
    $editable->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$saved, $editable): Config {
      $saved[$key] = $value;
      return $editable;
    });
    $editable->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(static fn(string $name) => $readonly->get($name));
    $factory->method('getEditable')->willReturn($editable);
    return $factory;
  }

  /**
   * Line parsing accepts the documented format and rejects everything else.
   */
  public function testParseLines(): void {
    $typos = LexiconOverrides::parseTypoLines("Tenet => Tenant\n# comment\n\nlandlard=>landlord\nbroken line\nsame => same\ntwo words => tenant");
    $this->assertSame([
      ['misspelling' => 'tenet', 'canonical' => 'tenant'],
      ['misspelling' => 'landlard', 'canonical' => 'landlord'],
    ], $typos['entries']);
    $this->assertSame([5, 6, 7], array_keys($typos['errors']));

    $aliases = LexiconOverrides::parseAliasLines("Entering a residence => topic_housing_landlord_tenant\nblack mold => topic_housing_landlord_tenant\nnope => apply_for_help\n=> topic_family");
    $this->assertSame([
      ['phrase' => 'entering a residence', 'topic_intent' => 'topic_housing_landlord_tenant'],
      ['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant'],
    ], $aliases['entries']);
    $this->assertSame([3, 4], array_keys($aliases['errors']));

    $this->assertSame("tenet => tenant\nlandlard => landlord", LexiconOverrides::formatTypoLines($typos['entries']));
    $this->assertSame("entering a residence => topic_housing_landlord_tenant\nblack mold => topic_housing_landlord_tenant", LexiconOverrides::formatAliasLines($aliases['entries']));
  }

  /**
   * Validation uses the real vocabulary and pack.
   */
  public function testValidationAgainstVocabularyAndPack(): void {
    $service = new LexiconOverrides(
      self::configFactoryWith([], []),
      $this->createStub(CacheBackendInterface::class),
      new TypoCorrector(NULL, new TopicRouter(NULL)),
      new TopIntentsPack(NULL),
    );

    $this->assertSame([], $service->validateTypos([['misspelling' => 'tenet', 'canonical' => 'tenant']]));
    $errors = $service->validateTypos([
      ['misspelling' => 'foo', 'canonical' => 'notaword'],
      ['misspelling' => 'tenant', 'canonical' => 'landlord'],
    ]);
    $this->assertCount(2, $errors);

    $this->assertSame([], $service->validateAliases([['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant']]));
    $this->assertCount(1, $service->validateAliases([['phrase' => 'x', 'topic_intent' => 'topic_does_not_exist']]));

    $options = $service->topicIntentOptions();
    $this->assertArrayHasKey('topic_housing_landlord_tenant', $options);
    $this->assertArrayNotHasKey('apply_for_help', $options);
  }

  /**
   * Saving writes normalised config and drops the routing caches.
   */
  public function testSaveAndAddAliasInvalidateCaches(): void {
    $saved = [];
    $factory = $this->savingConfigFactory([], [['phrase' => 'existing phrase', 'topic_intent' => 'topic_family']], $saved);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->exactly(2))->method('delete')
      ->with($this->logicalOr(TypoCorrector::CACHE_KEY, TopicRouter::CACHE_KEY));

    $service = new LexiconOverrides($factory, $cache);
    $this->assertTrue($service->addTopicAlias('  Black   Mold ', 'topic_housing_landlord_tenant'));
    $this->assertSame([
      ['phrase' => 'existing phrase', 'topic_intent' => 'topic_family'],
      ['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant'],
    ], $saved['lexicon_overrides']['topic_aliases']);
    $this->assertSame([], $saved['lexicon_overrides']['typos']);
  }

  /**
   * Duplicate aliases are not re-added.
   */
  public function testAddAliasIsIdempotent(): void {
    $saved = [];
    $factory = $this->savingConfigFactory([], [['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant']], $saved);
    $service = new LexiconOverrides($factory, $this->createStub(CacheBackendInterface::class));
    $this->assertFalse($service->addTopicAlias('black mold', 'topic_housing_landlord_tenant'));
    $this->assertSame([], $saved);
  }

  /**
   * TypoCorrector and TopicRouter merge the overrides at load time.
   */
  public function testRuntimeServicesMergeOverrides(): void {
    $factory = self::configFactoryWith(
      [['misspelling' => 'landlrd', 'canonical' => 'landlord']],
      [
        ['phrase' => 'black mold', 'topic_intent' => 'topic_housing_landlord_tenant'],
        ['phrase' => 'sublessee', 'topic_intent' => 'topic_housing_landlord_tenant'],
        ['phrase' => 'unknown target', 'topic_intent' => 'topic_nowhere_x'],
      ],
    );

    $corrector = new TypoCorrector(NULL, NULL, NULL, $factory);
    $result = $corrector->correct('my landlrd is rude');
    $this->assertSame('my landlord is rude', $result['text']);
    $this->assertSame('dictionary', $result['corrections'][0]['source']);

    $router = new TopicRouter(NULL, $factory);
    $this->assertSame('housing', $router->serviceAreaForPackIntent('topic_housing_landlord_tenant'));
    $this->assertSame('civil_rights', $router->serviceAreaForPackIntent('topic_civil_rights'));
    $this->assertNull($router->serviceAreaForPackIntent('topic_nowhere_x'));

    // Multi-word override: phrase match at area level + alias resolution.
    $route = $router->route('black mold');
    $this->assertSame('housing', $route['topic']);
    $this->assertSame('phrase', $route['match_type']);
    $keys = $router->resolveTopicKeys('my unit has black mold');
    $this->assertSame('topic_housing_landlord_tenant', $keys[0]['pack_intent']);
    $this->assertSame('black mold', $keys[0]['matched_alias']);

    // Single-word override: synonym match at area level.
    $this->assertSame('synonym', $router->route('sublessee')['match_type']);

    // Overrides targeting an unknown pack intent are ignored.
    $this->assertSame([], $router->resolveTopicKeys('unknown target'));
  }

}
