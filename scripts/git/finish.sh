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
ARTIFACT_POLL_SECONDS=3
ARTIFACT_POLL_ATTEMPTS=10
PROMPTFOO_ARTIFACT_NAME="promptfoo-gate-artifacts"
GATE_SUMMARY_ARTIFACT_NAME="gate-summary"
PROTECTED_PUSH_PROMPTFOO_CONFIG="promptfooconfig.protected-push.yaml"
PROTECTED_PUSH_RAG_MIN_COUNT="${PROTECTED_PUSH_RAG_MIN_COUNT:-2}"
PROTECTED_PUSH_P2DEL04_MIN_COUNT="${PROTECTED_PUSH_P2DEL04_MIN_COUNT:-2}"

usage() {
  cat <<'USAGE'
Usage:
  finish.sh

Finish the protected-master flow for the current local master commit:
  1) find the current helper PR (publish/master-active)
  2) wait for GitHub checks to appear and pass
  3) merge the PR with a merge commit
  4) sync local master from github/master
  5) deploy Pantheon dev if origin/master is behind
  6) run post-deploy Pantheon verification
  7) wait for the post-merge hosted master gate before returning success

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
  printf 'publish/master-active\n'
}

is_master_publish_branch() {
  local publish_branch="$1"
  [[ "$publish_branch" == "publish/master-active" || "$publish_branch" =~ ^publish/master- ]]
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

find_promptfoo_check_run_id() {
  local pr_number="$1"
  local pr_json=""

  pr_json="$(gh pr view "$pr_number" --json statusCheckRollup)"

  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $checks = $data["statusCheckRollup"] ?? [];
    foreach ($checks as $check) {
      if (($check["name"] ?? "") !== "Promptfoo Gate") {
        continue;
      }

      $detailsUrl = (string) ($check["detailsUrl"] ?? "");
      if (preg_match("~actions/runs/([0-9]+)(?:/job/[0-9]+)?~", $detailsUrl, $matches)) {
        echo $matches[1], PHP_EOL;
        exit(0);
      }
    }
    exit(1);
  ' <<< "$pr_json"
}

download_run_artifact() {
  local run_id="$1"
  local artifact_name="$2"
  local destination_dir="$3"
  local attempt=0

  while true; do
    rm -rf "$destination_dir"
    mkdir -p "$destination_dir"
    if gh run download "$run_id" --name "$artifact_name" --dir "$destination_dir" >/dev/null 2>&1; then
      if find "$destination_dir" -type f | grep -q .; then
        return 0
      fi
    fi

    attempt=$((attempt + 1))
    if (( attempt >= ARTIFACT_POLL_ATTEMPTS )); then
      return 1
    fi

    info "Waiting for $artifact_name artifact from Promptfoo Gate run $run_id..."
    sleep "$ARTIFACT_POLL_SECONDS"
  done
}

find_artifact_file() {
  local artifact_dir="$1"
  local filename="$2"

  find "$artifact_dir" -type f -name "$filename" | head -n1
}

download_promptfoo_artifacts() {
  local run_id="$1"
  local destination_dir="$2"

  if download_run_artifact "$run_id" "$PROMPTFOO_ARTIFACT_NAME" "$destination_dir"; then
    return 0
  fi

  download_run_artifact "$run_id" "$GATE_SUMMARY_ARTIFACT_NAME" "$destination_dir"
}

summary_field_value() {
  local summary_file="$1"
  local field_name="$2"
  grep -E "^${field_name}=" "$summary_file" | tail -n1 | cut -d= -f2- || true
}

require_publish_pr_promptfoo_artifact_pass() {
  local pr_number="$1"
  local publish_branch="$2"
  local promptfoo_run_id=""
  local artifact_dir=""
  local summary_file=""
  local structured_summary_file=""
  local mode=""
  local eval_execution_mode=""
  local failure_kind=""
  local eval_exit=""
  local failure_code=""

  if ! is_master_publish_branch "$publish_branch"; then
    return 0
  fi

  if ! promptfoo_run_id="$(find_promptfoo_check_run_id "$pr_number")"; then
    err "Unable to resolve the Promptfoo Gate workflow run for PR #$pr_number."
    err "Inspect with: gh pr view $pr_number --json statusCheckRollup,url"
    exit 1
  fi

  artifact_dir="$(mktemp -d)"
  if ! download_promptfoo_artifacts "$promptfoo_run_id" "$artifact_dir"; then
    rm -rf "$artifact_dir"
    err "Unable to download Promptfoo artifacts from run $promptfoo_run_id."
    err "Inspect with: gh run view $promptfoo_run_id"
    exit 1
  fi
  summary_file="$(find_artifact_file "$artifact_dir" "gate-summary.txt")"
  structured_summary_file="$(find_artifact_file "$artifact_dir" "structured-error-summary.txt")"

  if [[ -z "$summary_file" || ! -f "$summary_file" ]]; then
    rm -rf "$artifact_dir"
    err "Promptfoo artifacts from run $promptfoo_run_id do not contain gate-summary.txt."
    err "Inspect with: gh run download $promptfoo_run_id --name $PROMPTFOO_ARTIFACT_NAME"
    exit 1
  fi

  mode="$(summary_field_value "$summary_file" "mode")"
  eval_execution_mode="$(summary_field_value "$summary_file" "eval_execution_mode")"
  failure_kind="$(summary_field_value "$summary_file" "failure_kind")"
  failure_code="$(summary_field_value "$summary_file" "failure_code")"
  eval_exit="$(summary_field_value "$summary_file" "eval_exit")"

  if [[ -z "$mode" || -z "$eval_execution_mode" || -z "$eval_exit" ]]; then
    rm -rf "$artifact_dir"
    err "gate-summary artifact from Promptfoo Gate run $promptfoo_run_id is missing required fields."
    err "Inspect with: gh run download $promptfoo_run_id --name gate-summary"
    exit 1
  fi

  if [[ "$mode" != "blocking" ]]; then
    if [[ -n "$structured_summary_file" ]]; then
      err "Promptfoo structured summary:"
      cat "$structured_summary_file" >&2
    fi
    rm -rf "$artifact_dir"
    err "Refusing to merge helper PR #$pr_number because Promptfoo gate summary recorded mode=$mode."
    exit 1
  fi

  if [[ "$eval_execution_mode" != "real" ]]; then
    if [[ -n "$structured_summary_file" ]]; then
      err "Promptfoo structured summary:"
      cat "$structured_summary_file" >&2
    fi
    rm -rf "$artifact_dir"
    err "Refusing to merge helper PR #$pr_number because Promptfoo gate summary recorded eval_execution_mode=$eval_execution_mode."
    exit 1
  fi

  if [[ -n "$failure_kind" ]]; then
    if [[ -n "$structured_summary_file" ]]; then
      err "Promptfoo structured summary:"
      cat "$structured_summary_file" >&2
    fi
    rm -rf "$artifact_dir"
    err "Refusing to merge helper PR #$pr_number because Promptfoo gate summary recorded failure_kind=$failure_kind failure_code=${failure_code:-unknown}."
    exit 1
  fi

  if [[ "$eval_exit" != "0" ]]; then
    if [[ -n "$structured_summary_file" ]]; then
      err "Promptfoo structured summary:"
      cat "$structured_summary_file" >&2
    fi
    rm -rf "$artifact_dir"
    err "Refusing to merge helper PR #$pr_number because Promptfoo gate summary recorded eval_exit=$eval_exit."
    exit 1
  fi

  rm -rf "$artifact_dir"
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

print_promptfoo_failure_artifacts() {
  local artifact_dir="$1"
  local summary_file=""
  local structured_summary_file=""

  summary_file="$(find_artifact_file "$artifact_dir" "gate-summary.txt")"
  structured_summary_file="$(find_artifact_file "$artifact_dir" "structured-error-summary.txt")"

  if [[ -n "$summary_file" && -f "$summary_file" ]]; then
    err "Promptfoo gate summary:"
    cat "$summary_file" >&2
  fi

  if [[ -n "$structured_summary_file" && -f "$structured_summary_file" ]]; then
    err "Promptfoo structured summary:"
    cat "$structured_summary_file" >&2
  fi
}

print_promptfoo_failure_artifacts_from_run() {
  local run_id="$1"
  local artifact_dir=""

  artifact_dir="$(mktemp -d)"
  if download_promptfoo_artifacts "$run_id" "$artifact_dir"; then
    print_promptfoo_failure_artifacts "$artifact_dir"
  else
    err "Unable to download Promptfoo artifacts from run $run_id."
  fi
  rm -rf "$artifact_dir"
}

resolve_pantheon_dev_assistant_url() {
  if [[ -n "${ILAS_ASSISTANT_URL:-}" ]]; then
    printf '%s\n' "$ILAS_ASSISTANT_URL"
    return 0
  fi

  bash "$REPO_ROOT/scripts/ci/derive-assistant-url.sh" --env dev
}

run_post_deploy_pantheon_verification() {
  local assistant_url=""

  if ! assistant_url="$(resolve_pantheon_dev_assistant_url)"; then
    err "Unable to resolve the Pantheon dev assistant URL for post-deploy verification."
    err "Set ILAS_ASSISTANT_URL or ensure terminus can resolve idaho-legal-aid-services.dev."
    return 1
  fi

  info "Running post-deploy Pantheon dev hosted verification..."
  info "$assistant_url"

  if (
    cd "$REPO_ROOT"
    ILAS_ASSISTANT_URL="$assistant_url" \
    CI_BRANCH=master \
    RAG_METRIC_MIN_COUNT="$PROTECTED_PUSH_RAG_MIN_COUNT" \
    P2DEL04_METRIC_MIN_COUNT="$PROTECTED_PUSH_P2DEL04_MIN_COUNT" \
    bash scripts/ci/run-promptfoo-gate.sh \
      --env dev \
      --mode blocking \
      --config "$PROTECTED_PUSH_PROMPTFOO_CONFIG" \
      --no-deep-eval
  ); then
    ok "Post-deploy Pantheon dev hosted verification passed."
    return 0
  fi

  err "Post-deploy Pantheon dev hosted verification failed."
  print_promptfoo_failure_artifacts "$REPO_ROOT/promptfoo-evals/output"
  return 1
}

list_open_publish_pr_heads() {
  gh pr list --state open --json headRefName --limit 100 | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data)) {
      exit(0);
    }
    foreach ($data as $pr) {
      $head = (string) ($pr["headRefName"] ?? "");
      if (preg_match("~^publish/~", $head)) {
        echo $head, PHP_EOL;
      }
    }
  '
}

