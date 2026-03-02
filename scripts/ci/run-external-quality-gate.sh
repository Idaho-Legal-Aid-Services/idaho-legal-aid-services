#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

ENV_NAME=""
MODE="auto"
SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"
THRESHOLD=""
CONFIG_FILE=""
SKIP_EVAL="false"
SIMULATED_PASS_RATE=""

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--mode auto|blocking|advisory] [--site <pantheon-site>] [--threshold <0-100>] [--config <promptfoo-config>] [--skip-eval] [--simulate-pass-rate <0-100>]
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)
      ENV_NAME="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --site)
      SITE_NAME="${2:-}"
      shift 2
      ;;
    --threshold)
      THRESHOLD="${2:-}"
      shift 2
      ;;
    --config)
      CONFIG_FILE="${2:-}"
      shift 2
      ;;
    --skip-eval)
      SKIP_EVAL="true"
      shift 1
      ;;
    --simulate-pass-rate)
      SIMULATED_PASS_RATE="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$ENV_NAME" ]]; then
  echo "--env is required" >&2
  usage >&2
  exit 2
fi

cd "$REPO_ROOT"

# Phase 1: PHPUnit-only quality gate from module harness.
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# Phase 2: Promptfoo gate with branch-aware policy.
PROMPTFOO_ARGS=(
  --site "$SITE_NAME"
  --env "$ENV_NAME"
  --mode "$MODE"
)

if [[ -n "$THRESHOLD" ]]; then
  PROMPTFOO_ARGS+=(--threshold "$THRESHOLD")
fi
if [[ -n "$CONFIG_FILE" ]]; then
  PROMPTFOO_ARGS+=(--config "$CONFIG_FILE")
fi
if [[ "$SKIP_EVAL" == "true" ]]; then
  PROMPTFOO_ARGS+=(--skip-eval)
fi
if [[ -n "$SIMULATED_PASS_RATE" ]]; then
  PROMPTFOO_ARGS+=(--simulate-pass-rate "$SIMULATED_PASS_RATE")
fi

bash scripts/ci/run-promptfoo-gate.sh "${PROMPTFOO_ARGS[@]}"
