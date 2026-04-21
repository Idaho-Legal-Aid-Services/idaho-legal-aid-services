#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
PRE_PUSH_SOURCE="$REPO_ROOT/scripts/ci/pre-push-strict.sh"
PRE_COMMIT_SOURCE="$REPO_ROOT/scripts/ci/pre-commit-master-sync.sh"
HOOKS_DIR="$(git rev-parse --path-format=absolute --git-path hooks)"
PRE_PUSH_DEST="$HOOKS_DIR/pre-push"
PRE_COMMIT_DEST="$HOOKS_DIR/pre-commit"

if [[ ! -f "$PRE_PUSH_SOURCE" ]]; then
  echo "Hook source not found: $PRE_PUSH_SOURCE" >&2
  exit 1
fi

if [[ ! -f "$PRE_COMMIT_SOURCE" ]]; then
  echo "Hook source not found: $PRE_COMMIT_SOURCE" >&2
  exit 1
fi

if [[ ! -d "$HOOKS_DIR" ]]; then
  echo "Not a git repository (missing hooks directory): $HOOKS_DIR" >&2
  exit 1
fi

cp "$PRE_COMMIT_SOURCE" "$PRE_COMMIT_DEST"
cp "$PRE_PUSH_SOURCE" "$PRE_PUSH_DEST"
chmod +x "$PRE_COMMIT_DEST" "$PRE_PUSH_DEST"

echo "Installed strict git hooks:"
echo "  $PRE_COMMIT_DEST"
echo "  $PRE_PUSH_DEST"
echo ""
echo "Pre-commit hook:"
echo "  0) scripts/ci/pre-commit-master-sync.sh"
echo "     runs only on local master, fetches github + origin, and blocks stale/diverged master commits"
echo "     uses npm run git:sync-master for github drift and npm run git:reconcile-origin for Pantheon drift"
echo ""
echo "Pre-push hook:"
echo "  0) scripts/git/sync-check.sh (blocks remote-ahead/diverged pushes)"
echo "     and blocks direct github/master pushes plus pantheon-before-github master pushes"
echo "  1) composer install --no-interaction --no-progress --prefer-dist --dry-run"
echo "     mirroring the GitHub 'Install Composer dependencies' step to catch composer.json/composer.lock drift"
echo "  2) vendor/bin/phpunit -c phpunit.pure.xml --colors=always"
echo "     mirroring the GitHub 'Run PHPUnit pure-unit tests (VC-PURE)' step"
echo "  3) web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh"
echo "  4) scripts/ci/run-promptfoo-gate.sh --env dev --mode auto"
echo "     using the pushed target branch for blocking/advisory policy"
echo "     and requires local DDEV exact-code evals for synced origin/master deploy pushes"
echo ""
echo "Protected-master publish helper:"
echo "  git status --short --branch"
echo "  npm run git:sync-master"
echo "  npm run git:reconcile-origin"
echo "  npm run git:publish"
echo "  npm run git:finish"
echo ""
echo "If local master diverged before you committed, preserve and restack it:"
echo "  git branch backup/recovery-<timestamp> master"
echo "  git reset --hard github/master"
echo "  git cherry-pick <local-master-commit>"
echo ""
echo "If Pantheon origin/master moved unexpectedly, reconcile it before publishing:"
echo "  npm run git:reconcile-origin -- --dry-run"
echo "  terminus env:code-log idaho-legal-aid-services.dev --format=table"
echo ""
echo "Each publish reuses the rolling helper PR for publish/master-active and auto-closes superseded legacy publish/master-* PRs."
echo "Do not wait on stale PR numbers from earlier publishes."
echo "git:finish waits for the green helper PR, merges it, syncs local master, deploys Pantheon dev through the DDEV-blocked origin/master gate if needed,"
echo "then runs a hosted Pantheon dev verification plus waits for the hosted post-merge master gate before returning success."
echo "Pantheon test/live promotion remains a separate workflow."
echo ""
echo "Bypass once (not recommended): git push --no-verify"
