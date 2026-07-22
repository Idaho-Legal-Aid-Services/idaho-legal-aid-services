#!/usr/bin/env bash
# Strict pre-push gate. Versioned source for .git/hooks/pre-push.
# After editing this file, reinstall the hook with:
#   bash scripts/ci/install-pre-push-strict-hook.sh
#
# The four blocking test gates (composer dry-run, VC-PURE, module quality,
# promptfoo) are defined in scripts/ci/publish-gates.lib.sh and shared with
# scripts/ci/publish-gate-local.sh — keep gate command edits in the library
# so the local preflight cannot drift from this hook.
#
# Path-aware scoping: the assistant-scoped phpunit gates (VC-PURE, module
# quality) are skipped when every file in the push range is gate-safe (theme,
# docs, non-assistant config — see publish_gates_files_are_gate_safe). Any
# ambiguity, and any deploy-bound origin/master push, runs the full battery.
#
# The promptfoo gate (branch-aware and deploy-bound) is a LIVE provider call
# and is SKIPPED by default to keep PR/publish correctness gates deterministic
# (no random failures from slow/rate-limited/unavailable Cohere). Opt in with
#   ILAS_LIVE_PROVIDER_GATE=1 git push ...
# or, more typically, prove live infra explicitly before pushing with
#   npm run assistant:providers:health
#   npm run assistant:providers:health:strict   # also runs deploy-bound promptfoo
#
# Pre-push gates run ~673s. Pantheon drops idle SSH at ~603s. Repo-local
# core.sshCommand provides 60s keepalives; install via
# scripts/git/install-keepalive.sh. FD-isolation </dev/null + exec 0<&- below
# are defensive (belt-and-suspenders) per 03.1-02-SPIKE.md §"Recommended Fix Shape".
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"
# shellcheck source=./publish-gates.lib.sh
source "$REPO_ROOT/scripts/ci/publish-gates.lib.sh"

publish_gates_init_run "pre-push-strict"
publish_gates_install_summary_trap

REMOTE_NAME="${1:-unknown-remote}"
REMOTE_URL="${2:-unknown-url}"
CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
PUSH_LINES=()
PROMPTFOO_BRANCH="$CURRENT_BRANCH"
DEPLOY_BOUND_PROMPTFOO="false"
DDEV_ASSISTANT_URL=""

while IFS=' ' read -r local_ref local_oid remote_ref remote_oid; do
  [[ -z "${local_ref:-}" ]] && continue
  PUSH_LINES+=("${local_ref}|${local_oid}|${remote_ref}|${remote_oid}")
done

exec 0<&-   # Detach git's pre-push stdin per 03.1-02-SPIKE.md §"Recommended Fix Shape" H1 patch

is_protected_branch() {
  local branch="$1"
  [[ "$branch" == "master" || "$branch" == "main" || "$branch" =~ ^release/ ]]
}

is_pushing_remote_branch() {
  local branch="$1"
  local entry=""
  local remote_ref=""

  for entry in "${PUSH_LINES[@]}"; do
    IFS='|' read -r _ _ remote_ref _ <<< "$entry"
    if [[ "$remote_ref" == "refs/heads/$branch" ]]; then
      return 0
    fi
  done

  return 1
}

