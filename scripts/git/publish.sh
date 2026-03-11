#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

BRANCH=""
DO_FETCH=true
DRY_RUN=false
TARGET_MODE="both"
NO_VERIFY_ORIGIN=false
NO_VERIFY_GITHUB=false
REMOTES=("${DEFAULT_REMOTES[@]}")

usage() {
  cat <<'USAGE'
Usage:
  publish.sh [--branch <branch>] [--origin-only|--github-only] [--no-fetch]
             [--no-verify-origin] [--no-verify-github] [--dry-run]

On local master, the default flow is GitHub PR-first:
  1) push HEAD to github/publish/master-<shortsha>
  2) create or update a PR into protected github/master
  3) stop there

Pantheon deploys from local master are explicit:
  bash scripts/git/publish.sh --origin-only

Examples:
  bash scripts/git/publish.sh
  bash scripts/git/publish.sh --origin-only --no-verify-origin
  bash scripts/git/publish.sh --github-only --dry-run
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
      --origin-only)
        TARGET_MODE="origin"
        shift
        ;;
      --github-only)
        TARGET_MODE="github"
        shift
        ;;
      --no-fetch)
        DO_FETCH=false
        shift
        ;;
      --no-verify-origin)
        NO_VERIFY_ORIGIN=true
        shift
        ;;
      --no-verify-github)
        NO_VERIFY_GITHUB=true
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

push_refspec() {
  local remote="$1"
  local refspec="$2"
  local no_verify="$3"
  local args=(git -C "$REPO_ROOT" push "$remote" "$refspec")

  if [[ "$no_verify" == "true" ]]; then
    args=(git -C "$REPO_ROOT" push --no-verify "$remote" "$refspec")
  fi

  if "$DRY_RUN"; then
    print_cmd "${args[@]}"
    return 0
  fi

  "${args[@]}"
}

github_publish_branch() {
  local branch="$1"
  local short_sha=""

  short_sha="$(git -C "$REPO_ROOT" rev-parse --short=12 "$branch")"
  printf 'publish/%s-%s\n' "$branch" "$short_sha"
}

github_pr_title() {
  local branch="$1"
  git -C "$REPO_ROOT" show -s --format=%s "$branch"
}

