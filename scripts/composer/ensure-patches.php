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
 *
 * Direction detection uses patch(1) with an explicit --forward, NOT git apply.
 * git apply resolves patched paths against the repository toplevel and, per
 * git-apply(1), "when running from a subdirectory in a repository, patched
 * paths outside the directory are ignored" — so `git -C web/modules/contrib/x
 * apply -p1` prints "Skipped patch" and exits 0 for a patch whose paths are
 * package-relative. Both the forward and the reverse check therefore succeeded
 * vacuously, the first branch won, and this guard reported "OK (already
 * applied)" for every patch in the lockfile whatever the real state was
 * (observed 2026-07-30: drupal/token shipped unpatched to dev while this
 * script printed all-clear and exited 0).
 *
 * patch(1) is direction-deterministic as used here: --forward makes it refuse
 * an already-applied patch instead of silently reversing it, which is the
 * behaviour the earlier git-apply comment was written to avoid.
 *
 * Every apply is also re-verified afterwards, so a patcher that reports
 * success without changing the file can no longer pass.
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

    // See the file docblock for why this is patch(1) and not git apply.
    $run = static function (bool $reverse, bool $dry_run) use ($install_path, $depth, $patch_file): int {
      $cmd = sprintf(
        'patch -p%d -d %s --forward%s%s < %s 2>&1',
        $depth,
        escapeshellarg($install_path),
        $reverse ? ' --reverse' : '',
        $dry_run ? ' --dry-run' : '',
        escapeshellarg($patch_file)
      );
      exec($cmd, $out, $rc);
      return $rc;
    };

    // Already applied? (the patch applies cleanly in reverse)
    if ($run(TRUE, TRUE) === 0) {
      fwrite(STDOUT, "ensure-patches: OK (already applied) {$package}: {$description}\n");
      continue;
    }

    // Applies forward? Then the plugin skipped it — apply now.
    if ($run(FALSE, TRUE) === 0 && $run(FALSE, FALSE) === 0) {
      // Re-verify rather than trust the exit code: an apply that reports
      // success without changing the file is the failure mode this whole
      // guard exists to catch.
      if ($run(TRUE, TRUE) === 0) {
        fwrite(STDOUT, "ensure-patches: APPLIED {$package}: {$description}\n");
        continue;
      }
      fwrite(STDERR, "ensure-patches: FAILED {$package}: {$description} (apply reported success but the patch is not present)\n");
      $failures++;
      continue;
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
