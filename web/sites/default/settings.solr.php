<?php

/**
 * @file
 * Per-environment Solr connector overrides for the pantheon_search server.
 *
 * The committed server config (config/search_api.server.pantheon_search.yml)
 * is the Pantheon truth: connector "pantheon", Solr 8, endpoint resolved from
 * Pantheon-injected env vars. On DDEV the ddev-solr add-on provides a Solr
 * Cloud service at solr:8983 with basic auth, so the connector is swapped at
 * runtime. CI and plain checkouts get no override; the Solr server simply
 * stays unreachable there, and nothing consumes it outside Pantheon/DDEV.
 */

if (getenv('IS_DDEV_PROJECT') === 'true' && !defined('PANTHEON_ENVIRONMENT')) {
  $config['search_api.server.pantheon_search']['backend_config']['connector'] = 'solr_cloud_basic_auth';
  $config['search_api.server.pantheon_search']['backend_config']['connector_config'] = [
    'scheme' => 'http',
    'host' => 'solr',
    'port' => 8983,
    'path' => '/',
    'core' => 'ilas',
    'checkpoints_collection' => '',
    'timeout' => 5,
    'index_timeout' => 5,
    'optimize_timeout' => 10,
    'finalize_timeout' => 30,
    'solr_version' => '',
    'http_method' => 'AUTO',
    'commit_within' => 1000,
    'jmx' => FALSE,
    'jts' => FALSE,
    'solr_install_dir' => '',
    'skip_schema_check' => FALSE,
    'username' => 'solr',
    'password' => 'SolrRocks',
  ];
}
