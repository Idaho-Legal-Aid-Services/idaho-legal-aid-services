<?php

/**
 * @file
 * Creates redirects for the high-confidence legacy-file rows of §8.4.
 *
 * Source: docs/pantheon-cloudflare-preimplementation-validation.md §8.4
 * ("Broken legacy document and file links"), tracker item 7.
 *
 * Scope is deliberately narrow. Only the nine actionable high-confidence rows
 * are created. The three high-confidence "leave 404" rows (stale OG/D7 image
 * derivatives) and the ten editorially-gated rows are NOT touched — see
 * docs/legacy-file-content-owner-review.md.
 *
 * This is not a blanket prefix rewrite. Every destination below was resolved
 * individually against the origin and confirmed to return 200.
 *
 * Modes:
 *   --dry-run              Print the plan. Writes nothing.
 *   --apply                Create the redirects. Prints each new rid.
 *   --export-state PATH    Write the pre-change snapshot as JSON.
 *   --rollback PATH        Delete the redirects recorded in a state file.
 *
 * Run with:
 *   terminus drush <site>.<env> -- php:script scripts/seo/legacy-file-redirects-8-4.php -- --dry-run
 *
 * Or, without deploying, over php:eval. Strip the opening tag first — eval()
 * takes code, not a file — and base64-wrap to dodge the terminus quoting trap:
 *   B64=$(sed '1s/^<?php//' scripts/seo/legacy-file-redirects-8-4.php | base64 -w0)
 *   terminus drush <site>.<env> -- php:eval \
 *     "\$GLOBALS['ILAS_LEGACY_REDIRECT_ARGS']=['--dry-run']; eval(base64_decode('\$B64'));"
 */

// ---------------------------------------------------------------------------
// The nine rows. Sources are stored decoded and without a leading slash, which
// is how the redirect module stores them and how the 70 pre-existing legacy
// rows on live are stored.
// ---------------------------------------------------------------------------
if (!defined('ILAS_LEGACY_PREFIX')) {
  define('ILAS_LEGACY_PREFIX', 'sites/idaholegalaid.org/files/');
}

// 'validate' => FALSE marks a destination the applier's alias-based validator
// cannot see. Those are checked separately below via the router, never waved
// through blindly.
$entries = [
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'Protections for Debit Card and Electronic Transactions Fact Sheet.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/debit-card-electronic-transactions-protections-fact-sheet.pdf',
    'note' => 'Highest-volume row in the report window (349/7d).',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'mortgage_interestonly.pdf',
    'proposed_destination' => '/forms',
    'validate' => FALSE,
    'note' => 'Superseded; no interest-only mortgage document in the modern set. Report rates High. /forms is a View route (view.forms_categories.page_1), not a path alias, so it is router-checked instead.',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'What is Community Property Guide - FINAL.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/community-property-guide.pdf',
    'note' => '',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'Caregiving Brochure - Final.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/caregiving-brochure.pdf',
    'note' => '',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'Decision Making As We Age Brochure.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/decision-making-as-we-age-brochure.pdf',
    'note' => '',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'bankruptcy brochure english.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/bankruptcy-brochure.pdf',
    'note' => '',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'What is Normal Wear and Tear Guide.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/normal-wear-tear-guide.pdf',
    'note' => '',
  ],
  [
    'old_path' => ILAS_LEGACY_PREFIX . 'LRC Relocation Guide for Domestic Violence Survivors.pdf',
    'proposed_destination' => '/sites/default/files/2025-12/domestic-violence-survivors-relocation-guide.pdf',
    'note' => 'Report abbreviates this as "…for DV Survivors.pdf"; the real filename is spelled out.',
  ],
];

// §8.4 lists `MANUFACTURED HOMES.brochure.pdf` as a high-confidence row pointing
// at /sites/default/files/2025-12/advice-for-renters-manufactured-homes.pdf. It
// was created (rid 5159) and then removed on verification: that destination
// carries XMP ModifyDate 2013-09-05, seven years OLDER than the 2020-02-24 file
// the legacy URL used to serve, and a newer manufactured-homes document
// (manufactured-homes-advice-renters.pdf, 2025-12-23) exists on the site.
// Redirecting to a superseded edition of legal self-help material is exactly
// what the brief forbids, so the row is deferred for a legal-currency read.
// See docs/legacy-file-content-owner-review.md. Do not re-add without one.

// Paths the report marks high-confidence but explicitly "leave 404". Listed so
// the script can assert it never created a redirect for them.
$leave_404 = [
  ILAS_LEGACY_PREFIX . 'styles/logo_for_og/public/ilas-logo-100.png',
  ILAS_LEGACY_PREFIX . 'images/LSClogo_0.jpeg',
  ILAS_LEGACY_PREFIX . 'ILAS_60x60.jpg',
];

