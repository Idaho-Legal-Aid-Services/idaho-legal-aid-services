#!/usr/bin/env bash

GIT_HELPER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${REPO_ROOT:-}" ]]; then
  if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    REPO_ROOT="$(cd "$GIT_HELPER_DIR/../.." && pwd)"
  fi
fi
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
  # Emit a 4-column tab-separated record:
  #   col1: status token — in-sync | local-ahead | remote-ahead | diverged | missing
  #   col2: remote_only commit count (commits remote has that local lacks)
  #   col3: local_only commit count (commits local has that remote lacks)
  #   col4: recovery command (non-empty for drift types; empty for in-sync and local-ahead)
  #
  # PIPE-06: 4th column = recovery command.
  # Backward-compat: existing 3-column consumers (IFS=$'\t' read -r STATUS C2 C3) silently
  # drop the 4th column; new consumers read all 4 columns for the recovery command.
  local remote="$1"
  local branch="$2"
  local raw=""
  local remote_only=""
  local local_only=""
  local status=""
  local recovery=""

  if ! remote_ref_exists "$remote" "$branch"; then
    recovery="git remote add $remote <url>; git fetch $remote"
    printf 'missing\t-\t-\t%s\n' "$recovery"
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

  # PIPE-06: compute recovery command from (status, remote) pair.
  case "$status:$remote" in
    missing:*)
      recovery="git remote add $remote <url>; git fetch $remote" ;;
    remote-ahead:github)
      recovery="npm run git:sync-master" ;;
    remote-ahead:origin)
      recovery="npm run git:reconcile-origin" ;;
    diverged:github)
      recovery="git log --left-right --cherry-pick --oneline github/$branch...$branch; npm run git:sync-master" ;;
    diverged:origin)
      recovery="git log --left-right --cherry-pick --oneline origin/$branch...$branch; npm run git:reconcile-origin" ;;
    in-sync:*|local-ahead:*)
      recovery="" ;;
    *)
      recovery="(unknown drift type: $status)" ;;
  esac

  printf '%s\t%s\t%s\t%s\n' "$status" "$remote_only" "$local_only" "$recovery"
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

# Lists the checks of a pull request that did not pass.
#
# Reads the JSON of `gh pr view <n> --json statusCheckRollup` on stdin and
# prints one "name: outcome" line for every check that is not a success, a
# skip or neutral, including one that is still running. Prints nothing when
# every check passed.
#
# `gh pr checks --watch` cannot be used for this decision: gh 2.45 shows a
# cancelled job as "fail" but still exits 0, so a job cancelled at its time
# limit let a pull request through on 2026-11-20.
checks_not_passed() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data) || !isset($data["statusCheckRollup"]) || !is_array($data["statusCheckRollup"])) {
      echo "(could not read the checks of the pull request)", PHP_EOL;
      exit(0);
    }
    foreach ($data["statusCheckRollup"] as $check) {
      $name = (string) ($check["name"] ?? $check["context"] ?? "unnamed check");
      if (isset($check["state"]) && !isset($check["conclusion"])) {
        // A commit status rather than a check run.
        $outcome = strtoupper((string) $check["state"]);
        $passed = $outcome === "SUCCESS";
      }
      else {
        $status = strtoupper((string) ($check["status"] ?? ""));
        $outcome = strtoupper((string) ($check["conclusion"] ?? ""));
        if ($status !== "COMPLETED" || $outcome === "") {
          $outcome = $status === "" ? "UNKNOWN" : $status;
          $passed = false;
        }
        else {
          $passed = in_array($outcome, ["SUCCESS", "SKIPPED", "NEUTRAL"], true);
        }
      }
      if (!$passed) {
        echo $name, ": ", strtolower($outcome), PHP_EOL;
      }
    }
  '
}
