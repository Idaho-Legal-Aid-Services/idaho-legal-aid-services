#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

BRANCH=""
DO_FETCH=true
REMOTES=("${DEFAULT_REMOTES[@]}")

usage() {
  cat <<'USAGE'
Usage:
  sync-check.sh [--branch <branch>] [--no-fetch]

Checks local branch drift against origin and github. Exit code 0 means no
remote is ahead of or diverged from the local branch. Remotes that are merely
behind local are reported as warnings but still considered safe to publish.
USAGE
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --branch)
        if (($# < 2)); then
          err "Missing value for --branch"
          usage
          exit 1
        fi
        BRANCH="$2"
        shift 2
        ;;
      --branch=*)
        BRANCH="${1#*=}"
        shift
        ;;
      --no-fetch)
        DO_FETCH=false
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
  local remote=""
  local status=""
  local remote_only=""
  local local_only=""
  local unsafe=false

  parse_args "$@"

  branch="$(ensure_named_branch "$BRANCH")"
  require_local_branch "$branch"

  info "Checking branch '$branch' in $REPO_ROOT"
  warn_if_worktree_dirty

  if "$DO_FETCH"; then
    for remote in "${REMOTES[@]}"; do
      fetch_remote "$remote"
    done
  fi

  for remote in "${REMOTES[@]}"; do
    IFS=$'\t' read -r status remote_only local_only < <(describe_remote_status "$remote" "$branch")
    print_remote_status "$remote" "$branch" "$status" "$remote_only" "$local_only"

    if [[ "$status" == "remote-ahead" || "$status" == "diverged" ]]; then
      unsafe=true
      if [[ "$branch" == "master" && "$remote" == "github" ]]; then
        err "Run: npm run git:sync-master"
      elif [[ "$branch" == "master" && "$remote" == "origin" ]]; then
        err "Run: npm run git:reconcile-origin"
        err "Verify unexpected Pantheon code movement with: terminus env:code-log idaho-legal-aid-services.dev --format=table"
      fi
      err "Inspect with: git log --left-right --cherry-pick --oneline $remote/$branch...$branch"
    fi
  done

  if "$unsafe"; then
    err "One or more remotes are ahead of or diverged from local '$branch'."
    exit 1
  fi

  ok "No remote is ahead of local '$branch'."
}

main "$@"
