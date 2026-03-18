#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"

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

cd "$REPO_ROOT"

if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: Composer is required for strict pre-push dependency parity checks." >&2
  echo "Install Composer locally before publishing, or bypass intentionally with git push --no-verify." >&2
  exit 1
fi

echo "Running Composer installability parity check..."
if ! composer install --no-interaction --no-progress --prefer-dist --dry-run; then
  echo "ERROR: Composer install dry-run failed." >&2
  echo "This mirrors the GitHub 'Install Composer dependencies' step and usually means composer.json/composer.lock drift." >&2
  exit 1
fi

echo "Running PHPUnit pure-unit parity gate (VC-PURE)..."
if ! vendor/bin/phpunit -c phpunit.pure.xml --colors=always; then
  echo "ERROR: VC-PURE failed." >&2
  echo "This mirrors the GitHub 'Run PHPUnit pure-unit tests (VC-PURE)' step." >&2
  exit 1
fi

echo "Running module quality gate..."
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

if [[ "$DEPLOY_BOUND_PROMPTFOO" == "true" ]]; then
  echo "Running deploy-bound promptfoo gate for origin/master against local DDEV exact code..."
  CI_BRANCH="$PROMPTFOO_BRANCH" \
    ILAS_ASSISTANT_URL="$DDEV_ASSISTANT_URL" \
    bash scripts/ci/run-promptfoo-gate.sh \
      --env dev \
      --mode auto \
      --config promptfooconfig.deploy.yaml \
      --no-deep-eval
else
  echo "Running branch-aware promptfoo gate for target branch ${PROMPTFOO_BRANCH}..."
  CI_BRANCH="$PROMPTFOO_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto
fi

echo "Strict pre-push gate PASSED."
