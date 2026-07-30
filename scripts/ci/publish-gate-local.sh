#!/usr/bin/env bash
# Local publish-parity preflight. Runs the same four non-deploy-bound test gates
# as the strict pre-push hook (scripts/ci/pre-push-strict.sh), without doing any
# git push, GitHub branch creation, or Pantheon deploy.
#
# Gate logic lives in scripts/ci/publish-gates.lib.sh. Both this script and
# pre-push-strict.sh source the same library, so they cannot drift.
#
# What this DOES mirror (parity with pre-push):
#   - Composer install --dry-run
#   - VC-PURE: vendor/bin/phpunit -c phpunit.pure.xml
#   - Module quality gate: run-quality-gate.sh (incl. slow Phase 1d functional)
#   - Branch-aware promptfoo gate: run-promptfoo-gate.sh --env dev --mode auto
#     SKIPPED by default — live provider call (real Cohere). Opt in with
#     ILAS_LIVE_PROVIDER_GATE=1 or run `npm run assistant:providers:health`.
#
# What this DOES NOT do:
#   - Any `git push`, sync-check, protected-branch enforcement, or
#     detached-HEAD handling.
#   - The deploy-bound origin/master promptfoo branch (promptfooconfig.deploy.yaml
#     against a running DDEV instance) — that fires only on the actual push.
#
# A green run here means the deterministic test gates pass. To prove live
# Cohere/Voyage/Pinecone health, run `npm run assistant:providers:health`
# explicitly (or `npm run assistant:providers:health:strict` to also run the
# deploy-bound promptfoo gate).
#
# Related entry points:
#   npm run gate:publish-local              — this script
#   npm run gate:assistant-functional       — only the slow Phase 1d suite
#   npm run gate:assistant-functional:filter -- <regex>  — single test
#   npm run test:assistant:phase1d          — fastest single-test loop

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=./publish-gates.lib.sh
source "$REPO_ROOT/scripts/ci/publish-gates.lib.sh"

publish_gates_init_run "publish-gate-local"
publish_gates_install_summary_trap

# PIPE-05: fail closed if local-mirror drifts from lockfile.
# Plain echo, not info(): that helper lives in scripts/git/common.sh, which this
# script does not source. Where GNU texinfo is installed, bare "info" resolved to
# /usr/bin/info, which exited 1 under set -e and killed this preflight before it
# ran a single gate.
echo "[info] Verifying gate parity against scripts/ci/gates.lock.json..."
bash "$REPO_ROOT/scripts/ci/verify-gates-parity.sh"

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
PROMPTFOO_BRANCH="${CI_BRANCH:-$CURRENT_BRANCH}"

echo "=== publish-gate-local: mirrors non-deploy-bound pre-push test gates ==="
echo "[note] No git push, no Pantheon, no GitHub. Test gates only."
echo "[note] Branch used for promptfoo policy: ${PROMPTFOO_BRANCH}"

gate_composer_dryrun
gate_vc_pure
gate_module_quality
gate_promptfoo_branch_aware "$PROMPTFOO_BRANCH"

echo ""
echo "=== publish-gate-local: PASS (non-deploy-bound gates only) ==="
echo ""
echo "[reminder] GitHub CI additionally runs the following — none of which are"
echo "           covered above. Run them locally before pushing if your patch"
echo "           touches the relevant area:"
echo ""
echo "  • Static analysis (PHPCS + PHPStan + widget hardening) — ~30s-2min"
echo "      $ npm run gate:github-local"
echo ""
echo "  • Local DDEV a11y suite (impact-card + axe specs) — ~10 min cold"
echo "      $ npm run gate:a11y-local"
echo ""
echo "[reminder] If your patch touches custom PHP, run gate:github-local."
echo "[reminder] If your patch touches Twig/JS/SCSS/theme, run gate:a11y-local."
