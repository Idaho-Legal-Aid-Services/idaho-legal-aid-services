#!/usr/bin/env bash
set -euo pipefail

ZONE_NAME="idaholegalaid.org"
TOKEN_FILE=""

usage() {
  cat <<'EOF'
Usage: scripts/observability/cloudflare-security-action-items-check.sh [options]

Checks the current state for the Cloudflare Security Action Items triage:
DMARC, security.txt, robots.txt crawler posture, and optional Cloudflare
rules/list/bot settings when a read-scoped token is available.

Options:
  --zone NAME        Cloudflare zone name. Default: idaholegalaid.org
  --token-file PATH  Read Cloudflare API token from PATH.
  -h, --help         Show this help.

Token:
  Set CLOUDFLARE_API_TOKEN or pass --token-file for Cloudflare dashboard/API
  state. Public DNS and HTTP checks run without a token.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --zone)
      ZONE_NAME="${2:-}"
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
need dig
need jq

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -n "$TOKEN_FILE" ]]; then
  if [[ ! -s "$TOKEN_FILE" ]]; then
    echo "Token file is missing or empty: $TOKEN_FILE" >&2
    exit 1
  fi
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi

api_get() {
  curl -fsS \
    -H "Authorization: Bearer $CF_TOKEN" \
    -H "Content-Type: application/json" \
    "$1"
}

echo "== Cloudflare Security Action Items Check =="
echo "zone=${ZONE_NAME}"
echo

echo "== DMARC =="
dmarc="$(dig +short TXT "_dmarc.${ZONE_NAME}" | tr -d '"' | paste -sd' ' -)"
echo "record=${dmarc:-missing}"
if [[ "$dmarc" != v=DMARC1* ]]; then
  echo "dmarc_status=missing_or_invalid"
elif [[ "$dmarc" != *"rua=mailto:"* ]]; then
  echo "dmarc_status=monitoring_without_aggregate_reports"
elif [[ "$dmarc" == *"p=none"* ]]; then
  echo "dmarc_status=monitoring_with_reports"
elif [[ "$dmarc" == *"p=quarantine"* || "$dmarc" == *"p=reject"* ]]; then
  echo "dmarc_status=enforcing"
else
  echo "dmarc_status=review_required"
fi
echo

echo "== security.txt =="
security_headers="$(mktemp)"
security_body="$(mktemp)"
robots_body="$(mktemp)"
trap 'rm -f "$security_headers" "$security_body" "$robots_body"' EXIT
security_code="$(curl -sS -D "$security_headers" -o "$security_body" -w '%{http_code}' "https://${ZONE_NAME}/.well-known/security.txt" || true)"
echo "status=${security_code}"
sed -n '1,20p' "$security_body" | sed 's/^/body: /'
if [[ "$security_code" == "200" ]] \
  && grep -qi '^Contact:' "$security_body" \
  && grep -qi '^Expires:' "$security_body" \
  && grep -qi '^Canonical:' "$security_body"; then
  echo "security_txt_status=present"
else
  echo "security_txt_status=review_required"
fi
echo

echo "== robots.txt crawler posture =="
robots_code="$(curl -sS -o "$robots_body" -w '%{http_code}' "https://${ZONE_NAME}/robots.txt" || true)"
echo "status=${robots_code}"
for pattern in 'Disallow: /assistant/api/' 'Disallow: /search' 'Disallow: /user/login' 'Sitemap:'; do
  if grep -Fq "$pattern" "$robots_body"; then
    echo "robots_contains=${pattern}"
  else
    echo "robots_missing=${pattern}"
  fi
done
echo

if [[ -z "$CF_TOKEN" ]]; then
  echo "== Cloudflare API State =="
  echo "cloudflare_api_status=skipped_no_token"
  exit 0
fi

echo "== Cloudflare API State =="
zone_json="$(api_get "https://api.cloudflare.com/client/v4/zones?name=${ZONE_NAME}&status=active")"
zone_id="$(jq -r '.result[0].id // empty' <<<"$zone_json")"
account_id="$(jq -r '.result[0].account.id // empty' <<<"$zone_json")"
plan_name="$(jq -r '.result[0].plan.name // "unknown"' <<<"$zone_json")"
if [[ -z "$zone_id" || -z "$account_id" ]]; then
  echo "cloudflare_api_status=zone_not_found"
  exit 1
fi

echo "cloudflare_api_status=ok"
echo "zone_id=${zone_id}"
echo "account_id=${account_id}"
echo "plan=${plan_name}"

base="https://api.cloudflare.com/client/v4/zones/${zone_id}"
for setting in ssl browser_check security_level waf; do
  value="$(api_get "${base}/settings/${setting}" | jq -r 'if .success then .result.value else "unavailable" end')"
  echo "setting_${setting}=${value}"
done

for phase in http_request_firewall_custom http_ratelimit; do
  echo "phase=${phase}"
  api_get "${base}/rulesets/phases/${phase}/entrypoint" \
    | jq -r '.result.rules[]? | select((.ref // "") | test("^ilas_auth_|^fcfe|^ccc|^468|^de59")) | "rule ref=\(.ref // "") enabled=\(.enabled) action=\(.action) desc=\(.description // "")"'
done

echo "account_ip_lists="
api_get "https://api.cloudflare.com/client/v4/accounts/${account_id}/rules/lists" \
  | jq -r 'if .success then (.result[]? | select(.kind == "ip") | "list name=\(.name) id=\(.id) items=\(.num_items)") else "unavailable" end'
