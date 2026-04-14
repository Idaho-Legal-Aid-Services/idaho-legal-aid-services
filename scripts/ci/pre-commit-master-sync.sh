#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"

CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
GITHUB_STATUS=""
GITHUB_REMOTE_ONLY=""
GITHUB_LOCAL_ONLY=""
ORIGIN_STATUS=""
ORIGIN_REMOTE_ONLY=""
ORIGIN_LOCAL_ONLY=""

if [[ "$CURRENT_BRANCH" == "HEAD" || "$CURRENT_BRANCH" != "master" ]]; then
  exit 0
fi

echo "Pre-commit master sync guard: branch=${CURRENT_BRANCH}"
fetch_remote "github"
fetch_remote "origin"

IFS=$'\t' read -r GITHUB_STATUS GITHUB_REMOTE_ONLY GITHUB_LOCAL_ONLY < <(
  describe_remote_status "github" "$CURRENT_BRANCH"
)
print_remote_status "github" "$CURRENT_BRANCH" "$GITHUB_STATUS" "$GITHUB_REMOTE_ONLY" "$GITHUB_LOCAL_ONLY"

case "$GITHUB_STATUS" in
  in-sync|local-ahead)
    exit 0
    ;;

  remote-ahead)
    err "Local master is stale; github/master is ahead by $GITHUB_REMOTE_ONLY commit(s)."
    err "Run: npm run git:sync-master"
    exit 1
    ;;

  diverged)
    err "Local master diverged from github/master."
    err "Run: npm run git:sync-master"
    err "If local master has unpublished commits, preserve and restack them first:"
    err "  git branch backup/recovery-<timestamp> master"
    err "  git reset --hard github/master"
    err "  git cherry-pick <local-master-commit>"
    err "Inspect with: git log --left-right --cherry-pick --oneline github/master...master"
    exit 1
    ;;

  missing)
    err "github/master is required for commits on local master."
    err "Fetch or repair the github remote before committing on master."
    exit 1
    ;;
esac

IFS=$'\t' read -r ORIGIN_STATUS ORIGIN_REMOTE_ONLY ORIGIN_LOCAL_ONLY < <(
  describe_remote_status "origin" "$CURRENT_BRANCH"
)
print_remote_status "origin" "$CURRENT_BRANCH" "$ORIGIN_STATUS" "$ORIGIN_REMOTE_ONLY" "$ORIGIN_LOCAL_ONLY"

case "$ORIGIN_STATUS" in
  in-sync|local-ahead)
    exit 0
    ;;

  remote-ahead)
    err "Pantheon origin/master is ahead by $ORIGIN_REMOTE_ONLY commit(s)."
    err "Run: npm run git:reconcile-origin"
    err "Verify unexpected Pantheon code movement with: terminus env:code-log idaho-legal-aid-services.dev --format=table"
    exit 1
    ;;

  diverged)
    err "Local master diverged from origin/master."
    err "Run: npm run git:reconcile-origin"
    err "The reconcile helper preserves local-only commits, resets master to origin/master, then replays the preserved commits."
    err "Verify unexpected Pantheon code movement with: terminus env:code-log idaho-legal-aid-services.dev --format=table"
    err "Inspect with: git log --left-right --cherry-pick --oneline origin/master...master"
    exit 1
    ;;

  missing)
    err "origin/master is required for commits on local master."
    err "Fetch or repair the origin remote before committing on master."
    exit 1
    ;;
esac
