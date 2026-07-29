#!/usr/bin/env bash
#
# Compare Cloudflare edge 404 volume against the pre-deploy baseline recorded for
# tracker items 2 & 4 (icons + fast_404). See
# docs/pantheon-cloudflare-implementation-tracker.md, "Items 2 & 4".
#
# Baseline window 2026-07-27T02:00Z -> 2026-07-29T02:00Z (2 days, 76,558 requests):
#   zone-wide 404s                      4,564
#   /favicon.ico                          888
#   /apple-touch-icon.png                 247
#   /apple-touch-icon-precomposed.png     233
#   top three combined                  1,368  (30.0% of all zone 404s)
#
# Live cutover for the icon fix: 2026-07-29T04:51:14Z.
#
# Expected after: the three icon paths fall to ~0 and the zone-wide total drops by
# roughly a third. The remaining tail is legacy /sites/idaholegalaid.org/files/*.pdf
# links (tracker item 7) and encoded-path junk.
#
# Usage:
#   scripts/observability/cloudflare-404-volume-check.sh [START_ISO] [END_ISO]
#   scripts/observability/cloudflare-404-volume-check.sh --token-file PATH ...
#
# Defaults to the 48h window following the cutover. Needs CLOUDFLARE_API_TOKEN or
# --token-file; the token needs Zone Analytics read on idaholegalaid.org.
set -euo pipefail

ZONE_ID="7aef3c4adc977c9f645472338b031450"
TOKEN_FILE="${HOME}/.secrets/cloudflare_api_token"
START="2026-07-29T05:00:00Z"
END=""

while (($# > 0)); do
  case "$1" in
    --token-file) TOKEN_FILE="${2:-}"; shift 2 ;;
    --zone-id)    ZONE_ID="${2:-}";    shift 2 ;;
    -h|--help)    sed -n '2,25p' "$0"; exit 0 ;;
    *)
      if [[ -z "${START_SET:-}" ]]; then START="$1"; START_SET=1
      else END="$1"; fi
      shift ;;
  esac
done

[[ -n "$END" ]] || END="$(date -u -d "$START + 48 hours" +%Y-%m-%dT%H:%M:%SZ)"

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -s "$TOKEN_FILE" ]]; then
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi
if [[ -z "$CF_TOKEN" ]]; then
  echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2
  exit 1
fi

echo "window: $START -> $END"

read -r -d '' QUERY <<EOF || true
query {
  viewer {
    zones(filter: {zoneTag: "$ZONE_ID"}) {
      tot: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: "$START", datetime_lt: "$END", edgeResponseStatus: 404}) { count }
      all: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: "$START", datetime_lt: "$END"}) { count }
      paths: httpRequestsAdaptiveGroups(limit: 25, filter: {datetime_geq: "$START", datetime_lt: "$END", edgeResponseStatus: 404}, orderBy: [count_DESC]) {
        count dimensions { clientRequestPath }
      }
    }
  }
}
EOF

python3 -c "import json,sys; print(json.dumps({'query': sys.stdin.read()}))" <<<"$QUERY" \
  | curl -s https://api.cloudflare.com/client/v4/graphql \
      -H "Authorization: Bearer $CF_TOKEN" \
      -H "Content-Type: application/json" \
      --data @- \
  | python3 -c "
import json, sys

BASELINE = {
    '/favicon.ico': 888,
    '/apple-touch-icon.png': 247,
    '/apple-touch-icon-precomposed.png': 233,
}
BASELINE_TOTAL = 4564

d = json.load(sys.stdin)
if d.get('errors'):
    print('ERRORS:', json.dumps(d['errors'])[:600])
    sys.exit(1)

z = d['data']['viewer']['zones'][0]
tot = z['tot'][0]['count'] if z['tot'] else 0
allr = z['all'][0]['count'] if z['all'] else 0
print(f'zone-wide requests: {allr:,}')
print(f'zone-wide 404s:     {tot:,}   (2-day baseline: {BASELINE_TOTAL:,})')

seen = {r['dimensions']['clientRequestPath']: r['count'] for r in z['paths']}
print()
print('icon paths (the ones this change fixed):')
for p, base in BASELINE.items():
    now = seen.get(p, 0)
    print(f'  {now:>6}  {p}   (baseline {base})')

print()
print('top 404 paths in window:')
for r in z['paths']:
    print(f\"  {r['count']:>6}  {r['dimensions']['clientRequestPath'][:100]}\")
"
