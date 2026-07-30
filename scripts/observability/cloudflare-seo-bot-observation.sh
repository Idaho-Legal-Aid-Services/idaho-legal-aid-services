#!/usr/bin/env bash
#
# Observation report for the "Search Engine Optimization" verified-bot category on
# idaholegalaid.org. Backs tracker item 9 (validation report §8.6 / §10.2).
#
# Why this exists: §10.2 called for a custom rule in *Log* mode for >=7 days before any
# promotion to Managed Challenge. The Log action is Enterprise-only and this zone is on
# Business Website, so the rule was instead created as a behaviour-preserving mirror-skip
# (ref ilas_seo_category_observe) that carries the exact action_parameters skip rule
# 64fae5be applied to this category before the change. Runtime behaviour is unchanged;
# the rule exists purely so SEO-category matches carry their own rule ID.
#
# This script is the evidence-gathering half. It reports, for the window:
#   * matched user agents x path x status x ASN x country x request count
#   * status-code distribution and total volume
#   * a protected-traffic tripwire (uptime monitors, search engines, social preview,
#     security, accessibility, webhooks) -- these must never appear in the SEO category
#   * a cross-category integrity check -- Cloudflare recategorises bots without notice,
#     and a silent recategorisation is the mechanism that would break monitoring
#
# Two data sources, because their retention differs:
#   httpRequestsAdaptiveGroups  ~30 days, so a 7-day window is one query. Primary.
#   firewallEventsAdaptive      hard 3-day span cap on this plan, so it is issued in
#                               chunks. Used only to prove rule attribution.
#
# Exit codes: 0 clean, 2 review required (any tripwire hit), 1 error.
#
# Usage:
#   scripts/observability/cloudflare-seo-bot-observation.sh [--days 7] [--rule-id ID]
#     [--out DIR] [--json] [--token-file PATH] [--zone-id ID]
#
# Needs CLOUDFLARE_API_TOKEN or --token-file; the token needs Zone Analytics read.
set -euo pipefail

ZONE_ID="7aef3c4adc977c9f645472338b031450"
TOKEN_FILE="${HOME}/.secrets/cloudflare_api_token"
CATEGORY="Search Engine Optimization"
DAYS=7
RULE_ID=""
OUT_DIR=""
JSON_ONLY=0

while (($# > 0)); do
  case "$1" in
    --days)       DAYS="${2:-}";       shift 2 ;;
    --rule-id)    RULE_ID="${2:-}";    shift 2 ;;
    --out)        OUT_DIR="${2:-}";    shift 2 ;;
    --json)       JSON_ONLY=1;         shift ;;
    --token-file) TOKEN_FILE="${2:-}"; shift 2 ;;
    --zone-id)    ZONE_ID="${2:-}";    shift 2 ;;
    -h|--help)    sed -n '2,37p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

[[ "$DAYS" =~ ^[0-9]+$ && "$DAYS" -ge 1 ]] || { echo "--days must be a positive integer." >&2; exit 1; }

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -s "$TOKEN_FILE" ]]; then
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi
if [[ -z "$CF_TOKEN" ]]; then
  echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2
  exit 1
fi

command -v jq >/dev/null || { echo "jq is required." >&2; exit 1; }

# Minute precision, not %H:00:00Z. Flooring to the top of the hour silently discards up
# to 59 minutes of the most recent data -- which, right after a rule is created, can be
# its entire lifetime and makes a working rule look like it is matching nothing.
END="$(date -u +%Y-%m-%dT%H:%M:00Z)"
START="$(date -u -d "$DAYS days ago" +%Y-%m-%dT%H:%M:00Z)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

gql() {
  # gql <query-file> <variables-json> -> stdout JSON
  jq -n --rawfile q "$1" --argjson v "$2" '{query: $q, variables: $v}' \
    | curl -sS https://api.cloudflare.com/client/v4/graphql \
        -H "Authorization: Bearer $CF_TOKEN" \
        -H "Content-Type: application/json" \
        --data @-
}

