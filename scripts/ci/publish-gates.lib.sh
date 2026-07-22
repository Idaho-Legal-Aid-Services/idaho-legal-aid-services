#!/usr/bin/env bash
# Shared library defining the test gates that block a publish push.
#
# This file is the single source of truth for the four gate commands enforced by
# both the strict pre-push hook (scripts/ci/pre-push-strict.sh) and the local
# preflight (scripts/ci/publish-gate-local.sh). Editing the gate commands here
# changes both consumers at once — they cannot drift.
#
# This file is intended to be SOURCED, not executed. Callers own cwd and shell
# options. Each gate function exits non-zero on failure (terminating the parent
# script), after printing a structured FAIL block naming:
#   - the failed gate
#   - the failed suite
#   - the last failed test (when extractable)
#   - the exact reproducer command
#   - the canonical summary file path
#
# Reinstall reminder: after editing pre-push-strict.sh, run
#   bash scripts/ci/install-pre-push-strict-hook.sh
# to copy the updated source into .git/hooks/pre-push.

if [[ -n "${ILAS_PUBLISH_GATES_LIB_SOURCED:-}" ]]; then
  return 0
fi
ILAS_PUBLISH_GATES_LIB_SOURCED=1

# Resolve repo root from this file's location so callers don't have to pass it.
_PUBLISH_GATES_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ILAS_PUBLISH_GATES_REPO_ROOT="$(cd "$_PUBLISH_GATES_LIB_DIR/../.." && pwd)"
ILAS_PUBLISH_GATES_OUTPUT_DIR="$ILAS_PUBLISH_GATES_REPO_ROOT/promptfoo-evals/output"
ILAS_PUBLISH_GATES_SUMMARY_FILE="$ILAS_PUBLISH_GATES_OUTPUT_DIR/phpunit-summary.txt"
ILAS_PUBLISH_GATES_JUNIT_DIR="$ILAS_PUBLISH_GATES_OUTPUT_DIR/junit"

