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
  sync-master.sh [--no-fetch] [--dry-run]

Synchronize local master from github/master after a PR merge.

Examples:
  bash scripts/git/sync-master.sh
  bash scripts/git/sync-master.sh --dry-run
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

main() {
  local branch=""
  local github_status=""
  local github_remote_only=""
  local github_local_only=""
  local origin_status=""
  local origin_remote_only=""
  local origin_local_only=""

  parse_args "$@"

  branch="$(ensure_named_branch "")"
  if [[ "$branch" != "master" ]]; then
    err "sync-master.sh must be run from local master."
    exit 1
  fi

  info "Syncing local master from github/master"
  warn_if_worktree_dirty

  if "$DO_FETCH"; then
    fetch_remote "github"
    fetch_remote "origin"
  fi

  IFS=$'\t' read -r github_status github_remote_only github_local_only < <(describe_remote_status "github" "$branch")
  print_remote_status "github" "$branch" "$github_status" "$github_remote_only" "$github_local_only"

  case "$github_status" in
    remote-ahead)
      if "$DRY_RUN"; then
        print_cmd git -C "$REPO_ROOT" merge --ff-only github/master
      else
        git -C "$REPO_ROOT" merge --ff-only github/master
      fi
      ;;
    in-sync)
      ok "Local master is already up to date with github/master."
      ;;
    local-ahead)
      err "github/master does not yet include local master."
      err "Wait for the GitHub PR to merge, then rerun: npm run git:sync-master"
      exit 1
      ;;
    diverged)
      err "Local master diverged from github/master."
      err "Inspect with: git log --left-right --cherry-pick --oneline github/master...master"
      exit 1
      ;;
    missing)
      err "github/master does not exist."
      exit 1
      ;;
  esac

  IFS=$'\t' read -r origin_status origin_remote_only origin_local_only < <(describe_remote_status "origin" "$branch")
  print_remote_status "origin" "$branch" "$origin_status" "$origin_remote_only" "$origin_local_only"

  case "$origin_status" in
    local-ahead)
      warn "Pantheon is behind. Deploy with: npm run git:publish -- --origin-only"
      ;;
    in-sync)
      ok "Pantheon already matches local master."
      ;;
    remote-ahead)
      warn "Pantheon is ahead of local master. Inspect before deploying."
      ;;
    diverged)
      warn "Pantheon diverged from local master. Inspect before deploying."
      ;;
    missing)
      warn "origin/master does not exist."
      ;;
  esac

  ok "Master sync complete."
}

main "$@"
