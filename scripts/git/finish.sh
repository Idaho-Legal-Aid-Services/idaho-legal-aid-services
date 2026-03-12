#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

CHECK_POLL_SECONDS=5
CHECK_POLL_ATTEMPTS=36
PR_POLL_SECONDS=2
PR_POLL_ATTEMPTS=15
MASTER_RUN_POLL_SECONDS=5
MASTER_RUN_POLL_ATTEMPTS=24

usage() {
  cat <<'USAGE'
Usage:
  finish.sh

Finish the protected-master flow for the current local master commit:
  1) find the current helper PR (publish/master-<shortsha>)
  2) wait for GitHub checks to appear and pass
  3) merge the PR with a merge commit
  4) sync local master from github/master
  5) deploy Pantheon dev if origin/master is behind

If the PR is already merged, this script skips straight to sync + Pantheon deploy.
USAGE
}

ensure_clean_worktree() {
  if [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
    err "Worktree has uncommitted changes."
    err "Commit or stash them before running npm run git:finish."
    exit 1
  fi
}

expected_publish_branch() {
  printf 'publish/master-%s\n' "$(git -C "$REPO_ROOT" rev-parse --short=12 master)"
}

find_open_publish_pr() {
  local publish_branch="$1"
  local pr_json=""

  pr_json="$(gh pr list --base master --head "$publish_branch" --state open --json number,url,title)"

  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data) || empty($data[0]["number"])) {
      exit(1);
    }
    echo $data[0]["number"], "\t", ($data[0]["url"] ?? ""), "\t", ($data[0]["title"] ?? ""), PHP_EOL;
  ' <<< "$pr_json"
}

wait_for_open_publish_pr() {
  local publish_branch="$1"
  local attempt=0
  local pr_record=""

  while true; do
    if pr_record="$(find_open_publish_pr "$publish_branch")"; then
      printf '%s\n' "$pr_record"
      return 0
    fi

    attempt=$((attempt + 1))
    if (( attempt >= PR_POLL_ATTEMPTS )); then
      return 1
    fi

    info "Waiting for helper PR $publish_branch to appear on GitHub..."
    sleep "$PR_POLL_SECONDS"
  done
}

quality_checks_visible() {
  local pr_number="$1"
  local pr_json=""

  pr_json="$(gh pr view "$pr_number" --json statusCheckRollup)"

  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $checks = $data["statusCheckRollup"] ?? [];
    foreach ($checks as $check) {
      $name = $check["name"] ?? "";
      if ($name === "PHPUnit Quality Gate" || $name === "Promptfoo Gate") {
        exit(0);
      }
    }
    exit(1);
  ' <<< "$pr_json"
}

wait_for_quality_checks() {
  local pr_number="$1"
  local attempt=0

  until quality_checks_visible "$pr_number"; do
    attempt=$((attempt + 1))
    if (( attempt >= CHECK_POLL_ATTEMPTS )); then
      err "Timed out waiting for GitHub checks to appear on PR #$pr_number."
      err "Inspect with: gh pr view $pr_number --json statusCheckRollup,url"
      exit 1
    fi

    info "Waiting for GitHub checks to appear on PR #$pr_number..."
    sleep "$CHECK_POLL_SECONDS"
  done
}

find_master_quality_gate_run() {
  local head_sha="$1"
  local run_json=""

  run_json="$(gh run list --workflow "Quality Gate" --event push --limit 20 --json databaseId,headSha,status,conclusion,url)"

  php -r '
    $headSha = $argv[1];
    $runs = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($runs)) {
      exit(1);
    }

    foreach ($runs as $run) {
      if (($run["headSha"] ?? "") !== $headSha) {
        continue;
      }

      echo ($run["databaseId"] ?? ""), "\t", ($run["url"] ?? ""), "\t", ($run["status"] ?? ""), "\t", ($run["conclusion"] ?? ""), PHP_EOL;
      exit(0);
    }

    exit(1);
  ' "$head_sha" <<< "$run_json"
}

wait_for_master_quality_gate_run() {
  local head_sha="$1"
  local attempt=0
  local run_record=""

  while true; do
    if run_record="$(find_master_quality_gate_run "$head_sha")"; then
      printf '%s\n' "$run_record"
      return 0
    fi

    attempt=$((attempt + 1))
    if (( attempt >= MASTER_RUN_POLL_ATTEMPTS )); then
      return 1
    fi

    printf '[info] Waiting for post-merge Quality Gate run for %s...\n' "$head_sha" >&2
    sleep "$MASTER_RUN_POLL_SECONDS"
  done
}