cat >"$WORK/main.graphql" <<'GQL'
query($zone: String!, $start: Time!, $end: Time!, $cat: String!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      total: httpRequestsAdaptiveGroups(
        limit: 1,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: $cat}
      ) { count }

      status: httpRequestsAdaptiveGroups(
        limit: 100,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: $cat},
        orderBy: [count_DESC]
      ) { count dimensions { edgeResponseStatus } }

      byUa: httpRequestsAdaptiveGroups(
        limit: 500,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: $cat},
        orderBy: [count_DESC]
      ) {
        count
        dimensions { userAgent edgeResponseStatus clientASNDescription clientCountryName }
      }

      byPath: httpRequestsAdaptiveGroups(
        limit: 500,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: $cat},
        orderBy: [count_DESC]
      ) {
        count
        dimensions { clientRequestPath userAgent edgeResponseStatus }
      }

      census: httpRequestsAdaptiveGroups(
        limit: 100,
        filter: {datetime_geq: $start, datetime_lt: $end},
        orderBy: [count_DESC]
      ) { count dimensions { verifiedBotCategory } }

      monitoring: httpRequestsAdaptiveGroups(
        limit: 100,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: "Monitoring & Analytics"},
        orderBy: [count_DESC]
      ) { count dimensions { userAgent } }

      crawlers: httpRequestsAdaptiveGroups(
        limit: 100,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory: "Search Engine Crawler"},
        orderBy: [count_DESC]
      ) { count dimensions { userAgent } }
    }
  }
}
GQL

VARS="$(jq -n --arg z "$ZONE_ID" --arg s "$START" --arg e "$END" --arg c "$CATEGORY" \
  '{zone:$z, start:$s, end:$e, cat:$c}')"
gql "$WORK/main.graphql" "$VARS" >"$WORK/main.json"

if [[ "$(jq -r '.errors // empty | length' "$WORK/main.json")" != "" ]]; then
  echo "GraphQL errors:" >&2
  jq -r '.errors[] | "  " + .message' "$WORK/main.json" >&2
  exit 1
fi

# --- firewallEventsAdaptive, chunked: this plan caps each query at a 3-day span --------
: >"$WORK/fw.jsonl"
FW_STATUS="skipped (no --rule-id)"
if [[ -n "$RULE_ID" ]]; then
  cat >"$WORK/fw.graphql" <<'GQL'
query($zone: String!, $start: Time!, $end: Time!, $rule: String!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      firewallEventsAdaptive(
        limit: 1000,
        filter: {datetime_geq: $start, datetime_leq: $end, ruleId: $rule},
        orderBy: [datetime_DESC]
      ) {
        action datetime clientRequestPath clientAsn clientCountryName
        edgeResponseStatus userAgent ruleId
      }
    }
  }
}
GQL
  FW_STATUS="ok"
  chunk_start="$START"
  while [[ "$chunk_start" < "$END" ]]; do
    chunk_end="$(date -u -d "$chunk_start + 3 days" +%Y-%m-%dT%H:00:00Z)"
    [[ "$chunk_end" > "$END" ]] && chunk_end="$END"
    v="$(jq -n --arg z "$ZONE_ID" --arg s "$chunk_start" --arg e "$chunk_end" --arg r "$RULE_ID" \
      '{zone:$z, start:$s, end:$e, rule:$r}')"
    gql "$WORK/fw.graphql" "$v" >"$WORK/fw-chunk.json"
    if [[ "$(jq -r '.errors // empty | length' "$WORK/fw-chunk.json")" != "" ]]; then
      FW_STATUS="partial: $(jq -r '[.errors[].message] | join("; ")' "$WORK/fw-chunk.json")"
      break
    fi
    jq -c '.data.viewer.zones[0].firewallEventsAdaptive[]?' "$WORK/fw-chunk.json" >>"$WORK/fw.jsonl"
    chunk_start="$chunk_end"
  done
fi