# Initialize per-run state so the publish-failure summarizer always reads a
# fresh, consistent set of artifacts. Idempotent within a single run (callers
# pass a unique entrypoint name; second call is a no-op).
publish_gates_init_run() {
  local entrypoint="${1:-unknown}"
  if [[ "${ILAS_PUBLISH_GATES_RUN_INITIALIZED:-}" == "1" ]]; then
    return 0
  fi
  ILAS_PUBLISH_GATES_RUN_INITIALIZED=1

  mkdir -p "$ILAS_PUBLISH_GATES_OUTPUT_DIR" "$ILAS_PUBLISH_GATES_JUNIT_DIR"
  rm -f "$ILAS_PUBLISH_GATES_JUNIT_DIR"/*.xml 2>/dev/null || true
  {
    echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "repo_root=${ILAS_PUBLISH_GATES_REPO_ROOT}"
    echo "entrypoint=${entrypoint}"
  } > "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

# Append a phase result. Mirrors run-quality-gate.sh's append_phase_result so
# both producers feed the same summarizer.
publish_gates_record_phase() {
  local phase="$1"
  local exit_code="$2"
  echo "phase=${phase} exit_code=${exit_code} timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

publish_gates_record_junit() {
  local phase="$1"
  local path="$2"
  echo "junit_${phase}=${path}" >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

publish_gates_record_drift() {
  local note="$1"
  echo "drift=${note}" >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

# PIPE-04: see 03.1-SPEC.md PIPE-04 acceptance criteria.
# Three new record helpers (skip / duration_ms / env) and one wrapping helper.
# Schema additions are append-only; the existing PHP parser ignores unknown keys.

# Record that a gate was skipped and why.
# Usage: publish_gates_record_skip phase reason
publish_gates_record_skip() {
  local phase="$1"
  local reason="$2"
  echo "phase_skipped=${phase} ${reason}" >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

# Record per-gate duration in milliseconds.
# Usage: publish_gates_record_duration_ms phase duration_ms
publish_gates_record_duration_ms() {
  local phase="$1"
  local duration_ms="$2"
  echo "phase_duration_ms=${phase}=${duration_ms}" >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

# Record the env-var config that influenced a gate.
# Secret redaction: any arg whose name (left of '=') matches *_KEY, *_TOKEN,
# *_SECRET, or *_PASSWORD has its value replaced with <redacted>.
# Usage: publish_gates_record_env phase VAR1=val1 VAR2=val2 ...
publish_gates_record_env() {
  local phase="$1"
  shift
  local redacted_args=()
  local arg name value
  for arg in "$@"; do
    name="${arg%%=*}"
    value="${arg#*=}"
    if [[ "$name" == *_KEY || "$name" == *_TOKEN || "$name" == *_SECRET || "$name" == *_PASSWORD ]]; then
      value="<redacted>"
    fi
    redacted_args+=("${name}=${value}")
  done
  echo "phase_env=${phase} ${redacted_args[*]}" >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
}

# Path-aware gate scoping: return 0 (safe) only when EVERY provided path is in
# the gate-safe allow-list — files that cannot affect the assistant-scoped
# suites (VC-PURE, module quality). Anything unrecognized is gate-relevant, so
# new path categories fail toward running the full battery. Empty input is NOT
# safe (fail-safe). Callers decide what to do with the answer; this function is
# the single source of truth for the allow-list.
# Usage: publish_gates_files_are_push_safe file1 [file2 ...]
publish_gates_files_are_push_safe() {
  (( $# > 0 )) || return 1
  local f
  for f in "$@"; do
    case "$f" in
      config/*assistant*|config/*search_api*)
        # Assistant/search config exports feed the module under test.
        return 1
        ;;
      web/themes/custom/*|docs/*|.planning/*|config/*|web/robots.txt|*.md)
        continue
        ;;
      *)
        return 1
        ;;
    esac
  done
  return 0
}

# Wrap a gate call to capture per-gate duration. Records duration_ms after the
# gate returns; preserves the gate's exit code exactly.
# Usage: _publish_gates_run_with_record gate_fn arg1 arg2 ...
_publish_gates_run_with_record() {
  local gate_fn="$1"
  shift
  local started ended duration_ms
  started="${EPOCHREALTIME:-$(date +%s.%N)}"
  "$gate_fn" "$@"
  local gate_rc=$?
  ended="${EPOCHREALTIME:-$(date +%s.%N)}"
  duration_ms=$(awk "BEGIN{printf \"%d\", ($ended - $started) * 1000}")
  publish_gates_record_duration_ms "${gate_fn#gate_}" "$duration_ms"
  return "$gate_rc"
}

# Install the EXIT trap that prints the final structured summary block at the
# very bottom of the gate output, on both success and failure. The trap
# preserves the upstream exit code — it never alters caller semantics.
#
# 03.1-02-SPIKE.md §"File 2" — trap stdin isolation: the </dev/null below
# closes the summarizer child's stdin so it cannot inherit and re-leak the
# pre-push pipe back to git on EXIT (defensive H1/H3 patch).
publish_gates_install_summary_trap() {
  # shellcheck disable=SC2154  # gate_exit is assigned in the trap-string itself ($?), shellcheck cannot follow
  trap 'gate_exit=$?; bash "'"$_PUBLISH_GATES_LIB_DIR"'/publish-failure-summary.sh" "$gate_exit" </dev/null; exit "$gate_exit"' EXIT
}

# Print a structured FAIL block to stderr.
# Args:
#   $1 gate name
#   $2 failed suite (path or identifier)
#   $3 reproducer command
#   $4 summary file path (or empty for n/a)
#   $5 last failed test (or empty for n/a)
_publish_gates_print_fail() {
  local gate="$1"
  local suite="$2"
  local reproduce="$3"
  local summary="${4:-}"
  local failed_test="${5:-}"

  {
    echo ""
    echo "=================================================================="
    echo "FAIL: ${gate}"
    echo "=================================================================="
    echo "  Failed suite:    ${suite:-n/a}"
    echo "  Failed test:     ${failed_test:-n/a}"
    echo "  Reproduce:       ${reproduce}"
    echo "  Summary file:    ${summary:-n/a}"
    echo "=================================================================="
  } >&2
}

# Try to extract the most recent failed test name from the phpunit summary
# file. Falls back to empty string when nothing is parseable.
_publish_gates_last_failed_test() {
  local summary="$ILAS_PUBLISH_GATES_SUMMARY_FILE"
  [[ -f "$summary" ]] || { printf ''; return; }
  # The summary file is a flat key=value log; failed test names are not always
  # written there. Best-effort: scan PHPUnit's own state if a junit log exists.
  local junit="$ILAS_PUBLISH_GATES_REPO_ROOT/promptfoo-evals/output/phpunit-junit.xml"
  if [[ -f "$junit" ]]; then
    grep -oE 'name="[^"]+"[^>]*>[[:space:]]*<failure' "$junit" \
      | tail -n 1 \
      | sed -E 's/^name="([^"]+)".*/\1/'
    return
  fi
  printf ''
}

