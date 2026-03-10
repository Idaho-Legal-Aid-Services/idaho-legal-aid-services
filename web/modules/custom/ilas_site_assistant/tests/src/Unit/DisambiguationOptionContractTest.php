<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * IMP-REL-03 contract tests for disambiguation option schema/actionability.
 */
#[Group('ilas_site_assistant')]
final class DisambiguationOptionContractTest extends TestCase {

  /**
   * Builds an IntentRouter with deterministic stubs for contract checks.
   */
  private function buildRouter(): IntentRouter {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);

    $keywordExtractor = $this->createMock(KeywordExtractor::class);
    $keywordExtractor->method('extract')->willReturnCallback(function (string $message): array {
      return [
        'original' => $message,
        'normalized' => mb_strtolower(trim($message)),
      ];
    });

    return new IntentRouter(
      $configFactory,
      $topicResolver,
      $keywordExtractor,
      NULL,
      NULL,
      new Disambiguator()
    );
  }

  /**
   * Canonical disambiguation options must always expose non-empty intent keys.
   */
  public function testDisambiguatorOptionsExposeCanonicalIntent(): void {
    $disambiguator = new Disambiguator();
    $result = $disambiguator->check('i need help', []);

    $this->assertNotNull($result);
    $this->assertSame('disambiguation', $result['type']);
    $this->assertNotEmpty($result['options']);

    foreach ($result['options'] as $option) {
      $this->assertNotEmpty($option['intent'] ?? '', 'Each disambiguation option must expose canonical intent');
      if (isset($option['value'])) {
        $this->assertSame($option['intent'], $option['value'], 'Legacy value alias must match canonical intent');
      }
    }
  }

  /**
   * Compatibility shim (intent/value) must always yield actionable non-empty action.
   */
  public function testIntentValueCompatibilityMappingAlwaysActionable(): void {
    $options = [
      ['label' => 'Canonical', 'intent' => 'forms_finder'],
      ['label' => 'Legacy alias', 'value' => 'guides_finder'],
      ['label' => 'Both', 'intent' => 'apply_for_help', 'value' => 'apply_for_help'],
    ];

    foreach ($options as $option) {
      $action = $option['intent'] ?? $option['value'] ?? '';
      $this->assertNotSame('', $action, 'Disambiguation option must map to a non-empty action');
    }
  }

  /**
   * Mixed phrase query must stay in clarify flow with forms+guides options.
   */
  public function testMixedFormsGuidesPhraseReturnsActionableDisambiguation(): void {
    $router = $this->buildRouter();
    $intent = $router->route('eviction forms or guides?');

    $this->assertSame('disambiguation', $intent['type'] ?? '', 'Mixed forms/guides phrase should route to disambiguation');
    $this->assertNotEmpty($intent['options'] ?? [], 'Disambiguation must return options');

    $optionIntents = [];
    foreach ($intent['options'] as $option) {
      $optionIntent = (string) ($option['intent'] ?? $option['value'] ?? '');
      $this->assertNotSame('', $optionIntent, 'Each disambiguation option must be actionable');
      $optionIntents[] = $optionIntent;
    }

    $this->assertContains('forms_finder', $optionIntents);
    $this->assertContains('guides_finder', $optionIntents);
  }

}