# --- classification ---------------------------------------------------------------------
# CRITICAL: uptime monitoring. If these ever match the SEO category, roll back at once.
# WARN:     search engines, social preview, webhooks, security, accessibility. Any hit
#           blocks promotion to Managed Challenge until explained.
# ACCEPTED: the commercial SEO crawlers this observation is about. §8.6 records owner
#           confirmation (2026-07-28) that ILAS relies on none of Semrush, Ahrefs or
#           Siteimprove; MJ12bot/Majestic confirmation is still outstanding.
CRITICAL_RE='Better Stack|BetterUptime|Better Uptime|UptimeRobot|Pingdom|StatusCake'
WARN_RE='Googlebot|Google-Adwords|GoogleAssociationService|Google-InspectionTool|Storebot-Google|AdsBot-Google|bingbot|BingPreview|Baiduspider|DuckDuckBot|YandexBot|Applebot|facebookexternalhit|Twitterbot|Slack-ImgProxy|Slackbot|Iframely|LinkedInBot|Discordbot|WhatsApp|TelegramBot|SecurityScanner|CloudflareWebScanner|Qualys|Detectify'
ACCEPTED_RE='SemrushBot|SiteAuditBot|AhrefsBot|MJ12bot|Siteimprove|Barkrowler|DataForSeo|SearchAtlas|SEOkicks|Screaming Frog|BLEXBot|serpstatbot'

jq -r '.data.viewer.zones[0].byUa[]? | [.count, .dimensions.userAgent] | @tsv' "$WORK/main.json" \
  >"$WORK/ua.tsv"

crit="$(grep -Ei "$CRITICAL_RE" "$WORK/ua.tsv" || true)"
warn="$(grep -Ei "$WARN_RE" "$WORK/ua.tsv" || true)"

EXIT=0
[[ -n "$crit" || -n "$warn" ]] && EXIT=2

