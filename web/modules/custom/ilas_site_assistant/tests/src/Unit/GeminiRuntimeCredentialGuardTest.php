<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Form\AssistantSettingsForm;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the runtime-only Gemini credential posture for repository safety.
 */
#[Group('ilas_site_assistant')]
class GeminiRuntimeCredentialGuardTest extends TestCase {

  /**
   * Original Pantheon environment value.
   */
  private string|false $originalPantheonEnvironment = FALSE;

  /**
   * Captures process-level environment before each test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalPantheonEnvironment = getenv('PANTHEON_ENVIRONMENT');
  }

  /**
   * Resets process-level globals after each test.
   */
  protected function tearDown(): void {
    new Settings([]);

    if ($this->originalPantheonEnvironment === FALSE) {
      putenv('PANTHEON_ENVIRONMENT');
      unset($_ENV['PANTHEON_ENVIRONMENT']);
    }
    else {
      putenv('PANTHEON_ENVIRONMENT=' . $this->originalPantheonEnvironment);
      $_ENV['PANTHEON_ENVIRONMENT'] = $this->originalPantheonEnvironment;
    }

    parent::tearDown();
  }

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from the repo.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Parses a YAML file from the repo.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML file: {$relativePath}");
    return $parsed;
  }

  /**
   * The settings form source must not expose the old Gemini key field anymore.
   */
  public function testSettingsFormNoLongerContainsEditableGeminiSecretField(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringNotContainsString("['llm']['gemini_settings']['llm_api_key'] = [", $form);
    $this->assertStringContainsString('llm_api_key_runtime_notice', $form);
    $this->assertStringContainsString('Runtime-only. Set <code>ILAS_GEMINI_API_KEY</code>', $form);
  }

  /**
   * Spoofed POST values must not be saved into the llm config block.
   */
  public function testSettingsFormSubmitIgnoresSpoofedGeminiApiKey(): void {
    $savedLlmConfig = NULL;

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        return match ($key) {
          'canonical_urls.service_areas' => [
            'housing' => '/legal-help/housing',
            'family' => '/legal-help/family',
            'seniors' => '/legal-help/seniors',
            'health' => '/legal-help/health',
            'consumer' => '/legal-help/consumer',
            'civil_rights' => '/legal-help/civil-rights',
          ],
          default => NULL,
        };
      });
    $config->method('set')
      ->willReturnCallback(function (string $key, mixed $value) use (&$savedLlmConfig, $config): Config {
        if ($key === 'llm') {
          $savedLlmConfig = $value;
        }
        return $config;
      });
    $config->expects($this->once())->method('save');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->once())
      ->method('getEditable')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $typedConfigManager = $this->createStub(TypedConfigManagerInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');

    $form = new AssistantSettingsForm($configFactory, $typedConfigManager);
    $form->setMessenger($messenger);
    $form->setStringTranslation($this->translationStub());

    $formState = new FormState();
    $formState->setValues([
      'disclaimer_text' => 'Disclaimer',
      'welcome_message' => 'Welcome',
      'escalation_message' => 'Escalate',
      'enable_global_widget' => TRUE,
      'enable_assistant_page' => TRUE,
      'enable_faq' => TRUE,
      'enable_resources' => TRUE,
      'excluded_paths' => '',
      'enable_logging' => TRUE,
      'log_retention_days' => 90,
      'url_apply' => '/apply-for-help',
      'url_hotline' => '/Legal-Advice-Line',
      'url_offices' => '/contact/offices',
      'url_donate' => '/donate',
      'url_feedback' => '/get-involved/feedback',
      'url_resources' => '/what-we-do/resources',
      'url_forms' => '/forms',
      'url_guides' => '/guides',
      'url_faq' => '/faq',
      'url_services' => '/services',
      'url_senior_risk' => '/resources/legal-risk-detector',
      'faq_node_path' => '/faq',
      'retrieval_faq_index_id' => 'faq_accordion',
      'retrieval_resource_index_id' => 'assistant_resources',
      'retrieval_resource_fallback_index_id' => 'content',
      'retrieval_faq_vector_index_id' => 'faq_accordion_vector',
      'retrieval_resource_vector_index_id' => 'assistant_resources_vector',
      'vector_search_enabled' => FALSE,
      'vector_search_fallback_threshold' => 2,
      'vector_search_min_score' => 0.7,
      'vector_search_normalization_factor' => 100,
      'vector_search_min_lexical_score' => 0,
      'llm_enabled' => TRUE,
      'llm_provider' => 'gemini_api',
      'llm_model' => 'gemini-1.5-flash',
      'llm_api_key' => 'should-not-save',
      'llm_project_id' => '',
      'llm_location' => 'us-central1',
      'llm_max_tokens' => 150,
      'llm_temperature' => 0.3,
      'llm_enhance_greetings' => FALSE,
      'llm_fallback_on_error' => TRUE,
      'conversation_logging_enabled' => FALSE,
      'conversation_logging_retention_hours' => 72,
      'conversation_logging_redact_pii' => TRUE,
      'conversation_logging_show_user_notice' => TRUE,
    ]);

    $builtForm = [];
    $form->submitForm($builtForm, $formState);

    $this->assertIsArray($savedLlmConfig, 'Expected llm config to be saved.');
    $this->assertSame('', $savedLlmConfig['api_key']);
    $this->assertSame('gemini_api', $savedLlmConfig['provider']);
    $this->assertSame('gemini-1.5-flash', $savedLlmConfig['model']);
  }

  /**
   * Live settings saves must coerce vector enablement back to false.
   */
  public function testSettingsFormSubmitCoercesLiveVectorEnablementToFalse(): void {
    putenv('PANTHEON_ENVIRONMENT=live');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $savedVectorConfig = NULL;
    $savedLlmConfig = NULL;

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        return match ($key) {
          'canonical_urls.service_areas' => [
            'housing' => '/legal-help/housing',
            'family' => '/legal-help/family',
            'seniors' => '/legal-help/seniors',
            'health' => '/legal-help/health',
            'consumer' => '/legal-help/consumer',
            'civil_rights' => '/legal-help/civil-rights',
          ],
          default => NULL,
        };
      });
    $config->method('set')
      ->willReturnCallback(function (string $key, mixed $value) use (&$savedVectorConfig, &$savedLlmConfig, $config): Config {
        if ($key === 'vector_search') {
          $savedVectorConfig = $value;
        }
        if ($key === 'llm') {
          $savedLlmConfig = $value;
        }
        return $config;
      });
    $config->expects($this->once())->method('save');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->once())
      ->method('getEditable')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $typedConfigManager = $this->createStub(TypedConfigManagerInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');

    $form = new AssistantSettingsForm(
      $configFactory,
      $typedConfigManager,
      new EnvironmentDetector(),
    );
    $form->setMessenger($messenger);
    $form->setStringTranslation($this->translationStub());

    $formState = new FormState();
    $formState->setValues([
      'disclaimer_text' => 'Disclaimer',
      'welcome_message' => 'Welcome',
      'escalation_message' => 'Escalate',
      'enable_global_widget' => TRUE,
      'enable_assistant_page' => TRUE,
      'enable_faq' => TRUE,
      'enable_resources' => TRUE,
      'excluded_paths' => '',
      'enable_logging' => TRUE,
      'log_retention_days' => 90,
      'url_apply' => '/apply-for-help',
      'url_hotline' => '/Legal-Advice-Line',
      'url_offices' => '/contact/offices',
      'url_donate' => '/donate',
      'url_feedback' => '/get-involved/feedback',
      'url_resources' => '/what-we-do/resources',
      'url_forms' => '/forms',
      'url_guides' => '/guides',
      'url_faq' => '/faq',
      'url_services' => '/services',
      'url_senior_risk' => '/resources/legal-risk-detector',
      'faq_node_path' => '/faq',
      'retrieval_faq_index_id' => 'faq_accordion',
      'retrieval_resource_index_id' => 'assistant_resources',
      'retrieval_resource_fallback_index_id' => 'content',
      'retrieval_faq_vector_index_id' => 'faq_accordion_vector',
      'retrieval_resource_vector_index_id' => 'assistant_resources_vector',
      'vector_search_enabled' => TRUE,
      'vector_search_fallback_threshold' => 2,
      'vector_search_min_score' => 0.7,
      'vector_search_normalization_factor' => 100,
      'vector_search_min_lexical_score' => 0,
      'llm_enabled' => TRUE,
      'llm_provider' => 'gemini_api',
      'llm_model' => 'gemini-1.5-flash',
      'llm_project_id' => '',
      'llm_location' => 'us-central1',
      'llm_max_tokens' => 150,
      'llm_temperature' => 0.3,
      'llm_enhance_greetings' => FALSE,
      'llm_fallback_on_error' => TRUE,
      'conversation_logging_enabled' => FALSE,
      'conversation_logging_retention_hours' => 72,
      'conversation_logging_redact_pii' => TRUE,
      'conversation_logging_show_user_notice' => TRUE,
    ]);

    $builtForm = [];
    $form->submitForm($builtForm, $formState);

    $this->assertIsArray($savedVectorConfig, 'Expected vector_search config to be saved.');
    $this->assertFalse($savedVectorConfig['enabled']);
    $this->assertIsArray($savedLlmConfig, 'Expected llm config to be saved.');
    $this->assertFalse($savedLlmConfig['enabled']);
  }

  /**
   * settings.php must resolve the Gemini secret via site settings only.
   */
  public function testSettingsPhpStoresGeminiSecretInSiteSettingsOnly(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_GEMINI_API_KEY')", $settings);
    $this->assertStringContainsString("\$settings['ilas_gemini_api_key'] = \$ilas_gemini_key;", $settings);
    $this->assertStringNotContainsString("\$config['ilas_site_assistant.settings']['llm.api_key'] = \$ilas_gemini_key;", $settings);
    $this->assertStringNotContainsString("\$config['key.key.gemini_api_key']['key_provider_settings']['key_value']", $settings);
  }

  /**
   * The Gemini key entity must use the runtime provider and store no secret.
   */
  public function testGeminiKeyEntityUsesRuntimeProviderWithoutStoredSecret(): void {
    $keyConfig = self::readYaml('config/key.key.gemini_api_key.yml');
    $schema = self::readYaml('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.key.schema.yml');

    $this->assertSame('ilas_runtime_site_setting', $keyConfig['key_provider']);
    $this->assertSame('ilas_gemini_api_key', $keyConfig['key_provider_settings']['settings_key'] ?? NULL);
    $this->assertSame('none', $keyConfig['key_input']);
    $this->assertArrayNotHasKey('key_value', $keyConfig['key_provider_settings']);
    $this->assertArrayHasKey('key.provider.ilas_runtime_site_setting', $schema);
  }

  /**
   * Runtime site settings must take precedence over stored config.
   */
  public function testLlmEnhancerUsesRuntimeSettingBeforeStoredConfig(): void {
    new Settings([
      'ilas_gemini_api_key' => 'runtime-api-key',
    ]);

    $capture = new \stdClass();
    $capture->headers = [];

    $enhancer = new GeminiCredentialTestableEnhancer(
      $this->configFactoryWithGeminiKey('config-api-key'),
      $this->createStub(ClientInterface::class),
      $this->loggerFactoryStub(),
      $this->createStub(PolicyFilter::class),
      $capture,
    );

    $method = new \ReflectionMethod($enhancer, 'callGeminiApi');
    $method->setAccessible(TRUE);
    $method->invoke($enhancer, 'Test prompt', ['max_tokens' => 10]);

    $this->assertSame('runtime-api-key', $capture->headers['x-goog-api-key'] ?? NULL);
  }

  /**
   * Legacy config fallback remains available until environments are updated.
   */
  public function testLlmEnhancerFallsBackToStoredConfigWhenRuntimeSettingMissing(): void {
    new Settings([]);

    $capture = new \stdClass();
    $capture->headers = [];

    $enhancer = new GeminiCredentialTestableEnhancer(
      $this->configFactoryWithGeminiKey('config-api-key'),
      $this->createStub(ClientInterface::class),
      $this->loggerFactoryStub(),
      $this->createStub(PolicyFilter::class),
      $capture,
    );

    $method = new \ReflectionMethod($enhancer, 'callGeminiApi');
    $method->setAccessible(TRUE);
    $method->invoke($enhancer, 'Test prompt', ['max_tokens' => 10]);

    $this->assertSame('config-api-key', $capture->headers['x-goog-api-key'] ?? NULL);
  }

  /**
   * Builds a translation stub for form tests.
   */
  private function translationStub(): TranslationInterface {
    return new class implements TranslationInterface {

      public function translate($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      }

      public function translateString(\Drupal\Core\StringTranslation\TranslatableMarkup $translated_string) {
        return (string) $translated_string;
      }

      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        return strtr($count == 1 ? $singular : $plural, $args);
      }
    };
  }

  /**
   * Builds a config factory with a configurable Gemini API key.
   */
  private function configFactoryWithGeminiKey(string $apiKey): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['llm.enabled', TRUE],
        ['llm.provider', 'gemini_api'],
        ['llm.api_key', $apiKey],
        ['llm.model', 'gemini-1.5-flash'],
        ['llm.safety_threshold', 'BLOCK_MEDIUM_AND_ABOVE'],
      ]);

    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

  /**
   * Builds a logger factory stub.
   */
  private function loggerFactoryStub(): LoggerChannelFactoryInterface {
    $logger = $this->createStub(LoggerInterface::class);
    $factory = $this->createStub(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

}

/**
 * Test double for capturing Gemini request headers.
 */
class GeminiCredentialTestableEnhancer extends LlmEnhancer {

  /**
   * Capture object for headers.
   *
   * @var \stdClass
   */
  private \stdClass $capture;

  /**
   * Constructs the test double.
   */
  public function __construct(
    $config_factory,
    $http_client,
    $logger_factory,
    $policy_filter,
    \stdClass $capture,
  ) {
    parent::__construct($config_factory, $http_client, $logger_factory, $policy_filter);
    $this->capture = $capture;
  }

  /**
   * {@inheritdoc}
   */
  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $this->capture->headers = $headers;
    return 'test response';
  }

}
