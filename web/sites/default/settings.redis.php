<?php

/**
 * @file
 * Redis object cache wiring for Pantheon and DDEV.
 *
 * Included from settings.php. Activates only when a Redis service is
 * actually reachable for the current environment; everywhere else (CI,
 * plain checkouts) every setting below is skipped and Drupal stays on the
 * database cache backend.
 *
 * Deliberate deviations from Pantheon's stock snippet:
 * - flood + lock stay on the database: the assistant's rate-limit guards
 *   (session_bootstrap_guard, read_endpoint_guard) and
 *   LlmAdmissionCoordinator must not lose records to LRU eviction.
 * - The ilas_site_assistant bin stays on the database: SelectionStateStore
 *   keeps per-conversation state there, which must survive memory pressure.
 * - Only the cache-tags checksum service moves to Redis (services.redis.yml)
 *   instead of the module's example.services.yml, which would also move
 *   flood and lock.
 */

$ilas_redis_host = NULL;
$ilas_redis_port = NULL;
$ilas_redis_password = NULL;

if (defined('PANTHEON_ENVIRONMENT') && !empty($_ENV['CACHE_HOST'])) {
  $ilas_redis_host = $_ENV['CACHE_HOST'];
  $ilas_redis_port = $_ENV['CACHE_PORT'];
  $ilas_redis_password = $_ENV['CACHE_PASSWORD'];
}
elseif (getenv('IS_DDEV_PROJECT') === 'true' && gethostbyname('redis') !== 'redis') {
  // ddev-redis add-on service; no auth inside the project network.
  $ilas_redis_host = 'redis';
  $ilas_redis_port = 6379;
}

if ($ilas_redis_host !== NULL
  && extension_loaded('redis')
  && !\Drupal\Core\Installer\InstallerKernel::installationAttempted()
  && is_dir(__DIR__ . '/../../modules/contrib/redis/src')) {

  // PhpRedis is built into the Pantheon application container and the DDEV
  // web image.
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = $ilas_redis_host;
  $settings['redis.connection']['port'] = $ilas_redis_port;
  if ($ilas_redis_password !== NULL) {
    $settings['redis.connection']['password'] = $ilas_redis_password;
  }

  // Redis as the default backend for any bin not pinned below.
  $settings['cache']['default'] = 'cache.backend.redis';

  // Bins that must survive LRU eviction stay on the database.
  $settings['cache']['bins']['form'] = 'cache.backend.database';
  $settings['cache']['bins']['ilas_site_assistant'] = 'cache.backend.database';

  $settings['redis_compress_length'] = 100;
  $settings['redis_compress_level'] = 1;

  $settings['cache_prefix']['default'] = 'pantheon-redis';

  // TTLs per Pantheon guidance (default PERM entries would live 1 year).
  $settings['redis.settings']['perm_ttl'] = 2630000;
  $settings['redis.settings']['perm_ttl_config'] = 43200;
  $settings['redis.settings']['perm_ttl_data'] = 43200;
  $settings['redis.settings']['perm_ttl_default'] = 43200;
  $settings['redis.settings']['perm_ttl_entity'] = 172800;

  // Register the module services (redis.factory, cache.backend.redis) even
  // before the module is enabled, plus the checksum-only override.
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';
  $settings['container_yamls'][] = __DIR__ . '/services.redis.yml';
}
