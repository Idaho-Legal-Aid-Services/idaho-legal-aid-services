<?php

/**
 * @file
 * Lists Pinecone vector IDs by ID prefix in one namespace. Read-only.
 *
 * The AI Search backend stores one vector per chunk as
 * "<search_api item id>:<chunk>", so listing by "<item id>:" shows exactly
 * which chunks exist for an item. Use it to confirm stale vectors before a
 * cleanup and to prove they are gone afterwards.
 *
 * Usage (local):
 *   ddev drush php:script --script-path=/var/www/html/scripts/vector pinecone-list-prefix.php -- auto:faq 'entity:paragraph/402:'
 *
 * Usage (Pantheon):
 *   terminus remote:drush idaho-legal-aid-services.<env> -- php:script --script-path=/code/scripts/vector pinecone-list-prefix.php -- auto:faq 'entity:paragraph/402:'
 *
 * Arguments:
 *   1. Namespace: "auto:faq" or "auto:resource" resolves the effective
 *      namespace (including the per-environment suffix from settings.php)
 *      from the Search API server config. Any other value is used verbatim.
 *   2. Prefix (optional): vector ID prefix. Omit to list the whole namespace
 *      (paginated by 100; large namespaces take a while).
 */

use Drupal\search_api\Entity\Server;

$args = isset($extra) && is_array($extra) ? array_values($extra) : [];
$namespace_arg = (string) ($args[0] ?? '');
$prefix = (string) ($args[1] ?? '');

if ($namespace_arg === '') {
  fwrite(STDERR, "Usage: pinecone-list-prefix.php <auto:faq|auto:resource|namespace> [prefix]\n");
  return;
}

$server_ids = [
  'auto:faq' => 'pinecone_vector_faq',
  'auto:resource' => 'pinecone_vector_resources',
];
$server = Server::load($server_ids[$namespace_arg] ?? 'pinecone_vector_faq');
if (!$server) {
  fwrite(STDERR, "Search API server not found.\n");
  return;
}
$database_settings = $server->getBackendConfig()['database_settings'] ?? [];
$index_name = (string) ($database_settings['database_name'] ?? '');
$namespace = isset($server_ids[$namespace_arg])
  ? (string) ($database_settings['collection'] ?? '')
  : $namespace_arg;

if ($index_name === '' || $namespace === '') {
  fwrite(STDERR, "Could not resolve the Pinecone index name or namespace from server config.\n");
  return;
}

/** @var \Drupal\ai_vdb_provider_pinecone\Plugin\VdbProvider\PineconeProvider $provider */
$provider = \Drupal::service('ai.vdb_provider')->createInstance('pinecone');
$ids = $provider->getClient()->listIdsByPrefix($namespace, $prefix, $index_name);

printf("index=%s namespace=%s prefix=%s matches=%d\n", $index_name, $namespace, $prefix === '' ? '(none)' : $prefix, count($ids));
foreach ($ids as $id) {
  echo $id, PHP_EOL;
}