resolve_promptfoo_branch() {
  local entry=""
  local remote_ref=""
  local branch=""
  local first_head_branch=""

  for entry in "${PUSH_LINES[@]}"; do
    IFS='|' read -r _ _ remote_ref _ <<< "$entry"
    [[ "$remote_ref" == refs/heads/* ]] || continue

    branch="${remote_ref#refs/heads/}"
    if is_protected_branch "$branch"; then
      printf '%s\n' "$branch"
      return 0
    fi

    if [[ -z "$first_head_branch" ]]; then
      first_head_branch="$branch"
    fi
  done

  if [[ -n "$first_head_branch" ]]; then
    printf '%s\n' "$first_head_branch"
    return 0
  fi

  printf '%s\n' "$CURRENT_BRANCH"
}

resolve_ddev_assistant_url() {
  local ddev_json=""
  local ddev_primary_url=""

  if ! command -v ddev >/dev/null 2>&1; then
    return 1
  fi

  if ! ddev_json="$(ddev describe -j 2>/dev/null)"; then
    return 1
  fi

  ddev_primary_url="$(
    printf '%s' "$ddev_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write((data?.raw?.primary_url || '').trim());"
  )"

  if [[ -z "$ddev_primary_url" ]]; then
    return 1
  fi

  printf '%s/assistant/api/message\n' "${ddev_primary_url%/}"
}

is_effective_target_branch() {
  local branch="$1"

  if is_pushing_remote_branch "$branch"; then
    return 0
  fi

  [[ "$PROMPTFOO_BRANCH" == "$branch" && "$CURRENT_BRANCH" == "$branch" ]]
}

echo "Strict pre-push gate: remote=${REMOTE_NAME} branch=${CURRENT_BRANCH}"
echo "Remote URL: ${REMOTE_URL}"

if [[ "$CURRENT_BRANCH" == "HEAD" ]]; then
  echo "ERROR: Detached HEAD pushes are not supported by the strict hook." >&2
  echo "Push from a named branch or use git push --no-verify intentionally." >&2
  exit 1
fi

if [[ "$REMOTE_NAME" == "origin" || "$REMOTE_NAME" == "github" ]]; then
  OTHER_REMOTE="github"
  if [[ "$REMOTE_NAME" == "github" ]]; then
    OTHER_REMOTE="origin"
  fi

  PROMPTFOO_BRANCH="$(resolve_promptfoo_branch)"

  echo "Checking dual-remote drift before push..."
  bash "$REPO_ROOT/scripts/git/sync-check.sh" --branch "$CURRENT_BRANCH"
  echo "Promptfoo policy target branch: ${PROMPTFOO_BRANCH}"

  if [[ "$CURRENT_BRANCH" == "master" && "$REMOTE_NAME" == "github" ]] && is_effective_target_branch "master"; then
    echo "ERROR: Direct pushes to protected github/master are not supported." >&2
    echo "Use: npm run git:publish" >&2
    echo "Emergency bypass only: git push --no-verify github master" >&2
    exit 1
  fi

  if [[ "$CURRENT_BRANCH" == "master" && "$REMOTE_NAME" == "origin" ]] && is_effective_target_branch "master"; then
    IFS=$'\t' read -r GITHUB_STATUS _ _ < <(describe_remote_status "github" "$CURRENT_BRANCH")
    if [[ "$GITHUB_STATUS" != "in-sync" ]]; then
      echo "ERROR: Refusing to push origin/master before github/master matches local master." >&2
      echo "Use: npm run git:publish" >&2
      echo "After merge and local fast-forward, deploy Pantheon with: npm run git:publish -- --origin-only" >&2
      echo "Emergency bypass only: git push --no-verify origin master" >&2
      exit 1
    fi
    if ! DDEV_ASSISTANT_URL="$(resolve_ddev_assistant_url)"; then
      echo "ERROR: Deploy-bound origin/master promptfoo gating requires a running DDEV environment." >&2
      echo "Start DDEV so the exact local code can be evaluated before the Pantheon push." >&2
      echo "Emergency bypass only: git push --no-verify origin master" >&2
      exit 1
    fi
    DEPLOY_BOUND_PROMPTFOO="true"
  fi

  if git -C "$REPO_ROOT" show-ref --verify --quiet "refs/remotes/$OTHER_REMOTE/$CURRENT_BRANCH"; then
    read -r OTHER_REMOTE_ONLY OTHER_LOCAL_ONLY < <(
      git -C "$REPO_ROOT" rev-list --left-right --count \
        "$OTHER_REMOTE/$CURRENT_BRANCH...$CURRENT_BRANCH"
    )

    if [[ "$OTHER_REMOTE_ONLY" == "0" && "$OTHER_LOCAL_ONLY" != "0" ]]; then
      echo "WARN: $OTHER_REMOTE/$CURRENT_BRANCH is behind local by $OTHER_LOCAL_ONLY commit(s)." >&2
      echo "After this $REMOTE_NAME push, also run: git push $OTHER_REMOTE $CURRENT_BRANCH" >&2
      publish_gates_record_drift "$OTHER_REMOTE/$CURRENT_BRANCH behind local by $OTHER_LOCAL_ONLY commit(s); follow up with: git push $OTHER_REMOTE $CURRENT_BRANCH"
    fi
  fi
fi

if [[ -z "${ILAS_ASSISTANT_URL:-}" ]] &&
  ! command -v ddev >/dev/null 2>&1 &&
  ! command -v terminus >/dev/null 2>&1; then
  echo "ERROR: ILAS_ASSISTANT_URL is unset and neither DDEV nor Terminus is available." >&2
  echo "Set ILAS_ASSISTANT_URL, start DDEV, or install/authenticate Terminus before push." >&2
  exit 1
fi

# Path-aware scoping: union of changed files across all pushed ref ranges.
# Returns non-zero (caller keeps full gates, fail-safe) when any range is
# indeterminate: new branch without a master merge-base, remote OID missing
# locally, or an empty change set.
compute_push_changed_files() {
  local entry local_oid remote_oid base diff_out f
  local -A seen=()
  local files=()

  for entry in "${PUSH_LINES[@]}"; do
    IFS='|' read -r _ local_oid _ remote_oid <<< "$entry"
    [[ "$local_oid" =~ ^0+$ ]] && continue   # branch deletion
    if [[ "$remote_oid" =~ ^0+$ ]]; then
      base="$(git -C "$REPO_ROOT" merge-base "$local_oid" github/master 2>/dev/null)" ||
        base="$(git -C "$REPO_ROOT" merge-base "$local_oid" origin/master 2>/dev/null)" ||
        return 1
    else
      git -C "$REPO_ROOT" cat-file -e "${remote_oid}^{commit}" 2>/dev/null || return 1
      base="$remote_oid"
    fi
    diff_out="$(git -C "$REPO_ROOT" diff --name-only "$base" "$local_oid" 2>/dev/null)" || return 1
    while IFS= read -r f; do
      if [[ -n "$f" && -z "${seen[$f]:-}" ]]; then
        seen[$f]=1
        files+=("$f")
      fi
    done <<< "$diff_out"
  done

  (( ${#files[@]} > 0 )) || return 1
  printf '%s\n' "${files[@]}"
}

# Deploy-bound Pantheon pushes always get the full battery; otherwise skip the
# assistant-scoped phpunit gates when every changed file is gate-safe (see
# publish_gates_files_are_gate_safe in publish-gates.lib.sh).
ASSISTANT_GATES_REQUIRED="true"
PUSH_CHANGED_FILES=""
if [[ "$DEPLOY_BOUND_PROMPTFOO" != "true" ]]; then
  if PUSH_CHANGED_FILES="$(compute_push_changed_files)"; then
    mapfile -t _push_changed <<< "$PUSH_CHANGED_FILES"
    if publish_gates_files_are_gate_safe "${_push_changed[@]}"; then
      ASSISTANT_GATES_REQUIRED="false"
    fi
  fi
fi

cd "$REPO_ROOT"

# PIPE-04: wrap gates with _publish_gates_run_with_record for per-gate duration recording.
# Test gates — delegated to the shared library (see scripts/ci/publish-gates.lib.sh).
# Local parity entry point: npm run gate:publish-local.
# </dev/null on each gate is defensive H1/H3 FD-isolation per 03.1-02-SPIKE.md
# §"Wave 2 plan 03 task seed" — closes any leaked-from-parent stdin pipe so a
# gate child cannot write back into git's pre-push fd.
_publish_gates_run_with_record gate_composer_dryrun </dev/null
if [[ "$ASSISTANT_GATES_REQUIRED" == "true" ]]; then
  _publish_gates_run_with_record gate_vc_pure </dev/null
  _publish_gates_run_with_record gate_module_quality </dev/null
else
  echo ""
  echo "=== Gates: VC-PURE + Module quality — SKIPPED (push touches only gate-safe paths: ${#_push_changed[@]} files) ==="
  if (( ${#_push_changed[@]} <= 10 )); then
    printf '  - %s\n' "${_push_changed[@]}"
  fi
  publish_gates_record_skip "vc_pure" "gate-safe-paths"
  publish_gates_record_skip "module_quality" "gate-safe-paths"
fi

# PIPE-02: fail-closed if ILAS_LIVE_PROVIDER_GATE unset on deploy-bound master push
if [[ "$DEPLOY_BOUND_PROMPTFOO" == "true" ]]; then
  _publish_gates_run_with_record gate_promptfoo_deploy_bound_required "$PROMPTFOO_BRANCH" "$DDEV_ASSISTANT_URL" </dev/null
else
  _publish_gates_run_with_record gate_promptfoo_branch_aware "$PROMPTFOO_BRANCH" </dev/null
fi

echo "Strict pre-push gate PASSED."
