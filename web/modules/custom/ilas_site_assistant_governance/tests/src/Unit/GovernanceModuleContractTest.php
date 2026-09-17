<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Drupal\Component\Serialization\Yaml;

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
    $parsed = Yaml::decode(self::readFile($relative_path));
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

    $this->assertStringContainsString("'is_unresolved'", $source);
    $this->assertStringContainsString("'trigger_code'", $source);
    $this->assertStringContainsString('function ilas_site_assistant_governance_update_11007', $source);
  }

  /**
   * Trigger codes stay in sync between the runtime decider and the entity.
   */
  public function testTriggerCodesMirrorRuntimeDecider(): void {
    $decider = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/GapReviewDecider.php');
    $entity = self::readFile('web/modules/custom/ilas_site_assistant_governance/src/Entity/AssistantGapItem.php');
    foreach ([
      'no_match_fallback',
      'clarify_unknown_intent',
      'clarify_loop_break',
      'clarify_no_grounding',
      'negative_feedback',
      'reviewer_flagged',
    ] as $code) {
      $this->assertStringContainsString("'{$code}'", $decider);
      $this->assertStringContainsString("'{$code}'", $entity);
    }
  }

  /**
   * Tests the queue view config exists.
   */
  public function testQueueViewConfigExists(): void {
    $install_view = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_gap_items.yml');
    $active_view = self::parseYaml('config/views.view.assistant_gap_items.yml');
    $default_display = $install_view['display']['default']['display_options'];
    $fields = $default_display['fields'];
    $style_options = $default_display['style']['options'];
    $queue_filters = $install_view['display']['page_queue']['display_options']['filters']['review_state']['value'];
    $page_all_display = $install_view['display']['page_all']['display_options'];
    $page_all_fields = $page_all_display['fields'];
    $page_all_style = $page_all_display['style']['options'];

    $this->assertSame('assistant_gap_items', $install_view['id']);
    $this->assertSame('assistant_gap_item', $install_view['base_table']);
    $this->assertArrayHasKey('page_queue', $install_view['display']);
    $this->assertArrayHasKey('page_new', $install_view['display']);
    $this->assertArrayHasKey('page_needs_review', $install_view['display']);
    $this->assertArrayHasKey('page_clarify', $install_view['display']);
    $this->assertArrayHasKey('page_top_misses', $install_view['display']);
    $this->assertArrayHasKey('page_all', $install_view['display']);
    $this->assertArrayHasKey('trigger_code', $fields);
    $this->assertSame('Trigger', $fields['trigger_code']['label']);
    $this->assertArrayHasKey('trigger_code', $style_options['columns']);
    // The main queue hides generic-clarify items; they get their own tab.
    $queue_trigger_filter = $install_view['display']['page_queue']['display_options']['filters']['trigger_code'];
    $this->assertSame('not in', $queue_trigger_filter['operator']);
    $this->assertSame('in', $install_view['display']['page_queue']['display_options']['filters']['review_state']['operator']);
    $this->assertStringContainsString("['options callback'] = \$callback", self::readFile('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.views.inc'));
    $this->assertSame(['clarify_unknown_intent' => 'clarify_unknown_intent'], $queue_trigger_filter['value']);
    $clarify_display = $install_view['display']['page_clarify']['display_options'];
    $this->assertSame('admin/reports/ilas-assistant/gaps/clarify', $clarify_display['path']);
    $this->assertSame([
      'clarify_unknown_intent' => 'clarify_unknown_intent',
      'clarify_loop_break' => 'clarify_loop_break',
      'clarify_no_grounding' => 'clarify_no_grounding',
    ], $clarify_display['filters']['trigger_code']['value']);
    $this->assertSame('DESC', $clarify_display['sorts']['occurrence_count_unresolved']['order']);
    $top_display = $install_view['display']['page_top_misses']['display_options'];
    $this->assertSame('admin/reports/ilas-assistant/gaps/top-misses', $top_display['path']);
    $this->assertSame('-30 days', $top_display['filters']['last_seen']['value']['value']);
    $this->assertSame('offset', $top_display['filters']['last_seen']['value']['type']);
    $this->assertSame('DESC', $top_display['sorts']['occurrence_count_unresolved']['order']);
    $this->assertSame($fields['trigger_code'], $active_view['display']['default']['display_options']['fields']['trigger_code']);
    $this->assertSame($clarify_display['filters'], $active_view['display']['page_clarify']['display_options']['filters']);
    $this->assertSame($top_display['filters'], $active_view['display']['page_top_misses']['display_options']['filters']);
    $tasks = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.links.task.yml');
    $this->assertSame('view.assistant_gap_items.page_clarify', $tasks['view.assistant_gap_items.page_clarify_tab']['route_name']);
    $this->assertSame('view.assistant_gap_items.page_top_misses', $tasks['view.assistant_gap_items.page_top_misses_tab']['route_name']);
    $this->assertArrayHasKey('assistant_gap_item_bulk_form', $fields);
    $this->assertArrayNotHasKey('bulk_form', $fields);
    $this->assertSame('assistant_gap_item_bulk_form', $fields['assistant_gap_item_bulk_form']['field']);
    $this->assertSame('bulk_form', $fields['assistant_gap_item_bulk_form']['plugin_id']);
    $this->assertArrayHasKey('assistant_gap_item_bulk_form', $style_options['columns']);
    $this->assertArrayNotHasKey('bulk_form', $style_options['columns']);
    $this->assertArrayHasKey('exemplar_redacted_query', $fields);
    $this->assertArrayHasKey('next_action', $fields);
    $this->assertSame('assistant_gap_item_next_action', $fields['next_action']['plugin_id']);
    $this->assertArrayHasKey('next_action', $style_options['columns']);
    $this->assertArrayHasKey('identity_context_key', $fields);
    $this->assertArrayNotHasKey('last_intent', $fields);
    $this->assertArrayHasKey('primary_service_area_tid', $fields);
    $this->assertArrayHasKey('topic_assignment_source', $fields);
    $this->assertArrayHasKey('latest_conversation_id', $fields);
    $this->assertArrayHasKey('occurrence_count_unresolved', $fields);
    $this->assertArrayNotHasKey('occurrence_count_total', $fields);
    $this->assertSame('Open occurrences', $fields['occurrence_count_unresolved']['label']);
    $this->assertArrayHasKey('occurrence_count_unresolved', $style_options['columns']);
    $this->assertSame([
      'new' => 'new',
      'needs_review' => 'needs_review',
      'reviewed' => 'reviewed',
    ], $queue_filters);
    $this->assertContains('assistant_gap_item_flag_possible_taxonomy_gap_action', $fields['assistant_gap_item_bulk_form']['selected_actions']);
    $this->assertFalse($page_all_display['defaults']['fields']);
    $this->assertFalse($page_all_display['defaults']['style']);
    $this->assertArrayHasKey('occurrence_count_total', $page_all_fields);
    $this->assertArrayNotHasKey('occurrence_count_unresolved', $page_all_fields);
    $this->assertSame('Lifetime occurrences', $page_all_fields['occurrence_count_total']['label']);
    $this->assertArrayHasKey('occurrence_count_total', $page_all_style['columns']);

    $this->assertSame($fields['next_action'], $active_view['display']['default']['display_options']['fields']['next_action']);
    $this->assertSame(
      $fields['occurrence_count_unresolved'],
      $active_view['display']['default']['display_options']['fields']['occurrence_count_unresolved']
    );
    $this->assertSame(
      $page_all_fields['occurrence_count_total'],
      $active_view['display']['page_all']['display_options']['fields']['occurrence_count_total']
    );
    $this->assertSame(
      $queue_filters,
      $active_view['display']['page_queue']['display_options']['filters']['review_state']['value']
    );
  }

  /**
   * Tests governance routes expose only canonical staff surfaces.
   */
  public function testGovernanceRoutesExposeCanonicalStaffSurfaces(): void {
    $routes = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.routing.yml');

    $this->assertArrayNotHasKey('ilas_site_assistant_governance.topic_gap' . '_analysis', $routes);
    $this->assertArrayNotHasKey('ilas_site_assistant_governance.conversations', $routes);
    $this->assertArrayHasKey('ilas_site_assistant_governance.conversation_detail', $routes);
    $this->assertSame('/admin/reports/ilas-assistant/governance/conversations/{conversation_id}', $routes['ilas_site_assistant_governance.conversation_detail']['path']);
    $this->assertArrayHasKey('ilas_site_assistant_governance.gap_bulk_resolve_confirm', $routes);
    $this->assertArrayHasKey('ilas_site_assistant_governance.gap_bulk_archive_confirm', $routes);
    $this->assertArrayHasKey('ilas_site_assistant_governance.gap_start_review', $routes);
    $this->assertArrayHasKey('ilas_site_assistant_governance.flag_conversation_turn', $routes);
    $flag_route = $routes['ilas_site_assistant_governance.flag_conversation_turn'];
    $this->assertSame('/admin/reports/ilas-assistant/governance/conversations/{conversation_id}/turns/{turn_sequence}/flag', $flag_route['path']);
    $this->assertSame('flag assistant conversation turns', $flag_route['requirements']['_permission']);
    $this->assertSame('TRUE', $flag_route['requirements']['_csrf_token']);
    $permissions = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.permissions.yml');
    $this->assertArrayHasKey('flag assistant conversation turns', $permissions);
    $this->assertTrue($permissions['flag assistant conversation turns']['restrict access']);
  }

  /**
   * Feedback columns are declared in the schema and update hook.
   */
  public function testFeedbackColumnsDeclared(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.install');
    $this->assertStringContainsString("'feedback_value'", $source);
    $this->assertStringContainsString("'feedback_at'", $source);
    $this->assertStringContainsString("'has_negative_feedback'", $source);
    $this->assertStringContainsString('function ilas_site_assistant_governance_update_11008', $source);

    $conversations = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_governance_conversations.yml');
    $fields = $conversations['display']['default']['display_options']['fields'];
    $this->assertArrayHasKey('has_negative_feedback', $fields);
    $this->assertSame('Not-helpful feedback', $fields['has_negative_feedback']['label']);
    $active = self::parseYaml('config/views.view.assistant_governance_conversations.yml');
    $this->assertSame($fields['has_negative_feedback'], $active['display']['default']['display_options']['fields']['has_negative_feedback']);
  }

  /**
   * Tests the additional dashboard Views config exists.
   */
  public function testGovernanceDashboardViewsExist(): void {
    $conversations = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/config/install/views.view.assistant_governance_conversations.yml');
    $active_conversations = self::parseYaml('config/views.view.assistant_governance_conversations.yml');
    $conversation_display = $conversations['display']['default']['display_options'];
    $conversation_fields = $conversation_display['fields'];
    $active_fields = $active_conversations['display']['default']['display_options']['fields'];
    $conversation_columns = $conversation_display['style']['options']['columns'];

    $this->assertSame('assistant_governance_conversations', $conversations['id']);
    $this->assertSame('ilas_site_assistant_conversation_session', $conversations['base_table']);
    $this->assertSame('Conversation Logs', $conversations['label']);
    $this->assertArrayHasKey('page_all', $conversations['display']);
    $this->assertSame([], $conversation_display['filters']);
    $this->assertArrayHasKey('area_text_custom', $conversation_display['empty']);
    $this->assertSame('No canonical conversation logs are available yet.', $conversation_display['empty']['area_text_custom']['content']);
    $this->assertArrayHasKey('has_unresolved_gap', $conversation_fields);
    $this->assertArrayHasKey('has_unresolved_gap', $conversation_columns);
    $this->assertSame('Needs follow-up now', $conversation_fields['has_unresolved_gap']['label']);
    $this->assertSame('Ever had no-answer event', $conversation_fields['has_no_answer']['label']);
    $this->assertSame($conversation_fields['has_unresolved_gap'], $active_fields['has_unresolved_gap']);
    $this->assertSame($conversation_fields['has_no_answer'], $active_fields['has_no_answer']);

    $this->assertFileDoesNotExist(self::repoRoot() . '/config/views.view.' . 'assistant_gap_topic' . '_analysis.yml');
    $this->assertFileDoesNotExist(self::repoRoot() . '/web/modules/custom/ilas_site_assistant_governance/config/install/views.view.' . 'assistant_gap_topic' . '_analysis.yml');
  }

  /**
   * Tests governance report menu entries are directly discoverable.
   */
  public function testGovernanceMenuLinksTargetCanonicalRoutes(): void {
    $menu = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.links.menu.yml');

    $this->assertSame(
      'view.assistant_gap_items.page_queue',
      $menu['ilas_site_assistant_governance.gap_dashboard']['route_name']
    );
    $this->assertSame(
      'system.admin_reports',
      $menu['ilas_site_assistant_governance.gap_dashboard']['parent']
    );
    $this->assertSame(
      'view.assistant_governance_conversations.page_all',
      $menu['ilas_site_assistant_governance.conversation_logs']['route_name']
    );
    $this->assertSame(
      'system.admin_reports',
      $menu['ilas_site_assistant_governance.conversation_logs']['parent']
    );
    $this->assertArrayNotHasKey('ilas_site_assistant_governance.topic_gap' . '_analysis', $menu);
  }

  /**
   * Tests the module exposes Views data for conversation sessions.
   */
  public function testViewsDataExistsForConversationSessions(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.views.inc');

    $this->assertStringContainsString('ilas_site_assistant_conversation_session', $source);
    $this->assertStringContainsString('has_unresolved_gap', $source);
    $this->assertStringContainsString('latest_gap_item_id', $source);
    $this->assertStringContainsString("['assistant_gap_item']['next_action']", $source);
    $this->assertStringContainsString('assistant_gap_item_next_action', $source);
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
   * Tests the queue tabs are declared for reviewer navigation.
   */
  public function testQueueTabsExist(): void {
    $tasks = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.links.task.yml');

    $this->assertArrayHasKey('view.assistant_gap_items.page_new_tab', $tasks);
    $this->assertArrayHasKey('view.assistant_gap_items.page_needs_review_tab', $tasks);
    $this->assertArrayHasKey('view.assistant_gap_items.page_all_tab', $tasks);
  }

  /**
   * Tests the reviewed-gap Promptfoo exporter services are registered.
   */
  public function testReviewedGapPromptfooExporterServicesExist(): void {
    $services = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/ilas_site_assistant_governance.services.yml');
    $drush_services = self::parseYaml('web/modules/custom/ilas_site_assistant_governance/drush.services.yml');

    $this->assertArrayHasKey('ilas_site_assistant_governance.reviewed_gap_promptfoo_candidate_exporter', $services['services']);
    $this->assertSame(
      'Drupal\ilas_site_assistant_governance\Service\ReviewedGapPromptfooCandidateExporter',
      $services['services']['ilas_site_assistant_governance.reviewed_gap_promptfoo_candidate_exporter']['class']
    );

    $this->assertArrayHasKey('ilas_site_assistant_governance.reviewed_gap_promptfoo_export_commands', $drush_services['services']);
    $command = $drush_services['services']['ilas_site_assistant_governance.reviewed_gap_promptfoo_export_commands'];
    $this->assertSame(
      'Drupal\ilas_site_assistant_governance\Commands\ReviewedGapPromptfooExportCommands',
      $command['class']
    );
    $this->assertContains('@ilas_site_assistant_governance.reviewed_gap_promptfoo_candidate_exporter', $command['arguments']);
    $this->assertSame('drush.command', $command['tags'][0]['name']);
  }

  /**
   * Tests the secondary labels include the governance review labels.
   */
  public function testAssistantGapItemDeclaresSecondaryReviewLabels(): void {
    $source = self::readFile('web/modules/custom/ilas_site_assistant_governance/src/Entity/AssistantGapItem.php');

    foreach ([
      'identity_context_key',
      'identity_selection_key',
      'identity_intent',
      'FLAG_POSSIBLE_TAXONOMY_GAP',
      'FLAG_NEEDS_CONTENT_UPDATE',
      'FLAG_ESCALATE_TO_EDITOR',
      'FLAG_DUPLICATE_ISSUE',
    ] as $constant) {
      $this->assertStringContainsString($constant, $source);
    }
  }

  /**
   * Tests the canonical gap-item route is backed by the review workspace.
   */
  public function testAssistantGapItemCanonicalRouteUsesReviewWorkspace(): void {
    $entity_source = self::readFile('web/modules/custom/ilas_site_assistant_governance/src/Entity/AssistantGapItem.php');
    $route_provider_source = self::readFile('web/modules/custom/ilas_site_assistant_governance/src/AssistantGapItemHtmlRouteProvider.php');

    $this->assertStringContainsString("'html' => AssistantGapItemHtmlRouteProvider::class", $entity_source);
    $this->assertStringContainsString("AssistantGapItemReviewController::class . '::review'", $route_provider_source);
    $this->assertStringContainsString("AssistantGapItemReviewController::class . '::title'", $route_provider_source);
  }

}
