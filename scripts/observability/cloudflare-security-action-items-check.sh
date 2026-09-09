#!/usr/bin/env bash
set -euo pipefail

ZONE_NAME="idaholegalaid.org"
TOKEN_FILE=""

usage() {
  cat <<'EOF'
Usage: scripts/observability/cloudflare-security-action-items-check.sh [options]

Checks the current state for the Cloudflare Security Action Items triage:
DMARC, security.txt, robots.txt crawler posture, and optional Cloudflare
rules/list/bot settings when a read-scoped token is available, including the
Super Bot Fight Mode static-resource posture (must stay off) and a 24h count
of browser clients that were challenged on CSS/JS/image subrequests.

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
elif [[ "$security_code" == "403" ]] && grep -q 'cdn-cgi/styles/cf\.errors\.css' "$security_body"; then
  # Cloudflare bot protection blocked this non-browser client, so the origin
  # file was never reached. Fix is a bot-protection skip for this path, not
  # the file itself.
  echo "security_txt_status=blocked_by_bot_protection"
else
  echo "security_txt_status=review_required"
fi
echo

echo "== robots.txt crawler posture =="
robots_code="$(curl -sS -o "$robots_body" -w '%{http_code}' "https://${ZONE_NAME}/robots.txt" || true)"
echo "status=${robots_code}"
if grep -qi 'BEGIN Cloudflare Managed content' "$robots_body"; then
  echo "robots_source=cloudflare_managed_content_present"
  # When Cloudflare's managed robots.txt REPLACES (rather than prepends to)
  # the origin file, every robots_missing line below is a regression: the
  # origin directives in web/robots.txt are no longer served.
fi
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
    | jq -r '.result.rules[]? | select((.ref // "") | test("^ilas_auth_|^ilas_seo_|^ilas_skip_|^64fae5be|^fcfe|^ccc|^468|^de59")) | "rule ref=\(.ref // "") enabled=\(.enabled) action=\(.action) desc=\(.description // "")"'
done

echo "account_ip_lists="
api_get "https://api.cloudflare.com/client/v4/accounts/${account_id}/rules/lists" \
  | jq -r 'if .success then (.result[]? | select(.kind == "ip") | "list name=\(.name) id=\(.id) items=\(.num_items)") else "unavailable" end'

echo
echo "== Super Bot Fight Mode static resources =="
# Static resource protection extends "likely automated" challenges to CSS, JS,
# image and font subrequests. A <script>/<link>/<img> fetch can never solve a
# managed challenge, and JS detections only run on HTML responses, so real
# browsers routinely score "likely automated" on subrequests and receive a 403
# challenge page in place of the asset: unstyled or non-functional pages for
# 200-330 visitor IPs per day (2026-09-07..09), surfaced in Sentry as
# PHP-AP "Drupal is not defined" and PHP-AQ "The following files could not be
# loaded". Root cause of A5 in docs/sentry-active-errors-2026-09-09.md.
bot_json="$(api_get "${base}/bot_management" || echo '{"success": false}')"
static_protection="$(jq -r 'if .success then (.result.sbfm_static_resource_protection | tostring) else "unavailable" end' <<<"$bot_json")"
echo "sbfm_static_resource_protection=${static_protection}"
for key in sbfm_likely_automated sbfm_definitely_automated sbfm_verified_bots ai_bots_protection; do
  echo "${key}=$(jq -r ".result.${key} // \"unavailable\"" <<<"$bot_json")"
done
case "$static_protection" in
  false) echo "sbfm_static_status=ok" ;;
  true) echo "sbfm_static_status=regression_static_protection_enabled" ;;
  *) echo "sbfm_static_status=review_required" ;;
esac

# Security events from the two SBFM static-resource rules in the last 24h,
# restricted to browser user agents and render-critical paths (Drupal
# aggregates, module/theme JS+CSS, image styles, images, fonts). Any non-zero
# count means visitors are receiving challenge pages instead of assets.
# firewallEventsAdaptive caps at a 3-day span per query; 24h is well inside.
since="$(date -u -d '24 hours ago' +%Y-%m-%dT%H:%M:%SZ)"
until="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
gql_query='query($zone: String!, $since: Time!, $until: Time!) { viewer { zones(filter: {zoneTag: $zone}) { firewallEventsAdaptive(limit: 5000, filter: {datetime_geq: $since, datetime_lt: $until, ruleId_in: ["023ec3b3a7f548f292eaaa89a9a471bd", "5ac94856e22545c0ac580ded156bc052"]}) { action ruleId clientRequestPath userAgent clientIP } } } }'
gql_body="$(jq -n --arg q "$gql_query" --arg zone "$zone_id" --arg since "$since" --arg until "$until" \
  '{query: $q, variables: {zone: $zone, since: $since, until: $until}}')"
events_json="$(curl -fsS -X POST https://api.cloudflare.com/client/v4/graphql \
  -H "Authorization: Bearer $CF_TOKEN" -H "Content-Type: application/json" \
  --data "$gql_body" || true)"
if [[ -z "$events_json" ]] || [[ "$(jq -r '.errors | length' <<<"$events_json" 2>/dev/null || echo 1)" != "0" ]]; then
  echo "static_render_asset_challenges_24h=unavailable"
  echo "static_render_asset_status=review_required"
else
  render_events="$(jq '[.data.viewer.zones[0].firewallEventsAdaptive[]?
      | select(.clientRequestPath | test("/sites/default/files/(css|js)/(css|js)_|^/(core|modules|themes|libraries|profiles)/.*\\.(js|css)$|/files/styles/|\\.(svg|webp|png|jpe?g|gif|ico|woff2?|ttf|otf|eot)$"; "i"))
      | select(.userAgent | test("bot|spider|crawl|python|curl|Go-http|symbolicator|Recovery|HeadlessChrome"; "i") | not)]' <<<"$events_json")"
  echo "static_rule_events_24h=$(jq '.data.viewer.zones[0].firewallEventsAdaptive | length' <<<"$events_json")"
  echo "static_render_asset_challenges_24h=$(jq 'length' <<<"$render_events")"
  echo "static_render_asset_challenged_ips_24h=$(jq '[.[].clientIP] | unique | length' <<<"$render_events")"
  if [[ "$(jq 'length' <<<"$render_events")" == "0" ]]; then
    echo "static_render_asset_status=ok"
  else
    echo "static_render_asset_status=visitors_receiving_challenge_pages_for_assets"
  fi
fi