# Gate 1: Composer install dry-run. Mirrors GitHub CI's "Install Composer
# dependencies" step. Catches composer.json/composer.lock drift.
gate_composer_dryrun() {
  echo ""
  echo "=== Gate: Composer install dry-run ==="
  if ! command -v composer >/dev/null 2>&1; then
    publish_gates_record_phase "composer_dry_run" "1"
    _publish_gates_print_fail \
      "Composer install dry-run" \
      "(composer not found on PATH)" \
      "Install composer locally, then re-run the gate" \
      "" \
      ""
    exit 1
  fi

  local cmd='composer install --no-interaction --no-progress --prefer-dist --dry-run'
  if ! eval "$cmd"; then
    local rc=$?
    publish_gates_record_phase "composer_dry_run" "$rc"
    _publish_gates_print_fail \
      "Composer install dry-run (parity with GitHub CI 'Install Composer dependencies')" \
      "composer.json / composer.lock" \
      "$cmd" \
      "" \
      ""
    exit "$rc"
  fi
  publish_gates_record_phase "composer_dry_run" "0"
}

# Gate 2: VC-PURE — pure-unit phpunit suite. Mirrors GitHub CI's "Run PHPUnit
# pure-unit tests (VC-PURE)" step.
gate_vc_pure() {
  echo ""
  echo "=== Gate: VC-PURE (phpunit.pure.xml) ==="
  local junit="$ILAS_PUBLISH_GATES_JUNIT_DIR/vc_pure.xml"
  publish_gates_record_junit "vc_pure" "$junit"
  local cmd="vendor/bin/phpunit -c phpunit.pure.xml --colors=always --log-junit ${junit}"
  if ! eval "$cmd"; then
    local rc=$?
    publish_gates_record_phase "vc_pure" "$rc"
    _publish_gates_print_fail \
      "VC-PURE phpunit suite" \
      "phpunit.pure.xml (pure-unit tests)" \
      "$cmd" \
      "" \
      ""
    exit "$rc"
  fi
  publish_gates_record_phase "vc_pure" "0"
}

# Gate 3: Module quality gate. Composite — runs Phase 1 (VC-UNIT),
# 1b (VC-DRUPAL-UNIT), 1c (VC-KERNEL), 1d (Functional assistant API behavior),
# 1e (Conversation intent fixtures), 1f (Promptfoo runtime tests).
gate_module_quality() {
  echo ""
  echo "=== Gate: Module quality gate (run-quality-gate.sh) ==="
  local cmd='bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh'
  if ! eval "$cmd"; then
    local rc=$?
    local last_test
    last_test="$(_publish_gates_last_failed_test || true)"
    # The quality gate already prints its own per-phase FAIL block with the
    # specific failed suite. Add a top-level reproducer hint pointing at the
    # most useful entry point for iteration.
    _publish_gates_print_fail \
      "Module quality gate" \
      "see per-phase output above (likely Phase 1d if functional)" \
      "npm run gate:assistant-functional   # then narrow with: npm run gate:assistant-functional:filter -- <TestName>" \
      "$ILAS_PUBLISH_GATES_SUMMARY_FILE" \
      "$last_test"
    exit "$rc"
  fi
}

# Gate 4a: Branch-aware promptfoo gate (used by all non-deploy-bound paths).
# Live provider call (real Cohere). Skipped by default to keep PR/publish
# correctness gates deterministic; opt in with ILAS_LIVE_PROVIDER_GATE=1.
# To prove live providers explicitly: npm run assistant:providers:health.
# Args: $1 = branch name to use for blocking/advisory policy.
gate_promptfoo_branch_aware() {
  local branch="${1:-}"
  if [[ -z "$branch" ]]; then
    echo "gate_promptfoo_branch_aware: missing branch arg" >&2
    exit 2
  fi
  if [[ "${ILAS_LIVE_PROVIDER_GATE:-0}" != "1" ]]; then
    echo ""
    echo "=== Gate: Promptfoo branch-aware (branch=${branch}) — SKIPPED (live provider gate disabled) ==="
    echo "Set ILAS_LIVE_PROVIDER_GATE=1 to enable, or run 'npm run assistant:providers:health' to verify live providers explicitly."
    return 0
  fi
  echo ""
  echo "=== Gate: Promptfoo branch-aware (branch=${branch}) ==="
  local cmd="CI_BRANCH=${branch} bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto"
  if ! eval "$cmd"; then
    local rc=$?
    publish_gates_record_phase "promptfoo_branch_aware" "$rc"
    _publish_gates_print_fail \
      "Promptfoo branch-aware gate" \
      "promptfoo-evals (branch policy: ${branch})" \
      "$cmd" \
      "$ILAS_PUBLISH_GATES_REPO_ROOT/promptfoo-evals/output/gate-summary.txt" \
      ""
    exit "$rc"
  fi
  publish_gates_record_phase "promptfoo_branch_aware" "0"
}

