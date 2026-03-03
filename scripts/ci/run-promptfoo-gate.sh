#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DERIVE_SCRIPT="$SCRIPT_DIR/derive-assistant-url.sh"
PROMPTFOO_SCRIPT="$REPO_ROOT/promptfoo-evals/scripts/run-promptfoo.sh"
RESULTS_FILE="$REPO_ROOT/promptfoo-evals/output/results.json"
SUMMARY_FILE="$REPO_ROOT/promptfoo-evals/output/gate-summary.txt"

SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"
ENV_NAME=""
MODE="auto"
THRESHOLD="${PROMPTFOO_PASS_THRESHOLD:-90}"
CONFIG_FILE=""
DEEP_CONFIG_FILE=""
SKIP_EVAL="false"
SIMULATED_PASS_RATE=""

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--site <pantheon-site>] [--mode auto|blocking|advisory] [--threshold <0-100>] [--config <promptfoo-config>] [--deep-config <deep-config>] [--skip-eval] [--simulate-pass-rate <0-100>]

Policy:
  mode=auto -> blocking on master/main/release/*, advisory otherwise.
  --deep-config auto-enables on blocking branches if not explicitly set.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)
      ENV_NAME="${2:-}"
      shift 2
      ;;
    --site)
      SITE_NAME="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
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
    --deep-config)
      DEEP_CONFIG_FILE="${2:-}"
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

if [[ "$MODE" != "auto" && "$MODE" != "blocking" && "$MODE" != "advisory" ]]; then
  echo "--mode must be one of: auto|blocking|advisory" >&2
  exit 2
fi

if ! command -v node >/dev/null 2>&1; then
  echo "node is required to parse promptfoo results" >&2
  exit 127
fi

CI_BRANCH_NAME="${CI_BRANCH:-${GIT_BRANCH:-$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)}}"
if [[ "$MODE" == "auto" ]]; then
  if [[ "$CI_BRANCH_NAME" == "master" || "$CI_BRANCH_NAME" == "main" || "$CI_BRANCH_NAME" =~ ^release/ ]]; then
    EFFECTIVE_MODE="blocking"
  else
    EFFECTIVE_MODE="advisory"
  fi
else
  EFFECTIVE_MODE="$MODE"
fi

# Default config policy: abuse suite is the primary config; deep suite
# auto-enables on blocking branches as a secondary eval.
if [[ -z "$CONFIG_FILE" ]]; then
  CONFIG_FILE="promptfooconfig.abuse.yaml"
fi

# Auto-enable deep config on blocking branches if not explicitly set.
if [[ "$EFFECTIVE_MODE" == "blocking" && -z "$DEEP_CONFIG_FILE" ]]; then
  DEEP_CONFIG_FILE="promptfooconfig.deep.yaml"
fi

if [[ -z "${ILAS_ASSISTANT_URL:-}" ]]; then
  if [[ "$SKIP_EVAL" == "true" ]]; then
    # Simulation mode does not require live endpoint discovery.
    ILAS_ASSISTANT_URL="https://example.invalid/assistant/api/message"
  else
    ILAS_ASSISTANT_URL="$("$DERIVE_SCRIPT" --site "$SITE_NAME" --env "$ENV_NAME")"
  fi
fi
export ILAS_ASSISTANT_URL

if [[ -z "${ILAS_REQUEST_DELAY_MS:-}" ]]; then
  if [[ "$ENV_NAME" == "live" ]]; then
    export ILAS_REQUEST_DELAY_MS=31000
  else
    export ILAS_REQUEST_DELAY_MS=0
  fi
fi

mkdir -p "$(dirname "$SUMMARY_FILE")"

RESULTS_FILE_DEEP="$REPO_ROOT/promptfoo-evals/output/results-deep.json"

EVAL_EXIT=0
if [[ "$SKIP_EVAL" != "true" ]]; then
  if [[ ! -x "$PROMPTFOO_SCRIPT" ]]; then
    echo "Promptfoo runner not found: $PROMPTFOO_SCRIPT" >&2
    exit 1
  fi

  # Primary eval (abuse config).
  (
    cd "$REPO_ROOT"
    bash "$PROMPTFOO_SCRIPT" eval "$CONFIG_FILE"
  ) || EVAL_EXIT=$?
fi

PASS_RATE="0"
TOTAL_CASES="0"
PASSED_CASES="0"
if [[ -f "$RESULTS_FILE" ]]; then
  read -r PASS_RATE TOTAL_CASES PASSED_CASES < <(node -e "const fs=require('node:fs'); const path=process.argv[1]; try { const json=JSON.parse(fs.readFileSync(path,'utf8')); const rows=json.results?.results || json.results || []; const total=Array.isArray(rows)?rows.length:0; const passed=Array.isArray(rows)?rows.filter((r)=>r&&r.success).length:0; const rate=total>0?(100*passed/total):0; process.stdout.write(rate.toFixed(1)+' '+total+' '+passed+'\\n');} catch (err) { process.stdout.write('0 0 0\\n'); }" "$RESULTS_FILE")
fi

# Deep eval (runs after primary if deep config is set).
DEEP_EVAL_EXIT=0
DEEP_PASS_RATE="0"
DEEP_TOTAL_CASES="0"
DEEP_PASSED_CASES="0"
if [[ -n "$DEEP_CONFIG_FILE" && "$SKIP_EVAL" != "true" ]]; then
  echo ""
  printf 'Running deep eval: %s\n' "$DEEP_CONFIG_FILE"
  (
    cd "$REPO_ROOT"
    PROMPTFOO_OUTPUT_FILE="$RESULTS_FILE_DEEP" bash "$PROMPTFOO_SCRIPT" eval "$DEEP_CONFIG_FILE"
  ) || DEEP_EVAL_EXIT=$?

  if [[ -f "$RESULTS_FILE_DEEP" ]]; then
    read -r DEEP_PASS_RATE DEEP_TOTAL_CASES DEEP_PASSED_CASES < <(node -e "const fs=require('node:fs'); const path=process.argv[1]; try { const json=JSON.parse(fs.readFileSync(path,'utf8')); const rows=json.results?.results || json.results || []; const total=Array.isArray(rows)?rows.length:0; const passed=Array.isArray(rows)?rows.filter((r)=>r&&r.success).length:0; const rate=total>0?(100*passed/total):0; process.stdout.write(rate.toFixed(1)+' '+total+' '+passed+'\\n');} catch (err) { process.stdout.write('0 0 0\\n'); }" "$RESULTS_FILE_DEEP")
  fi
fi

if [[ -n "$SIMULATED_PASS_RATE" ]]; then
  PASS_RATE="$SIMULATED_PASS_RATE"
  TOTAL_CASES="0"
  PASSED_CASES="0"
fi

TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
{
  echo "timestamp_utc=${TS}"
  echo "site=${SITE_NAME}"
  echo "env=${ENV_NAME}"
  echo "branch=${CI_BRANCH_NAME}"
  echo "mode=${EFFECTIVE_MODE}"
  echo "threshold=${THRESHOLD}"
  echo "config_file=${CONFIG_FILE}"
  echo "deep_config_file=${DEEP_CONFIG_FILE}"
  echo "assistant_url=${ILAS_ASSISTANT_URL}"
  echo "request_delay_ms=${ILAS_REQUEST_DELAY_MS}"
  echo "eval_exit=${EVAL_EXIT}"
  echo "pass_rate=${PASS_RATE}"
  echo "total_cases=${TOTAL_CASES}"
  echo "passed_cases=${PASSED_CASES}"
  echo "deep_eval_exit=${DEEP_EVAL_EXIT}"
  echo "deep_pass_rate=${DEEP_PASS_RATE}"
  echo "deep_total_cases=${DEEP_TOTAL_CASES}"
  echo "deep_passed_cases=${DEEP_PASSED_CASES}"
} > "$SUMMARY_FILE"

printf 'Promptfoo gate summary: mode=%s threshold=%s pass_rate=%s%% eval_exit=%s\n' "$EFFECTIVE_MODE" "$THRESHOLD" "$PASS_RATE" "$EVAL_EXIT"
if [[ -n "$DEEP_CONFIG_FILE" ]]; then
  printf 'Deep eval summary: deep_pass_rate=%s%% deep_eval_exit=%s\n' "$DEEP_PASS_RATE" "$DEEP_EVAL_EXIT"
fi
printf 'Summary file: %s\n' "$SUMMARY_FILE"

THRESHOLD_FAIL=$(node -e "const p=parseFloat('${PASS_RATE}'); const t=parseFloat('${THRESHOLD}'); console.log(Number.isFinite(p)&&Number.isFinite(t)&&p<t ? 'yes':'no');")
DEEP_THRESHOLD_FAIL="no"
if [[ -n "$DEEP_CONFIG_FILE" ]]; then
  DEEP_THRESHOLD_FAIL=$(node -e "const p=parseFloat('${DEEP_PASS_RATE}'); const t=parseFloat('${THRESHOLD}'); console.log(Number.isFinite(p)&&Number.isFinite(t)&&p<t ? 'yes':'no');")
fi

if [[ "$EVAL_EXIT" -ne 0 || "$THRESHOLD_FAIL" == "yes" || "$DEEP_EVAL_EXIT" -ne 0 || "$DEEP_THRESHOLD_FAIL" == "yes" ]]; then
  if [[ "$EFFECTIVE_MODE" == "blocking" ]]; then
    echo "Promptfoo gate FAILED in blocking mode" >&2
    exit 2
  fi
  echo "Promptfoo gate FAILED in advisory mode (non-blocking)" >&2
  exit 0
fi

echo "Promptfoo gate PASSED"
