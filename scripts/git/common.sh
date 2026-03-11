#!/usr/bin/env bash

GIT_HELPER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$GIT_HELPER_DIR/../.." && pwd)"
DEFAULT_REMOTES=(origin github)

info() {
  printf '[info] %s\n' "$*"
}

ok() {
  printf '[ok] %s\n' "$*"
}

warn() {
  printf '[warn] %s\n' "$*" >&2
}

err() {
  printf '[error] %s\n' "$*" >&2
}

require_command() {
  local cmd="$1"

  if ! command -v "$cmd" >/dev/null 2>&1; then
    err "Required command not found: $cmd"
    exit 1
  fi
}

print_cmd() {
  local rendered=()
  local arg
  for arg in "$@"; do
    printf -v arg '%q' "$arg"
    rendered+=("$arg")
  done
  printf '[dry-run] %s\n' "${rendered[*]}"
}

ensure_named_branch() {
  local branch="${1:-}"

  if [[ -n "$branch" ]]; then
    echo "$branch"
    return 0
  fi

  branch="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD)"
  if [[ "$branch" == "HEAD" ]]; then
    err "Detached HEAD detected. Pass --branch explicitly."
    exit 1
  fi

  echo "$branch"
}

require_local_branch() {
  local branch="$1"
  if ! git -C "$REPO_ROOT" show-ref --verify --quiet "refs/heads/$branch"; then
    err "Local branch '$branch' does not exist."
    exit 1
  fi
}

warn_if_worktree_dirty() {
  if [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
    warn "Worktree has uncommitted changes."
  fi
}

fetch_remote() {
  local remote="$1"
  info "Fetching $remote..."
  git -C "$REPO_ROOT" fetch --prune "$remote"
}

remote_ref_exists() {
  local remote="$1"
  local branch="$2"
  git -C "$REPO_ROOT" show-ref --verify --quiet "refs/remotes/$remote/$branch"
}

describe_remote_status() {
  local remote="$1"
  local branch="$2"
  local raw=""
  local remote_only=""
  local local_only=""
  local status=""

  if ! remote_ref_exists "$remote" "$branch"; then
    printf 'missing\t-\t-\n'
    return 0
  fi

  raw="$(git -C "$REPO_ROOT" rev-list --left-right --count "$remote/$branch...$branch")"
  read -r remote_only local_only <<< "$raw"

  if [[ "$remote_only" == "0" && "$local_only" == "0" ]]; then
    status="in-sync"
  elif [[ "$remote_only" == "0" ]]; then
    status="local-ahead"
  elif [[ "$local_only" == "0" ]]; then
    status="remote-ahead"
  else
    status="diverged"
  fi

  printf '%s\t%s\t%s\n' "$status" "$remote_only" "$local_only"
}

print_remote_status() {
  local remote="$1"
  local branch="$2"
  local status="$3"
  local remote_only="$4"
  local local_only="$5"

  case "$status" in
    in-sync)
      ok "$remote/$branch matches local $branch"
      ;;
    local-ahead)
      warn "$remote/$branch is behind local $branch by $local_only commit(s)"
      ;;
    remote-ahead)
      err "$remote/$branch is ahead of local $branch by $remote_only commit(s)"
      ;;
    diverged)
      err "$remote/$branch diverged from local $branch (remote_only=$remote_only local_only=$local_only)"
      ;;
    missing)
      warn "$remote/$branch does not exist on the remote"
      ;;
    *)
      err "Unknown status '$status' for $remote/$branch"
      exit 1
      ;;
  esac
}