prune_merged_publish_branches() {
  local open_publish_heads=""
  local branch=""

  open_publish_heads="$(list_open_publish_pr_heads || true)"

  while IFS= read -r branch; do
    if [[ -z "$branch" || "$branch" != publish/* ]]; then
      continue
    fi
    if printf '%s\n' "$open_publish_heads" | grep -Fxq "$branch"; then
      continue
    fi
    if ! git -C "$REPO_ROOT" merge-base --is-ancestor "github/$branch" "github/master" 2>/dev/null; then
      continue
    fi

    info "Deleting merged helper branch github/$branch..."
    if ! git -C "$REPO_ROOT" push github ":refs/heads/$branch" >/dev/null 2>&1; then
      warn "Unable to delete github/$branch automatically."
    fi
  done < <(git -C "$REPO_ROOT" for-each-ref --format='%(refname:strip=3)' refs/remotes/github/publish)
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
  local post_deploy_ok="true"

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
    if ! gh pr checks "$pr_number" --watch; then
      err "GitHub checks failed on PR #$pr_number."
      err "Inspect with: gh pr checks $pr_number"
      exit 1
    fi

    require_publish_pr_promptfoo_artifact_pass "$pr_number" "$publish_branch"

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

  info "Syncing local master from github/master..."
  bash "$SCRIPT_DIR/sync-master.sh"

  IFS=$'\t' read -r origin_status origin_remote_only origin_local_only < <(describe_remote_status "origin" "$branch")
  print_remote_status "origin" "$branch" "$origin_status" "$origin_remote_only" "$origin_local_only"

  case "$origin_status" in
    local-ahead)
      info "Pantheon dev is behind; deploying origin/master through the local DDEV deploy gate..."
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

  if ! run_post_deploy_pantheon_verification; then
    post_deploy_ok="false"
  fi

  if ! master_run_record="$(wait_for_master_quality_gate_run "$merge_sha")"; then
    master_gate_ok="false"
    err "Timed out waiting for post-merge Quality Gate run for $merge_sha."
    err "Inspect with: gh run list --workflow \"Quality Gate\" --event push --limit 20"
  else
    IFS=$'\t' read -r master_run_id master_run_url master_run_status master_run_conclusion <<< "$master_run_record"
    info "Post-merge Quality Gate run: $master_run_id"
    if [[ -n "$master_run_url" ]]; then
      info "$master_run_url"
    fi
    info "Waiting for post-merge Quality Gate run to finish..."
    if ! gh run watch "$master_run_id" --exit-status; then
      master_gate_ok="false"
      err "Post-merge Quality Gate failed for github/master at $merge_sha."
      print_promptfoo_failure_artifacts_from_run "$master_run_id"
    fi
  fi

  fetch_remote "github"
  prune_merged_publish_branches

  if [[ "$master_gate_ok" != "true" || "$post_deploy_ok" != "true" ]]; then
    err "Protected-master flow is incomplete."
    err "Completion requires both the hosted GitHub master gate and the post-deploy Pantheon dev verification to pass."
    exit 1
  fi

  ok "Protected-master flow complete."
}

main "$@"
