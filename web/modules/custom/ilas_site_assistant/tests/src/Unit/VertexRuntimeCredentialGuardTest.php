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
use Drupal\ilas_site_assistant\Plugin\KeyProvider\RuntimeSiteSettingKeyProvider;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the runtime-only Vertex credential posture for RAUD-03.
 */
#[Group('ilas_site_assistant')]
class VertexRuntimeCredentialGuardTest extends TestCase {

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
   * The settings form source must not expose the old secret field anymore.
   */
  public function testSettingsFormNoLongerContainsEditableVertexSecretField(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringNotContainsString("['llm']['vertex_settings']['llm_service_account'] = [", $form);
    $this->assertStringNotContainsString('Service Account JSON', $form);
    $this->assertStringContainsString('llm_service_account_runtime_notice', $form);
    $this->assertStringContainsString('Runtime-only. Set <code>ILAS_VERTEX_SA_JSON</code>', $form);
  }

  /**
   * Spoofed POST values must not be saved into the llm config block.
   */
  public function testSettingsFormSubmitIgnoresSpoofedServiceAccountValue(): void {
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
      'llm_provider' => 'vertex_ai',
      'llm_model' => 'gemini-1.5-flash',
      'llm_api_key' => '',
      'llm_project_id' => 'project-123',
      'llm_location' => 'us-central1',
      'llm_service_account' => '{"private_key":"should-not-save"}',
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
    $this->assertArrayNotHasKey('service_account_json', $savedLlmConfig);
    $this->assertSame('project-123', $savedLlmConfig['project_id']);
    $this->assertSame('us-central1', $savedLlmConfig['location']);
  }

  /**
   * Install defaults, active config, and schema must all omit the blob key.
   */
  public function testConfigAndSchemaNoLongerContainServiceAccountJson(): void {
    $install = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $active = self::readYaml('config/ilas_site_assistant.settings.yml');
    $schema = self::readYaml('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');

    $this->assertIsArray($install['llm'] ?? NULL);
    $this->assertIsArray($active['llm'] ?? NULL);
    $this->assertIsArray($schema['ilas_site_assistant.settings']['mapping']['llm']['mapping'] ?? NULL);

    $this->assertArrayNotHasKey('service_account_json', $install['llm']);
    $this->assertArrayNotHasKey('service_account_json', $active['llm']);
    $this->assertArrayNotHasKey('service_account_json', $schema['ilas_site_assistant.settings']['mapping']['llm']['mapping']);
  }

  /**
   * settings.php must resolve the runtime secret without writing it to config.
   */
  public function testSettingsPhpStoresVertexSecretInSiteSettingsOnly(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString("_ilas_get_secret('ILAS_VERTEX_SA_JSON')", $settings);
    $this->assertStringContainsString("\$settings['ilas_vertex_sa_json'] = \$ilas_vertex_sa;", $settings);
    $this->assertStringNotContainsString("\$config['ilas_site_assistant.settings']['llm.service_account_json']", $settings);
    $this->assertStringNotContainsString("\$config['key.key.vertex_sa_credentials']['key_provider_settings']['key_value']", $settings);
  }

  /**
   * TOVR-14 removes the dormant exported Vertex key entity entirely.
   */
  public function testVertexKeyEntityConfigNoLongerExists(): void {
    $path = self::repoRoot() . '/config/key.key.vertex_sa_credentials.yml';
    $this->assertFileDoesNotExist($path);
  }

  /**
   * The custom key provider must read from the runtime site setting.
   */
  public function testRuntimeKeyProviderReadsDrupalSiteSetting(): void {
    if (!class_exists('Drupal\\key\\Plugin\\KeyProviderBase') || !interface_exists('Drupal\\key\\KeyInterface')) {
      $this->markTestSkipped('drupal/key is not available in the pure test harness.');
    }

    new Settings([
      'ilas_vertex_sa_json' => '{"private_key":"runtime-key"}',
    ]);

    $provider = new RuntimeSiteSettingKeyProvider(
      ['settings_key' => 'ilas_vertex_sa_json'],
      'ilas_runtime_site_setting',
      ['plugin_type' => 'key_provider'],
    );

    $value = $provider->getKeyValue($this->createStub(\Drupal\key\KeyInterface::class));
    $this->assertSame('{"private_key":"runtime-key"}', $value);
  }

  /**
   * The enhancer must prefer the runtime setting and ignore stored config.
   */
  public function testLlmEnhancerUsesRuntimeSettingInsteadOfStoredConfig(): void {
    new Settings([
      'ilas_vertex_sa_json' => '{"private_key":"runtime-key","client_email":"bot@example.com"}',
    ]);

    $enhancer = $this->buildEnhancerWithConfigSecret('{"private_key":"config-key","client_email":"config@example.com"}');

    $this->assertSame('runtime-token', $enhancer->exposedGetVertexAiAccessToken());
    $this->assertSame('{"private_key":"runtime-key","client_email":"bot@example.com"}', $enhancer->capturedJson);
  }

  /**
   * The enhancer must fall back to metadata when the runtime setting is absent.
   */
  public function testLlmEnhancerFallsBackToMetadataWhenRuntimeSettingMissing(): void {
    new Settings([]);

    $enhancer = $this->buildEnhancerWithConfigSecret('{"private_key":"config-key","client_email":"config@example.com"}');

    $this->assertSame('metadata-token', $enhancer->exposedGetVertexAiAccessToken());
    $this->assertNull($enhancer->capturedJson);
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
   * Builds a test enhancer that records which credential path was used.
   */
  private function buildEnhancerWithConfigSecret(string $configSecret): VertexCredentialTestableEnhancer {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['llm.service_account_json', $configSecret],
      ]);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createStub(LoggerInterface::class);
    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new VertexCredentialTestableEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $loggerFactory,
      $this->createStub(PolicyFilter::class),
    );
  }

}

/**
 * Test double for verifying the Vertex credential source path.
 */
class VertexCredentialTestableEnhancer extends LlmEnhancer {

  /**
   * The JSON blob passed to the service-account path.
   *
   * @var string|null
   */
  public ?string $capturedJson = NULL;

  /**
   * Exposes the protected token-resolution method for testing.
   */
  public function exposedGetVertexAiAccessToken(): string {
    $token = $this->getVertexAiAccessToken();
    return $token['access_token'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getTokenFromServiceAccount(string $json): array {
    $this->capturedJson = $json;
    return [
      'access_token' => 'runtime-token',
      'expires_at' => time() + 3600,
      'cache_ttl' => 3500,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getTokenFromMetadataServer(): array {
    return [
      'access_token' => 'metadata-token',
      'expires_at' => time() + 3600,
      'cache_ttl' => 3500,
    ];
  }

}