// The deferred /files/html/ content rows. Not modified — captured in the state
// export so the editorial queue has an accurate "before".
$deferred_content = [
  ['entity_type' => 'paragraph', 'ids' => [88, 801, 1064, 1327], 'table' => 'paragraph__field_external_link', 'columns' => ['field_external_link_uri', 'field_external_link_title']],
  ['entity_type' => 'paragraph', 'ids' => [132, 760, 1023, 1286], 'table' => 'paragraph__field_accordion_body', 'columns' => ['field_accordion_body_value']],
];

// ---------------------------------------------------------------------------
// Argument handling. Works under php:script (Drush sets $extra) and under
// php:eval (set $GLOBALS['ILAS_LEGACY_REDIRECT_ARGS']).
// ---------------------------------------------------------------------------
$args = [];
if (!empty($GLOBALS['ILAS_LEGACY_REDIRECT_ARGS']) && is_array($GLOBALS['ILAS_LEGACY_REDIRECT_ARGS'])) {
  $args = $GLOBALS['ILAS_LEGACY_REDIRECT_ARGS'];
}
elseif (isset($extra) && is_array($extra)) {
  $args = $extra;
}

$mode = 'dry-run';
$state_path = '';
foreach ($args as $i => $arg) {
  if ($arg === '--apply') {
    $mode = 'apply';
  }
  elseif ($arg === '--dry-run') {
    $mode = 'dry-run';
  }
  elseif ($arg === '--export-state') {
    $mode = 'export-state';
    $state_path = $args[$i + 1] ?? '';
  }
  elseif ($arg === '--rollback') {
    $mode = 'rollback';
    $state_path = $args[$i + 1] ?? '';
  }
}

$database = \Drupal::database();
$entity_type_manager = \Drupal::entityTypeManager();

echo "§8.4 legacy-file redirects — mode: {$mode}\n";
echo str_repeat('=', 72) . "\n\n";

/**
 * Collects the current redirect/404 state for the nine sources.
 */