github_pr_body() {
  local branch="$1"
  local publish_branch="$2"
  local short_sha=""

  short_sha="$(git -C "$REPO_ROOT" rev-parse --short=12 "$branch")"

  cat <<EOF
Automated by \`npm run git:publish\`.

- source branch: \`$branch\`
- protected target: \`master\`
- helper branch: \`$publish_branch\`
- head commit: \`$short_sha\`

Next steps:
1. Wait for \`PHPUnit Quality Gate\` and \`Promptfoo Gate\`.
2. Merge this PR into \`master\` after the required GitHub checks pass.
3. Run \`npm run git:sync-master\` to sync local \`master\` from GitHub.
4. Run \`npm run git:publish -- --origin-only\` to deploy Pantheon.
EOF
}

ensure_master_github_pr() {
  local branch="$1"
  local no_verify="$2"
  local publish_branch=""
  local title=""
  local body=""
  local pr_json=""
  local pr_url=""
  local pr_number=""

  require_command gh

  publish_branch="$(github_publish_branch "$branch")"
  title="$(github_pr_title "$branch")"
  body="$(github_pr_body "$branch" "$publish_branch")"

  info "Publishing local $branch to github/$publish_branch for PR flow..."
  push_refspec "github" "$branch:refs/heads/$publish_branch" "$no_verify"

  if "$DRY_RUN"; then
    print_cmd gh pr list --head "$publish_branch" --base master --state open --json number,url
    print_cmd gh pr create --base master --head "$publish_branch" --title "$title" --body "$body"
    ok "Dry-run PR publish plan complete."
    return 0
  fi

  pr_json="$(gh pr list --head "$publish_branch" --base master --state open --json number,url)"
  pr_url="$(php -r '$data = json_decode(stream_get_contents(STDIN), true); if (!empty($data[0]["url"])) { echo $data[0]["url"]; }' <<< "$pr_json")"

  if [[ -n "$pr_url" ]]; then
    pr_number="$(php -r '$data = json_decode(stream_get_contents(STDIN), true); if (!empty($data[0]["number"])) { echo $data[0]["number"]; }' <<< "$pr_json")"
    gh pr edit "$pr_number" --title "$title" --body "$body" >/dev/null
    ok "Updated PR: $pr_url"
    return 0
  fi

  pr_url="$(gh pr create --base master --head "$publish_branch" --title "$title" --body "$body")"
  ok "Created PR: $pr_url"
}

main() {
  local branch=""
  local remote=""
  local status=""
  local remote_only=""
  local local_only=""
  local github_master_status=""
  local no_verify=false
  local targets=()
  declare -A remote_status=()

  parse_args "$@"

  branch="$(ensure_named_branch "$BRANCH")"
  require_local_branch "$branch"

  info "Publishing branch '$branch' from $REPO_ROOT"
  warn_if_worktree_dirty

  if "$DO_FETCH"; then
    for remote in "${REMOTES[@]}"; do
      fetch_remote "$remote"
    done
  fi

  for remote in "${REMOTES[@]}"; do
    IFS=$'\t' read -r status remote_only local_only < <(describe_remote_status "$remote" "$branch")
    remote_status["$remote"]="$status"
    print_remote_status "$remote" "$branch" "$status" "$remote_only" "$local_only"
  done

  github_master_status="${remote_status[github]}"

  if [[ "$branch" == "master" ]]; then
    case "$TARGET_MODE" in
      both|github)
        if [[ "$github_master_status" == "remote-ahead" || "$github_master_status" == "diverged" ]]; then
          err "Refusing to publish from local master while github/master is '$github_master_status'."
          err "Inspect with: git log --left-right --cherry-pick --oneline github/master...master"
          exit 1
        fi

        if [[ "$github_master_status" == "in-sync" ]]; then
          ok "github/master already matches local master; no PR publish is needed."
          if [[ "${remote_status[origin]}" == "local-ahead" ]]; then
            warn "Pantheon is behind. Run: npm run git:publish -- --origin-only"
          fi
          exit 0
        fi

        no_verify=false
        if [[ "$NO_VERIFY_GITHUB" == "true" ]]; then
          no_verify=true
          warn "Bypassing pre-push hooks for github publish branch."
        fi

        ensure_master_github_pr "$branch" "$no_verify"

        if [[ "$TARGET_MODE" == "both" ]]; then
          info "Pantheon deploy is intentionally separate. After merge + local fast-forward, run: npm run git:publish -- --origin-only"
        fi
        exit 0
        ;;

      origin)
        if [[ "$github_master_status" != "in-sync" ]]; then
          err "Refusing to push origin/master until github/master matches local master."
          err "Run: npm run git:publish"
          exit 1
        fi
        ;;
    esac
  fi

  case "$TARGET_MODE" in
    both)
      targets=(github origin)
      ;;
    origin)
      targets=(origin)
      ;;
    github)
      targets=(github)
      ;;
    *)
      err "Unknown target mode: $TARGET_MODE"
      exit 1
      ;;
  esac

  for remote in "${targets[@]}"; do
    status="${remote_status[$remote]}"
    if [[ "$status" == "remote-ahead" || "$status" == "diverged" ]]; then
      err "Refusing to push $remote/$branch while status is '$status'."
      err "Inspect with: git log --left-right --cherry-pick --oneline $remote/$branch...$branch"
      exit 1
    fi
  done

  if [[ "$TARGET_MODE" == "origin" && "${remote_status[github]}" != "in-sync" ]]; then
    warn "github/$branch will remain '${remote_status[github]}' after this origin-only publish."
  fi

  if [[ "$TARGET_MODE" == "github" && "${remote_status[origin]}" != "in-sync" ]]; then
    warn "origin/$branch will remain '${remote_status[origin]}' after this github-only publish."
  fi

  for remote in "${targets[@]}"; do
    status="${remote_status[$remote]}"

    if [[ "$status" == "in-sync" ]]; then
      info "Skipping $remote/$branch; already in sync."
      continue
    fi

    no_verify=false
    if [[ "$remote" == "origin" && "$NO_VERIFY_ORIGIN" == "true" ]]; then
      no_verify=true
      warn "Bypassing pre-push hooks for origin/$branch."
    elif [[ "$remote" == "github" && "$NO_VERIFY_GITHUB" == "true" ]]; then
      no_verify=true
      warn "Bypassing pre-push hooks for github/$branch."
    fi

    info "Pushing $branch to $remote/$branch..."
    push_refspec "$remote" "$branch:$branch" "$no_verify"
  done

  if "$DRY_RUN"; then
    ok "Dry-run publish plan complete."
  else
    ok "Publish sequence complete."
  fi
}

main "$@"
