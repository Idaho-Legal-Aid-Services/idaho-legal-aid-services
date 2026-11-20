#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME="$(basename "$0")"
DEFAULT_SITE="idaho-legal-aid-services"

SITE_NAME="$DEFAULT_SITE"
ENV_NAME=""
DRY_RUN=false
YES_LIVE=false
SKIP_LIVE_BACKUP=false
SKIP_SMOKE=false
SKIP_PROMOTE=false
DEPLOY_NOTE=""
CHECK_ONLY=false
PUSHED_AT=""
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Seconds between looks at Pantheon while it is still working, and between
# rechecks of a result that may not have settled. Tests set these to 0.
WORKFLOW_POLL_SECONDS="${ILAS_DEPLOY_WORKFLOW_POLL_SECONDS:-30}"
RECHECK_SECONDS="${ILAS_DEPLOY_RECHECK_SECONDS:-60}"

usage() {
  cat <<'USAGE'
Usage:
  pantheon-deploy.sh --env <dev|test|live> [--site <machine-name>] [--dry-run] [--yes-live] [--skip-live-backup] [--skip-promote] [--note <text>] [--skip-smoke]
  pantheon-deploy.sh --check [--site <machine-name>]

--check changes nothing. It reports whether this checkout, GitHub, Pantheon
and the dev, test and live environments are all on the same code, and exits
non-zero if they are not.

This is the one deploy command for every environment:
  dev   code arrives by git push; this applies it (database updates, config
        import, cache rebuild) and checks the result.
  test  promotes the code from dev, applies it and checks the result.
  live  backs up, asks for confirmation, promotes from test, applies, checks.

Options:
  --env <env>            Target Pantheon environment (dev|test|live). Required.
  --site <name>          Pantheon site machine name. Default: idaho-legal-aid-services
  --dry-run              Print commands without executing remote operations.
  --yes-live             Skip live confirmation prompt.
  --skip-live-backup     Skip automatic live backup creation.
  --skip-promote         test/live: do not run terminus env:deploy (code is already there).
  --note <text>          test/live: note recorded on the Pantheon deploy. Default: the local commit.
  --skip-smoke           Skip the post-deploy site smoke, assistant smoke and Langfuse probe.
  --pushed-at <epoch>    dev: code was pushed at this time; wait for the Pantheon code sync
                         that the push started before touching the environment.
  -h, --help             Show this help output.
USAGE
}

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

print_cmd() {
  local rendered=()
  local arg
  for arg in "$@"; do
    printf -v arg '%q' "$arg"
    rendered+=("$arg")
  done
  printf '[dry-run] %s\n' "${rendered[*]}"
}

run_cmd() {
  if "$DRY_RUN"; then
    print_cmd "$@"
    return 0
  fi
  "$@"
}