$snapshot = function () use ($database, $entries, $leave_404, $deferred_content) {
  $rows = [];
  foreach ($entries as $entry) {
    $existing = $database->select('redirect', 'r')
      ->fields('r', ['rid', 'redirect_redirect__uri', 'status_code'])
      ->condition('redirect_source__path', $entry['old_path'])
      ->execute()
      ->fetchAll();
    $rows[] = [
      'old_path' => $entry['old_path'],
      'proposed_destination' => $entry['proposed_destination'],
      'existing_redirects' => array_map(static fn($r) => [
        'rid' => (int) $r->rid,
        'uri' => $r->redirect_redirect__uri,
        'status_code' => (int) $r->status_code,
      ], $existing),
    ];
  }

  $leave_404_state = [];
  foreach ($leave_404 as $path) {
    $leave_404_state[$path] = (int) $database->select('redirect', 'r')
      ->condition('redirect_source__path', $path)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  $r404 = $database->select('redirect_404', 'r')
    ->fields('r', ['path', 'count', 'resolved'])
    ->condition('path', '%idaholegalaid.org/files%', 'LIKE')
    ->execute()
    ->fetchAll();

  // Baseline for the deferred editorial rows. Not modified by this script.
  $content_baseline = [];
  foreach ($deferred_content as $group) {
    foreach ($group['ids'] as $id) {
      $select = $database->select($group['table'], 't')
        ->fields('t', array_merge(['entity_id', 'revision_id', 'bundle', 'langcode', 'delta'], $group['columns']))
        ->condition('entity_id', $id);
      foreach ($select->execute()->fetchAll() as $row) {
        $content_baseline[] = ['table' => $group['table']] + (array) $row;
      }
    }
  }

  return [
    'entries' => $rows,
    'leave_404_redirect_counts' => $leave_404_state,
    'redirect_404_legacy_rows' => array_map(static fn($r) => (array) $r, $r404),
    'redirect_total' => (int) $database->select('redirect', 'r')->countQuery()->execute()->fetchField(),
    'redirect_legacy_prefix_total' => (int) $database->select('redirect', 'r')
      ->condition('redirect_source__path', ILAS_LEGACY_PREFIX . '%', 'LIKE')
      ->countQuery()->execute()->fetchField(),
    'content_entities_changed' => 0,
    'content_entities_changed_evidence' => 'Whole-database scan of all text/varchar/blob columns found zero current-content rows containing "sites/idaholegalaid.org/files". Nothing to revise, so no content revisions are created. See docs/legacy-file-content-owner-review.md.',
    'deferred_content_baseline' => $content_baseline,
  ];
};

// ---------------------------------------------------------------------------
// export-state
// ---------------------------------------------------------------------------
if ($mode === 'export-state') {
  if ($state_path === '') {
    echo "ERROR: --export-state requires a path.\n";
    return;
  }
  $state = $snapshot();
  $state['captured_at'] = date('c');
  $state['created_rids'] = [];
  file_put_contents($state_path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  echo "Wrote state to {$state_path}\n";
  echo "  redirect total:            {$state['redirect_total']}\n";
  echo "  legacy-prefix redirects:   {$state['redirect_legacy_prefix_total']}\n";
  echo "  deferred content rows:     " . count($state['deferred_content_baseline']) . "\n";
  echo "  content entities changed:  {$state['content_entities_changed']}\n";
  return;
}

// ---------------------------------------------------------------------------
// rollback
// ---------------------------------------------------------------------------
if ($mode === 'rollback') {
  if ($state_path === '' || !file_exists($state_path)) {
    echo "ERROR: --rollback requires an existing state file.\n";
    return;
  }
  $state = json_decode(file_get_contents($state_path), TRUE);
  $rids = $state['created_rids'] ?? [];
  if (!$rids) {
    echo "State file records no created rids. Nothing to roll back.\n";
    return;
  }
  $storage = $entity_type_manager->getStorage('redirect');
  $deleted = 0;
  foreach ($rids as $rid) {
    $redirect = $storage->load($rid);
    if (!$redirect) {
      echo "  rid {$rid}: already gone\n";
      continue;
    }
    echo "  rid {$rid}: deleting {$redirect->getSourceUrl()}\n";
    $redirect->delete();
    $deleted++;
  }
  $after = (int) $database->select('redirect', 'r')
    ->condition('redirect_source__path', ILAS_LEGACY_PREFIX . '%', 'LIKE')
    ->countQuery()->execute()->fetchField();
  echo "\nDeleted {$deleted}. Legacy-prefix redirects now: {$after}";
  echo " (expected {$state['redirect_legacy_prefix_total']}).\n";
  echo "Content revisions: none to restore — this change never modified content.\n";
  return;
}

// ---------------------------------------------------------------------------
// dry-run / apply
// ---------------------------------------------------------------------------
$applier = \Drupal::service('ilas_redirect_automation.applier');
$dry_run = ($mode !== 'apply');

// Guard: refuse to run if any leave-404 path has picked up a redirect.
foreach ($leave_404 as $path) {
  $count = (int) $database->select('redirect', 'r')
    ->condition('redirect_source__path', $path)
    ->countQuery()->execute()->fetchField();
  if ($count > 0) {
    echo "ABORT: {$path} must stay 404 but already has {$count} redirect(s).\n";
    return;
  }
}
echo "Guard OK: the three intentional-404 paths carry no redirects.\n\n";

// Split by how the destination can be proven to exist. Most rows resolve
// through the applier's own validator (path_alias lookup, or file_exists under
// DRUPAL_ROOT for document destinations). A destination served by a View route
// has no alias row, so the validator would reject it; those are proven here
// against the router instead of skipping the check.
$validated = array_values(array_filter($entries, static fn($e) => ($e['validate'] ?? TRUE)));
$router_checked = array_values(array_filter($entries, static fn($e) => !($e['validate'] ?? TRUE)));

$path_validator = \Drupal::service('path.validator');
foreach ($router_checked as $entry) {
  $destination = $entry['proposed_destination'];
  $url = $path_validator->getUrlIfValidWithoutAccessCheck($destination);
  if (!$url || !$url->isRouted()) {
    echo "ABORT: {$destination} is not a routed path.\n";
    return;
  }
  echo "Router check OK: {$destination} -> {$url->getRouteName()}\n";
}
echo "\n";

$results = $applier->applyFromEntries($validated, 301, $dry_run, FALSE);
if ($router_checked) {
  $extra_results = $applier->applyFromEntries($router_checked, 301, $dry_run, TRUE);
  foreach (['created', 'skipped', 'errors'] as $bucket) {
    $results[$bucket] = array_merge($results[$bucket], $extra_results[$bucket]);
  }
}

$created_rids = [];
echo "Created (" . count($results['created']) . "):\n";
foreach ($results['created'] as $row) {
  $rid = $row['redirect_id'] ?? NULL;
  if ($rid) {
    $created_rids[] = (int) $rid;
  }
  $label = $rid ? "rid {$rid}" : ($row['note'] ?? 'would create');
  echo "  [{$label}] {$row['entry']['old_path']}\n";
  echo "      -> {$row['entry']['proposed_destination']}\n";
}

echo "\nSkipped (" . count($results['skipped']) . "):\n";
foreach ($results['skipped'] as $row) {
  echo "  {$row['entry']['old_path']} — {$row['reason']}\n";
}

echo "\nErrors (" . count($results['errors']) . "):\n";
foreach ($results['errors'] as $row) {
  echo "  {$row['entry']['old_path']} — {$row['reason']}\n";
}

if (!$dry_run && $created_rids) {
  echo "\ncreated_rids: " . implode(',', $created_rids) . "\n";
  echo "Record these in the state file so --rollback can undo them.\n";
}

$legacy_total = (int) $database->select('redirect', 'r')
  ->condition('redirect_source__path', ILAS_LEGACY_PREFIX . '%', 'LIKE')
  ->countQuery()->execute()->fetchField();
echo "\nLegacy-prefix redirects now: {$legacy_total}\n";
echo "Content entities changed: 0 (nothing in current content references the legacy prefix).\n";
