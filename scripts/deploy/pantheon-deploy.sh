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
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

usage() {
  cat <<'USAGE'
Usage:
  pantheon-deploy.sh --env <dev|test|live> [--site <machine-name>] [--dry-run] [--yes-live] [--skip-live-backup] [--skip-smoke]

Options:
  --env <env>            Target Pantheon environment (dev|test|live). Required.
  --site <name>          Pantheon site machine name. Default: idaho-legal-aid-services
  --dry-run              Print commands without executing remote operations.
  --yes-live             Skip live confirmation prompt.
  --skip-live-backup     Skip automatic live backup creation.
  --skip-smoke           Skip the post-deploy site smoke and Langfuse export probe.
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
    run_cmd terminus backup:create "$site_env" --keep-for=1
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
  info "Config drift status (informational only):"

  if "$DRY_RUN"; then
    run_cmd terminus drush "$site_env" -- config:status
    return
  fi

  local status_output=""
  if status_output="$(terminus drush "$site_env" -- config:status 2>&1)"; then
    printf '%s\n' "$status_output"
    if printf '%s\n' "$status_output" | grep -Eq 'No differences|No config changes|No changes'; then
      ok "No config drift reported."
    else
      warn "Config status reported differences. Review output above."
    fi
  else
    warn "Unable to fetch config:status (non-fatal)."
    printf '%s\n' "$status_output"
  fi
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
    print_cmd terminus drush "$site_env" -- ilas:langfuse-probe --direct
    return 0
  fi

  local diagnosis=""
  diagnosis="$(terminus drush "$site_env" -- ilas:langfuse-probe --diagnose 2>/dev/null || true)"
  if [[ "$diagnosis" != *'"verdict": "READY"'* ]]; then
    info "Langfuse is not enabled on $site_env; skipping the export probe."
    return 0
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

  if ! terminus drush "$site_env" -- ilas:langfuse-lookup "$trace_id" --attempts=10 --delay-ms=2000 >/dev/null 2>&1; then
    err "Langfuse accepted probe $trace_id but it could not be read back."
    return 1
  fi
  ok "Langfuse export probe $trace_id was stored and read back."
}

main() {
  parse_args "$@"
  validate_args

  local site_env="${SITE_NAME}.${ENV_NAME}"
  info "Target environment: $site_env"
  if "$DRY_RUN"; then
    warn "Dry run mode enabled; no remote state changes will be made."
  fi

  preflight_checks

  info "Waking Pantheon environment..."
  run_cmd terminus env:wake "$site_env"

  live_guardrails "$site_env"

  show_pending_updates "$site_env" "before deploy"

  info "Running Drush deploy pipeline..."
  run_cmd terminus drush "$site_env" -- deploy -y

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

  check_config_drift "$site_env"

  if "$SKIP_SMOKE"; then
    warn "Skipping the post-deploy smoke due to --skip-smoke."
  else
    run_site_smoke || exit 1
    run_langfuse_export_probe "$site_env" || exit 1
  fi

  ok "Deployment workflow completed for $site_env."
}

main "$@"
