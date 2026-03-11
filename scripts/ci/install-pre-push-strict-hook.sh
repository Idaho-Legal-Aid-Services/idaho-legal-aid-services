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
echo "  1) web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh"
echo "  2) scripts/ci/run-promptfoo-gate.sh --env dev --mode auto"
echo "     using the pushed target branch for blocking/advisory policy"
echo "     or trusts GitHub Promptfoo Gate for synced origin/master deploy pushes"
echo ""
echo "Protected-master publish helper:"
echo "  npm run git:publish"
echo "  npm run git:sync-master"
echo "  npm run git:publish -- --origin-only"
echo ""
echo "Bypass once (not recommended): git push --no-verify"
