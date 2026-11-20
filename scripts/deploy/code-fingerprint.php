<?php

/**
 * @file
 * Prints a fingerprint of the code and packages in a checkout.
 *
 * A deploy is only real once the environment's application container runs the
 * new code. On 2026-11-19 Pantheon's "bring application container to desired
 * configuration state" step failed twice: dev kept its old custom code and
 * live got the new custom code with the previous day's packages. Database
 * updates, the config import and both smokes still passed on dev, because
 * none of them looks at which code is actually there.
 *
 * This script answers that question two ways:
 *  - code: a hash over the git blob id of every tracked source file, which
 *    can be computed from git for the commit an environment should be on and
 *    from the files on the environment itself;
 *  - packages: whether what is installed under vendor/ matches composer.lock
 *    on the same filesystem.
 *
 * The report also carries a short fingerprint per module, theme and directory
 * ("groups"), so that a mismatch names the part of the code that differs.
 *
 * Usage:
 *   php scripts/deploy/code-fingerprint.php                 files on disk
 *   php scripts/deploy/code-fingerprint.php --git-ref=REF   a commit, from git
 *   drush php:script /code/scripts/deploy/code-fingerprint.php   on Pantheon
 *   php scripts/deploy/code-fingerprint.php --git-ref=REF --compare=FILE
 *
 * Output: one JSON object. No Drupal bootstrap is needed. With --compare, FILE
 * holds the report an environment printed; the output is then one line per
 * difference from REF and the exit status is 1 if there is any.
 */

declare(strict_types=1);

namespace Ilas\Deploy;

/**
 * Directories whose source files are fingerprinted.
 */
const FINGERPRINT_ROOTS = [
  'web/modules/custom',
  'web/themes/custom',
  'config',
  'scripts/deploy',
  'web/private/scripts',
];

/**
 * Single files that are fingerprinted.
 */
const FINGERPRINT_FILES = [
  'composer.lock',
  'pantheon.yml',
  'web/sites/default/settings.php',
];

/**
 * Returns TRUE for a path that takes part in the fingerprint.
 *
 * Only source files that are the same in git and on the server: build output
 * and dependency directories are left out.
 */
function is_fingerprinted_path(string $path): bool {
  if (in_array($path, FINGERPRINT_FILES, TRUE)) {
    return TRUE;
  }
  if (preg_match('#(^|/)(node_modules|vendor)/#', $path) === 1) {
    return FALSE;
  }
  foreach (FINGERPRINT_ROOTS as $root) {
    if (str_starts_with($path, $root . '/')) {
      return preg_match('/\.(php|module|inc|install|theme|profile|yml|twig)$/', $path) === 1;
    }
  }
  return FALSE;
}

/**
 * Returns the group a path is reported under when fingerprints differ.
 *
 * One group per custom module or theme, one per other fingerprinted
 * directory, and one per single file.
 */
function group_of(string $path): string {
  if (preg_match('#^(web/(?:modules|themes)/custom/[^/]+)/#', $path, $match) === 1) {
    return $match[1];
  }
  foreach (FINGERPRINT_ROOTS as $root) {
    if (str_starts_with($path, $root . '/')) {
      return $root;
    }
  }
  return $path;
}

/**
 * Hashes "blob-id path" lines into one fingerprint.
 *
 * @param array<string, string> $blobs
 *   Git blob ids keyed by path.
 *
 * @return array{fingerprint: string, files: int, groups: array<string, string>}
 *   The fingerprint, the number of files behind it, and a short fingerprint
 *   per group so that a mismatch says where to look.
 */
function fingerprint_of(array $blobs): array {
  ksort($blobs, SORT_STRING);
  $lines = [];
  $grouped = [];
  foreach ($blobs as $path => $blob) {
    $lines[] = $blob . ' ' . $path;
    $grouped[group_of($path)][] = $blob . ' ' . $path;
  }
  $groups = [];
  foreach ($grouped as $group => $groupLines) {
    $groups[$group] = substr(hash('sha256', implode("\n", $groupLines)), 0, 12);
  }
  ksort($groups, SORT_STRING);
  return [
    'fingerprint' => hash('sha256', implode("\n", $lines)),
    'files' => count($lines),
    'groups' => $groups,
  ];
}

/**
 * Returns the git blob id of a file's contents.
 */
function blob_id(string $contents): string {
  return sha1('blob ' . strlen($contents) . "\0" . $contents);
}

/**
 * Collects blob ids from the files on disk.
 *
 * @return array<string, string>
 *   Git blob ids keyed by path relative to the root.
 */
function blobs_from_disk(string $root): array {
  $blobs = [];
  foreach (FINGERPRINT_FILES as $file) {
    if (is_file($root . '/' . $file)) {
      $blobs[$file] = blob_id((string) file_get_contents($root . '/' . $file));
    }
  }
  foreach (FINGERPRINT_ROOTS as $dir) {
    if (!is_dir($root . '/' . $dir)) {
      continue;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->isLink()) {
        continue;
      }
      $path = substr($file->getPathname(), strlen($root) + 1);
      if (is_fingerprinted_path($path)) {
        $blobs[$path] = blob_id((string) file_get_contents($file->getPathname()));
      }
    }
  }
  return $blobs;
}

/**
 * Collects blob ids from a git commit.
 *
 * @return array<string, string>
 *   Git blob ids keyed by path.
 */