require_command() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    err "Required command not found: $cmd"
    exit 1
  fi
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --env)
        if (($# < 2)); then
          err "Missing value for --env"
          usage
          exit 1
        fi
        ENV_NAME="$2"
        shift 2
        ;;
      --env=*)
        ENV_NAME="${1#*=}"
        shift
        ;;
      --site)
        if (($# < 2)); then
          err "Missing value for --site"
          usage
          exit 1
        fi
        SITE_NAME="$2"
        shift 2
        ;;
      --site=*)
        SITE_NAME="${1#*=}"
        shift
        ;;
      --dry-run)
        DRY_RUN=true
        shift
        ;;
      --yes-live)
        YES_LIVE=true
        shift
        ;;
      --skip-live-backup)
        SKIP_LIVE_BACKUP=true
        shift
        ;;
      --skip-smoke)
        SKIP_SMOKE=true
        shift
        ;;
      --skip-promote)
        SKIP_PROMOTE=true
        shift
        ;;
      --check)
        CHECK_ONLY=true
        shift
        ;;
      --pushed-at)
        if (($# < 2)) || [[ ! "$2" =~ ^[0-9]+$ ]]; then
          err "--pushed-at needs a time in seconds since the epoch."
          exit 1
        fi
        PUSHED_AT="$2"
        shift 2
        ;;
      --note)
        if (($# < 2)); then
          err "Missing value for --note"
          usage
          exit 1
        fi
        DEPLOY_NOTE="$2"
        shift 2
        ;;
      --note=*)
        DEPLOY_NOTE="${1#*=}"
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

validate_args() {
  if "$CHECK_ONLY"; then
    return 0
  fi
  if [[ -z "$ENV_NAME" ]]; then
    err "--env is required."
    usage
    exit 1
  fi

  if [[ ! "$ENV_NAME" =~ ^(dev|test|live)$ ]]; then
    err "Invalid --env value: $ENV_NAME (expected dev, test, or live)."
    exit 1
  fi

  if [[ -z "$SITE_NAME" ]]; then
    err "--site cannot be empty."
    exit 1
  fi
}

preflight_checks() {
  require_command terminus
  require_command php

  if "$DRY_RUN"; then
    info "Dry run enabled; skipping Terminus auth check."
    return
  fi

  if ! terminus auth:whoami >/dev/null 2>&1; then
    err "Terminus is not authenticated. Run: terminus auth:login"
    exit 1
  fi

  ok "Terminus authenticated as $(terminus auth:whoami)"
}

live_guardrails() {
  local site_env="$1"

  if [[ "$ENV_NAME" != "live" ]]; then
    return
  fi

  if "$SKIP_LIVE_BACKUP"; then
    warn "Skipping live backup due to --skip-live-backup."
  else
    info "Creating live backup before deployment..."
    run_cmd terminus backup:create "$site_env" --keep-for=7
    if ! "$DRY_RUN"; then
      ok "Live backup created for $site_env."
    fi
  fi

  if "$YES_LIVE"; then
    warn "Live confirmation bypassed with --yes-live."
    return
  fi

  if "$DRY_RUN"; then
    print_cmd read -r LIVE_CONFIRMATION
    info "Dry run enabled; skipping interactive live confirmation."
    return
  fi

  local token="DEPLOY ${site_env}"
  echo
  warn "Live deployment confirmation required."
  warn "Type this exact token to continue: $token"
  printf '> '
  local input=""
  read -r input
  if [[ "$input" != "$token" ]]; then
    err "Confirmation token mismatch. Aborting live deployment."
    exit 1
  fi
  ok "Live confirmation accepted."
}

show_pending_updates() {
  local site_env="$1"
  local phase="$2"
  info "Pending database updates ($phase):"
  run_cmd terminus drush "$site_env" -- updatedb:status --format=table
}

pending_update_count() {
  local site_env="$1"

  if "$DRY_RUN"; then
    echo "0"
    return 0
  fi

  local output
  if ! output="$(terminus drush "$site_env" -- updatedb:status --format=table 2>&1)"; then
    echo "unknown"
    return 0
  fi

  # Drush may emit warnings/notices alongside status output. Rely on the
  # stable success phrase + table row pattern instead of strict JSON parsing.
  if printf '%s\n' "$output" | grep -q "No database updates required."; then
    echo "0"
    return 0
  fi

  local count
  count="$(
    printf '%s\n' "$output" | awk '
      /^[[:space:]]*[A-Za-z0-9_]+[[:space:]]+[0-9]+[[:space:]]+/ { c++ }
      END { print c+0 }
    '
  )"

  if [[ -z "$count" || ! "$count" =~ ^[0-9]+$ ]]; then
    echo "unknown"
    return 0
  fi

  echo "$count"
}

check_config_drift() {
  local site_env="$1"
  # After the config import, active config must equal the repository on test
  # and live; a difference there means the import did not apply or someone
  # changed config by hand. Dev is allowed to differ (work in progress).
  info "Config drift status:"

  if "$DRY_RUN"; then
    run_cmd terminus drush "$site_env" -- config:status
    return
  fi

  # config:status can still report an object as Different for a short while
  # after an import that changed it: on dev it did so 27 seconds after a
  # successful import and was clean three minutes later with nothing done in
  # between. A difference is therefore only treated as drift once it has
  # survived two rechecks a minute apart; real drift does not go away.
  local attempt=1
  local max_attempts=3
  local status_output=""
  while true; do
    if ! status_output="$(terminus drush "$site_env" -- config:status 2>&1)"; then
      printf '%s\n' "$status_output"
      if [[ "$ENV_NAME" == "dev" ]]; then
        warn "Unable to fetch config:status (non-fatal on dev)."
        return 0
      fi
      err "Unable to fetch config:status for $site_env."
      return 1
    fi

    printf '%s\n' "$status_output"
    if printf '%s\n' "$status_output" | grep -Eq 'No differences|No config changes|No changes'; then
      ok "No config drift reported."
      return 0
    fi
    if [[ "$ENV_NAME" == "dev" ]]; then
      warn "Config status reported differences on dev. Review output above."
      return 0
    fi
    if (( attempt >= max_attempts )); then
      err "Config on $site_env still differs from the repository ${max_attempts} checks after the import."
      return 1
    fi

    warn "Config status reported differences (check ${attempt} of ${max_attempts}); rechecking in ${RECHECK_SECONDS} seconds."
    attempt=$((attempt + 1))
    sleep "$RECHECK_SECONDS"
  done
}

# Moves code to test (from dev) or live (from test).
#
# Dev receives code by git push, so there is nothing to promote there.
promote_code() {
  local site_env="$1"

  if [[ "$ENV_NAME" == "dev" ]]; then
    return 0
  fi
  if "$SKIP_PROMOTE"; then
    warn "Skipping terminus env:deploy due to --skip-promote."
    return 0
  fi

  local note="$DEPLOY_NOTE"
  if [[ -z "$note" ]]; then
    note="$(git -C "$REPO_ROOT" log -1 --format='%h %s' 2>/dev/null || true)"
    note="${note:-Deploy via pantheon-deploy.sh}"
  fi

  info "Promoting code to $site_env ..."
  run_cmd terminus env:deploy "$site_env" --updatedb --cc --note="$note" -y
}

# Applies the code: database updates, config import, cache rebuild.
#
# When the config import installs or uninstalls a module, Drupal's locale
# module afterwards rewrites translated configuration from the interface
# translations, replacing the translations kept in the repository. On
# 2026-11-20 enabling one module on dev replaced sixteen Spanish and Dutch
# configuration translations, front page metatags among them; a second
# import restored every one. So after such a deploy config is imported once
# more. The drift check that follows still decides whether the result is
# right.
apply_deploy() {
  local site_env="$1"
  local output=""

  if "$DRY_RUN"; then
    print_cmd terminus drush "$site_env" -- deploy -y
    return 0
  fi

  output="$(mktemp)"
  if ! terminus drush "$site_env" -- deploy -y 2>&1 | tee "$output"; then
    rm -f "$output"
    err "drush deploy failed on $site_env."
    exit 1
  fi

  if grep -q 'Synchronized extensions' "$output"; then
    warn "This deploy installed or uninstalled a module, after which Drupal rewrites translated configuration."
    warn "Importing config once more to put the repository's translations back ..."
    if ! terminus drush "$site_env" -- config:import -y; then
      rm -f "$output"
      err "The second config import failed on $site_env."
      exit 1
    fi
  fi
  rm -f "$output"
}

# Proves the environment's application container runs the code it was given.
#
# Pantheon records the commit an environment should be on before it rebuilds
# the container. On 2026-11-19 that rebuild ("Bring application container to
# desired configuration state") failed twice: dev kept its old custom code,
# and live got the new custom code with the previous day's packages. Nothing
# else in this script looks at which files are actually there, so the
# environment is asked for a fingerprint of them, which is compared with the
# same fingerprint computed from git for the recorded commit. This runs
# before the database updates so that they are never applied by the wrong
# code.
verify_deployed_code() {
  local site_env="$1"
  local fingerprint_script="$REPO_ROOT/scripts/deploy/code-fingerprint.php"
  # Relative to the site root, which is where Drush runs, so the same call
  # works on Pantheon (/code) and in DDEV (/var/www/html).
  local remote_script="scripts/deploy/code-fingerprint.php"

  info "Checking that $site_env runs the code it was given ..."
  if "$DRY_RUN"; then
    print_cmd terminus env:code-log "$site_env" --format=csv --fields=hash
    print_cmd terminus drush "$site_env" -- php:script "$remote_script"
    print_cmd php "$fingerprint_script" --git-ref="<recorded commit>" --compare="<environment report>"
    return 0
  fi

  local expected=""
  if ! expected="$(recorded_commit "$site_env")"; then
    return 1
  fi

  # Pantheon records the commit at once and rebuilds the container afterwards,
  # which takes minutes after a push to dev. Checking before that has finished
  # would compare against the old container, so wait for Pantheon first, and
  # look again if the first answer is wrong: the workflow for a push can take
  # a moment to appear.
  local verdict="" status=0 attempt=1 max_attempts=3
  while true; do
    wait_for_pantheon_workflows || return 1

    status=0
    verdict="$(compare_with_commit "$site_env" "$expected")" || status=$?
    if (( status == 0 )); then
      ok "$site_env runs ${expected:0:10}: $verdict"
      return 0
    fi

    printf '%s\n' "$verdict" >&2
    if (( attempt >= max_attempts )); then
      break
    fi
    warn "$site_env does not match ${expected:0:10} yet (check ${attempt} of ${max_attempts}); rechecking in ${RECHECK_SECONDS} seconds."
    attempt=$((attempt + 1))
    sleep "$RECHECK_SECONDS"
  done

  err "$site_env is not running commit ${expected:0:10}, although Pantheon records it as deployed there."
  err "Look at 'terminus workflow:list $SITE_NAME' for a failed code sync or deploy. Nothing has been applied by this run."
  return 1
}

# Prints the commit Pantheon records for an environment.
#
# On test and live the first entry is Pantheon's own build-artifact commit,
# which exists only there; the commit that was promoted is the one below it.
recorded_commit() {
  local site_env="$1"
  local env_name="${site_env##*.}"
  local hashes="" candidate="" position=0

  if ! hashes="$(terminus env:code-log "$site_env" --format=csv --fields=hash 2>/dev/null | grep -oE '[0-9a-f]{40}' | head -n 2)"; then
    err "Could not read the commit recorded for $site_env."
    return 1
  fi
  for candidate in $hashes; do
    position=$((position + 1))
    if [[ "$env_name" == "dev" ]] && (( position > 1 )); then
      break
    fi
    if git -C "$REPO_ROOT" cat-file -e "${candidate}^{commit}" 2>/dev/null; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  err "The commit recorded for $site_env is not in this checkout. Fetch, then run this again."
  return 1
}

# Asks an environment for its fingerprint and compares it with a commit.
#
# Prints one line per difference, or one line saying everything matches.
compare_with_commit() {
  local site_env="$1"
  local commit="$2"
  local report="" status=0

  report="$(mktemp)"
  terminus drush "$site_env" -- php:script scripts/deploy/code-fingerprint.php 2>/dev/null | grep -m 1 '^{"source":"disk"' >"$report" || true
  php "$REPO_ROOT/scripts/deploy/code-fingerprint.php" --git-ref="$commit" --compare="$report" || status=$?
  rm -f "$report"
  return "$status"
}

# Reports, without changing anything, whether this checkout, GitHub, Pantheon
# and the three environments are all on the same code.
#
# For each environment: is Pantheon still working on it or did its last
# workflow fail, which commit it records, whether its files and packages are
# that commit, whether database updates are pending, and whether its config
# equals the repository. Exits 1 if anything is out of line. Config that
# differs on dev is shown but allowed, as in a deploy.
run_alignment_check() {
  local problems=0 head="" remote="" remote_head="" env="" site_env=""
  local statuses="" state="" commit="" verdict="" pending="" config=""

  head="$(git -C "$REPO_ROOT" rev-parse HEAD)"
  printf '%-22s %s\n' "this checkout" "${head:0:10}"
  if [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
    printf '%-22s %s\n' "" "(has uncommitted changes)"
  fi
  for remote in github origin; do
    remote_head="$(git -C "$REPO_ROOT" ls-remote "$remote" refs/heads/master 2>/dev/null | cut -c1-40)"
    if [[ "$remote_head" == "$head" ]]; then
      printf '%-22s %s\n' "$remote master" "${remote_head:0:10}"
    else
      printf '%-22s %s  <-- differs from this checkout\n' "$remote master" "${remote_head:0:10}"
      problems=$((problems + 1))
    fi
  done

  if ! statuses="$(terminus workflow:list "$SITE_NAME" --format=csv --fields=env,status,started_at,workflow 2>/dev/null)"; then
    err "Could not list Pantheon workflows for $SITE_NAME."
    return 1
  fi

  for env in dev test live; do
    site_env="${SITE_NAME}.${env}"
    printf '\n%s\n' "$env"

    state="$(printf '%s\n' "$statuses" | grep -m 1 -E "^${env},[a-z]+,.*(Sync code|Deploy code)" | cut -d, -f2)"
    if printf '%s\n' "$statuses" | grep -q "^${env},running"; then
      printf '  %-18s %s\n' "Pantheon" "still working on it  <-- wait and check again (nothing else was asked of $env)"
      problems=$((problems + 1))
      continue
    elif [[ "$state" == "failed" ]]; then
      printf '  %-18s %s\n' "Pantheon" "last code workflow FAILED  <-- the lines below show what it left behind"
      problems=$((problems + 1))
    else
      printf '  %-18s %s\n' "Pantheon" "idle, last code workflow ${state:-unknown}"
    fi

    if ! commit="$(recorded_commit "$site_env")"; then
      problems=$((problems + 1))
      continue
    fi
    if [[ "$commit" == "$head" ]]; then
      printf '  %-18s %s\n' "recorded commit" "${commit:0:10}"
    else
      printf '  %-18s %s  <-- differs from this checkout\n' "recorded commit" "${commit:0:10}"
      problems=$((problems + 1))
    fi

    if verdict="$(compare_with_commit "$site_env" "$commit")"; then
      printf '  %-18s %s\n' "files and packages" "$verdict"
    elif [[ "$verdict" == "The environment did not return a fingerprint." ]]; then
      printf '  %-18s %s\n' "files and packages" "cannot tell: no fingerprint script there (code older than the check, or not deployed)  <-- unproven"
      problems=$((problems + 1))
    else
      printf '  %-18s %s  <-- NOT the recorded commit\n' "files and packages" "$(printf '%s' "$verdict" | tr '\n' ' ')"
      problems=$((problems + 1))
    fi

    pending="$(pending_update_count "$site_env")"
    if [[ "$pending" == "0" ]]; then
      printf '  %-18s %s\n' "database updates" "none pending"
    else
      printf '  %-18s %s  <-- not applied\n' "database updates" "$pending pending"
      problems=$((problems + 1))
    fi

    config="$(terminus drush "$site_env" -- config:status 2>&1 || true)"
    if printf '%s\n' "$config" | grep -Eq 'No differences|No config changes|No changes'; then
      printf '  %-18s %s\n' "config" "matches the repository"
    elif [[ "$env" == "dev" ]]; then
      printf '  %-18s %s\n' "config" "differs from the repository (allowed on dev)"
    else
      printf '  %-18s %s\n' "config" "differs from the repository  <-- not imported, or changed by hand"
      problems=$((problems + 1))
    fi
  done

  echo
  if (( problems > 0 )); then
    err "Not aligned: $problems item(s) marked above."
    return 1
  fi
  ok "Aligned: this checkout, GitHub, Pantheon and all three environments are on ${head:0:10}."
}

# Waits until Pantheon has no workflow running on the target environment.
#
# A push to dev starts a "Sync code" workflow that builds and replaces the
# application container, which takes three to six minutes. Until it ends the
# environment still runs the old code, so nothing here may act on it: from
# 2026-11-18 this script ran straight after the push and applied updates,
# rebuilt caches and passed its smokes on the previous code.
#
# (Whether acting on the environment during the rebuild also makes Pantheon's
# container step fail was tested on 2026-11-20 with eight rebuilds and pushes
# on a Multidev, half of them under Drush and page load: all eight succeeded
# and the step took 26 to 132 seconds either way. It does not.)
#
# With "wait-only" the state of the last code workflow is not judged (before
# a promote, which starts a new one). Otherwise a last code workflow that
# failed stops the run.
wait_for_pantheon_workflows() {
  local judge="${1:-judge}"
  local waited=0 max_wait=2700 appear_wait=600 rows="" code_row="" started="" started_epoch=0 appeared=true

  while true; do
    if ! rows="$(terminus workflow:list "$SITE_NAME" --format=csv --fields=env,status,started_at,workflow 2>/dev/null)"; then
      err "Could not list Pantheon workflows for $SITE_NAME."
      return 1
    fi
    # The newest code workflow (a sync on dev, a deploy on test and live).
    code_row="$(printf '%s\n' "$rows" | grep -m 1 -E "^${ENV_NAME},[a-z]+,.*(Sync code|Deploy code)" || true)"

    # Straight after a push the workflow for it may not be listed yet. Until
    # one that started after the push is, the environment must be left alone.
    appeared=true
    if [[ -n "$PUSHED_AT" ]]; then
      started="$(printf '%s' "$code_row" | cut -d, -f3 | tr -d '"')"
      started_epoch="$(date -u -d "$started" +%s 2>/dev/null || echo 0)"
      if (( started_epoch < PUSHED_AT - 300 )); then
        appeared=false
      fi
    fi

    if "$appeared" && ! printf '%s\n' "$rows" | grep -q "^${ENV_NAME},running"; then
      break
    fi
    if ! "$appeared" && (( waited >= appear_wait )); then
      err "Pantheon has not started a code sync on $ENV_NAME for the push made $((waited / 60)) minutes ago."
      return 1
    fi
    if (( waited >= max_wait )); then
      err "Pantheon still has a workflow running on $ENV_NAME after $((max_wait / 60)) minutes."
      return 1
    fi
    if (( waited == 0 )); then
      info "Pantheon is still working on $ENV_NAME; leaving it alone until it has finished ..."
    fi
    sleep "$WORKFLOW_POLL_SECONDS"
    waited=$((waited + 30))
  done

  if [[ "$judge" == "judge" && "$(printf '%s' "$code_row" | cut -d, -f2)" == "failed" ]]; then
    err "Pantheon's last code workflow on $ENV_NAME failed: $(printf '%s' "$code_row" | cut -d, -f4- | tr -d '"') (started $(printf '%s' "$code_row" | cut -d, -f3 | tr -d '"') UTC)."
    err "A failed code workflow can leave the new code in place without the link to the file mount. Nothing has been applied by this run."
    if [[ "$ENV_NAME" == "test" || "$ENV_NAME" == "live" ]]; then
      err "Pantheon has to complete a code deploy on $ENV_NAME first: run 'npm run deploy:$ENV_NAME' again."
    else
      err "Repair it with: terminus env:code-rebuild ${SITE_NAME}.${ENV_NAME}   (then run this again; do not touch $ENV_NAME while it rebuilds)"
    fi
    return 1
  fi
}

# Checks the assistant endpoints with the read-mostly smoke.
run_assistant_smoke() {
  local base_url="https://${ENV_NAME}-${SITE_NAME}.pantheonsite.io"

  info "Running assistant smoke against $base_url ..."
  if "$DRY_RUN"; then
    print_cmd node "$REPO_ROOT/scripts/smoke/assistant-smoke.mjs" --base-url "$base_url" --mode production-safe --env "$ENV_NAME"
    return 0
  fi
  if ! node "$REPO_ROOT/scripts/smoke/assistant-smoke.mjs" --base-url "$base_url" --mode production-safe --env "$ENV_NAME"; then
    err "Assistant smoke failed for $base_url."
    return 1
  fi
  ok "Assistant smoke passed."
}

# Requests one of each kind of public page on the environment just deployed.
#
# Dev and test get no organic traffic, so without this a deploy there is only
# exercised by the assistant checks. The pantheonsite.io host is used for every
# environment: Cloudflare challenges scripted clients on the public live host.
run_site_smoke() {
  local base_url="https://${ENV_NAME}-${SITE_NAME}.pantheonsite.io"

  info "Running site smoke against $base_url ..."
  if ! run_cmd node "$REPO_ROOT/scripts/smoke/site-smoke.mjs" --base-url "$base_url" --env "$ENV_NAME"; then
    err "Site smoke failed for $base_url."
    return 1
  fi
  ok "Site smoke passed."
}

# Proves the Langfuse export path end to end where Langfuse is enabled.
#
# Sends one synthetic trace directly and reads it back from Langfuse. Skipped
# when Langfuse is not enabled on the environment (the default off live).
# Not run on live: live exports real traces and alerts on its own failures.
run_langfuse_export_probe() {
  local site_env="$1"

  if [[ "$ENV_NAME" == "live" ]]; then
    return 0
  fi
  if "$DRY_RUN"; then
    print_cmd terminus drush "$site_env" -- queue:run ilas_langfuse_export
    print_cmd terminus drush "$site_env" -- ilas:langfuse-probe --direct
    return 0
  fi

  local diagnosis=""
  diagnosis="$(terminus drush "$site_env" -- ilas:langfuse-probe --diagnose 2>/dev/null || true)"
  if [[ "$diagnosis" != *'"verdict": "READY"'* ]]; then
    info "Langfuse is not enabled on $site_env; skipping the export probe."
    return 0
  fi

  # The assistant smoke just queued a real trace. Export it now: this
  # environment may be asleep before cron next runs, and a queued trace that
  # waits more than two hours is discarded with a stale-queue warning.
  info "Exporting queued Langfuse traces on $site_env ..."
  if ! terminus drush "$site_env" -- queue:run ilas_langfuse_export; then
    err "Could not process the Langfuse export queue on $site_env."
    return 1
  fi

  info "Sending a Langfuse export probe from $site_env ..."
  local output="" trace_id=""
  if ! output="$(terminus drush "$site_env" -- ilas:langfuse-probe --direct 2>&1)"; then
    printf '%s\n' "$output"
    err "Langfuse export probe was not accepted."
    return 1
  fi
  trace_id="$(printf '%s\n' "$output" | grep -oE 'Trace ID: [0-9a-f-]{36}' | head -n 1 | cut -d' ' -f3)"
  if [[ -z "$trace_id" ]]; then
    printf '%s\n' "$output"
    err "Could not read the probe trace ID."
    return 1
  fi

  # Langfuse stores a trace some time after accepting it: usually within
  # seconds, but on 2026-11-20 a probe was not readable after 20 seconds and
  # was there two minutes later. The lookup returns as soon as the trace is
  # found, so a long limit costs nothing when Langfuse is quick.
  if ! terminus drush "$site_env" -- ilas:langfuse-lookup "$trace_id" --attempts=45 --delay-ms=4000 >/dev/null 2>&1; then
    err "Langfuse accepted probe $trace_id but it could not be read back within three minutes."
    err "Look again with: terminus drush $site_env -- ilas:langfuse-lookup $trace_id"
    return 1
  fi
  ok "Langfuse export probe $trace_id was stored and read back."
}

main() {
  parse_args "$@"
  validate_args

  if "$CHECK_ONLY"; then
    preflight_checks
    run_alignment_check
    return
  fi

  local site_env="${SITE_NAME}.${ENV_NAME}"
  info "Target environment: $site_env"
  if "$DRY_RUN"; then
    warn "Dry run mode enabled; no remote state changes will be made."
  fi

  preflight_checks

  # Nothing below may reach the environment while Pantheon is rebuilding it.
  if "$DRY_RUN"; then
    print_cmd terminus workflow:list "$SITE_NAME" --format=csv --fields=env,status,started_at,workflow
  else
    wait_for_pantheon_workflows wait-only || exit 1
  fi

  info "Waking Pantheon environment..."
  run_cmd terminus env:wake "$site_env"

  live_guardrails "$site_env"

  promote_code "$site_env"

  verify_deployed_code "$site_env" || exit 1

  show_pending_updates "$site_env" "before deploy"

  info "Running Drush deploy pipeline..."
  apply_deploy "$site_env"

  show_pending_updates "$site_env" "after deploy"

  local pending_count
  pending_count="$(pending_update_count "$site_env")"
  if [[ "$pending_count" == "unknown" ]]; then
    err "Could not determine post-deploy pending update count."
    exit 1
  fi

  if [[ "$pending_count" != "0" ]]; then
    err "Post-deploy pending database updates remain: $pending_count"
    exit 1
  fi
  ok "No pending database updates remain."

  check_config_drift "$site_env" || exit 1

  if "$SKIP_SMOKE"; then
    warn "Skipping the post-deploy smoke due to --skip-smoke."
  else
    run_site_smoke || exit 1
    run_assistant_smoke || exit 1
    run_langfuse_export_probe "$site_env" || exit 1
  fi

  ok "Deployment workflow completed for $site_env."
}

main "$@"
