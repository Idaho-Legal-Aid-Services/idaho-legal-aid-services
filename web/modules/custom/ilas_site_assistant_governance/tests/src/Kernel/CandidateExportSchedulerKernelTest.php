<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\CandidateExportScheduler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for the weekly promptfoo candidate export.
 */
#[Group('ilas_site_assistant_governance')]
#[RunTestsInSeparateProcesses]
final class CandidateExportSchedulerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'options',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
    'ilas_site_assistant_governance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // Kernel tests have no private file system by default; the scheduler
    // refuses to run without one. The setting must exist before the kernel
    // boots so the private:// stream wrapper gets registered.
    $private = $this->siteDirectory . '/private';
    if (!is_dir($private)) {
      mkdir($private, 0777, TRUE);
    }
    $this->setSetting('file_private_path', $private);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('assistant_gap_item');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant', 'ilas_site_assistant_governance']);
    $this->installSchema('ilas_site_assistant_governance', [
      'ilas_site_assistant_conversation_session',
      'ilas_site_assistant_conversation_turn',
      'ilas_site_assistant_gap_hit',
      'ilas_site_assistant_legal_hold',
    ]);
  }

  /**
   * The first run writes the candidate file; a second run within a week skips.
   */
  public function testRunsWeeklyAndWritesPrivateFile(): void {
    $this->createResolvedItem('resolved-1', 'My landlord tried to enter my home without notice');

    /** @var \Drupal\ilas_site_assistant_governance\Service\CandidateExportScheduler $scheduler */
    $scheduler = $this->container->get('ilas_site_assistant_governance.candidate_export_scheduler');
    $this->assertTrue($scheduler->isDue(), 'Never ran: due');
    $this->assertNull($scheduler->getRealPath());

    $summary = $scheduler->runIfDue();
    $this->assertIsArray($summary);
    $this->assertTrue($summary['written'], (string) ($summary['error'] ?? ''));
    $this->assertNull($summary['error']);
    $this->assertSame(1, $summary['considered']);
    $this->assertSame(1, $summary['candidates']);

    $path = $scheduler->getRealPath();
    $this->assertNotNull($path);
    $yaml = (string) file_get_contents($path);
    $this->assertStringContainsString('Human review is required', $yaml);
    $this->assertStringContainsString('My landlord tried to enter my home without notice', $yaml);
    $this->assertStringContainsString('Reviewed gap candidate', $yaml);

    $this->assertFalse($scheduler->isDue(), 'Just ran: not due');
    $this->assertNull($scheduler->runIfDue(), 'Not due: skipped');
    $this->assertSame($summary['ran_at'], $scheduler->getLastRun()['ran_at']);

    // Forced runs always execute and refresh the file.
    $this->createResolvedItem('resolved-2', 'Can my landlord keep my deposit');
    $forced = $scheduler->runIfDue(TRUE);
    $this->assertTrue($forced['forced']);
    $this->assertSame(2, $forced['candidates']);
    $this->assertStringContainsString('Can my landlord keep my deposit', (string) file_get_contents($path));
  }

  /**
   * Cron triggers the scheduler.
   */
  public function testCronRunsExport(): void {
    $this->createResolvedItem('resolved-cron', 'Entering a residence');
    $this->container->get('cron')->run();

    /** @var \Drupal\ilas_site_assistant_governance\Service\CandidateExportScheduler $scheduler */
    $scheduler = $this->container->get('ilas_site_assistant_governance.candidate_export_scheduler');
    $this->assertNotNull($scheduler->getLastRun());
    $this->assertNotNull($scheduler->getRealPath());
    $this->assertSame(CandidateExportScheduler::DIRECTORY . '/' . CandidateExportScheduler::FILENAME, $scheduler->getUri());
  }

  /**
   * Creates a resolved gap item that the exporter will accept.
   */
  private function createResolvedItem(string $seed, string $query): AssistantGapItem {
    $storage = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item');
    $now = \Drupal::time()->getRequestTime();
    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $storage->create([
      'cluster_hash' => hash('sha256', $seed . ':cluster'),
      'query_hash' => hash('sha256', $seed . ':query'),
      'exemplar_redacted_query' => $query,
      'language_hint' => 'en',
      'query_length_bucket' => '25-99',
      'redaction_profile' => 'none',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'resolution_code' => AssistantGapItem::RESOLUTION_FAQ_CREATED,
      'resolution_reference' => 'node/50',
      'first_seen' => $now,
      'last_seen' => $now,
      'reviewed_at' => $now,
      'resolved_at' => $now,
      'occurrence_count_total' => 2,
      'occurrence_count_unresolved' => 0,
    ]);
    $entity->save();
    return $entity;
  }

}
