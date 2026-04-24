#!/usr/bin/env bash
set -euo pipefail

ZONE_NAME="idaholegalaid.org"
WINDOW_HOURS="24"
TOKEN_FILE=""

usage() {
  cat <<'EOF'
Usage: scripts/observability/cloudflare-waf-rollout-monitor.sh [options]

Summarizes the ILAS Cloudflare auth WAF rollout state and recent Security
Events for the rollout rules.

Options:
  --zone NAME        Cloudflare zone name. Default: idaholegalaid.org
  --hours HOURS      Lookback window for Security Events. Default: 24
  --token-file PATH  Read Cloudflare API token from PATH.
  -h, --help         Show this help.

Token:
  Set CLOUDFLARE_API_TOKEN or pass --token-file. The token needs read access
  for Zone, DNS, WAF/rulesets, and Security Events/analytics.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --zone)
      ZONE_NAME="${2:-}"
      shift 2
      ;;
    --hours)
      WINDOW_HOURS="${2:-}"
      shift 2
      ;;
    --token-file)
      TOKEN_FILE="${2:-}"
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

need() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

need curl
need jq
need dig

if [[ ! "$WINDOW_HOURS" =~ ^[0-9]+$ ]] || [[ "$WINDOW_HOURS" -lt 1 ]]; then
  echo "--hours must be a positive integer" >&2
  exit 2
fi

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -n "$TOKEN_FILE" ]]; then
  if [[ ! -s "$TOKEN_FILE" ]]; then
    echo "Token file is missing or empty: $TOKEN_FILE" >&2
    exit 1
  fi
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi

if [[ -z "$CF_TOKEN" ]]; then
  echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2
  exit 1
fi

api_get() {
  curl -fsS \
    -H "Authorization: Bearer $CF_TOKEN" \
    -H "Content-Type: application/json" \
    "$1"
}

api_post() {
  local url="$1"
  local payload="$2"
  curl -fsS \
    -H "Authorization: Bearer $CF_TOKEN" \
    -H "Content-Type: application/json" \
    --data "$payload" \
    "$url"
}

RULE_REFS=(
  "ilas_auth_observed_abusive_ips"
  "ilas_auth_vpn_hosting_asns"
  "ilas_auth_post_rate_limit"
)

is_expected_auth_path() {
  local path="$1"
  [[ "$path" =~ ^/((es|sw|nl)/)?user(/(login|password|register))?$ ]] \
    || [[ "$path" =~ ^/((es|sw|nl)/)?user/reset/ ]] \
    || [[ "$path" =~ ^/((es|sw|nl)/)?admin(/|$) ]]
}

json_zone="$(api_get "https://api.cloudflare.com/client/v4/zones?name=${ZONE_NAME}&status=active")"
zone_id="$(jq -r '.result[0].id // empty' <<<"$json_zone")"
account_name="$(jq -r '.result[0].account.name // "unknown"' <<<"$json_zone")"
plan_name="$(jq -r '.result[0].plan.name // "unknown"' <<<"$json_zone")"

if [[ -z "$zone_id" ]]; then
  echo "Cloudflare zone not found: $ZONE_NAME" >&2
  exit 1
fi

base="https://api.cloudflare.com/client/v4/zones/${zone_id}"

echo "== ILAS Cloudflare WAF Rollout Monitor =="
echo "zone=${ZONE_NAME}"
echo "zone_id=${zone_id}"
echo "account=${account_name}"
echo "plan=${plan_name}"
echo "window_hours=${WINDOW_HOURS}"
echo

echo "== DNS =="
echo "dig_apex=$(dig +short A "$ZONE_NAME" | paste -sd, -)"
echo "dig_www=$(dig +short A "www.${ZONE_NAME}" | paste -sd, -)"
api_get "${base}/dns_records?per_page=100" \
  | jq -r --arg zone "$ZONE_NAME" '
      .result[]
      | select((.name == $zone or .name == ("www." + $zone)) and (.type == "A" or .type == "CNAME"))
      | "cloudflare_dns type=\(.type) name=\(.name) proxied=\(.proxied) content=\(.content)"
    '
echo

echo "== Zone Settings =="
for setting in ssl browser_check security_level; do
  value="$(api_get "${base}/settings/${setting}" | jq -r '.result.value // "unavailable"')"
  echo "${setting}=${value}"
done
echo

declare -A RULE_ID_TO_REF=()
declare -A RULE_REF_TO_ACTION=()
declare -A RULE_REF_TO_ENABLED=()

