<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the RAUD-21 lexical Search API index repair update hook.
 *
 */
#[Group('ilas_site_assistant')]
final class RetrievalIndexUpdateHookKernelTest extends KernelTestBase {

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
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
  ];

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
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant']);
  }

  /**
   * The update hook recreates missing lexical indexes from canonical config.
   */
  public function testUpdate10009RecreatesMissingLexicalIndexes(): void {
    require_once $this->modulePath() . '/ilas_site_assistant.install';

    $index_storage = $this->container->get('entity_type.manager')->getStorage('search_api_index');
    $retrieval_before = $this->config('ilas_site_assistant.settings')->get('retrieval');

    foreach (['faq_accordion', 'assistant_resources'] as $index_id) {
      $index = $index_storage->load($index_id);
      $this->assertNotNull($index, sprintf('Expected lexical Search API index "%s" to exist before deletion.', $index_id));
      $index->delete();
      $this->assertNull($index_storage->load($index_id), sprintf('Expected lexical Search API index "%s" to be deleted.', $index_id));
    }

    $message = ilas_site_assistant_update_10009();

    foreach (['faq_accordion', 'assistant_resources'] as $index_id) {
      $index = $index_storage->load($index_id);
      $this->assertNotNull($index, sprintf('Update 10009 must recreate lexical Search API index "%s".', $index_id));

      $expected = $this->canonicalIndexDefinition($index_id);
      $this->assertSame($expected['uuid'], $index->uuid(), sprintf('Index "%s" must reuse the canonical UUID.', $index_id));
      $this->assertSame($expected['server'], $index->getServerId(), sprintf('Index "%s" must use the canonical Search API server.', $index_id));
      $this->assertSame($expected['id'], $index->id());
    }

    $this->assertSame(
      $retrieval_before,
      $this->config('ilas_site_assistant.settings')->get('retrieval'),
      'Update 10009 must not alter retrieval config.'
    );
    $this->assertStringContainsString('faq_accordion, assistant_resources', (string) $message);
  }

  /**
   * Returns the canonical active-sync definition for one lexical index.
   */
  private function canonicalIndexDefinition(string $index_id): array {
    return Yaml::decode(file_get_contents($this->repoRoot() . '/config/search_api.index.' . $index_id . '.yml'));
  }

  /**
   * Returns the repository root.
   */
  private function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Returns the ilas_site_assistant module path.
   */
  private function modulePath(): string {
    return $this->repoRoot() . '/web/modules/custom/ilas_site_assistant';
  }

}
