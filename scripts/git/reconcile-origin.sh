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

Reconcile local master with Pantheon origin/master by preserving local-only
commits, resetting local master to origin/master, then replaying the preserved
commits in chronological order.

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
  local backup_origin=""
  local original_head=""
  local original_tree=""
  local origin_tree=""
  local final_tree=""
  local cherry_output=""
  local commit=""
  local local_commit_count=0
  local equivalent_commit_count=0
  local merge_commit_count=0
  local -a local_commits=()

  parse_args "$@"

  branch="$(ensure_named_branch "")"
  if [[ "$branch" != "master" ]]; then
    err "reconcile-origin.sh must be run from local master."
    exit 1
  fi

  ensure_clean_worktree
  info "Reconciling local master from origin/master"

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
  original_tree="$(git -C "$REPO_ROOT" rev-parse "$branch^{tree}")"
  origin_tree="$(git -C "$REPO_ROOT" rev-parse "origin/$branch^{tree}")"
  cherry_output="$(git -C "$REPO_ROOT" cherry -v "origin/$branch" "$branch" || true)"
  if [[ -n "$cherry_output" ]]; then
    # Replay only commits whose content is not already represented on origin.
    mapfile -t local_commits < <(printf '%s\n' "$cherry_output" | awk '$1 == "+" { print $2 }')
    equivalent_commit_count="$(printf '%s\n' "$cherry_output" | awk '$1 == "-" { count += 1 } END { print count + 0 }')"
  fi
  local_commit_count="${#local_commits[@]}"
  merge_commit_count="$(git -C "$REPO_ROOT" rev-list --count --merges "origin/$branch..$branch")"

  if (( merge_commit_count > 0 )) && [[ "$original_tree" != "$origin_tree" ]]; then
    err "Local master includes $merge_commit_count local-only merge commit(s) with content differences from origin/master."
    err "Automatic reconciliation would risk dropping merge-resolution changes."
    err "Create recovery branches and reconcile manually."
    err "Inspect with: git log --left-right --cherry-pick --oneline origin/master...master"
    exit 1
  fi

  if (( equivalent_commit_count > 0 )); then
    info "Skipping $equivalent_commit_count patch-equivalent local commit(s) already represented on origin/$branch."
  fi

  if (( merge_commit_count > 0 )); then
    warn "Skipping $merge_commit_count local-only merge commit(s); local master and origin/$branch already resolve to the same tree."
  fi

  timestamp="$(date +%Y%m%d-%H%M%S)"
  backup_local="backup/recovery-local-$timestamp"
  backup_origin="backup/recovery-origin-$timestamp"

  info "Saving recovery branches:"
  info "  $backup_local -> $original_head"
  info "  $backup_origin -> origin/$branch"

  if "$DRY_RUN"; then
    print_cmd git -C "$REPO_ROOT" branch "$backup_local" "$branch"
    print_cmd git -C "$REPO_ROOT" branch "$backup_origin" "origin/$branch"
    print_cmd git -C "$REPO_ROOT" reset --hard "origin/$branch"
  else
    git -C "$REPO_ROOT" branch "$backup_local" "$branch"
    git -C "$REPO_ROOT" branch "$backup_origin" "origin/$branch"
    git -C "$REPO_ROOT" reset --hard "origin/$branch"
  fi

  if (( local_commit_count == 0 )); then
    if "$DRY_RUN"; then
      ok "Dry-run origin reconciliation plan complete."
    else
      ok "Origin reconciliation complete; local master now matches origin/master."
    fi
    exit 0
  fi

  info "Replaying $local_commit_count local-only commit(s) onto origin/master..."
  for commit in "${local_commits[@]}"; do
    info "  $(git -C "$REPO_ROOT" show -s --format='%h %s' "$commit")"
    if "$DRY_RUN"; then
      print_cmd git -C "$REPO_ROOT" cherry-pick "$commit"
      continue
    fi

    if ! git -C "$REPO_ROOT" cherry-pick "$commit"; then
      err "Cherry-pick failed while replaying $commit."
      err "Recovery branches:"
      err "  $backup_local"
      err "  $backup_origin"
      err "Inspect with: git log --left-right --cherry-pick --oneline origin/master...$backup_local"
      exit 1
    fi
  done

  if "$DRY_RUN"; then
    ok "Dry-run origin reconciliation plan complete."
    exit 0
  fi

  final_tree="$(git -C "$REPO_ROOT" rev-parse "$branch^{tree}")"
  if [[ "$final_tree" != "$original_tree" ]]; then
    err "Origin reconciliation changed the tree compared to the original local master."
    err "Recovery branches:"
    err "  $backup_local"
    err "  $backup_origin"
    err "Restore local master with: git reset --hard $backup_local"
    exit 1
  fi

  ok "Origin reconciliation complete."
  info "Next steps:"
  info "  composer install --no-interaction --no-progress --prefer-dist --dry-run"
  info "  vendor/bin/phpunit -c phpunit.pure.xml --colors=always"
  info "  npm run git:publish"
  info "  npm run git:finish"
}

main "$@"