function blobs_from_git(string $root, string $ref): array {
  $command = sprintf(
    'git -C %s ls-tree -r -z %s 2>/dev/null',
    escapeshellarg($root),
    escapeshellarg($ref),
  );
  $output = shell_exec($command);
  if (!is_string($output) || $output === '') {
    fwrite(STDERR, "Could not read git tree for {$ref}.\n");
    exit(2);
  }
  $blobs = [];
  foreach (explode("\0", $output) as $entry) {
    // "<mode> <type> <id>\t<path>".
    if (preg_match('/^(\d+) blob ([0-9a-f]{40})\t(.+)$/s', $entry, $match) !== 1) {
      continue;
    }
    // 120000 is a symbolic link; the disk walk skips those too.
    if ($match[1] !== '120000' && is_fingerprinted_path($match[3])) {
      $blobs[$match[3]] = $match[2];
    }
  }
  return $blobs;
}

/**
 * Compares installed packages with composer.lock on the same filesystem.
 *
 * @return array{checked: bool, match: bool, mismatches: string[], core: string}
 *   Whether the comparison could run, whether every locked production package
 *   is installed at the locked version, the first differences, and the
 *   installed drupal/core version.
 */
function package_state(string $root): array {
  $state = ['checked' => FALSE, 'match' => FALSE, 'mismatches' => [], 'core' => ''];
  $lock = json_decode((string) @file_get_contents($root . '/composer.lock'), TRUE);
  $installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), TRUE);
  if (!is_array($lock) || !is_array($installed)) {
    return $state;
  }

  $installedVersions = [];
  foreach ($installed['packages'] ?? [] as $package) {
    $installedVersions[$package['name']] = (string) ($package['version'] ?? '');
  }
  $state['core'] = $installedVersions['drupal/core'] ?? '';

  // Production packages only: dev packages are not installed on Pantheon.
  foreach ($lock['packages'] ?? [] as $package) {
    $name = $package['name'];
    $locked = (string) ($package['version'] ?? '');
    $actual = $installedVersions[$name] ?? '(not installed)';
    if ($actual !== $locked) {
      $state['mismatches'][] = "{$name}: locked {$locked}, installed {$actual}";
    }
  }
  $state['checked'] = TRUE;
  $state['match'] = $state['mismatches'] === [];
  $state['mismatches'] = array_slice($state['mismatches'], 0, 8);
  return $state;
}

/**
 * Lists how an environment's report differs from the commit it should run.
 *
 * @param array<string, mixed> $expected
 *   The report computed from git for that commit.
 * @param array<string, mixed> $actual
 *   The report the environment printed from its own files.
 *
 * @return string[]
 *   One line per problem; empty when the environment runs that commit with
 *   the packages its composer.lock names.
 */
function differences(array $expected, array $actual): array {
  $problems = [];

  if (!is_string($actual['fingerprint'] ?? NULL)) {
    return ['The environment did not return a fingerprint.'];
  }
  if ($actual['fingerprint'] !== $expected['fingerprint']) {
    $expectedGroups = $expected['groups'] ?? [];
    $actualGroups = is_array($actual['groups'] ?? NULL) ? $actual['groups'] : [];
    $changed = [];
    foreach (array_keys($expectedGroups + $actualGroups) as $group) {
      if (($expectedGroups[$group] ?? NULL) !== ($actualGroups[$group] ?? NULL)) {
        $changed[] = $group . match (TRUE) {
          !isset($actualGroups[$group]) => ' (missing on the environment)',
          !isset($expectedGroups[$group]) => ' (not in the commit)',
          default => '',
        };
      }
    }
    sort($changed, SORT_STRING);
    $problems[] = 'Source files differ from the commit in: ' . implode(', ', $changed);
  }

  $packages = is_array($actual['packages'] ?? NULL) ? $actual['packages'] : [];
  if (($packages['checked'] ?? FALSE) !== TRUE) {
    $problems[] = 'Installed packages could not be compared with composer.lock on the environment.';
  }
  elseif (($packages['match'] ?? FALSE) !== TRUE) {
    $problems[] = 'Installed packages do not match composer.lock: ' . implode('; ', $packages['mismatches'] ?? []);
  }

  return $problems;
}

/**
 * Runs the script.
 *
 * @param string[] $argv
 *   Command-line arguments.
 *
 * @return int
 *   The exit status.
 */
function main(array $argv): int {
  $root = dirname(__DIR__, 2);
  $ref = NULL;
  $compare = NULL;
  foreach ($argv as $argument) {
    if (str_starts_with($argument, '--git-ref=')) {
      $ref = substr($argument, strlen('--git-ref='));
    }
    if (str_starts_with($argument, '--compare=')) {
      $compare = substr($argument, strlen('--compare='));
    }
  }

  if ($ref === NULL) {
    $report = ['source' => 'disk'] + fingerprint_of(blobs_from_disk($root)) + ['packages' => package_state($root)];
    echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
  }

  $expected = ['source' => 'git:' . $ref] + fingerprint_of(blobs_from_git($root, $ref));
  if ($compare === NULL) {
    echo json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
  }

  $actual = json_decode((string) @file_get_contents($compare), TRUE);
  $problems = differences($expected, is_array($actual) ? $actual : []);
  foreach ($problems as $problem) {
    echo $problem . "\n";
  }
  if ($problems !== []) {
    return 1;
  }
  echo sprintf(
    "%d source files match %s; packages match composer.lock (drupal/core %s).\n",
    $expected['files'],
    $ref,
    $actual['packages']['core'],
  );
  return 0;
}

// Drush's php:script includes this file; plain PHP runs it as the main script.
// Either way it prints the report. It defines nothing outside its namespace,
// and it only ends the process when the check it ran failed, so that Drush
// finishes normally after a report.
$status = main($_SERVER['argv'] ?? []);
if ($status !== 0) {
  exit($status);
}
