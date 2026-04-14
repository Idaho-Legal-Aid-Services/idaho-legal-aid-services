<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Source-level contract checks for the governance module surface.
 */
#[Group('ilas_site_assistant_governance')]
class GovernanceModuleContractTest extends TestCase {

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file.
   */
  private static function readFile(string $relative_path): string {
    $path = self::repoRoot() . '/' . ltrim($relative_path, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relative_path}");
    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relative_path}");
    return $contents;
  }

  /**
   * Parses a YAML file.
   */
  private static function parseYaml(string $relative_path): array {
    $parsed = Yaml::parse(self::readFile($relative_path));
    self::assertIsArray($parsed, "YAML parse failed for: {$relative_path}");
    return $parsed;
  }

  /**
   * Tests the module info file declares the expected dependencies.
   */
  public function testInfoFileDeclaresDependencies(): void {
    $info = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.info.yml');

    $this->assertSame('ILAS Site Assistant Governance', $info['name']);
    $this->assertContains('ilas_site_assistant:ilas_site_assistant', $info['dependencies']);
    $this->assertContains('drupal:views', $info['dependencies']);
  }

  /**
   * Tests the governance schema declares the canonical tables.
   */
  public function testSchemaDeclaresCanonicalTables(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.install');

    foreach ([
      'ilas_site_assistant_conversation_session',
      'ilas_site_assistant_conversation_turn',
      'ilas_site_assistant_gap_hit',
      'ilas_site_assistant_legal_hold',
    ] as $table) {
      $this->assertStringContainsString($table, $source);
    }
  }

  /**
   * Tests the queue view config exists.
   */
  public function testQueueViewConfigExists(): void {
    $view = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_gap_items.yml');

    $this->assertSame('assistant_gap_items', $view['id']);
    $this->assertSame('assistant_gap_item', $view['base_table']);
    $this->assertArrayHasKey('page_queue', $view['display']);
    $this->assertArrayHasKey('page_new', $view['display']);
    $this->assertArrayHasKey('page_all', $view['display']);
    $this->assertContains('assistant_gap_item_flag_possible_taxonomy_gap_action', $view['display']['default']['display_options']['fields']['bulk_form']['selected_actions']);
  }

  /**
   * Tests the governance routes expose the conversation admin pages.
   */
  public function testConversationRoutesExist(): void {
    $routes = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.routing.yml');

    $this->assertArrayHasKey('ilas_site_assistant_governance.conversations', $routes);
    $this->assertArrayHasKey('ilas_site_assistant_governance.conversation_detail', $routes);
    $this->assertSame('/admin/reports/ilas-assistant/governance/conversations/legacy', $routes['ilas_site_assistant_governance.conversations']['path']);
  }

  /**
   * Tests the additional dashboard Views config exists.
   */
  public function testGovernanceDashboardViewsExist(): void {
    $conversations = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_governance_conversations.yml');
    $topic_analysis = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_gap_topic_analysis.yml');

    $this->assertSame('assistant_governance_conversations', $conversations['id']);
    $this->assertSame('ilas_site_assistant_conversation_session', $conversations['base_table']);
    $this->assertArrayHasKey('page_all', $conversations['display']);

    $this->assertSame('assistant_gap_topic_analysis', $topic_analysis['id']);
    $this->assertSame('assistant_gap_item', $topic_analysis['base_table']);
    $this->assertArrayHasKey('page_topics', $topic_analysis['display']);
  }

  /**
   * Tests the module exposes Views data for conversation sessions.
   */
  public function testViewsDataExistsForConversationSessions(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.views.inc');

    $this->assertStringContainsString('ilas_site_assistant_conversation_session', $source);
    $this->assertStringContainsString('latest_gap_item_id', $source);
  }

  /**
   * Tests the bulk-action config surface exists for triage and flagging.
   */
  public function testBulkActionConfigExists(): void {
    foreach ([
      'system.action.assistant_gap_item_assign_to_current_user_action.yml',
      'system.action.assistant_gap_item_to_needs_review_action.yml',
      'system.action.assistant_gap_item_to_reviewed_action.yml',
      'system.action.assistant_gap_item_to_resolved_action.yml',
      'system.action.assistant_gap_item_to_archived_action.yml',
      'system.action.assistant_gap_item_reopen_action.yml',
      'system.action.assistant_gap_item_flag_potential_faq_candidate_action.yml',
      'system.action.assistant_gap_item_flag_possible_taxonomy_gap_action.yml',
      'system.action.assistant_gap_item_flag_needs_content_update_action.yml',
      'system.action.assistant_gap_item_flag_escalate_to_editor_action.yml',
      'system.action.assistant_gap_item_flag_duplicate_issue_action.yml',
      'system.action.assistant_gap_item_place_legal_hold_action.yml',
      'system.action.assistant_gap_item_release_legal_hold_action.yml',
    ] as $relative_path) {
      self::assertFileExists(self::repoRoot() . '/web/modules/custom/ilas_site_assistant_governance/config/install/' . $relative_path);
    }
  }

  /**
   * Tests the secondary labels include the governance review labels.
   */
  public function testAssistantGapItemDeclaresSecondaryReviewLabels(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/src/Entity/AssistantGapItem.php');

    foreach ([
      'FLAG_POSSIBLE_TAXONOMY_GAP',
      'FLAG_NEEDS_CONTENT_UPDATE',
      'FLAG_ESCALATE_TO_EDITOR',
      'FLAG_DUPLICATE_ISSUE',
    ] as $constant) {
      $this->assertStringContainsString($constant, $source);
    }
  }

}
