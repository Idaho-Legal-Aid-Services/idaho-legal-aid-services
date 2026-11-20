#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"

CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
GITHUB_STATUS=""
GITHUB_REMOTE_ONLY=""
GITHUB_LOCAL_ONLY=""
GITHUB_RECOVERY=""
ORIGIN_STATUS=""
ORIGIN_REMOTE_ONLY=""
ORIGIN_LOCAL_ONLY=""
ORIGIN_RECOVERY=""

if [[ "$CURRENT_BRANCH" == "HEAD" || "$CURRENT_BRANCH" != "master" ]]; then
  exit 0
fi

echo "Pre-commit master sync guard: branch=${CURRENT_BRANCH}"
fetch_remote "github"
fetch_remote "origin"

# PIPE-06: typed recovery from describe_remote_status col4.
IFS=$'\t' read -r GITHUB_STATUS GITHUB_REMOTE_ONLY GITHUB_LOCAL_ONLY GITHUB_RECOVERY < <(
  describe_remote_status "github" "$CURRENT_BRANCH"
)
print_remote_status "github" "$CURRENT_BRANCH" "$GITHUB_STATUS" "$GITHUB_REMOTE_ONLY" "$GITHUB_LOCAL_ONLY"

case "$GITHUB_STATUS" in
  in-sync|local-ahead)
    exit 0
    ;;

  remote-ahead)
    err "Local master is stale; github/master is ahead by $GITHUB_REMOTE_ONLY commit(s)."
    [[ -n "$GITHUB_RECOVERY" ]] && err "Recovery: $GITHUB_RECOVERY"
    exit 1
    ;;

  diverged)
    err "Local master diverged from github/master."
    [[ -n "$GITHUB_RECOVERY" ]] && err "Recovery: $GITHUB_RECOVERY"
    err "If local master has unpublished commits, preserve and restack them first:"
    err "  git branch backup/recovery-<timestamp> master"
    err "  git reset --hard github/master"
    err "  git cherry-pick <local-master-commit>"
    err "Inspect with: git log --left-right --cherry-pick --oneline github/master...master"
    exit 1
    ;;

  missing)
    err "github/master is required for commits on local master."
    [[ -n "$GITHUB_RECOVERY" ]] && err "Recovery: $GITHUB_RECOVERY"
    err "Fetch or repair the github remote before committing on master."
    exit 1
    ;;
esac

IFS=$'\t' read -r ORIGIN_STATUS ORIGIN_REMOTE_ONLY ORIGIN_LOCAL_ONLY ORIGIN_RECOVERY < <(
  describe_remote_status "origin" "$CURRENT_BRANCH"
)
print_remote_status "origin" "$CURRENT_BRANCH" "$ORIGIN_STATUS" "$ORIGIN_REMOTE_ONLY" "$ORIGIN_LOCAL_ONLY"

case "$ORIGIN_STATUS" in
  in-sync|local-ahead)
    exit 0
    ;;

  remote-ahead)
    err "Pantheon origin/master is ahead by $ORIGIN_REMOTE_ONLY commit(s)."
    [[ -n "$ORIGIN_RECOVERY" ]] && err "Recovery: $ORIGIN_RECOVERY"
    err "Verify unexpected Pantheon code movement with: terminus env:code-log idaho-legal-aid-services.dev --format=table"
    exit 1
    ;;

  diverged)
    err "Local master diverged from origin/master."
    [[ -n "$ORIGIN_RECOVERY" ]] && err "Recovery: $ORIGIN_RECOVERY"
    err "The reconcile helper merges origin/master into local master; it pushes nothing and rewrites no commit."
    err "Verify unexpected Pantheon code movement with: terminus env:code-log idaho-legal-aid-services.dev --format=table"
    err "Inspect with: git log --left-right --cherry-pick --oneline origin/master...master"
    exit 1
    ;;

  missing)
    err "origin/master is required for commits on local master."
    [[ -n "$ORIGIN_RECOVERY" ]] && err "Recovery: $ORIGIN_RECOVERY"
    err "Fetch or repair the origin remote before committing on master."
    exit 1
    ;;
esac
