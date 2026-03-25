<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests locking the "no legal advice" audit governance config.
 *
 * These tests enforce the governance specification defined in
 * docs/aila/AUDIT_GOVERNANCE_SPEC.md. Any change to audit domain classes,
 * event types, permissions, or cadence must be intentional and reviewed.
 */
#[Group('ilas_site_assistant')]
class NoLegalAdviceGovernanceTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Returns parsed install config.
   */
  private static function installConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml';
    self::assertFileExists($path, 'Install config YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed active config.
   */
  private static function activeConfig(): array {
    $path = self::repoRoot() . '/config/ilas_site_assistant.settings.yml';
    self::assertFileExists($path, 'Active config YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed schema config.
   */
  private static function schemaConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/config/schema/ilas_site_assistant.schema.yml';
    self::assertFileExists($path, 'Schema YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * Returns parsed permissions YAML.
   */
  private static function permissionsConfig(): array {
    $path = self::repoRoot() . '/' . self::MODULE_PATH . '/ilas_site_assistant.permissions.yml';
    self::assertFileExists($path, 'Permissions YAML not found');
    return Yaml::parseFile($path);
  }

  /**
   * All 7 audit_governance sub-keys must exist in install config.
   */
  public function testAuditGovernanceBlockExistsInInstallConfig(): void {
    $install = self::installConfig();
    $this->assertArrayHasKey('audit_governance', $install, 'Install config missing audit_governance block');

    $ag = $install['audit_governance'];
    $requiredKeys = [
      'audit_domain_classes',
      'audit_domain_event_types',
      'report_cadence_days',
      'report_required_permission',
      'signoff_required_permission',
      'report_retention_days',
      'signoff_deadline_days',
    ];
    foreach ($requiredKeys as $key) {
      $this->assertArrayHasKey($key, $ag, "audit_governance missing sub-key: {$key}");
    }
  }

  /**
   * All 7 audit_governance sub-keys must exist in active config.
   */
  public function testAuditGovernanceBlockExistsInActiveConfig(): void {
    $active = self::activeConfig();
    $this->assertArrayHasKey('audit_governance', $active, 'Active config missing audit_governance block');

    $ag = $active['audit_governance'];
    $requiredKeys = [
      'audit_domain_classes',
      'audit_domain_event_types',
      'report_cadence_days',
      'report_required_permission',
      'signoff_required_permission',
      'report_retention_days',
      'signoff_deadline_days',
    ];
    foreach ($requiredKeys as $key) {
      $this->assertArrayHasKey($key, $ag, "audit_governance missing sub-key: {$key}");
    }
  }

  /**
   * Schema must define audit_governance mapping with all sub-keys.
   */
  public function testSchemaCoversAuditGovernanceBlock(): void {
    $schema = self::schemaConfig();
    $mapping = $schema['ilas_site_assistant.settings']['mapping'];

    $this->assertArrayHasKey('audit_governance', $mapping, 'Schema missing audit_governance mapping');

    $agSchema = $mapping['audit_governance']['mapping'];
    $requiredKeys = [
      'audit_domain_classes',
      'audit_domain_event_types',
      'report_cadence_days',
      'report_required_permission',
      'signoff_required_permission',
      'report_retention_days',
      'signoff_deadline_days',
    ];
    foreach ($requiredKeys as $key) {
      $this->assertArrayHasKey($key, $agSchema, "Schema audit_governance missing sub-key: {$key}");
    }
  }

  /**
   * Each audit domain class must map to a SafetyClassifier CLASS_* constant.
   */
  public function testAuditDomainClassesAreValidClassConstants(): void {
    $install = self::installConfig();
    $classes = $install['audit_governance']['audit_domain_classes'];

    $reflection = new \ReflectionClass(SafetyClassifier::class);
    $constants = $reflection->getConstants();
    $classConstants = [];
    foreach ($constants as $name => $value) {
      if (str_starts_with($name, 'CLASS_')) {
        $classConstants[$value] = $name;
      }
    }

    foreach ($classes as $class) {
      $this->assertArrayHasKey(
        $class,
        $classConstants,
        "Audit domain class '{$class}' does not match any SafetyClassifier::CLASS_* constant",
      );
    }
  }

  /**
   * legal_advice must always be in the audit domain.
   */
  public function testAuditDomainContainsLegalAdviceClass(): void {
    $install = self::installConfig();
    $classes = $install['audit_governance']['audit_domain_classes'];
    $this->assertContains('legal_advice', $classes, 'Audit domain must contain legal_advice');
  }

  /**
   * document_drafting must always be in the audit domain.
   */
  public function testAuditDomainContainsDocumentDraftingClass(): void {
    $install = self::installConfig();
    $classes = $install['audit_governance']['audit_domain_classes'];
    $this->assertContains('document_drafting', $classes, 'Audit domain must contain document_drafting');
  }

  /**
   * Audit domain classes must match the exact expected sorted set.
   */
  public function testAuditDomainClassesMatchExpectedSet(): void {
    $install = self::installConfig();
    $classes = $install['audit_governance']['audit_domain_classes'];
    sort($classes);

    $expected = ['criminal', 'document_drafting', 'external', 'immigration', 'legal_advice'];
    $this->assertSame($expected, $classes, 'Audit domain classes do not match expected set');
  }

  /**
   * All 5 required analytics event types must be present.
   */
  public function testAuditDomainEventTypesAreComplete(): void {
    $install = self::installConfig();
    $eventTypes = $install['audit_governance']['audit_domain_event_types'];

    $required = [
      'safety_violation',
      'policy_violation',
      'out_of_scope',
      'post_gen_safety_legal_advice',
      'post_gen_safety_review_flag',
      'post_gen_safety_legal_advice_scan',
    ];
    foreach ($required as $type) {
      $this->assertContains($type, $eventTypes, "Audit domain missing event type: {$type}");
    }
  }

  /**
   * Report cadence must be 30 days (monthly).
   */
  public function testReportCadenceDaysIsMonthly(): void {
    $install = self::installConfig();
    $this->assertSame(
      30,
      $install['audit_governance']['report_cadence_days'],
      'Report cadence must be 30 days',
    );
  }

  /**
   * Report required permission must be a non-empty string.
   */
  public function testReportRequiredPermissionIsSet(): void {
    $install = self::installConfig();
    $permission = $install['audit_governance']['report_required_permission'];
    $this->assertIsString($permission);
    $this->assertNotEmpty($permission, 'report_required_permission must be non-empty');
  }

  /**
   * Signoff permission must be a non-empty string distinct from report permission.
   */
  public function testSignoffRequiredPermissionIsSet(): void {
    $install = self::installConfig();
    $ag = $install['audit_governance'];

    $signoffPerm = $ag['signoff_required_permission'];
    $reportPerm = $ag['report_required_permission'];

    $this->assertIsString($signoffPerm);
    $this->assertNotEmpty($signoffPerm, 'signoff_required_permission must be non-empty');
    $this->assertNotSame(
      $reportPerm,
      $signoffPerm,
      'signoff_required_permission must differ from report_required_permission',
    );
  }

  /**
   * The signoff permission must be defined in permissions.yml.
   */
  public function testSignoffPermissionExistsInPermissionsYml(): void {
    $install = self::installConfig();
    $signoffPerm = $install['audit_governance']['signoff_required_permission'];

    $permissions = self::permissionsConfig();
    $this->assertArrayHasKey(
      $signoffPerm,
      $permissions,
      "Permission '{$signoffPerm}' not found in permissions.yml",
    );
  }

  /**
   * Install and active config audit_governance blocks must match.
   */
  public function testInstallAndActiveConfigAuditGovernanceMatch(): void {
    $install = self::installConfig();
    $active = self::activeConfig();

    $this->assertSame(
      $install['audit_governance'],
      $active['audit_governance'],
      'Active audit_governance has drifted from install config',
    );
  }

  /**
   * Retention days and signoff deadline must both be positive integers.
   */
  public function testReportRetentionAndSignoffDeadlineArePositive(): void {
    $install = self::installConfig();
    $ag = $install['audit_governance'];

    $this->assertIsInt($ag['report_retention_days']);
    $this->assertGreaterThan(0, $ag['report_retention_days'], 'report_retention_days must be > 0');

    $this->assertIsInt($ag['signoff_deadline_days']);
    $this->assertGreaterThan(0, $ag['signoff_deadline_days'], 'signoff_deadline_days must be > 0');
  }

}
