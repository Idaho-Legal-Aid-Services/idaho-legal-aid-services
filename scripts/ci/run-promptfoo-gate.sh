#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DERIVE_SCRIPT="$SCRIPT_DIR/derive-assistant-url.sh"
RESOLVE_TARGET_SCRIPT="$SCRIPT_DIR/resolve-assistant-target.sh"
PROMPTFOO_SCRIPT="$REPO_ROOT/promptfoo-evals/scripts/run-promptfoo.sh"
ASSERTION_LINTER="$REPO_ROOT/promptfoo-evals/scripts/lint-javascript-assertions.mjs"
GATE_METRICS_SCRIPT="$REPO_ROOT/promptfoo-evals/scripts/gate-metrics.js"
PREFLIGHT_SCRIPT="$REPO_ROOT/promptfoo-evals/scripts/preflight-live.js"
CA_DISCOVERY_SCRIPT="$REPO_ROOT/promptfoo-evals/scripts/discover-node-extra-ca-certs.js"
RESULTS_FILE="$REPO_ROOT/promptfoo-evals/output/results.json"
RESULTS_FILE_SMOKE="$REPO_ROOT/promptfoo-evals/output/results-smoke.json"
RESULTS_FILE_DEEP="$REPO_ROOT/promptfoo-evals/output/results-deep.json"
SUMMARY_FILE="$REPO_ROOT/promptfoo-evals/output/gate-summary.txt"

SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"
ENV_NAME=""
MODE="auto"
THRESHOLD="${PROMPTFOO_PASS_THRESHOLD:-90}"
CONFIG_FILE=""
DEEP_CONFIG_FILE=""
SMOKE_CONFIG_FILE="promptfooconfig.smoke.yaml"
CONNECTIVITY_ONLY="false"
SKIP_EVAL="false"
SIMULATED_PASS_RATE=""
RAG_METRIC_THRESHOLD="${RAG_CONFIDENCE_THRESHOLD:-90}"
RAG_METRIC_MIN_COUNT="${RAG_METRIC_MIN_COUNT:-10}"
P2DEL04_METRIC_THRESHOLD="${P2DEL04_METRIC_THRESHOLD:-85}"
P2DEL04_METRIC_MIN_COUNT="${P2DEL04_METRIC_MIN_COUNT:-10}"
ILAS_HOURLY_LIMIT_PREFLIGHT="${ILAS_HOURLY_LIMIT_PREFLIGHT:-true}"
ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE="${ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE:-}"
ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR="${ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR:-}"
ILAS_429_FAIL_FAST="${ILAS_429_FAIL_FAST:-1}"
REQUEST_DELAY_OVERRIDE_MS="${ILAS_REQUEST_DELAY_MS:-}"
ILAS_GATE_MODE=1

TARGET_KIND="unknown"
TARGET_SOURCE="unknown"
TARGET_HOST=""
DDEV_PRIMARY_URL=""
REQUESTED_TARGET_ENV=""
RESOLVED_TARGET_ENV=""
TARGET_VALIDATION_STATUS="unvalidated"
CONNECTIVITY_STATUS="skipped"
CONNECTIVITY_ERROR_CODE=""
QUALITY_PHASE="not_started"
FAILURE_KIND=""
FAILURE_CODE=""
RATE_LIMIT_SOURCE="unresolved"
RATE_LIMIT_PREFLIGHT_STATUS="skipped"
CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE=""
CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE=""
EFFECTIVE_RATE_LIMIT_PER_MINUTE=""
EFFECTIVE_RATE_LIMIT_PER_HOUR=""
EFFECTIVE_REQUEST_DELAY_MS=""
DDEV_RATE_LIMIT_OVERRIDE="not_needed"
NODE_CA_SOURCE=""
FULL_EVAL_COMPLETED="false"

PLANNED_SMOKE_CASE_COUNT="0"
PLANNED_PRIMARY_CASE_COUNT="0"
PLANNED_DEEP_CASE_COUNT="0"
PLANNED_CASE_COUNT="0"

SMOKE_EVAL_EXIT=0
SMOKE_PASS_RATE="0"
SMOKE_TOTAL_CASES="0"
SMOKE_PASSED_CASES="0"
SMOKE_ERROR_KIND=""
SMOKE_ERROR_CODE=""

EVAL_EXIT=0
PASS_RATE="0"
TOTAL_CASES="0"
PASSED_CASES="0"
PRIMARY_ERROR_KIND=""
PRIMARY_ERROR_CODE=""

DEEP_EVAL_EXIT=0
DEEP_PASS_RATE="0"
DEEP_TOTAL_CASES="0"
DEEP_PASSED_CASES="0"
DEEP_ERROR_KIND=""
DEEP_ERROR_CODE=""

RAG_METRICS_ENFORCED="false"
RAG_CONTRACT_META_RATE="0"
RAG_CONTRACT_META_SCORE="0"
RAG_CONTRACT_META_COUNT="0"
RAG_CONTRACT_META_COUNT_FAIL="no"
RAG_CITATION_COVERAGE_RATE="0"
RAG_CITATION_COVERAGE_SCORE="0"
RAG_CITATION_COVERAGE_COUNT="0"
RAG_CITATION_COVERAGE_COUNT_FAIL="no"
RAG_LOW_CONF_REFUSAL_RATE="0"
RAG_LOW_CONF_REFUSAL_SCORE="0"
RAG_LOW_CONF_REFUSAL_COUNT="0"
RAG_LOW_CONF_REFUSAL_COUNT_FAIL="no"
RAG_CONTRACT_META_FAIL="no"
RAG_CITATION_COVERAGE_FAIL="no"
RAG_LOW_CONF_REFUSAL_FAIL="no"

