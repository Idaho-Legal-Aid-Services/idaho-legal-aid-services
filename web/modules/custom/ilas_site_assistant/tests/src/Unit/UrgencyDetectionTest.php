<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\InputNormalizer;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Locks engine-based deadline urgency detection.
 */
#[Group('ilas_site_assistant')]
final class UrgencyDetectionTest extends TestCase {

  /**
   * The authoritative pre-routing decision engine.
   */
  private PreRoutingDecisionEngine $engine;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn([]);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    $policyFilter = new PolicyFilter($configFactory);
    $policyFilter->setStringTranslation($translation);

    $this->engine = new PreRoutingDecisionEngine(
      $policyFilter,
      new SafetyClassifier($configFactory),
      new OutOfScopeClassifier($configFactory),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Golden deadline utterances must emit the deadline override contract.
   */
  #[DataProvider('deadlineOverrideProvider')]
  public function testDeadlineSignalsEmitAuthoritativeOverride(string $message): void {
    $decision = $this->engine->evaluate(InputNormalizer::normalize($message));

    $this->assertSame(PreRoutingDecisionEngine::DECISION_CONTINUE, $decision['decision_type']);
    $this->assertContains('deadline_pressure', $decision['urgency_signals']);
    $this->assertSame('urgency', $decision['winner_source']);
    $this->assertSame('high_risk_deadline', $decision['routing_override_intent']['risk_category'] ?? NULL);
  }

  /**
   * Deadline goldens that previously depended on router substring triggers.
   */
  public static function deadlineOverrideProvider(): array {
    return [
      'lawsuit deadline friday' => ['deadline to respond to lawsuit is friday'],
      'file paperwork monday' => ['have to file paperwork by monday'],
      'spanglish court date' => ['tengo una corte date manana'],
    ];
  }

  /**
   * Informational deadline queries must not emit urgency overrides.
   */
  #[DataProvider('deadlineDampenerProvider')]
  public function testInformationalQueriesAreDampened(string $message): void {
    $decision = $this->engine->evaluate(InputNormalizer::normalize($message));

    $this->assertNotContains('deadline_pressure', $decision['urgency_signals']);
    $this->assertNull($decision['routing_override_intent']);
    $this->assertSame(PreRoutingDecisionEngine::DECISION_CONTINUE, $decision['decision_type']);
  }

  /**
   * Deadline dampeners that must remain stable.
   */
  public static function deadlineDampenerProvider(): array {
    return [
      'how long question' => ['how long do i have to respond to an eviction'],
      'what is deadline' => ['what is the deadline for filing an answer'],
      'general info' => ['what is typical deadline for responding to a lawsuit'],
      'spanish info' => ['cuanto tiempo tengo para responder'],
      'learn about deadlines' => ['general information about court dates'],
    ];
  }

  /**
   * Eviction emergencies must remain safety exits, not deadline overrides.
   */
  #[DataProvider('evictionSafetyProvider')]
  public function testEvictionEmergenciesStaySafetyExits(string $message): void {
    $decision = $this->engine->evaluate(InputNormalizer::normalize($message));

    $this->assertSame(PreRoutingDecisionEngine::DECISION_SAFETY_EXIT, $decision['decision_type']);
    $this->assertSame(SafetyClassifier::CLASS_EVICTION_EMERGENCY, $decision['safety']['class']);
    $this->assertNull($decision['routing_override_intent']);
  }

  /**
   * Eviction-adjacent deadline language that must stay hard-stop.
   */
  public static function evictionSafetyProvider(): array {
    return [
      'locked out today' => ['i got locked out today'],
      'three day notice' => ['i got a 3-day notice'],
      'sheriff tomorrow' => ['the sheriff is coming tomorrow'],
    ];
  }

}
