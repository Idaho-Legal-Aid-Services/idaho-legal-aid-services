#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
HOOK_SOURCE="$REPO_ROOT/scripts/ci/pre-push-strict.sh"
HOOK_DEST="$REPO_ROOT/.git/hooks/pre-push"

if [[ ! -f "$HOOK_SOURCE" ]]; then
  echo "Hook source not found: $HOOK_SOURCE" >&2
  exit 1
fi

if [[ ! -d "$REPO_ROOT/.git/hooks" ]]; then
  echo "Not a git repository (missing .git/hooks): $REPO_ROOT" >&2
  exit 1
fi

cp "$HOOK_SOURCE" "$HOOK_DEST"
chmod +x "$HOOK_DEST"

echo "Installed strict pre-push hook:"
echo "  $HOOK_DEST"
echo ""
echo "This hook runs:"
echo "  0) scripts/git/sync-check.sh (blocks remote-ahead/diverged pushes)"
echo "     and blocks direct github/master pushes plus pantheon-before-github master pushes"
echo "  1) composer install --no-interaction --no-progress --prefer-dist --dry-run"
echo "     mirroring the GitHub 'Install Composer dependencies' step to catch composer.json/composer.lock drift"
echo "  2) web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh"
echo "  3) scripts/ci/run-promptfoo-gate.sh --env dev --mode auto"
echo "     using the pushed target branch for blocking/advisory policy"
echo "     and requires local DDEV exact-code evals for synced origin/master deploy pushes"
echo ""
echo "Protected-master publish helper:"
echo "  git status --short --branch"
echo "  npm run git:publish"
echo "  npm run git:finish"
echo ""
echo "Each publish creates or updates the helper PR for the current publish/master-<sha> branch."
echo "Do not wait on stale PR numbers from earlier publishes."
echo "git:finish waits for the green PR, merges it, syncs local master, and deploys Pantheon dev through the DDEV-blocked origin/master gate if needed."
echo "Pantheon test/live promotion remains a separate workflow."
echo ""
echo "Bypass once (not recommended): git push --no-verify"