P2DEL04_METRICS_ENFORCED="false"
P2DEL04_CONTRACT_META_RATE="0"
P2DEL04_CONTRACT_META_SCORE="0"
P2DEL04_CONTRACT_META_COUNT="0"
P2DEL04_CONTRACT_META_COUNT_FAIL="no"
P2DEL04_CONTRACT_META_FAIL="no"
P2DEL04_WEAK_GROUNDING_RATE="0"
P2DEL04_WEAK_GROUNDING_SCORE="0"
P2DEL04_WEAK_GROUNDING_COUNT="0"
P2DEL04_WEAK_GROUNDING_COUNT_FAIL="no"
P2DEL04_WEAK_GROUNDING_FAIL="no"
P2DEL04_ESCALATION_ROUTING_RATE="0"
P2DEL04_ESCALATION_ROUTING_SCORE="0"
P2DEL04_ESCALATION_ROUTING_COUNT="0"
P2DEL04_ESCALATION_ROUTING_COUNT_FAIL="no"
P2DEL04_ESCALATION_ROUTING_FAIL="no"
P2DEL04_ESCALATION_ACTIONABILITY_RATE="0"
P2DEL04_ESCALATION_ACTIONABILITY_SCORE="0"
P2DEL04_ESCALATION_ACTIONABILITY_COUNT="0"
P2DEL04_ESCALATION_ACTIONABILITY_COUNT_FAIL="no"
P2DEL04_ESCALATION_ACTIONABILITY_FAIL="no"
P2DEL04_SAFETY_BOUNDARY_ROUTING_RATE="0"
P2DEL04_SAFETY_BOUNDARY_ROUTING_SCORE="0"
P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT="0"
P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT_FAIL="no"
P2DEL04_SAFETY_BOUNDARY_ROUTING_FAIL="no"
P2DEL04_BOUNDARY_DAMPENING_RATE="0"
P2DEL04_BOUNDARY_DAMPENING_SCORE="0"
P2DEL04_BOUNDARY_DAMPENING_COUNT="0"
P2DEL04_BOUNDARY_DAMPENING_COUNT_FAIL="no"
P2DEL04_BOUNDARY_DAMPENING_FAIL="no"
P2DEL04_BOUNDARY_URGENT_ROUTING_RATE="0"
P2DEL04_BOUNDARY_URGENT_ROUTING_SCORE="0"
P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT="0"
P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT_FAIL="no"
P2DEL04_BOUNDARY_URGENT_ROUTING_FAIL="no"

DDEV_RATE_LIMIT_OVERRIDE_APPLIED="false"
DDEV_ORIGINAL_RATE_LIMIT_PER_MINUTE=""
DDEV_ORIGINAL_RATE_LIMIT_PER_HOUR=""

count_cases_for_config() {
  local config_rel="$1"
  local config_abs="$REPO_ROOT/promptfoo-evals/$config_rel"
  local total=0

  if [[ ! -f "$config_abs" ]]; then
    echo "0"
    return 0
  fi

  mapfile -t test_files < <(
    grep -E '^[[:space:]]*-[[:space:]]*file://tests/.+\.ya?ml[[:space:]]*$' "$config_abs" \
      | sed -E 's|^[[:space:]]*-[[:space:]]*file://||'
  )

  if [[ "${#test_files[@]}" -eq 0 ]]; then
    echo "0"
    return 0
  fi

  for test_rel in "${test_files[@]}"; do
    local test_abs="$REPO_ROOT/promptfoo-evals/$test_rel"
    if [[ -f "$test_abs" ]]; then
      local case_count
      case_count=$(grep -E '^-[[:space:]]+' "$test_abs" | wc -l | awk '{print $1}')
      total=$((total + case_count))
    fi
  done

  echo "$total"
}

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--site <pantheon-site>] [--mode auto|blocking|advisory] [--threshold <0-100>] [--config <promptfoo-config>] [--deep-config <deep-config>] [--connectivity-only] [--skip-eval] [--simulate-pass-rate <0-100>]