# --- output --------------------------------------------------------------------------
emit_json() {
  jq -n \
    --arg zone "$ZONE_ID" --arg cat "$CATEGORY" \
    --arg start "$START" --arg end "$END" --argjson days "$DAYS" \
    --arg rule "$RULE_ID" --arg fw_status "$FW_STATUS" --argjson exit "$EXIT" \
    --slurpfile main "$WORK/main.json" \
    --rawfile crit_raw <(printf '%s' "$crit") \
    --rawfile warn_raw <(printf '%s' "$warn") \
    --slurpfile fw <(jq -s '.' "$WORK/fw.jsonl") \
    '{
      zone: $zone, category: $cat, window: {start: $start, end: $end, days: $days},
      rule_id: (if $rule == "" then null else $rule end),
      firewall_events_status: $fw_status,
      status: (if $exit == 0 then "clean" else "review_required" end),
      total: ($main[0].data.viewer.zones[0].total[0].count // 0),
      by_status: $main[0].data.viewer.zones[0].status,
      by_user_agent: $main[0].data.viewer.zones[0].byUa,
      by_path: $main[0].data.viewer.zones[0].byPath,
      category_census: $main[0].data.viewer.zones[0].census,
      monitoring_category: $main[0].data.viewer.zones[0].monitoring,
      crawler_category: $main[0].data.viewer.zones[0].crawlers,
      tripwire: {
        critical: ($crit_raw | select(length > 0) // "" | split("\n") | map(select(length > 0))),
        warn:     ($warn_raw | select(length > 0) // "" | split("\n") | map(select(length > 0)))
      },
      firewall_events: ($fw[0] // [])
    }'
}

if [[ -n "$OUT_DIR" ]]; then
  mkdir -p "$OUT_DIR"
  emit_json >"$OUT_DIR/seo-bot-observation.json"
fi

if ((JSON_ONLY)); then
  emit_json
  exit "$EXIT"
fi

TOTAL="$(jq -r '.data.viewer.zones[0].total[0].count // 0' "$WORK/main.json")"

echo "=========================================================================="
echo " SEO verified-bot observation -- $CATEGORY"
echo " zone $ZONE_ID"
echo " window $START -> $END  (${DAYS}d)"
[[ -n "$RULE_ID" ]] && echo " rule   $RULE_ID"
echo "=========================================================================="
echo
printf 'total requests in category: %s\n' "$TOTAL"
echo
echo "--- status-code distribution ---"
jq -r '.data.viewer.zones[0].status[]? | "  \(.count | tostring | (" " * (8 - length)) + .)  \(.dimensions.edgeResponseStatus)"' "$WORK/main.json"
echo
echo "--- matched user agents (count / status / country / ASN / UA) ---"
# Row caps are applied inside jq, not with `head`: truncating the pipe makes jq take
# SIGPIPE and, under `set -o pipefail`, kills the script at exit 141 mid-report.
jq -r '[.data.viewer.zones[0].byUa[]?][0:60][] |
  "  \(.count | tostring | (" " * (7 - length)) + .)  \(.dimensions.edgeResponseStatus)  \(.dimensions.clientCountryName)  \(.dimensions.clientASNDescription)  \(.dimensions.userAgent[0:110])"' \
  "$WORK/main.json"
echo
echo "--- top paths (count / status / path / UA) ---"
jq -r '[.data.viewer.zones[0].byPath[]?][0:40][] |
  "  \(.count | tostring | (" " * (7 - length)) + .)  \(.dimensions.edgeResponseStatus)  \(.dimensions.clientRequestPath[0:70])  \(.dimensions.userAgent[0:60])"' \
  "$WORK/main.json"
echo
echo "--- protected-traffic tripwire ---"
if [[ -n "$crit" ]]; then
  echo "  CRITICAL -- uptime monitoring matched the SEO category. ROLL BACK NOW."
  printf '%s\n' "$crit" | sed 's/^/    /'
else
  echo "  ok  no Better Stack / UptimeRobot / Pingdom / StatusCake in category"
fi
if [[ -n "$warn" ]]; then
  echo "  WARN -- search-engine / social-preview / security / webhook traffic in category:"
  printf '%s\n' "$warn" | sed 's/^/    /'
  echo "         Promotion to Managed Challenge is blocked until each line is explained."
else
  echo "  ok  no search-engine / social-preview / security / webhook UA in category"
fi
echo
echo "--- accepted (the crawlers this observation is about) ---"
accepted="$(grep -Ei "$ACCEPTED_RE" "$WORK/ua.tsv" || true)"
if [[ -n "$accepted" ]]; then
  printf '%s\n' "$accepted" | awk -F'\t' 'NR<=20 {print "    " $1 "  " substr($2,1,100)}'
else
  echo "    none"
fi
echo
echo "--- cross-category integrity ---"
mon_ok=$(jq -r '[.data.viewer.zones[0].monitoring[]?.dimensions.userAgent] | map(select(test("Better Stack|UptimeRobot"; "i"))) | length' "$WORK/main.json")
craw_ok=$(jq -r '[.data.viewer.zones[0].crawlers[]?.dimensions.userAgent] | map(select(test("Googlebot|bingbot|Baiduspider|DuckDuckBot"; "i"))) | length' "$WORK/main.json")
if [[ "$mon_ok" -gt 0 ]]; then
  echo "  ok  Better Stack / UptimeRobot still classified Monitoring & Analytics ($mon_ok UA rows)"
else
  echo "  FAIL  no Better Stack / UptimeRobot under Monitoring & Analytics -- recategorised?"
  EXIT=2
fi
if [[ "$craw_ok" -gt 0 ]]; then
  echo "  ok  Googlebot / bingbot / Baiduspider / DuckDuckBot still Search Engine Crawler ($craw_ok UA rows)"
else
  echo "  FAIL  major search engines missing from Search Engine Crawler -- recategorised?"
  EXIT=2
fi
echo
echo "--- full category census ---"
jq -r '.data.viewer.zones[0].census[]? |
  "  \(.count | tostring | (" " * (8 - length)) + .)  \(if .dimensions.verifiedBotCategory == "" then "(not a verified bot)" else .dimensions.verifiedBotCategory end)"' \
  "$WORK/main.json"
echo
echo "--- rule attribution (firewallEventsAdaptive, 3-day chunks) ---"
echo "  status: $FW_STATUS"
if [[ -s "$WORK/fw.jsonl" ]]; then
  echo "  events: $(wc -l <"$WORK/fw.jsonl")"
  jq -r -s 'group_by(.action) | map("    \(.[0].action): \(length)") | .[]' "$WORK/fw.jsonl"
elif [[ -n "$RULE_ID" ]]; then
  echo "  events: 0 (note: firewallEventsAdaptive retention is short; absence here is not"
  echo "            evidence of absence -- the category dimension above is authoritative)"
fi
echo
[[ -n "$OUT_DIR" ]] && echo "json written: $OUT_DIR/seo-bot-observation.json"
echo "status=$( ((EXIT==0)) && echo clean || echo review_required )"
exit "$EXIT"
