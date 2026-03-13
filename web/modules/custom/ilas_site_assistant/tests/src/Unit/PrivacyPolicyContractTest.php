<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\ConversationLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests enforcing privacy-first runtime policy.
 *
 * Guards against config drift, missing retention caps, broken access controls,
 * and cron cleanup dispatch. Reads deployed artifacts directly — no Drupal
 * bootstrap required.
 *
 */
#[Group('ilas_site_assistant')]
class PrivacyPolicyContractTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file after asserting it exists.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Parses a YAML file and returns its contents.
   */
  private static function parseYaml(string $relativePath): array {
    $contents = self::readFile($relativePath);
    $parsed = Yaml::parse($contents);
    self::assertIsArray($parsed, "YAML parse failed for: {$relativePath}");
    return $parsed;
  }

  /**
   * Tests deployed config defaults conversation logging to disabled.
   */
  public function testDeployedConfigDefaultsConversationLoggingDisabled(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertArrayHasKey('conversation_logging', $config);
    $this->assertArrayHasKey('enabled', $config['conversation_logging']);
    $this->assertFalse(
      $config['conversation_logging']['enabled'],
      'Deployed config must default conversation_logging.enabled to false (privacy-first).',
    );
  }

  /**
   * Tests install config defaults conversation logging to disabled.
   */
  public function testInstallConfigDefaultsConversationLoggingDisabled(): void {
    $config = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml'
    );

    $this->assertArrayHasKey('conversation_logging', $config);
    $this->assertArrayHasKey('enabled', $config['conversation_logging']);
    $this->assertFalse(
      $config['conversation_logging']['enabled'],
      'Install config must default conversation_logging.enabled to false (privacy-first).',
    );
  }

  /**
   * Tests deployed config defaults PII redaction to enabled.
   */
  public function testDeployedConfigDefaultsRedactPiiTrue(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertTrue(
      $config['conversation_logging']['redact_pii'],
      'Deployed config must default conversation_logging.redact_pii to true.',
    );
  }

  /**
   * Tests deployed config defaults user notice to enabled.
   */
  public function testDeployedConfigDefaultsShowUserNoticeTrue(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertTrue(
      $config['conversation_logging']['show_user_notice'],
      'Deployed config must default conversation_logging.show_user_notice to true.',
    );
  }

  /**
   * Tests deployed config retention hours are within the maximum cap.
   */
  public function testDeployedConfigRetentionWithinCap(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertLessThanOrEqual(
      ConversationLogger::MAX_RETENTION_HOURS,
      $config['conversation_logging']['retention_hours'],
      'Deployed conversation retention_hours must not exceed MAX_RETENTION_HOURS.',
    );
  }

  /**
   * Tests deployed config analytics retention days are within the maximum cap.
   */
  public function testDeployedConfigAnalyticsRetentionWithinCap(): void {
    $config = self::parseYaml('config/ilas_site_assistant.settings.yml');

    $this->assertLessThanOrEqual(
      AnalyticsLogger::MAX_RETENTION_DAYS,
      $config['log_retention_days'],
      'Deployed log_retention_days must not exceed MAX_RETENTION_DAYS.',
    );
  }

  /**
   * Tests ConversationLogger declares a maximum retention cap constant.
   */
  public function testConversationLoggerHasMaxRetentionCap(): void {
    $this->assertSame(
      720,
      ConversationLogger::MAX_RETENTION_HOURS,
      'ConversationLogger::MAX_RETENTION_HOURS must be 720 (30 days).',
    );
  }

  /**
   * Tests AnalyticsLogger declares a maximum retention cap constant.
   */
  public function testAnalyticsLoggerHasMaxRetentionCap(): void {
    $this->assertSame(
      365,
      AnalyticsLogger::MAX_RETENTION_DAYS,
      'AnalyticsLogger::MAX_RETENTION_DAYS must be 365 (1 year).',
    );
  }

  /**
   * Tests permission file gates conversation view with restrict_access.
   */
  public function testPermissionFileHasConversationViewRestriction(): void {
    $perms = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.permissions.yml'
    );

    $this->assertArrayHasKey('view ilas site assistant conversations', $perms);
    $perm = $perms['view ilas site assistant conversations'];
    $this->assertTrue(
      $perm['restrict access'] ?? FALSE,
      'Conversation view permission must have restrict access: true.',
    );
  }

  /**
   * Tests conversation routes require the correct permission.
   */
  public function testConversationRoutesRequireViewPermission(): void {
    $routes = self::parseYaml(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.routing.yml'
    );

    $expected_permission = 'view ilas site assistant conversations';
    $route_names = [
      'ilas_site_assistant.admin.conversations',
      'ilas_site_assistant.admin.conversation_detail',
    ];

    foreach ($route_names as $route_name) {
      $this->assertArrayHasKey($route_name, $routes, "Route {$route_name} must exist.");
      $this->assertSame(
        $expected_permission,
        $routes[$route_name]['requirements']['_permission'] ?? '',
        "Route {$route_name} must require '{$expected_permission}'.",
      );
    }
  }

  /**
   * Tests cron hook dispatches conversation log cleanup.
   */
  public function testCronHookDispatchesConversationCleanup(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.module'
    );

    $this->assertStringContainsString(
      'ilas_site_assistant.conversation_logger',
      $source,
      'Cron hook must reference the conversation logger service.',
    );
    $this->assertStringContainsString(
      '->cleanup()',
      $source,
      'Cron hook must dispatch conversation cleanup.',
    );
  }

  /**
   * Tests cron hook dispatches analytics cleanup.
   */
  public function testCronHookDispatchesAnalyticsCleanup(): void {
    $source = self::readFile(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.module'
    );

    $this->assertStringContainsString(
      'ilas_site_assistant.analytics_logger',
      $source,
      'Cron hook must reference the analytics logger service.',
    );
    $this->assertStringContainsString(
      '->cleanupOldData()',
      $source,
      'Cron hook must dispatch analytics cleanup.',
    );
  }

}