Policy:
  mode=auto -> blocking on master/main/release/*, advisory otherwise.
  --deep-config auto-enables on blocking branches if not explicitly set.
USAGE
}

compute_request_delay_ms() {
  local per_minute="$1"
  if [[ -z "$per_minute" || "$per_minute" -le 0 ]]; then
    echo "0"
    return 0
  fi

  echo $(((60000 + per_minute - 1) / per_minute))
}

parse_results_pass_rate() {
  local results_file="$1"
  if [[ ! -f "$results_file" ]]; then
    echo "0 0 0"
    return 0
  fi
  node "$GATE_METRICS_SCRIPT" pass-rate "$results_file" 2>/dev/null || echo "0 0 0"
}

parse_structured_error_from_results() {
  local results_file="$1"
  if [[ ! -f "$results_file" ]]; then
    echo ""
    return 0
  fi
  node "$GATE_METRICS_SCRIPT" structured-error "$results_file" 2>/dev/null || echo ""
}

apply_metric_threshold_report() {
  local results_file="$1"
  local threshold="$2"
  local min_count="$3"
  local namespace="$4"
  shift 4

  local line type metric rate score count count_fail fail
  while IFS='|' read -r type metric rate score count count_fail fail; do
    if [[ -z "$type" ]]; then
      continue
    fi

    if [[ "$type" == "overall" ]]; then
      case "$namespace" in
        rag)
          RAG_THRESHOLD_FAIL="$metric"
          ;;

        p2del04)
          P2DEL04_THRESHOLD_FAIL="$metric"
          ;;
      esac
      continue
    fi

    case "${namespace}:${metric}" in
      rag:rag-contract-meta-present)
        RAG_CONTRACT_META_RATE="$rate"
        RAG_CONTRACT_META_SCORE="$score"
        RAG_CONTRACT_META_COUNT="$count"
        RAG_CONTRACT_META_COUNT_FAIL="$count_fail"
        RAG_CONTRACT_META_FAIL="$fail"
        ;;

      rag:rag-citation-coverage)
        RAG_CITATION_COVERAGE_RATE="$rate"
        RAG_CITATION_COVERAGE_SCORE="$score"
        RAG_CITATION_COVERAGE_COUNT="$count"
        RAG_CITATION_COVERAGE_COUNT_FAIL="$count_fail"
        RAG_CITATION_COVERAGE_FAIL="$fail"
        ;;

      rag:rag-low-confidence-refusal)
        RAG_LOW_CONF_REFUSAL_RATE="$rate"
        RAG_LOW_CONF_REFUSAL_SCORE="$score"
        RAG_LOW_CONF_REFUSAL_COUNT="$count"
        RAG_LOW_CONF_REFUSAL_COUNT_FAIL="$count_fail"
        RAG_LOW_CONF_REFUSAL_FAIL="$fail"
        ;;

      p2del04:p2del04-contract-meta-present)
        P2DEL04_CONTRACT_META_RATE="$rate"
        P2DEL04_CONTRACT_META_SCORE="$score"
        P2DEL04_CONTRACT_META_COUNT="$count"
        P2DEL04_CONTRACT_META_COUNT_FAIL="$count_fail"
        P2DEL04_CONTRACT_META_FAIL="$fail"
        ;;

      p2del04:p2del04-weak-grounding-handling)
        P2DEL04_WEAK_GROUNDING_RATE="$rate"
        P2DEL04_WEAK_GROUNDING_SCORE="$score"
        P2DEL04_WEAK_GROUNDING_COUNT="$count"
        P2DEL04_WEAK_GROUNDING_COUNT_FAIL="$count_fail"
        P2DEL04_WEAK_GROUNDING_FAIL="$fail"
        ;;

      p2del04:p2del04-escalation-routing)
        P2DEL04_ESCALATION_ROUTING_RATE="$rate"
        P2DEL04_ESCALATION_ROUTING_SCORE="$score"
        P2DEL04_ESCALATION_ROUTING_COUNT="$count"
        P2DEL04_ESCALATION_ROUTING_COUNT_FAIL="$count_fail"
        P2DEL04_ESCALATION_ROUTING_FAIL="$fail"
        ;;

      p2del04:p2del04-escalation-actionability)
        P2DEL04_ESCALATION_ACTIONABILITY_RATE="$rate"
        P2DEL04_ESCALATION_ACTIONABILITY_SCORE="$score"
        P2DEL04_ESCALATION_ACTIONABILITY_COUNT="$count"
        P2DEL04_ESCALATION_ACTIONABILITY_COUNT_FAIL="$count_fail"
        P2DEL04_ESCALATION_ACTIONABILITY_FAIL="$fail"
        ;;

      p2del04:p2del04-safety-boundary-routing)
        P2DEL04_SAFETY_BOUNDARY_ROUTING_RATE="$rate"
        P2DEL04_SAFETY_BOUNDARY_ROUTING_SCORE="$score"
        P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT="$count"
        P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT_FAIL="$count_fail"
        P2DEL04_SAFETY_BOUNDARY_ROUTING_FAIL="$fail"
        ;;

      p2del04:p2del04-boundary-dampening)
        P2DEL04_BOUNDARY_DAMPENING_RATE="$rate"
        P2DEL04_BOUNDARY_DAMPENING_SCORE="$score"
        P2DEL04_BOUNDARY_DAMPENING_COUNT="$count"
        P2DEL04_BOUNDARY_DAMPENING_COUNT_FAIL="$count_fail"
        P2DEL04_BOUNDARY_DAMPENING_FAIL="$fail"
        ;;

      p2del04:p2del04-boundary-urgent-routing)
        P2DEL04_BOUNDARY_URGENT_ROUTING_RATE="$rate"
        P2DEL04_BOUNDARY_URGENT_ROUTING_SCORE="$score"
        P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT="$count"
        P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT_FAIL="$count_fail"
        P2DEL04_BOUNDARY_URGENT_ROUTING_FAIL="$fail"
        ;;
    esac
  done < <(node "$GATE_METRICS_SCRIPT" evaluate-thresholds "$results_file" "$threshold" "$min_count" "$@" 2>/dev/null)
}

resolve_remote_rate_limit() {
  local config_key="$1"
  local env_override="$2"

  if [[ -n "$env_override" ]]; then
    echo "$env_override env"
    return 0
  fi

  if command -v terminus >/dev/null 2>&1; then
    local raw
    if raw="$(terminus remote:drush "${SITE_NAME}.${ENV_NAME}" -- config:get ilas_site_assistant.settings "$config_key" 2>/dev/null)"; then
      local parsed
      parsed="$(echo "$raw" | grep -Eo '[0-9]+' | tail -n1)"
      if [[ -n "$parsed" ]]; then
        echo "$parsed terminus"
        return 0
      fi
    fi
  fi

  echo " unresolved"
}

resolve_ddev_rate_limit() {
  local config_key="$1"
  if ! command -v ddev >/dev/null 2>&1; then
    echo " unresolved"
    return 0
  fi

  local raw
  if raw="$(ddev exec drush config:get ilas_site_assistant.settings "$config_key" 2>/dev/null)"; then
    local parsed
    parsed="$(echo "$raw" | grep -Eo '[0-9]+' | tail -n1)"
    if [[ -n "$parsed" ]]; then
      echo "$parsed ddev_active"
      return 0
    fi
  fi

  echo " unresolved"
}

resolve_assistant_target() {
  local output exit_code=0
  output="$(bash "$RESOLVE_TARGET_SCRIPT" --site "$SITE_NAME" --env "$ENV_NAME" 2>/dev/null)" || exit_code=$?

  if [[ -n "$output" ]]; then
    while IFS='=' read -r key value; do
      case "$key" in
        assistant_url) ILAS_ASSISTANT_URL="$value" ;;
        target_kind) TARGET_KIND="$value" ;;
        target_source) TARGET_SOURCE="$value" ;;
        target_host) TARGET_HOST="$value" ;;
        ddev_primary_url) DDEV_PRIMARY_URL="$value" ;;
        requested_env) REQUESTED_TARGET_ENV="$value" ;;
        resolved_target_env) RESOLVED_TARGET_ENV="$value" ;;
        target_validation_status) TARGET_VALIDATION_STATUS="$value" ;;
      esac
    done <<< "$output"
  fi

  if [[ "$exit_code" -eq 4 && "$TARGET_VALIDATION_STATUS" == "target_env_mismatch" ]]; then
    cat >&2 <<EOF
Promptfoo target validation failed:
  requested_env=${REQUESTED_TARGET_ENV:-$ENV_NAME}
  resolved_target_env=${RESOLVED_TARGET_ENV:-unknown}
  assistant_url=${ILAS_ASSISTANT_URL:-}
Update ILAS_ASSISTANT_URL so it points at the requested Pantheon environment before running the gate.
EOF
    CONNECTIVITY_STATUS="failed"
    CONNECTIVITY_ERROR_CODE="target_env_mismatch"
    FAILURE_KIND="configuration"
    FAILURE_CODE="target_env_mismatch"
    return 4
  fi

  if [[ "$exit_code" -ne 0 || -z "$output" ]]; then
    CONNECTIVITY_STATUS="failed"
    CONNECTIVITY_ERROR_CODE="target_resolution_failed"
    FAILURE_KIND="connectivity"
    FAILURE_CODE="target_resolution_failed"
    return 3
  fi

  export ILAS_ASSISTANT_URL
  return 0
}

classify_assistant_url() {
  local assistant_url="$1"
  local ddev_primary_url="$2"

  local target_json
  target_json="$(
    node - "$assistant_url" "$ddev_primary_url" "$REPO_ROOT/promptfoo-evals/lib/gate-target.js" <<'NODE'
const [assistantUrl, ddevPrimaryUrl, libPath] = process.argv.slice(2);
const { classifyTargetUrl } = require(libPath);
process.stdout.write(JSON.stringify(classifyTargetUrl(assistantUrl, ddevPrimaryUrl)));
NODE
  )"
  TARGET_KIND="$(printf '%s' "$target_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.targetKind);")"
  TARGET_HOST="$(printf '%s' "$target_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.host);")"
}

ensure_ddev_node_trust() {
  if [[ "$TARGET_KIND" != "ddev" ]]; then
    return 0
  fi
  if [[ ! "$ILAS_ASSISTANT_URL" =~ ^https:// ]]; then
    return 0
  fi

  local discovery_json
  if ! discovery_json="$(node "$CA_DISCOVERY_SCRIPT" --assistant-url "$ILAS_ASSISTANT_URL" 2>/dev/null)"; then
    CONNECTIVITY_STATUS="failed"
    CONNECTIVITY_ERROR_CODE="tls_untrusted"
    FAILURE_KIND="connectivity"
    FAILURE_CODE="tls_untrusted"
    return 1
  fi

  local discovered_path discovered_source
  discovered_path="$(printf '%s' "$discovery_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.path || '');")"
  discovered_source="$(printf '%s' "$discovery_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.source || '');")"

  if [[ -z "$discovered_path" ]]; then
    CONNECTIVITY_STATUS="failed"
    CONNECTIVITY_ERROR_CODE="tls_untrusted"
    FAILURE_KIND="connectivity"
    FAILURE_CODE="tls_untrusted"
    return 1
  fi

  export NODE_EXTRA_CA_CERTS="$discovered_path"
  NODE_CA_SOURCE="$discovered_source"
  return 0
}

resolve_rate_limits() {
  local minute_result hour_result
  if [[ "$TARGET_KIND" == "ddev" ]]; then
    minute_result="$(resolve_ddev_rate_limit rate_limit_per_minute)"
    hour_result="$(resolve_ddev_rate_limit rate_limit_per_hour)"
  else
    minute_result="$(resolve_remote_rate_limit rate_limit_per_minute "$ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE")"
    hour_result="$(resolve_remote_rate_limit rate_limit_per_hour "$ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR")"
  fi

  CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE="${minute_result%% *}"
  CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE="${hour_result%% *}"
  local minute_source="${minute_result#* }"
  local hour_source="${hour_result#* }"

  if [[ "$minute_source" == "unresolved" || "$hour_source" == "unresolved" ]]; then
    RATE_LIMIT_SOURCE="unresolved"
  elif [[ "$TARGET_KIND" == "ddev" ]]; then
    RATE_LIMIT_SOURCE="ddev_active"
  elif [[ "$minute_source" == "env" || "$hour_source" == "env" ]]; then
    RATE_LIMIT_SOURCE="env"
  else
    RATE_LIMIT_SOURCE="terminus"
  fi

  EFFECTIVE_RATE_LIMIT_PER_MINUTE="$CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE"
  EFFECTIVE_RATE_LIMIT_PER_HOUR="$CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE"
  EFFECTIVE_REQUEST_DELAY_MS="$(compute_request_delay_ms "${EFFECTIVE_RATE_LIMIT_PER_MINUTE:-0}")"
  export ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE="$EFFECTIVE_RATE_LIMIT_PER_MINUTE"
  export ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR="$EFFECTIVE_RATE_LIMIT_PER_HOUR"
  export ILAS_REQUEST_DELAY_MS="$EFFECTIVE_REQUEST_DELAY_MS"
}

run_connectivity_preflight() {
  QUALITY_PHASE="preflight"
  local preflight_json exit_code=0
  preflight_json="$(node "$PREFLIGHT_SCRIPT" 2>/dev/null)" || exit_code=$?

  if [[ "$exit_code" -ne 0 ]]; then
    CONNECTIVITY_STATUS="failed"
    CONNECTIVITY_ERROR_CODE="$(
      printf '%s' "$preflight_json" | node -e "const fs=require('node:fs'); try { const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.code || 'connectivity_failed'); } catch (_) { process.stdout.write('connectivity_failed'); }"
    )"
    FAILURE_KIND="connectivity"
    FAILURE_CODE="$CONNECTIVITY_ERROR_CODE"
    if [[ "$exit_code" -eq 4 ]]; then
      FAILURE_KIND="capacity"
    fi
    return "$exit_code"
  fi

  CONNECTIVITY_STATUS="passed"
  CONNECTIVITY_ERROR_CODE=""
  return 0
}

run_promptfoo_suite() {
  local config_rel="$1"
  local output_file="$2"
  local expected_total="$3"

  local suite_exit=0
  (
    cd "$REPO_ROOT"
    PROMPTFOO_OUTPUT_FILE="$output_file" \
    ILAS_EXPECTED_REQUEST_TOTAL="$expected_total" \
    ILAS_GATE_MODE=1 \
    ILAS_429_FAIL_FAST="$ILAS_429_FAIL_FAST" \
    bash "$PROMPTFOO_SCRIPT" eval "$config_rel" 1>&2
  ) || suite_exit=$?

  local suite_rate suite_total suite_passed suite_error
  read -r suite_rate suite_total suite_passed < <(parse_results_pass_rate "$output_file")
  suite_error="$(parse_structured_error_from_results "$output_file")"

  printf '%s|%s|%s|%s|%s\n' \
    "$suite_exit" \
    "$suite_rate" \
    "$suite_total" \
    "$suite_passed" \
    "$suite_error"
}

apply_ddev_rate_limit_override() {
  if [[ "$TARGET_KIND" != "ddev" ]]; then
    return 0
  fi

  DDEV_ORIGINAL_RATE_LIMIT_PER_MINUTE="$CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE"
  DDEV_ORIGINAL_RATE_LIMIT_PER_HOUR="$CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE"

  # Local DDEV verification commonly reruns the full gate back-to-back; use a
  # generous local-only ceiling so residual flood counters do not force 429s.
  local override_minute=1000
  local override_hour=$((PLANNED_CASE_COUNT * 15))
  if [[ "$override_hour" -lt 6000 ]]; then
    override_hour=6000
  fi

  # Clear residual flood counters so previous runs don't eat into the budget.
  ddev exec drush sql-query "\"DELETE FROM flood WHERE event LIKE '%assistant%';\"" >/dev/null 2>&1 || true

  if ! ddev exec drush cset ilas_site_assistant.settings rate_limit_per_minute "$override_minute" -y >/dev/null ||
    ! ddev exec drush cset ilas_site_assistant.settings rate_limit_per_hour "$override_hour" -y >/dev/null ||
    ! ddev exec drush cr >/dev/null; then
    DDEV_RATE_LIMIT_OVERRIDE="override_failed"
    FAILURE_KIND="capacity"
    FAILURE_CODE="ddev_rate_limit_override_failed"
    return 1
  fi

  CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE="$override_minute"
  CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE="$override_hour"
  EFFECTIVE_RATE_LIMIT_PER_MINUTE="$override_minute"
  EFFECTIVE_RATE_LIMIT_PER_HOUR="$override_hour"
  if [[ "$REQUEST_DELAY_OVERRIDE_MS" =~ ^[0-9]+$ && "$REQUEST_DELAY_OVERRIDE_MS" -gt 0 ]]; then
    EFFECTIVE_REQUEST_DELAY_MS="$REQUEST_DELAY_OVERRIDE_MS"
  else
    EFFECTIVE_REQUEST_DELAY_MS="$(compute_request_delay_ms "$override_minute")"
  fi
  export ILAS_REQUEST_DELAY_MS="$EFFECTIVE_REQUEST_DELAY_MS"
  export ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE="$override_minute"
  export ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR="$override_hour"
  RATE_LIMIT_SOURCE="ddev_override"
  DDEV_RATE_LIMIT_OVERRIDE="applied"
  DDEV_RATE_LIMIT_OVERRIDE_APPLIED="true"
}

cleanup_ddev_rate_limit_override() {
  if [[ "$DDEV_RATE_LIMIT_OVERRIDE_APPLIED" != "true" ]]; then
    return 0
  fi

  if ddev exec drush cset ilas_site_assistant.settings rate_limit_per_minute "$DDEV_ORIGINAL_RATE_LIMIT_PER_MINUTE" -y >/dev/null &&
    ddev exec drush cset ilas_site_assistant.settings rate_limit_per_hour "$DDEV_ORIGINAL_RATE_LIMIT_PER_HOUR" -y >/dev/null &&
    ddev exec drush cr >/dev/null; then
    DDEV_RATE_LIMIT_OVERRIDE="restored"
  else
    DDEV_RATE_LIMIT_OVERRIDE="restore_failed"
  fi

  CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE="$DDEV_ORIGINAL_RATE_LIMIT_PER_MINUTE"
  CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE="$DDEV_ORIGINAL_RATE_LIMIT_PER_HOUR"
  DDEV_RATE_LIMIT_OVERRIDE_APPLIED="false"
}

write_summary() {
  local timestamp
  timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  mkdir -p "$(dirname "$SUMMARY_FILE")"

  {
    echo "timestamp_utc=${timestamp}"
    echo "site=${SITE_NAME}"
    echo "env=${ENV_NAME}"
    echo "branch=${CI_BRANCH_NAME}"
    echo "mode=${EFFECTIVE_MODE}"
    echo "threshold=${THRESHOLD}"
    echo "config_file=${CONFIG_FILE}"
    echo "smoke_config_file=${SMOKE_CONFIG_FILE}"
    echo "deep_config_file=${DEEP_CONFIG_FILE}"
    echo "assistant_url=${ILAS_ASSISTANT_URL:-}"
    echo "target_kind=${TARGET_KIND}"
    echo "target_source=${TARGET_SOURCE}"
    echo "target_host=${TARGET_HOST}"
    echo "requested_target_env=${REQUESTED_TARGET_ENV}"
    echo "resolved_target_env=${RESOLVED_TARGET_ENV}"
    echo "target_validation_status=${TARGET_VALIDATION_STATUS}"
    echo "connectivity_status=${CONNECTIVITY_STATUS}"
    echo "connectivity_error_code=${CONNECTIVITY_ERROR_CODE}"
    echo "quality_phase=${QUALITY_PHASE}"
    echo "rate_limit_source=${RATE_LIMIT_SOURCE}"
    echo "configured_rate_limit_per_minute=${CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE}"
    echo "configured_rate_limit_per_hour=${CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE}"
    echo "effective_rate_limit_per_minute=${EFFECTIVE_RATE_LIMIT_PER_MINUTE}"
    echo "effective_rate_limit_per_hour=${EFFECTIVE_RATE_LIMIT_PER_HOUR}"
    echo "effective_request_delay_ms=${EFFECTIVE_REQUEST_DELAY_MS}"
    echo "hourly_limit_preflight=${RATE_LIMIT_PREFLIGHT_STATUS}"
    echo "ddev_rate_limit_override=${DDEV_RATE_LIMIT_OVERRIDE}"
    echo "node_extra_ca_certs=${NODE_EXTRA_CA_CERTS:-}"
    echo "node_extra_ca_source=${NODE_CA_SOURCE}"
    echo "failure_kind=${FAILURE_KIND}"
    echo "failure_code=${FAILURE_CODE}"
    echo "planned_case_count=${PLANNED_CASE_COUNT}"
    echo "planned_smoke_case_count=${PLANNED_SMOKE_CASE_COUNT}"
    echo "planned_primary_case_count=${PLANNED_PRIMARY_CASE_COUNT}"
    echo "planned_deep_case_count=${PLANNED_DEEP_CASE_COUNT}"
    echo "smoke_eval_exit=${SMOKE_EVAL_EXIT}"
    echo "smoke_pass_rate=${SMOKE_PASS_RATE}"
    echo "smoke_total_cases=${SMOKE_TOTAL_CASES}"
    echo "smoke_passed_cases=${SMOKE_PASSED_CASES}"
    if [[ "$FULL_EVAL_COMPLETED" == "true" ]]; then
      echo "eval_exit=${EVAL_EXIT}"
      echo "pass_rate=${PASS_RATE}"
      echo "total_cases=${TOTAL_CASES}"
      echo "passed_cases=${PASSED_CASES}"
      echo "deep_eval_exit=${DEEP_EVAL_EXIT}"
      echo "deep_pass_rate=${DEEP_PASS_RATE}"
      echo "deep_total_cases=${DEEP_TOTAL_CASES}"
      echo "deep_passed_cases=${DEEP_PASSED_CASES}"
      echo "rag_metrics_enforced=${RAG_METRICS_ENFORCED}"
      echo "rag_metric_threshold=${RAG_METRIC_THRESHOLD}"
      echo "rag_metric_min_count=${RAG_METRIC_MIN_COUNT}"
      echo "rag_contract_meta_rate=${RAG_CONTRACT_META_RATE}"
      echo "rag_contract_meta_score=${RAG_CONTRACT_META_SCORE}"
      echo "rag_contract_meta_count=${RAG_CONTRACT_META_COUNT}"
      echo "rag_contract_meta_count_fail=${RAG_CONTRACT_META_COUNT_FAIL}"
      echo "rag_contract_meta_fail=${RAG_CONTRACT_META_FAIL}"
      echo "rag_citation_coverage_rate=${RAG_CITATION_COVERAGE_RATE}"
      echo "rag_citation_coverage_score=${RAG_CITATION_COVERAGE_SCORE}"
      echo "rag_citation_coverage_count=${RAG_CITATION_COVERAGE_COUNT}"
      echo "rag_citation_coverage_count_fail=${RAG_CITATION_COVERAGE_COUNT_FAIL}"
      echo "rag_citation_coverage_fail=${RAG_CITATION_COVERAGE_FAIL}"
      echo "rag_low_confidence_refusal_rate=${RAG_LOW_CONF_REFUSAL_RATE}"
      echo "rag_low_confidence_refusal_score=${RAG_LOW_CONF_REFUSAL_SCORE}"
      echo "rag_low_confidence_refusal_count=${RAG_LOW_CONF_REFUSAL_COUNT}"
      echo "rag_low_confidence_refusal_count_fail=${RAG_LOW_CONF_REFUSAL_COUNT_FAIL}"
      echo "rag_low_confidence_refusal_fail=${RAG_LOW_CONF_REFUSAL_FAIL}"
      echo "p2del04_metrics_enforced=${P2DEL04_METRICS_ENFORCED}"
      echo "p2del04_metric_threshold=${P2DEL04_METRIC_THRESHOLD}"
      echo "p2del04_metric_min_count=${P2DEL04_METRIC_MIN_COUNT}"
      echo "p2del04_contract_meta_rate=${P2DEL04_CONTRACT_META_RATE}"
      echo "p2del04_contract_meta_score=${P2DEL04_CONTRACT_META_SCORE}"
      echo "p2del04_contract_meta_count=${P2DEL04_CONTRACT_META_COUNT}"
      echo "p2del04_contract_meta_count_fail=${P2DEL04_CONTRACT_META_COUNT_FAIL}"
      echo "p2del04_contract_meta_fail=${P2DEL04_CONTRACT_META_FAIL}"
      echo "p2del04_weak_grounding_handling_rate=${P2DEL04_WEAK_GROUNDING_RATE}"
      echo "p2del04_weak_grounding_handling_score=${P2DEL04_WEAK_GROUNDING_SCORE}"
      echo "p2del04_weak_grounding_handling_count=${P2DEL04_WEAK_GROUNDING_COUNT}"
      echo "p2del04_weak_grounding_handling_count_fail=${P2DEL04_WEAK_GROUNDING_COUNT_FAIL}"
      echo "p2del04_weak_grounding_handling_fail=${P2DEL04_WEAK_GROUNDING_FAIL}"
      echo "p2del04_escalation_routing_rate=${P2DEL04_ESCALATION_ROUTING_RATE}"
      echo "p2del04_escalation_routing_score=${P2DEL04_ESCALATION_ROUTING_SCORE}"
      echo "p2del04_escalation_routing_count=${P2DEL04_ESCALATION_ROUTING_COUNT}"
      echo "p2del04_escalation_routing_count_fail=${P2DEL04_ESCALATION_ROUTING_COUNT_FAIL}"
      echo "p2del04_escalation_routing_fail=${P2DEL04_ESCALATION_ROUTING_FAIL}"
      echo "p2del04_escalation_actionability_rate=${P2DEL04_ESCALATION_ACTIONABILITY_RATE}"
      echo "p2del04_escalation_actionability_score=${P2DEL04_ESCALATION_ACTIONABILITY_SCORE}"
      echo "p2del04_escalation_actionability_count=${P2DEL04_ESCALATION_ACTIONABILITY_COUNT}"
      echo "p2del04_escalation_actionability_count_fail=${P2DEL04_ESCALATION_ACTIONABILITY_COUNT_FAIL}"
      echo "p2del04_escalation_actionability_fail=${P2DEL04_ESCALATION_ACTIONABILITY_FAIL}"
      echo "p2del04_safety_boundary_routing_rate=${P2DEL04_SAFETY_BOUNDARY_ROUTING_RATE}"
      echo "p2del04_safety_boundary_routing_score=${P2DEL04_SAFETY_BOUNDARY_ROUTING_SCORE}"
      echo "p2del04_safety_boundary_routing_count=${P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT}"
      echo "p2del04_safety_boundary_routing_count_fail=${P2DEL04_SAFETY_BOUNDARY_ROUTING_COUNT_FAIL}"
      echo "p2del04_safety_boundary_routing_fail=${P2DEL04_SAFETY_BOUNDARY_ROUTING_FAIL}"
      echo "p2del04_boundary_dampening_rate=${P2DEL04_BOUNDARY_DAMPENING_RATE}"
      echo "p2del04_boundary_dampening_score=${P2DEL04_BOUNDARY_DAMPENING_SCORE}"
      echo "p2del04_boundary_dampening_count=${P2DEL04_BOUNDARY_DAMPENING_COUNT}"
      echo "p2del04_boundary_dampening_count_fail=${P2DEL04_BOUNDARY_DAMPENING_COUNT_FAIL}"
      echo "p2del04_boundary_dampening_fail=${P2DEL04_BOUNDARY_DAMPENING_FAIL}"
      echo "p2del04_boundary_urgent_routing_rate=${P2DEL04_BOUNDARY_URGENT_ROUTING_RATE}"
      echo "p2del04_boundary_urgent_routing_score=${P2DEL04_BOUNDARY_URGENT_ROUTING_SCORE}"
      echo "p2del04_boundary_urgent_routing_count=${P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT}"
      echo "p2del04_boundary_urgent_routing_count_fail=${P2DEL04_BOUNDARY_URGENT_ROUTING_COUNT_FAIL}"
      echo "p2del04_boundary_urgent_routing_fail=${P2DEL04_BOUNDARY_URGENT_ROUTING_FAIL}"
    fi
  } > "$SUMMARY_FILE"
}

finalize_and_exit() {
  local requested_exit="$1"

  trap - EXIT
  cleanup_ddev_rate_limit_override || true
  write_summary

  if [[ "$requested_exit" -ne 0 ]]; then
    if [[ "$EFFECTIVE_MODE" == "blocking" ]]; then
      echo "Promptfoo gate FAILED in blocking mode" >&2
      exit "$requested_exit"
    fi
    echo "Promptfoo gate FAILED in advisory mode (non-blocking)" >&2
    exit 0
  fi

  echo "Promptfoo gate PASSED"
  exit 0
}

trap 'cleanup_ddev_rate_limit_override >/dev/null 2>&1 || true' EXIT

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
    --connectivity-only)
      CONNECTIVITY_ONLY="true"
      shift 1
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

if [[ ! -f "$ASSERTION_LINTER" ]]; then
  echo "Promptfoo assertion linter not found: $ASSERTION_LINTER" >&2
  exit 1
fi

if [[ ! -f "$GATE_METRICS_SCRIPT" ]]; then
  echo "Promptfoo gate metrics helper not found: $GATE_METRICS_SCRIPT" >&2
  exit 1
fi

node "$ASSERTION_LINTER"

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

if [[ -z "$CONFIG_FILE" ]]; then
  CONFIG_FILE="promptfooconfig.abuse.yaml"
fi
if [[ "$EFFECTIVE_MODE" == "blocking" && -z "$DEEP_CONFIG_FILE" ]]; then
  DEEP_CONFIG_FILE="promptfooconfig.deep.yaml"
fi
if [[ "$CONNECTIVITY_ONLY" == "true" && "$SKIP_EVAL" == "true" ]]; then
  echo "--connectivity-only cannot be combined with --skip-eval" >&2
  exit 2
fi

PLANNED_SMOKE_CASE_COUNT="$(count_cases_for_config "$SMOKE_CONFIG_FILE")"
PLANNED_PRIMARY_CASE_COUNT="$(count_cases_for_config "$CONFIG_FILE")"
if [[ -n "$DEEP_CONFIG_FILE" ]]; then
  PLANNED_DEEP_CASE_COUNT="$(count_cases_for_config "$DEEP_CONFIG_FILE")"
fi
PLANNED_CASE_COUNT=$((PLANNED_SMOKE_CASE_COUNT + PLANNED_PRIMARY_CASE_COUNT + PLANNED_DEEP_CASE_COUNT))

mkdir -p "$(dirname "$SUMMARY_FILE")"

if [[ "$SKIP_EVAL" == "true" ]]; then
  QUALITY_PHASE="target_resolution"
  if [[ -n "${ILAS_ASSISTANT_URL:-}" ]]; then
    resolve_assistant_target
    target_resolution_exit=$?
    if [[ "$target_resolution_exit" -ne 0 ]]; then
      finalize_and_exit "$target_resolution_exit"
    fi

    resolve_rate_limits
    if [[ -z "$CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE" || -z "$CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE" ]]; then
      cat >&2 <<EOF
Promptfoo rate-limit preflight could not resolve required limits for ${SITE_NAME}.${ENV_NAME}.
Provide ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE and ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR,
or ensure Terminus can read ilas_site_assistant.settings from the requested remote env.
EOF
      RATE_LIMIT_PREFLIGHT_STATUS="unresolved"
      FAILURE_KIND="capacity"
      FAILURE_CODE="rate_limit_unresolved"
      finalize_and_exit 4
    fi
  else
    ILAS_ASSISTANT_URL="${ILAS_ASSISTANT_URL:-https://example.invalid/assistant/api/message}"
    TARGET_SOURCE="skip_eval"
    REQUESTED_TARGET_ENV="$ENV_NAME"
    TARGET_VALIDATION_STATUS="not_applicable"
    classify_assistant_url "$ILAS_ASSISTANT_URL" ""
  fi

  QUALITY_PHASE="simulated"
  if [[ -n "$SIMULATED_PASS_RATE" ]]; then
    PASS_RATE="$SIMULATED_PASS_RATE"
  fi
  finalize_and_exit 0
fi

QUALITY_PHASE="target_resolution"
resolve_assistant_target
target_resolution_exit=$?
if [[ "$target_resolution_exit" -ne 0 ]]; then
  finalize_and_exit "$target_resolution_exit"
fi
ensure_ddev_node_trust || finalize_and_exit 3
resolve_rate_limits

if [[ -z "$CONFIGURED_RATE_LIMIT_PER_MINUTE_VALUE" || -z "$CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE" ]]; then
  cat >&2 <<EOF
Promptfoo rate-limit preflight could not resolve required limits for ${SITE_NAME}.${ENV_NAME}.
Provide ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE and ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR,
or ensure Terminus can read ilas_site_assistant.settings from the requested remote env.
EOF
  RATE_LIMIT_PREFLIGHT_STATUS="unresolved"
  FAILURE_KIND="capacity"
  FAILURE_CODE="rate_limit_unresolved"
  finalize_and_exit 4
fi

if [[ "$TARGET_KIND" != "ddev" && "$ILAS_HOURLY_LIMIT_PREFLIGHT" == "true" ]]; then
  RATE_LIMIT_PREFLIGHT_STATUS="checked"
  if [[ "$CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE" -lt "$PLANNED_CASE_COUNT" ]]; then
    cat >&2 <<EOF
Promptfoo hourly-rate preflight failed:
  configured_hour_limit=${CONFIGURED_RATE_LIMIT_PER_HOUR_VALUE}
  planned_case_count=${PLANNED_CASE_COUNT}
  smoke_cases=${PLANNED_SMOKE_CASE_COUNT}
  primary_cases=${PLANNED_PRIMARY_CASE_COUNT}
  deep_cases=${PLANNED_DEEP_CASE_COUNT}
Configured hourly limit is lower than planned eval volume and will likely cause 429 failures.
EOF
    FAILURE_KIND="capacity"
    FAILURE_CODE="hourly_limit_preflight"
    finalize_and_exit 4
  fi
else
  RATE_LIMIT_PREFLIGHT_STATUS="$([[ "$TARGET_KIND" == "ddev" ]] && echo "ddev_override_planned" || echo "skipped")"
fi

if [[ "$TARGET_KIND" == "ddev" ]]; then
  apply_ddev_rate_limit_override || finalize_and_exit 4
fi

run_connectivity_preflight || finalize_and_exit $?

if [[ "$CONNECTIVITY_ONLY" == "true" ]]; then
  QUALITY_PHASE="connectivity_only"
  finalize_and_exit 0
fi

if [[ ! -x "$PROMPTFOO_SCRIPT" ]]; then
  echo "Promptfoo runner not found: $PROMPTFOO_SCRIPT" >&2
  FAILURE_KIND="eval"
  FAILURE_CODE="runner_missing"
  finalize_and_exit 2
fi

QUALITY_PHASE="smoke"
suite_summary="$(run_promptfoo_suite "$SMOKE_CONFIG_FILE" "$RESULTS_FILE_SMOKE" "$PLANNED_SMOKE_CASE_COUNT")"
IFS='|' read -r SMOKE_EVAL_EXIT SMOKE_PASS_RATE SMOKE_TOTAL_CASES SMOKE_PASSED_CASES smoke_error <<< "$suite_summary"
if [[ -n "$smoke_error" ]]; then
  read -r SMOKE_ERROR_KIND SMOKE_ERROR_CODE <<< "$smoke_error"
fi

if [[ "$SMOKE_ERROR_KIND" == "capacity" ]]; then
  FAILURE_KIND="capacity"
  FAILURE_CODE="$SMOKE_ERROR_CODE"
  finalize_and_exit 4
fi
if [[ "$SMOKE_ERROR_KIND" == "connectivity" ]]; then
  FAILURE_KIND="connectivity"
  FAILURE_CODE="$SMOKE_ERROR_CODE"
  finalize_and_exit 3
fi

if [[ "$SMOKE_EVAL_EXIT" -ne 0 ]]; then
  FAILURE_KIND="eval"
  FAILURE_CODE="smoke_eval_exit"
  finalize_and_exit 2
fi

SMOKE_THRESHOLD_FAIL="$(node -e "const p=parseFloat('${SMOKE_PASS_RATE}'); const t=parseFloat('${THRESHOLD}'); console.log(Number.isFinite(p)&&Number.isFinite(t)&&p<t ? 'yes':'no');")"
if [[ "$SMOKE_THRESHOLD_FAIL" == "yes" ]]; then
  FAILURE_KIND="eval"
  FAILURE_CODE="smoke_threshold"
  finalize_and_exit 2
fi

QUALITY_PHASE="full"
suite_summary="$(run_promptfoo_suite "$CONFIG_FILE" "$RESULTS_FILE" "$PLANNED_PRIMARY_CASE_COUNT")"
IFS='|' read -r EVAL_EXIT PASS_RATE TOTAL_CASES PASSED_CASES primary_error <<< "$suite_summary"
if [[ -n "$primary_error" ]]; then
  read -r PRIMARY_ERROR_KIND PRIMARY_ERROR_CODE <<< "$primary_error"
fi
FULL_EVAL_COMPLETED="true"

if [[ "$PRIMARY_ERROR_KIND" == "capacity" ]]; then
  FAILURE_KIND="capacity"
  FAILURE_CODE="$PRIMARY_ERROR_CODE"
  finalize_and_exit 4
fi
if [[ "$PRIMARY_ERROR_KIND" == "connectivity" ]]; then
  FAILURE_KIND="connectivity"
  FAILURE_CODE="$PRIMARY_ERROR_CODE"
  finalize_and_exit 3
fi

if [[ "$EVAL_EXIT" -eq 0 && -f "$RESULTS_FILE" ]]; then
  RAG_METRICS_ENFORCED="true"
  RAG_THRESHOLD_FAIL="no"
  apply_metric_threshold_report \
    "$RESULTS_FILE" \
    "$RAG_METRIC_THRESHOLD" \
    "$RAG_METRIC_MIN_COUNT" \
    "rag" \
    "rag-contract-meta-present" \
    "rag-citation-coverage" \
    "rag-low-confidence-refusal"

  P2DEL04_METRICS_ENFORCED="true"
  P2DEL04_THRESHOLD_FAIL="no"
  apply_metric_threshold_report \
    "$RESULTS_FILE" \
    "$P2DEL04_METRIC_THRESHOLD" \
    "$P2DEL04_METRIC_MIN_COUNT" \
    "p2del04" \
    "p2del04-contract-meta-present" \
    "p2del04-weak-grounding-handling" \
    "p2del04-escalation-routing" \
    "p2del04-escalation-actionability" \
    "p2del04-safety-boundary-routing" \
    "p2del04-boundary-dampening" \
    "p2del04-boundary-urgent-routing"
fi

if [[ -n "$DEEP_CONFIG_FILE" ]]; then
  suite_summary="$(run_promptfoo_suite "$DEEP_CONFIG_FILE" "$RESULTS_FILE_DEEP" "$PLANNED_DEEP_CASE_COUNT")"
  IFS='|' read -r DEEP_EVAL_EXIT DEEP_PASS_RATE DEEP_TOTAL_CASES DEEP_PASSED_CASES deep_error <<< "$suite_summary"
  if [[ -n "$deep_error" ]]; then
    read -r DEEP_ERROR_KIND DEEP_ERROR_CODE <<< "$deep_error"
  fi
fi

THRESHOLD_FAIL="$(node -e "const p=parseFloat('${PASS_RATE}'); const t=parseFloat('${THRESHOLD}'); console.log(Number.isFinite(p)&&Number.isFinite(t)&&p<t ? 'yes':'no');")"
DEEP_THRESHOLD_FAIL="no"
if [[ -n "$DEEP_CONFIG_FILE" ]]; then
  DEEP_THRESHOLD_FAIL="$(node -e "const p=parseFloat('${DEEP_PASS_RATE}'); const t=parseFloat('${THRESHOLD}'); console.log(Number.isFinite(p)&&Number.isFinite(t)&&p<t ? 'yes':'no');")"
fi

if [[ "$DEEP_ERROR_KIND" == "capacity" ]]; then
  FAILURE_KIND="capacity"
  FAILURE_CODE="$DEEP_ERROR_CODE"
  finalize_and_exit 4
fi
if [[ "$DEEP_ERROR_KIND" == "connectivity" || "$PRIMARY_ERROR_KIND" == "connectivity" ]]; then
  FAILURE_KIND="connectivity"
  FAILURE_CODE="${DEEP_ERROR_CODE:-$PRIMARY_ERROR_CODE}"
  finalize_and_exit 3
fi

if [[ "$EVAL_EXIT" -ne 0 || "$THRESHOLD_FAIL" == "yes" || "$DEEP_EVAL_EXIT" -ne 0 || "$DEEP_THRESHOLD_FAIL" == "yes" || "$RAG_THRESHOLD_FAIL" == "yes" || "$P2DEL04_THRESHOLD_FAIL" == "yes" ]]; then
  FAILURE_KIND="eval"
  FAILURE_CODE="threshold_or_eval_failure"
  if [[ "$PRIMARY_ERROR_KIND" == "capacity" ]]; then
    FAILURE_KIND="capacity"
    FAILURE_CODE="$PRIMARY_ERROR_CODE"
    finalize_and_exit 4
  fi
  finalize_and_exit 2
fi

finalize_and_exit 0
