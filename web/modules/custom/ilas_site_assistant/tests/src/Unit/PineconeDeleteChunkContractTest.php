<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ai_vdb_provider_pinecone\Request\ListVectors;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Probots\Pinecone\Client as PineconeClient;

/**
 * Guards the repo patch that makes Pinecone per-item deletes find chunk IDs.
 *
 * The AI Search backend stores vectors as "<item id>:<chunk>" while the
 * unpatched provider fetched by the bare item ID, so every delete was a
 * silent no-op and stale vectors survived forever
 * (patches/ai-vdb-provider-pinecone-delete-chunk-ids.patch).
 */
#[Group('ilas_site_assistant')]
class PineconeDeleteChunkContractTest extends TestCase {

  public function testListVectorsRequestTargetsTheListEndpointWithPrefixAndPagination(): void {
    self::assertTrue(
      class_exists(ListVectors::class),
      'ListVectors request is unavailable. Run composer install so the delete-chunk-ids Pinecone patch applies.'
    );

    $client = new PineconeClient('test-api-key', 'https://example-pinecone.test');
    $client->data();
    $pending_request = $client->createPendingRequest(new ListVectors(
      namespace: 'faq_accordion_vector-dev',
      prefix: 'entity:paragraph/402:en:',
      limit: 500,
      paginationToken: 'next-token',
    ));

    $this->assertSame('GET', $pending_request->getMethod()->value);
    $this->assertSame('https://example-pinecone.test/vectors/list', $pending_request->getUrl());
    $this->assertSame([
      'limit' => 100,
      'namespace' => 'faq_accordion_vector-dev',
      'prefix' => 'entity:paragraph/402:en:',
      'paginationToken' => 'next-token',
    ], $pending_request->query()->all());
  }

  public function testListVectorsOmitsEmptyOptionalParameters(): void {
    $client = new PineconeClient('test-api-key', 'https://example-pinecone.test');
    $client->data();
    $pending_request = $client->createPendingRequest(new ListVectors(namespace: 'faq_accordion_vector'));

    $this->assertSame(['limit' => 100, 'namespace' => 'faq_accordion_vector'], $pending_request->query()->all());
  }

  public function testProviderResolvesChunkIdsByPrefixBeforeDeleting(): void {
    // Source-level checks: the pure bootstrap cannot autoload the AI module
    // base classes these two classes extend.
    $module_root = $this->repoRoot() . '/web/modules/contrib/ai_vdb_provider_pinecone';
    $wrapper_source = file_get_contents($module_root . '/src/Pinecone.php');
    $provider_source = file_get_contents($module_root . '/src/Plugin/VdbProvider/PineconeProvider.php');
    self::assertIsString($wrapper_source);
    self::assertIsString($provider_source);

    $this->assertStringContainsString('public function listIdsByPrefix(', $wrapper_source, 'Pinecone::listIdsByPrefix() is missing; the delete-chunk-ids patch is not applied.');
    $list_by_prefix = $this->methodSource($wrapper_source, 'listIdsByPrefix');
    $this->assertStringContainsString('new ListVectors(', $list_by_prefix);
    $this->assertStringContainsString("['pagination']['next']", $list_by_prefix, 'listIdsByPrefix() must follow pagination.');

    $get_vdb_ids = $this->methodSource($provider_source, 'getVdbIds');
    $this->assertStringContainsString('listIdsByPrefix(', $get_vdb_ids, 'getVdbIds() must discover chunk IDs by prefix.');
    $this->assertStringContainsString("\$drupal_id . ':'", $get_vdb_ids, 'The prefix must end with a colon so item IDs that share a numeric prefix do not collide.');
    $this->assertStringContainsString('$this->fetch(', $get_vdb_ids, 'The exact-ID fetch must remain as a fallback for unsuffixed vectors.');

    $delete_items = $this->methodSource($provider_source, 'deleteItems');
    $this->assertStringContainsString('array_chunk($vdbIds, 1000)', $delete_items, 'deleteItems() must respect the 1000-ID Pinecone delete limit.');
  }

  private function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  private function methodSource(string $source, string $method): string {
    $start = strpos($source, 'public function ' . $method . '(');
    self::assertNotFalse($start, "Method $method not found.");
    $end = strpos($source, "\n  }\n", $start);
    self::assertNotFalse($end);
    return substr($source, $start, $end - $start);
  }

}