echo "== Rollout Rules =="
for phase in http_request_firewall_custom http_ratelimit; do
  ruleset_json="$(api_get "${base}/rulesets/phases/${phase}/entrypoint")"
  for ref in "${RULE_REFS[@]}"; do
    rule="$(jq -c --arg ref "$ref" '.result.rules[]? | select(.ref == $ref)' <<<"$ruleset_json")"
    if [[ -z "$rule" ]]; then
      echo "rule phase=${phase} ref=${ref} present=false"
      continue
    fi
    id="$(jq -r '.id' <<<"$rule")"
    action="$(jq -r '.action' <<<"$rule")"
    enabled="$(jq -r '.enabled' <<<"$rule")"
    RULE_ID_TO_REF["$id"]="$ref"
    RULE_ID_TO_REF["$ref"]="$ref"
    RULE_REF_TO_ACTION["$ref"]="$action"
    RULE_REF_TO_ENABLED["$ref"]="$enabled"
    rate="$(jq -r 'if .ratelimit then "\(.ratelimit.requests_per_period)/\(.ratelimit.period)s timeout=\(.ratelimit.mitigation_timeout)" else "n/a" end' <<<"$rule")"
    echo "rule phase=${phase} ref=${ref} id=${id} enabled=${enabled} action=${action} rate=${rate}"
  done
done
echo

since="$(date -u -d "${WINDOW_HOURS} hours ago" +%Y-%m-%dT%H:%M:%SZ)"
query='query($zone: String!, $since: Time!) {
  viewer {
    zones(filter: { zoneTag: $zone }) {
      firewallEventsAdaptive(
        limit: 1000
        filter: { datetime_geq: $since }
        orderBy: [datetime_DESC]
      ) {
        action
        clientIP
        clientRequestHTTPHost
        clientRequestHTTPMethodName
        clientRequestPath
        datetime
        ruleId
        source
        userAgent
      }
    }
  }
}'
payload="$(jq -n --arg query "$query" --arg zone "$zone_id" --arg since "$since" \
  '{query:$query, variables:{zone:$zone, since:$since}}')"

events_json="$(api_post "https://api.cloudflare.com/client/v4/graphql" "$payload")"
if jq -e '.errors and (.errors | length > 0)' >/dev/null <<<"$events_json"; then
  echo "== Security Events =="
  echo "security_events_status=unavailable"
  jq -r '.errors[] | "error=\(.message)"' <<<"$events_json"
  echo
  echo "Grant the token Cloudflare Security Events/analytics read permission, then rerun this script."
  exit 1
fi

tmp_events="$(mktemp)"
trap 'rm -f "$tmp_events"' EXIT
jq -c '.data.viewer.zones[0].firewallEventsAdaptive[]?' <<<"$events_json" > "$tmp_events"

total=0
matched=0
unexpected_paths=0
block_actions=0

echo "== Security Events For Rollout Rules =="
while IFS= read -r event; do
  total=$((total + 1))
  rule_id="$(jq -r '.ruleId // ""' <<<"$event")"
  ref="${RULE_ID_TO_REF[$rule_id]:-}"
  if [[ -z "$ref" ]]; then
    continue
  fi

  matched=$((matched + 1))
  action="$(jq -r '.action // ""' <<<"$event")"
  method="$(jq -r '.clientRequestHTTPMethodName // ""' <<<"$event")"
  path="$(jq -r '.clientRequestPath // ""' <<<"$event")"
  datetime="$(jq -r '.datetime // ""' <<<"$event")"
  source="$(jq -r '.source // ""' <<<"$event")"
  ip="$(jq -r '.clientIP // ""' <<<"$event")"

  path_status="expected_path"
  if ! is_expected_auth_path "$path"; then
    path_status="unexpected_path"
    unexpected_paths=$((unexpected_paths + 1))
  fi
  if [[ "$action" == "block" ]]; then
    block_actions=$((block_actions + 1))
  fi

  printf 'event datetime=%s ref=%s action=%s source=%s method=%s path=%s ip=%s %s\n' \
    "$datetime" "$ref" "$action" "$source" "$method" "$path" "$ip" "$path_status"
done < "$tmp_events"

echo
echo "== Summary =="
echo "security_events_total_window=${total}"
echo "rollout_rule_events=${matched}"
echo "rollout_rule_unexpected_paths=${unexpected_paths}"
echo "rollout_rule_block_actions=${block_actions}"

if [[ "$unexpected_paths" -gt 0 || "$block_actions" -gt 0 ]]; then
  echo "status=review_required"
  exit 2
fi

echo "status=ok"
