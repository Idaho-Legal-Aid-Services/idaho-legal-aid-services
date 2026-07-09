<?php

/**
 * @file
 * Verifies every patch in patches.lock.json is applied; applies any missing.
 *
 * Why: cweagans/composer-patches v2 applies patches only when a package is
 * (re)installed. Pantheon Integrated Composer caches the built package tree
 * between builds, so a patch added AFTER a package landed in the build cache
 * is silently skipped (observed 2026-07-09: drupal/ai stayed unpatched on
 * dev while patches.lock.json was current). Running this from
 * post-install-cmd makes patch state deterministic on every build.
 *
 * Idempotent: a patch that no longer applies forward but applies in reverse
 * is treated as already applied. A patch that applies neither way fails the
 * build loudly rather than deploying unpatched code.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$lock_file = $root . '/patches.lock.json';

if (!is_file($lock_file)) {
  fwrite(STDOUT, "ensure-patches: no patches.lock.json, nothing to do.\n");
  exit(0);
}

$lock = json_decode((string) file_get_contents($lock_file), TRUE);
if (!is_array($lock) || empty($lock['patches'])) {
  fwrite(STDOUT, "ensure-patches: patches.lock.json has no patches.\n");
  exit(0);
}

/**
 * Resolves the install path for a package (mirrors extra.installer-paths).
 */
function ensure_patches_install_path(string $root, string $package): ?string {
  if ($package === 'drupal/core') {
    return $root . '/web/core';
  }
  [$vendor, $name] = array_pad(explode('/', $package, 2), 2, '');
  $candidates = [
    $root . '/web/modules/contrib/' . $name,
    $root . '/web/themes/contrib/' . $name,
    $root . '/web/profiles/contrib/' . $name,
    $root . '/web/libraries/' . $name,
    $root . '/vendor/' . $package,
  ];
  foreach ($candidates as $candidate) {
    if (is_dir($candidate)) {
      return $candidate;
    }
  }
  return NULL;
}

$failures = 0;
foreach ($lock['patches'] as $package => $patches) {
  $install_path = ensure_patches_install_path($root, (string) $package);
  if ($install_path === NULL) {
    fwrite(STDERR, "ensure-patches: cannot resolve install path for {$package}.\n");
    $failures++;
    continue;
  }
  foreach ($patches as $patch) {
    $url = (string) ($patch['url'] ?? '');
    $depth = (int) ($patch['depth'] ?? 1);
    $description = (string) ($patch['description'] ?? $url);
    if ($url === '' || preg_match('#^https?://#', $url)) {
      // Remote patches are outside this guard's scope.
      continue;
    }
    $patch_file = $root . '/' . $url;
    if (!is_file($patch_file)) {
      fwrite(STDERR, "ensure-patches: missing patch file {$url} for {$package}.\n");
      $failures++;
      continue;
    }

    // git apply is direction-deterministic (unlike patch, whose --batch mode
    // auto-detects reversed patches and reports success either way).
    $base = sprintf(
      'git -C %s apply --ignore-whitespace -p%d',
      escapeshellarg($install_path),
      $depth
    );
    $patch_arg = ' ' . escapeshellarg($patch_file);

    // Already applied? (reverse-apply check succeeds)
    exec($base . ' --reverse --check' . $patch_arg . ' 2>/dev/null', $o1, $rc_applied);
    if ($rc_applied === 0) {
      fwrite(STDOUT, "ensure-patches: OK (already applied) {$package}: {$description}\n");
      continue;
    }

    // Applies forward? Then the plugin skipped it — apply now.
    exec($base . ' --check' . $patch_arg . ' 2>/dev/null', $o2, $rc_forward);
    if ($rc_forward === 0) {
      exec($base . $patch_arg . ' 2>&1', $o3, $rc_apply);
      if ($rc_apply === 0) {
        fwrite(STDOUT, "ensure-patches: APPLIED {$package}: {$description}\n");
        continue;
      }
    }

    fwrite(STDERR, "ensure-patches: FAILED {$package}: {$description} (neither applied nor applicable)\n");
    $failures++;
  }
}

if ($failures > 0) {
  fwrite(STDERR, "ensure-patches: {$failures} problem(s) — failing the build rather than deploying unpatched code.\n");
  exit(1);
}
fwrite(STDOUT, "ensure-patches: all patches verified.\n");
