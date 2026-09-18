<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\LlmRuntimeConfigResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for effective request-time LLM config resolution.
 */
#[Group('ilas_site_assistant')]
final class LlmRuntimeConfigResolverTest extends TestCase {

  /**
   *
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   *
   */
  public function testResolveReportsExplicitCohereConfigAndSourcesWithoutSecrets(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'ilas_llm_override_channel' => 'settings.php runtime toggle ILAS_LLM_ENABLED -> getenv/pantheon_get_secret',
      'hash_salt' => 'test-salt',
    ]);

    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
    ]));

    $summary = $resolver->resolve();

    $this->assertTrue($summary['enabled']);
    $this->assertSame('cohere', $summary['provider']);
    $this->assertSame('command-a-03-2025', $summary['model']);
    $this->assertTrue($summary['key_present']);
    $this->assertTrue($summary['runtime_ready']);
    $this->assertFalse($summary['generation_enabled']);
    $this->assertFalse($summary['generation_runtime_ready']);
    $this->assertSame('config export llm.generation.enabled', $summary['sources']['generation_enabled'] ?? NULL);
    $this->assertSame('config export llm.provider', $summary['sources']['provider'] ?? NULL);
    $this->assertSame('settings.php runtime toggle ILAS_LLM_ENABLED -> getenv/pantheon_get_secret', $summary['sources']['enabled'] ?? NULL);

    $json = json_encode($summary, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('cohere-secret-value', $json);
  }

  /**
   *
   */
  public function testResolveFallsBackToSafeDefaultsAndRequiresEnablement(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => FALSE,
      'llm.provider' => '',
      'llm.model' => '',
    ]));

    $summary = $resolver->resolve();

    $this->assertFalse($summary['enabled']);
    $this->assertSame('cohere', $summary['provider']);
    $this->assertSame('command-a-03-2025', $summary['model']);
    $this->assertFalse($summary['runtime_ready']);
    $this->assertSame('code default', $summary['sources']['provider'] ?? NULL);
  }

  /**
   *
   */
  public function testResolveMarksRuntimeNotReadyWhenProviderIsNotCohere(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => TRUE,
      'llm.provider' => 'other-provider',
      'llm.model' => 'command-a-03-2025',
    ]));

    $summary = $resolver->resolve();

    $this->assertTrue($summary['enabled']);
    $this->assertSame('other-provider', $summary['provider']);
    $this->assertFalse($summary['provider_is_cohere']);
    $this->assertFalse($summary['runtime_ready']);
  }

  /**
   *
   */
  public function testResolveFallsBackToDefaultModelWhenStoredModelIsBlank(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'hash_salt' => 'test-salt',
    ]);

    $resolver = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => '',
    ]));

    $summary = $resolver->resolve();

    $this->assertSame(LlmRuntimeConfigResolver::DEFAULT_MODEL, $summary['model']);
    $this->assertTrue($summary['model_configured']);
    $this->assertTrue($summary['runtime_ready']);
    $this->assertSame('code default', $summary['sources']['model'] ?? NULL);
    $this->assertSame(
      'settings.php runtime site setting ILAS_COHERE_API_KEY -> getenv/pantheon_get_secret',
      $summary['sources']['key_present'] ?? NULL,
    );
  }

  /**
   *
   */
  public function testGenerationRuntimeReadyRequiresClassificationRuntimeReady(): void {
    new Settings([
      'ilas_cohere_api_key' => 'cohere-secret-value',
      'ilas_llm_generation_override_channel' => 'settings.php runtime toggle ILAS_LLM_GENERATION_ENABLED -> getenv/pantheon_get_secret',
      'hash_salt' => 'test-salt',
    ]);

    $disabled = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => FALSE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => TRUE,
    ]));
    $summary = $disabled->resolve();
    $this->assertTrue($summary['generation_enabled']);
    $this->assertFalse($summary['generation_runtime_ready'], 'Generation must not be ready while llm.enabled is FALSE.');
    $this->assertFalse($disabled->isGenerationRuntimeReady());
    $this->assertSame(
      'settings.php runtime toggle ILAS_LLM_GENERATION_ENABLED -> getenv/pantheon_get_secret',
      $summary['sources']['generation_enabled'] ?? NULL,
    );

    $enabled = new LlmRuntimeConfigResolver($this->buildConfigFactory([
      'llm.enabled' => TRUE,
      'llm.provider' => 'cohere',
      'llm.model' => 'command-a-03-2025',
      'llm.generation.enabled' => TRUE,
    ]));
    $this->assertTrue($enabled->isGenerationRuntimeReady());
    $this->assertSame('llm.runtime_ready + llm.generation.enabled', $enabled->resolve()['sources']['generation_runtime_ready'] ?? NULL);
  }

  /**
   *
   */
  private function buildConfigFactory(array $values): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $factory;
  }

}
