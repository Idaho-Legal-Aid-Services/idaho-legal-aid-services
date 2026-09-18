<?php

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ilas_site_assistant\Service\KbCoverageAnalyzer;
use Drupal\ilas_site_assistant\Service\KbStubValidator;
use Drupal\paragraphs\Entity\Paragraph;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for importing KB content.
 */
class KbImportCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a KbImportCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Import FAQ stubs from YAML file.
   *
   * @param string $file
   *   Path to the YAML file containing FAQ stubs.
   * @param array $options
   *   Command options.
   *
   * @command ilas:kb-import
   * @aliases kb-import
   * @option dry-run Show what would be imported without creating entities.
   * @option node-id Node ID to attach paragraphs to (required for actual import).
   * @usage ilas:kb-import config/kb-stubs/top5-gap-faq-stubs.yml --dry-run
   *   Preview FAQ import.
   * @usage ilas:kb-import config/kb-stubs/top5-gap-faq-stubs.yml --node-id=123
   *   Import FAQs to node 123.
   */
  public function kbImport($file, array $options = ['dry-run' => FALSE, 'node-id' => NULL]) {
    $module_path = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');
    $file_path = $module_path . '/' . $file;

    if (!file_exists($file_path)) {
      // Try absolute path.
      $file_path = $file;
    }

    if (!file_exists($file_path)) {
      $this->logger()->error("File not found: {$file_path}");
      return 1;
    }

    $this->logger()->notice("Loading FAQ stubs from: {$file_path}");

    try {
      $data = Yaml::parseFile($file_path);
    }
    catch (\Exception $e) {
      $this->logger()->error("Failed to parse YAML: " . $e->getMessage());
      return 1;
    }

    if (empty($data['faq_stubs'])) {
      $this->logger()->error("No faq_stubs found in file.");
      return 1;
    }

    $stubs = $data['faq_stubs'];
    $this->logger()->notice("Found " . count($stubs) . " FAQ stubs.");

    // FAQ answers are published legal information: refuse the whole file
    // unless every stub is complete and carries an attorney sign-off. The
    // check also runs for --dry-run so a preview shows what would be blocked.
    $blocked = KbStubValidator::blockedStubs($stubs);
    if ($blocked !== []) {
      foreach ($blocked as $stub_id => $reasons) {
        $this->logger()->error("BLOCKED {$stub_id}: " . implode('; ', $reasons));
      }
      $this->logger()->error(count($blocked) . ' of ' . count($stubs) . ' stubs are not importable; nothing was imported. Each stub needs a finished answer plus review_status: approved, reviewed_by and reviewed_on (YYYY-MM-DD) recorded by the reviewing attorney.');
      return 1;
    }

    $dry_run = $options['dry-run'];
    $node_id = $options['node-id'];

    if (!$dry_run && empty($node_id)) {
      $this->logger()->error("--node-id is required for actual import. Use --dry-run to preview.");
      return 1;
    }

    $node = NULL;
    if (!$dry_run) {
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
      if (!$node) {
        $this->logger()->error("Node {$node_id} not found.");
        return 1;
      }
      $this->logger()->notice("Will attach paragraphs to node: " . $node->label());
    }

    $created = 0;
    foreach ($stubs as $stub) {
      $this->logger()->notice("Processing: {$stub['id']}");
      $this->logger()->notice("  Question: " . substr($stub['question'], 0, 60) . "...");
      $this->logger()->notice("  Category: {$stub['category']}");
      $this->logger()->notice("  Anchor: {$stub['anchor_id']}");

      if (!$dry_run) {
        // Create the paragraph.
        $paragraph = Paragraph::create([
          'type' => 'faq_item',
          'field_faq_question' => $stub['question'],
          'field_faq_answer' => [
            'value' => $stub['answer'],
            'format' => 'basic_html',
          ],
        ]);

        // Add anchor_id field if it exists.
        if ($paragraph->hasField('field_anchor_id')) {
          $paragraph->set('field_anchor_id', $stub['anchor_id']);
        }

        $paragraph->save();
        $created++;

        $this->logger()->success("  Created paragraph ID: " . $paragraph->id());

        // Note: Attaching to node requires knowing the field name.
        // This would typically be done manually or with additional logic.
      }
    }

    if ($dry_run) {
      $this->logger()->notice("DRY RUN complete. Would create " . count($stubs) . " FAQ paragraphs.");
    }
    else {
      $this->logger()->success("Created {$created} FAQ paragraphs.");
      $this->logger()->notice("NOTE: Paragraphs created but not attached to node. Attach manually via the node edit form.");
    }

    return 0;
  }

  /**
   * Report FAQ/accordion content coverage for every routed sub-topic.
   *
   * Read-only. For each topic_map.yml sub-topic, counts published faq_item
   * and accordion_item paragraphs whose heading (strong) or body (weak)
   * mentions the sub-topic, whether a Top Intents pack answer exists, and
   * how many kb-stubs are still awaiting attorney sign-off.
   *
   * @param array $options
   *   Command options.
   *
   * @command ilas:kb-coverage
   * @aliases kb-coverage
   * @option gaps-only Only list sub-topics with thin or no content.
   * @usage ilas:kb-coverage
   *   Coverage matrix for all sub-topics.
   * @usage ilas:kb-coverage --gaps-only
   *   Only the sub-topics that need content.
   */
  public function kbCoverage(array $options = ['gaps-only' => FALSE]) {
    $module_path = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');

    try {
      $topic_map = Yaml::parseFile($module_path . '/config/routing/topic_map.yml');
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to parse topic_map.yml: ' . $e->getMessage());
      return 1;
    }

    $stubs = [];
    foreach (glob($module_path . '/config/kb-stubs/*.yml') ?: [] as $file) {
      try {
        $stubs = array_merge($stubs, Yaml::parseFile($file)['faq_stubs'] ?? []);
      }
      catch (\Exception $e) {
        $this->logger()->warning('Skipped unparseable stub file ' . basename($file));
      }
    }

    $documents = $this->loadCoverageDocuments();
    $rows = KbCoverageAnalyzer::analyze(
      is_array($topic_map) ? $topic_map : [],
      $documents,
      \Drupal::service('ilas_site_assistant.top_intents_pack')->getAllKeys(),
      $stubs,
    );

    $counts = array_count_values(array_column($rows, 'status')) + [
      KbCoverageAnalyzer::STATUS_OK => 0,
      KbCoverageAnalyzer::STATUS_THIN => 0,
      KbCoverageAnalyzer::STATUS_NONE => 0,
    ];
    $labels = [
      KbCoverageAnalyzer::STATUS_OK => 'ok',
      KbCoverageAnalyzer::STATUS_THIN => 'THIN (body mentions only)',
      KbCoverageAnalyzer::STATUS_NONE => 'NO CONTENT',
    ];

    $table = [];
    foreach ($rows as $row) {
      if ($options['gaps-only'] && $row['status'] === KbCoverageAnalyzer::STATUS_OK) {
        continue;
      }
      $table[] = [
        $row['area'],
        $row['topic'],
        $row['has_pack'] ? 'yes' : 'no',
        $row['faq_heading'],
        $row['accordion_heading'],
        $row['body_only'],
        $row['stubs_pending'],
        $labels[$row['status']],
      ];
    }

    $this->io()->table(
      ['Service area', 'Sub-topic', 'Pack answer', 'FAQ headings', 'Accordion headings', 'Body-only', 'Stubs pending', 'Coverage'],
      $table,
    );
    $this->logger()->notice(sprintf(
      'Scanned %d distinct published FAQ/accordion items against %d sub-topics: %d ok, %d thin, %d with no content. %d sub-topics have no pack answer.',
      count($documents),
      count($rows),
      $counts[KbCoverageAnalyzer::STATUS_OK],
      $counts[KbCoverageAnalyzer::STATUS_THIN],
      $counts[KbCoverageAnalyzer::STATUS_NONE],
      count(array_filter($rows, static fn(array $row): bool => !$row['has_pack'])),
    ));

    return 0;
  }

  /**
   * Loads heading/body text of published FAQ and accordion paragraphs.
   */
  protected function loadCoverageDocuments(): array {
    $fields = [
      'faq_item' => ['field_faq_question', 'field_faq_answer'],
      'accordion_item' => ['field_accordion_title', 'field_accordion_body'],
    ];
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', array_keys($fields), 'IN')
      ->condition('status', 1)
      ->execute();

    // Each language version of a page (en/es/nl/sw) owns its own copy of the
    // page's accordion items, so the same item exists as up to four
    // paragraph entities. Count distinct content, not entities: one question
    // answered in four language versions is still one answer.
    $documents = [];
    foreach (array_chunk($ids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $paragraph) {
        [$heading_field, $body_field] = $fields[$paragraph->bundle()];
        $heading = $paragraph->hasField($heading_field) ? (string) $paragraph->get($heading_field)->value : '';
        $body = $paragraph->hasField($body_field) ? (string) $paragraph->get($body_field)->value : '';
        $documents[$paragraph->bundle() . '|' . md5($heading . "\0" . $body)] = [
          'bundle' => $paragraph->bundle(),
          'heading' => $heading,
          'body' => $body,
        ];
      }
      $storage->resetCache($chunk);
    }
    return array_values($documents);
  }

  /**
   * List available KB stub files.
   *
   * @command ilas:kb-list
   * @aliases kb-list
   */
  public function kbList() {
    $module_path = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');
    $stub_dir = $module_path . '/config/kb-stubs';

    if (!is_dir($stub_dir)) {
      $this->logger()->notice("No kb-stubs directory found.");
      return;
    }

    $files = glob($stub_dir . '/*.yml');
    if (empty($files)) {
      $this->logger()->notice("No YAML files found in kb-stubs directory.");
      return;
    }

    $this->logger()->notice("Available KB stub files:");
    foreach ($files as $file) {
      $this->logger()->notice("  - " . basename($file));

      try {
        $data = Yaml::parseFile($file);
        if (!empty($data['faq_stubs'])) {
          $total = count($data['faq_stubs']);
          $blocked = count(KbStubValidator::blockedStubs($data['faq_stubs']));
          $this->logger()->notice("    Contains {$total} FAQ stubs ({$blocked} awaiting attorney sign-off, " . ($total - $blocked) . " importable)");
        }
      }
      catch (\Exception $e) {
        $this->logger()->warning("    Failed to parse: " . $e->getMessage());
      }
    }
  }

}
