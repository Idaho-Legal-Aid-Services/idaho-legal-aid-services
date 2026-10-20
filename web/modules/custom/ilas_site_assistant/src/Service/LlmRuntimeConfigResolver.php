<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Resolves effective request-time LLM runtime configuration.
 */
final class LlmRuntimeConfigResolver {

  public const DEFAULT_PROVIDER = 'cohere';
  public const DEFAULT_MODEL = 'command-a-03-2025';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a sanitized effective runtime summary.
   *
   * @return array<string, mixed>
   *   LLM runtime state and source labels. No secret values are returned.
   */
  public function resolve(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    $provider_config = trim((string) ($config->get('llm.provider') ?? ''));
    $model_config = trim((string) ($config->get('llm.model') ?? ''));
    $provider = $provider_config !== '' ? $provider_config : self::DEFAULT_PROVIDER;
    $model = $model_config !== '' ? $model_config : self::DEFAULT_MODEL;
    $enabled = (bool) ($config->get('llm.enabled') ?? FALSE);
    $key_present = $this->getApiKey() !== '';
    $provider_is_cohere = $provider === self::DEFAULT_PROVIDER;
    $model_configured = $model !== '';
    $runtime_ready = $enabled && $provider_is_cohere && $model_configured && $key_present;
    $generation_enabled = (bool) ($config->get('llm.generation.enabled') ?? FALSE);

    return [
      'enabled' => $enabled,
      'provider' => $provider,
      'model' => $model,
      'provider_is_cohere' => $provider_is_cohere,
      'model_configured' => $model_configured,
      'key_present' => $key_present,
      'runtime_ready' => $runtime_ready,
      'generation_enabled' => $generation_enabled,
      'generation_runtime_ready' => $runtime_ready && $generation_enabled,
      'sources' => [
        'enabled' => $this->enabledSource(),
        'provider' => $provider_config !== '' ? 'config export llm.provider' : 'code default',
        'model' => $model_config !== '' ? 'config export llm.model' : 'code default',
        'key_present' => 'settings.php runtime site setting ILAS_COHERE_API_KEY -> getenv/pantheon_get_secret',
        'runtime_ready' => 'llm.enabled + llm.provider + llm.model + runtime Cohere key',
        'generation_enabled' => $this->generationEnabledSource(),
        'generation_runtime_ready' => 'llm.runtime_ready + llm.generation.enabled',
      ],
    ];
  }

  /**
   * Returns TRUE when grounded answer generation is fully runtime-ready.
   */
  public function isGenerationRuntimeReady(): bool {
    return (bool) ($this->resolve()['generation_runtime_ready'] ?? FALSE);
  }

  /**
   * Returns TRUE when request-time generation is fully runtime-ready.
   */
  public function isRuntimeReady(): bool {
    return (bool) ($this->resolve()['runtime_ready'] ?? FALSE);
  }

  /**
   * Returns the effective provider identifier.
   */
  public function getProviderId(): string {
    return (string) ($this->resolve()['provider'] ?? self::DEFAULT_PROVIDER);
  }

  /**
   * Returns the effective model identifier.
   */
  public function getModelId(): string {
    return (string) ($this->resolve()['model'] ?? self::DEFAULT_MODEL);
  }

  /**
   * Returns TRUE when the Cohere runtime key is present.
   */
  public function hasApiKey(): bool {
    return $this->getApiKey() !== '';
  }

  /**
   * Returns the runtime-only Cohere API key for internal transport use.
   */
  public function getApiKey(): string {
    return trim((string) Settings::get('ilas_cohere_api_key', ''));
  }

  /**
   * Returns the source label for the effective LLM enablement value.
   */
  private function enabledSource(): string {
    $override = Settings::get('ilas_llm_override_channel');
    if (is_string($override) && trim($override) !== '') {
      return trim($override);
    }

    return 'config export llm.enabled';
  }

  /**
   * Returns the source label for the effective generation enablement value.
   */
  private function generationEnabledSource(): string {
    $override = Settings::get('ilas_llm_generation_override_channel');
    if (is_string($override) && trim($override) !== '') {
      return trim($override);
    }

    return 'config export llm.generation.enabled';
  }

}