main() {
  local branch=""
  local publish_branch=""
  local pr_record=""
  local pr_number=""
  local pr_url=""
  local pr_title=""
  local github_status=""
  local github_remote_only=""
  local github_local_only=""
  local origin_status=""
  local origin_remote_only=""
  local origin_local_only=""
  local merge_sha=""
  local master_run_record=""
  local master_run_id=""
  local master_run_url=""
  local master_run_status=""
  local master_run_conclusion=""
  local master_gate_ok="true"

  if (($# > 0)); then
    case "$1" in
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
  fi

  require_command gh

  branch="$(ensure_named_branch "")"
  if [[ "$branch" != "master" ]]; then
    err "git:finish must be run from local master."
    exit 1
  fi

  ensure_clean_worktree

  info "Finishing protected-master flow from $REPO_ROOT"
  fetch_remote "github"
  fetch_remote "origin"

  publish_branch="$(expected_publish_branch)"

  if pr_record="$(wait_for_open_publish_pr "$publish_branch")"; then
    IFS=$'\t' read -r pr_number pr_url pr_title <<< "$pr_record"
    info "Found helper PR #$pr_number for $publish_branch"
    if [[ -n "$pr_url" ]]; then
      info "$pr_url"
    fi
    if [[ -n "$pr_title" ]]; then
      info "PR title: $pr_title"
    fi

    wait_for_quality_checks "$pr_number"
    info "Waiting for GitHub checks to finish on PR #$pr_number..."
    gh pr checks "$pr_number" --watch

    info "Merging PR #$pr_number with a merge commit..."
    gh pr merge "$pr_number" --merge --delete-branch
  else
    IFS=$'\t' read -r github_status github_remote_only github_local_only < <(describe_remote_status "github" "$branch")
    print_remote_status "github" "$branch" "$github_status" "$github_remote_only" "$github_local_only"

    case "$github_status" in
      local-ahead)
        err "No open helper PR exists for $publish_branch."
        err "Run: npm run git:publish"
        exit 1
        ;;
      in-sync|remote-ahead)
        info "No open helper PR for the current commit; continuing with sync/deploy."
        ;;
      diverged|missing)
        err "Cannot continue while github/master is '$github_status'."
        err "Inspect with: git log --left-right --cherry-pick --oneline github/master...master"
        exit 1
        ;;
    esac
  fi

  fetch_remote "github"
  merge_sha="$(git -C "$REPO_ROOT" rev-parse github/master)"
  if ! master_run_record="$(wait_for_master_quality_gate_run "$merge_sha")"; then
    err "Timed out waiting for post-merge Quality Gate run for $merge_sha."
    err "Inspect with: gh run list --workflow \"Quality Gate\" --event push --limit 20"
    exit 1
  fi

  IFS=$'\t' read -r master_run_id master_run_url master_run_status master_run_conclusion <<< "$master_run_record"
  info "Post-merge Quality Gate run: $master_run_id"
  if [[ -n "$master_run_url" ]]; then
    info "$master_run_url"
  fi
  info "Waiting for post-merge Quality Gate run to finish..."
  if ! gh run watch "$master_run_id" --exit-status; then
    master_gate_ok="false"
  fi

  info "Syncing local master from github/master..."
  bash "$SCRIPT_DIR/sync-master.sh"

  if [[ "$master_gate_ok" != "true" ]]; then
    err "Post-merge Quality Gate failed for github/master at $merge_sha."
    err "Refusing to deploy Pantheon dev while master is red."
    exit 1
  fi

  IFS=$'\t' read -r origin_status origin_remote_only origin_local_only < <(describe_remote_status "origin" "$branch")
  print_remote_status "origin" "$branch" "$origin_status" "$origin_remote_only" "$origin_local_only"

  case "$origin_status" in
    local-ahead)
      info "Pantheon dev is behind; deploying origin/master..."
      bash "$SCRIPT_DIR/publish.sh" --origin-only
      ;;
    in-sync)
      ok "Pantheon already matches local master."
      ;;
    remote-ahead|diverged|missing)
      err "Pantheon is '$origin_status'; inspect before deploying."
      err "Inspect with: git log --left-right --cherry-pick --oneline origin/master...master"
      exit 1
      ;;
  esac

  ok "Protected-master flow complete."
}

main "$@"