# Gate 4b: Deploy-bound promptfoo gate (used only by pre-push when pushing
# origin/master after github/master sync). Live provider call (real Cohere
# against DDEV). Requires DDEV running. Skipped by default to keep deploy
# pushes deterministic; opt in with ILAS_LIVE_PROVIDER_GATE=1 (or run
# 'npm run assistant:providers:health:strict' before pushing master).
# Args: $1 = branch, $2 = ddev assistant URL.
gate_promptfoo_deploy_bound() {
  local branch="${1:-}"
  local url="${2:-}"
  if [[ -z "$branch" || -z "$url" ]]; then
    echo "gate_promptfoo_deploy_bound: missing branch or url arg" >&2
    exit 2
  fi
  if [[ "${ILAS_LIVE_PROVIDER_GATE:-0}" != "1" ]]; then
    echo ""
    echo "=== Gate: Promptfoo deploy-bound (origin/master against DDEV) — SKIPPED (live provider gate disabled) ==="
    echo "Set ILAS_LIVE_PROVIDER_GATE=1 to enable, or run 'npm run assistant:providers:health:strict' before pushing master."
    return 0
  fi
  echo ""
  echo "=== Gate: Promptfoo deploy-bound (origin/master against DDEV) ==="
  local cmd="CI_BRANCH=${branch} ILAS_ASSISTANT_URL=${url} bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto --config promptfooconfig.deploy.yaml --no-deep-eval"
  if ! eval "$cmd"; then
    local rc=$?
    publish_gates_record_phase "promptfoo_deploy_bound" "$rc"
    _publish_gates_print_fail \
      "Promptfoo deploy-bound gate (origin/master, promptfooconfig.deploy.yaml)" \
      "promptfooconfig.deploy.yaml against ${url}" \
      "$cmd" \
      "$ILAS_PUBLISH_GATES_REPO_ROOT/promptfoo-evals/output/gate-summary.txt" \
      ""
    exit "$rc"
  fi
  publish_gates_record_phase "promptfoo_deploy_bound" "0"
}

# Gate 4b (required variant): Deploy-bound promptfoo gate — FAIL-CLOSED.
# Per PIPE-02; closes .planning/todos/pending/2026-05-07-promptfoo-deploy-bound-gate-silently-auto-skipped.md
#
# Called by pre-push-strict.sh on the protected-master deploy path (DEPLOY_BOUND_PROMPTFOO=true).
# Unlike gate_promptfoo_deploy_bound (advisory, opt-in), this variant exits 1 when
# ILAS_LIVE_PROVIDER_GATE is unset — a deploy-bound master push MUST either run the
# gate or fail explicitly. The "PASSED while gate ran nothing" state is unreachable
# from this call site.
#
# Args: $1 = branch, $2 = ddev assistant URL.
# Per PIPE-02; closes 2026-05-07-promptfoo-deploy-bound-gate-silently-auto-skipped.md
gate_promptfoo_deploy_bound_required() {
  local branch="${1:-}"
  local url="${2:-}"
  if [[ -z "$branch" || -z "$url" ]]; then
    echo "gate_promptfoo_deploy_bound_required: missing branch or url arg" >&2
    exit 2
  fi
  if [[ "${ILAS_LIVE_PROVIDER_GATE:-0}" != "1" ]]; then
    publish_gates_record_phase "promptfoo_deploy_bound" "1"
    # Inline skip record (plan 05 will introduce publish_gates_record_skip helper)
    echo "phase_skipped=promptfoo_deploy_bound ILAS_LIVE_PROVIDER_GATE-not-set" \
      >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
    _publish_gates_print_fail \
      "Promptfoo deploy-bound (required on master push)" \
      "promptfooconfig.deploy.yaml against ${url}" \
      "ILAS_LIVE_PROVIDER_GATE=1 git push origin master" \
      "$ILAS_PUBLISH_GATES_SUMMARY_FILE" \
      ""
    exit 1
  fi
  # Env var is set — delegate to the advisory variant which owns the live eval body.
  gate_promptfoo_deploy_bound "$branch" "$url"
}
