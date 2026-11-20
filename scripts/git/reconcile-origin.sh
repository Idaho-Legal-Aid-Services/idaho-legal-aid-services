#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

DO_FETCH=true
DRY_RUN=false

usage() {
  cat <<'USAGE'
Usage:
  reconcile-origin.sh [--no-fetch] [--dry-run]

Bring commits that were pushed straight to Pantheon (origin/master) into local
master with an ordinary merge commit. Nothing is pushed, and no history is
rewritten: local commits keep their ids, so GitHub stays a plain ancestor of
local master and the result can be published as a normal pull request.

  --dry-run   Show the Pantheon-only commits, the files they change and
              whether the merge would conflict. Changes nothing except
              fetching (add --no-fetch to skip that too).

Examples:
  bash scripts/git/reconcile-origin.sh
  bash scripts/git/reconcile-origin.sh --dry-run
USAGE
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --no-fetch)
        DO_FETCH=false
        shift
        ;;
      --dry-run)
        DRY_RUN=true
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        err "Unknown option: $1"
        usage
        exit 1
        ;;
    esac
  done
}

ensure_clean_worktree() {
  if [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
    err "Worktree has uncommitted changes."
    err "Commit or stash them before running npm run git:reconcile-origin."
    exit 1
  fi
}

main() {
  local branch=""
  local github_status=""
  local github_remote_only=""
  local github_local_only=""
  local origin_status=""
  local origin_remote_only=""
  local origin_local_only=""
  local timestamp=""
  local backup_local=""
  local original_head=""
  local prediction=""
  local predicted_conflict=false
  local conflicted=""

  parse_args "$@"

  branch="$(ensure_named_branch "")"
  if [[ "$branch" != "master" ]]; then
    err "reconcile-origin.sh must be run from local master."
    exit 1
  fi

  ensure_clean_worktree
  info "Merging Pantheon origin/master into local master"

  if "$DO_FETCH"; then
    fetch_remote "github"
    fetch_remote "origin"
  fi

  IFS=$'\t' read -r github_status github_remote_only github_local_only < <(describe_remote_status "github" "$branch")
  print_remote_status "github" "$branch" "$github_status" "$github_remote_only" "$github_local_only"

  case "$github_status" in
    in-sync|local-ahead)
      ;;
    remote-ahead)
      err "Local master is stale; github/master is ahead by $github_remote_only commit(s)."
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
      err "github/master is required before reconciling origin/master."
      err "Fetch or repair the github remote before continuing."
      exit 1
      ;;
  esac

  IFS=$'\t' read -r origin_status origin_remote_only origin_local_only < <(describe_remote_status "origin" "$branch")
  print_remote_status "origin" "$branch" "$origin_status" "$origin_remote_only" "$origin_local_only"

  case "$origin_status" in
    in-sync)
      ok "origin/master already matches local master; no reconciliation is needed."
      exit 0
      ;;
    local-ahead)
      ok "origin/master is behind local master; no reconciliation is needed."
      warn "Publish after GitHub merge with: npm run git:publish -- --origin-only"
      exit 0
      ;;
    remote-ahead|diverged)
      ;;
    missing)
      err "origin/master is required for Pantheon reconciliation."
      err "Fetch or repair the origin remote before continuing."
      exit 1
      ;;
  esac

  original_head="$(git -C "$REPO_ROOT" rev-parse "$branch")"

  info "Commits on Pantheon that local master does not have:"
  git -C "$REPO_ROOT" log --format='  %h  %ad  %an  %s' --date=short "$branch..origin/$branch"
  info "Files they change:"
  git -C "$REPO_ROOT" diff --stat "$branch...origin/$branch" | sed 's/^/  /'

  # Predict the merge without touching the worktree or the index.
  if prediction="$(git -C "$REPO_ROOT" merge-tree --write-tree --name-only --messages "$branch" "origin/$branch" 2>&1)"; then
    ok "The merge is predicted to apply without conflicts."
  else
    predicted_conflict=true
    warn "The merge is predicted to conflict:"
    printf '%s\n' "$prediction" | sed '1d' | sed 's/^/  /' >&2
  fi

  timestamp="$(date +%Y%m%d-%H%M%S)"
  backup_local="backup/recovery-local-$timestamp"

  if "$DRY_RUN"; then
    print_cmd git -C "$REPO_ROOT" branch "$backup_local" "$branch"
    print_cmd git -C "$REPO_ROOT" merge --no-ff "origin/$branch"
    ok "Dry run complete; nothing was changed."
    exit 0
  fi

  info "Saving recovery branch $backup_local -> ${original_head:0:10}"
  git -C "$REPO_ROOT" branch "$backup_local" "$branch"

  if ! git -C "$REPO_ROOT" merge --no-ff --no-edit \
    -m "Merge Pantheon origin/$branch: adopt $origin_remote_only commit(s) pushed outside the GitHub flow" \
    "origin/$branch"; then
    conflicted="$(git -C "$REPO_ROOT" diff --name-only --diff-filter=U || true)"
    err "The merge stopped on conflicts in:"
    printf '%s\n' "$conflicted" | sed 's/^/  /' >&2
    err "Either resolve each file, 'git add' it and run 'git commit',"
    err "or undo the merge completely with: git merge --abort"
    err "Local master before the merge is also saved as $backup_local."
    exit 1
  fi

  if "$predicted_conflict"; then
    warn "A conflict was predicted but git merged cleanly; review the result."
  fi

  ok "Pantheon's commits are merged into local master (nothing has been pushed)."
  info "Before publishing, make the local site match the merged code:"
  info "  ddev composer install          # then confirm the patches still apply"
  info "  ddev drush updatedb -y"
  info "  ddev drush config:status       # export only what an update really changed"
  info "  vendor/bin/phpunit -c phpunit.pure.xml --colors=always"
  info "Then publish it like any other change:"
  info "  npm run git:publish"
  info "  npm run git:finish"
}

main "$@"
