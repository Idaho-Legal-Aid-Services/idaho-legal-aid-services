#!/usr/bin/env bash
# Local mirror of GitHub's static-analysis + widget-hardening jobs.
#
# Runs the fast, deterministic checks that GitHub CI runs but the strict
# pre-push hook (scripts/ci/pre-push-strict.sh) and publish-gate-local.sh
# do not. Catches PHPCS/PHPStan/widget-hardening regressions before push
# instead of after.
#
# This is intentionally separate from gate:publish-local because:
#   - publish-gate-local mirrors the deploy-bound test gates (PHPUnit, etc.)
#   - this script mirrors the GitHub-only static-analysis + JS unit jobs
#   - the slow a11y suite gets its own command (gate:a11y-local) — running
#     a fresh DDEV install in every pre-push would make iteration painful
#
# Total runtime: ~30s-2min depending on cache state.
#
# Related entry points:
#   npm run gate:publish-local   — deploy-bound test gates (PHPUnit, etc.)
#   npm run gate:github-local    — this script (phpcs + phpstan + widget)
#   npm run gate:a11y-local      — local DDEV a11y suite (~10 min, requires DDEV)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

echo "=== gate:github-local: mirrors GitHub static-analysis + widget-hardening ==="
echo "[note] Catches PHPCS/PHPStan/widget regressions before push, not after."
echo ""

run_step() {
  local label="$1"
  shift
  echo "--- ${label} ---"
  if "$@"; then
    echo "[pass] ${label}"
    echo ""
  else
    local rc=$?
    echo ""
    echo "=== gate:github-local: FAIL at ${label} (exit ${rc}) ==="
    echo "Reproducer: $*"
    exit "$rc"
  fi
}

run_step "composer phpcs" composer phpcs
run_step "composer phpstan" composer phpstan
run_step "widget hardening (P3-EXT-01)" \
  node web/modules/custom/ilas_site_assistant/tests/js/run-assistant-widget-hardening.mjs
run_step "browser observability node tests" \
  npm run --silent test:assistant:js

echo "=== gate:github-local: PASS ==="
