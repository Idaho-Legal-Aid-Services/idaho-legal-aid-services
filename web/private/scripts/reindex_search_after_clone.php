<?php

/**
 * @file
 * Quicksilver: rebuild the Solr content index after a database clone.
 *
 * Runs on the environment that received the database (clone_database, after).
 *
 * A cloned database brings the source environment's Search API tracker with
 * it, which says every item is indexed, while this environment's Solr core
 * still holds whatever was indexed here before. Until the index is rebuilt
 * the two disagree; on 2026-11-18 that left site search on dev and test
 * returning nothing, with no error anywhere. Clearing and reindexing here
 * makes a clone self-healing instead of a manual step someone has to know.
 *
 * Only the Solr index is rebuilt. The database-backed indexes travel with the
 * clone, and the vector indexes live in per-environment Pinecone namespaces
 * that a database clone does not touch.
 *
 * Web requests cannot reach this file: /private/ is in protected_web_paths.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && empty($_POST['wf_type'])) {
  // Not a Quicksilver invocation.
  return;
}

$environment = (string) ($_POST['to_environment'] ?? ($_ENV['PANTHEON_ENVIRONMENT'] ?? 'unknown'));
echo "Rebuilding content_solr on {$environment} after a database clone from "
  . (string) ($_POST['from_environment'] ?? 'unknown') . ".\n";

// The clear only removes this site's documents (it is scoped to the site
// hash, which settings.php pins so a clone cannot change it).
passthru('drush search-api:clear content_solr 2>&1', $clearStatus);
passthru('drush cache:rebuild 2>&1');
// Quicksilver scripts stop at the platform's 120 s limit. Anything not
// indexed by then stays queued in the tracker and is indexed by cron.
passthru('drush search-api:index content_solr 2>&1', $indexStatus);

echo "content_solr rebuild finished (clear={$clearStatus}, index={$indexStatus}).\n";
