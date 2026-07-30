#!/usr/bin/env bash
#
# Evidence collector for validation report §8.7 (browser-spoofing automated traffic) and
# §8.8 (legitimate scripted and non-browser access) on idaholegalaid.org.
#
# Why this exists: §8.7 refuses to propose any Cloudflare rule until a named list of
# signals has been measured over >=7 days, and §8.8 asks for an inventory of legitimate
# scripted consumers before any skip rule is widened. This script is that measurement.
# It is read-only: every Cloudflare call is a GraphQL analytics query. It never touches a
# ruleset, a bot-management setting, or a zone setting.
#
# MEASUREMENT LIMITS ON THIS PLAN (probed 2026-07-29, zone is Business Website).
# The GraphQL schema advertises these dimensions but the zone is denied them with
# "zone ... does not have access to the field": botScore, botScoreBucketBy10, ja4,
# ja3Hash, jsDetectionPassed, botDetectionTags. They are Enterprise Bot Management.
# Consequences, which the report's decision standard depends on:
#   * "at least 95% low bot-score" -> substituted with botManagementDecision, a five-way
#     bucket (likely_human / likely_automated / automated / verified_bot / other). This is
#     Cloudflare's own classification of the same score, coarsened. Documented substitute.
#   * "inconsistent with a genuine browser JA4" -> NOT MEASURABLE. No substitute exists.
#     Any conclusion that would rest on JA4 must be reported as unmet, not worked around.
#   * JS execution -> substituted with /cdn-cgi/rum beacon counts and botScoreSrcName
#     js_fingerprinting, since jsDetectionPassed is denied.
#   * Cookie behaviour -> no cookie dimension exists in any accessible dataset.
#     cacheStatus dynamic/bypass is reported as a weak proxy and labelled as such.
#
# Retention and span, both measured against this zone rather than assumed:
#   httpRequestsAdaptiveGroups  max span 4w4d, so a 14-day window is one query. Primary.
#   httpRequestsAdaptive        raw per-request rows, available. Used for inter-arrival
#                               timing and navigation order, which the Groups dataset
#                               cannot express.
#   firewallEventsAdaptive      hard 3-day span cap on this plan, so it is chunked.
#
# Candidate populations are DERIVED, not hardcoded. A stale browser version is explicitly
# not treated as evidence: selection is on the automated-bucket share, request volume, and
# a browser-claiming user agent, and the report on each candidate carries every signal so
# a reader can disagree with the selection.
#
# Exit codes: 0 clean, 2 review required (tripwire hit or a candidate crossed threshold),
# 1 error.
#
# Usage:
#   scripts/observability/cloudflare-browser-spoofing-analysis.sh [--days 14]
#     [--min-requests N] [--auto-share PCT] [--ua-like PATTERN] [--out DIR] [--json]
#     [--token-file PATH] [--zone-id ID]
#
#   --min-requests  volume floor for a UA to be profiled as a candidate (default 5000)
#   --auto-share    automated+likely_automated share, percent, for candidate selection
#                   (default 95, matching the report's own decision standard)
#   --ua-like       profile this UA pattern as well, regardless of selection (repeatable)
#
# Needs CLOUDFLARE_API_TOKEN or --token-file; the token needs Zone Analytics read.
set -euo pipefail

ZONE_ID="7aef3c4adc977c9f645472338b031450"
TOKEN_FILE="${HOME}/.secrets/cloudflare_api_token"
DAYS=14
MIN_REQUESTS=5000
AUTO_SHARE=95
OUT_DIR=""
JSON_ONLY=0
EXTRA_UA=()

while (($# > 0)); do
  case "$1" in
    --days)         DAYS="${2:-}";         shift 2 ;;
    --min-requests) MIN_REQUESTS="${2:-}"; shift 2 ;;
    --auto-share)   AUTO_SHARE="${2:-}";   shift 2 ;;
    --ua-like)      EXTRA_UA+=("${2:-}");  shift 2 ;;
    --out)          OUT_DIR="${2:-}";      shift 2 ;;
    --json)         JSON_ONLY=1;           shift ;;
    --token-file)   TOKEN_FILE="${2:-}";   shift 2 ;;
    --zone-id)      ZONE_ID="${2:-}";      shift 2 ;;
    -h|--help)      sed -n '2,52p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

[[ "$DAYS" =~ ^[0-9]+$ && "$DAYS" -ge 1 && "$DAYS" -le 32 ]] \
  || { echo "--days must be 1..32 (zone max span is 4w4d)." >&2; exit 1; }
[[ "$MIN_REQUESTS" =~ ^[0-9]+$ ]] || { echo "--min-requests must be an integer." >&2; exit 1; }
[[ "$AUTO_SHARE" =~ ^[0-9]+$ && "$AUTO_SHARE" -le 100 ]] \
  || { echo "--auto-share must be 0..100." >&2; exit 1; }

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -s "$TOKEN_FILE" ]]; then
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi
if [[ -z "$CF_TOKEN" ]]; then
  echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2
  exit 1
fi

command -v jq >/dev/null || { echo "jq is required." >&2; exit 1; }
command -v curl >/dev/null || { echo "curl is required." >&2; exit 1; }

# Minute precision, not %H:00:00Z. Flooring to the top of the hour silently discards up
# to 59 minutes of the most recent data, which matters when a population is ramping.
END="$(date -u +%Y-%m-%dT%H:%M:00Z)"
START="$(date -u -d "$DAYS days ago" +%Y-%m-%dT%H:%M:00Z)"
# The raw dataset is queried over a shorter tail: per-request rows are capped at 10k, so a
# narrow window is a denser and more honest sample than a 14-day slice of the same 10k.
RAW_DAYS=3
((RAW_DAYS > DAYS)) && RAW_DAYS="$DAYS"
RAW_START="$(date -u -d "$RAW_DAYS days ago" +%Y-%m-%dT%H:%M:00Z)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

GQL_ERRORS=()

gql() {
  # gql <query-file> <variables-json> <label> -> stdout JSON. Records, but does not
  # abort on, GraphQL errors: a denied dimension is a finding to report, not a crash.
  local out
  out="$(jq -n --rawfile q "$1" --argjson v "$2" '{query: $q, variables: $v}' \
    | curl -sS --max-time 180 https://api.cloudflare.com/client/v4/graphql \
        -H "Authorization: Bearer $CF_TOKEN" \
        -H "Content-Type: application/json" \
        --data @-)"
  if [[ -z "$out" ]]; then
    GQL_ERRORS+=("$3: empty response")
    echo '{"data":null}'
    return 0
  fi
  # A curl timeout mid-body yields well-formed-looking but truncated JSON. Reject it here
  # rather than letting a partial object be read as a real result.
  if ! jq -e 'type == "object"' >/dev/null 2>&1 <<<"$out"; then
    GQL_ERRORS+=("$3: unparseable or truncated response (${#out} bytes)")
    echo '{"data":null}'
    return 0
  fi
  if jq -e '.errors' >/dev/null 2>&1 <<<"$out"; then
    GQL_ERRORS+=("$3: $(jq -r '[.errors[].message] | join("; ")' <<<"$out")")
  fi
  printf '%s' "$out"
}

# ---------------------------------------------------------------------------------------
# Phase A -- zone census. Establishes the denominator, the decision mix, the verified-bot
# categories, and the JS-execution and robots.txt baselines.
# ---------------------------------------------------------------------------------------
cat >"$WORK/census.graphql" <<'GQL'
query($zone: String!, $start: Time!, $end: Time!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      total: httpRequestsAdaptiveGroups(
        limit: 1, filter: {datetime_geq: $start, datetime_lt: $end}
      ) { count sum { visits } avg { sampleInterval } }

      decision: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { botManagementDecision } }

      scoreSrc: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { botScoreSrcName } }

      uaDecision: httpRequestsAdaptiveGroups(
        limit: 2000, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { userAgent botManagementDecision } }

      categories: httpRequestsAdaptiveGroups(
        limit: 50, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { verifiedBotCategory } }

      verifiedBots: httpRequestsAdaptiveGroups(
        limit: 300,
        filter: {datetime_geq: $start, datetime_lt: $end, verifiedBotCategory_neq: ""},
        orderBy: [count_DESC]
      ) { count dimensions { verifiedBotCategory userAgent edgeResponseStatus } }

      rum: httpRequestsAdaptiveGroups(
        limit: 10,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath: "/cdn-cgi/rum"},
        orderBy: [count_DESC]
      ) { count dimensions { edgeResponseStatus } }

      robots: httpRequestsAdaptiveGroups(
        limit: 50,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath: "/robots.txt"},
        orderBy: [count_DESC]
      ) { count dimensions { edgeResponseStatus botManagementDecision } }

      contentTypes: httpRequestsAdaptiveGroups(
        limit: 40, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { edgeResponseContentTypeName } }
    }
  }
}
GQL

VARS="$(jq -n --arg z "$ZONE_ID" --arg s "$START" --arg e "$END" '{zone:$z,start:$s,end:$e}')"
gql "$WORK/census.graphql" "$VARS" census >"$WORK/census.json"

if [[ "$(jq -r '.data.viewer.zones[0].total[0].count // "null"' "$WORK/census.json")" == "null" ]]; then
  echo "Census query returned no data. Errors:" >&2
  printf '  %s\n' "${GQL_ERRORS[@]:-none}" >&2
  exit 1
fi

# ---------------------------------------------------------------------------------------
# Candidate selection. Browser-claiming user agents only, over the volume floor, with an
# automated-bucket share at or above the threshold. Version staleness is NOT a criterion.
# ---------------------------------------------------------------------------------------
jq --argjson floor "$MIN_REQUESTS" --argjson share "$AUTO_SHARE" '
  [.data.viewer.zones[0].uaDecision[]]
  | group_by(.dimensions.userAgent)
  | map({
      ua: .[0].dimensions.userAgent,
      total: (map(.count) | add),
      buckets: (map({key: .dimensions.botManagementDecision, value: .count}) | from_entries)
    })
  | map(. + {
      automated: (((.buckets.automated // 0) + (.buckets.likely_automated // 0))),
      human: (.buckets.likely_human // 0),
      verified: (.buckets.verified_bot // 0)
    })
  | map(. + {auto_pct: (if .total > 0 then (.automated * 100 / .total) else 0 end)})
  # A browser-claiming UA: presents a browser token and does not self-identify as a bot.
  | map(. + {claims_browser: (
      (.ua | test("Mozilla/|AppleWebKit|Gecko/|Chrome/|Safari/|Firefox/|Edg/"))
      and (.ua | test("bot|crawler|spider|slurp|scanner|monitor|uptime|preview|curl|wget|python|axios|okhttp|java/|go-http|libwww|feedfetcher|validator"; "i") | not)
    )})
  | {
      candidates: (map(select(.claims_browser and .total >= $floor and .auto_pct >= $share))
                   | sort_by(-.total)),
      ambiguous:  (map(select(.claims_browser and .total >= $floor and .auto_pct < $share))
                   | sort_by(-.total)),
      self_identified: (map(select((.claims_browser | not) and .total >= $floor))
                   | sort_by(-.total))
    }
' "$WORK/census.json" >"$WORK/selection.json"

# Extra --ua-like patterns are profiled whether or not they were selected, so a reviewer
# can force a population into the report to see it cleared rather than merely absent.
: >"$WORK/targets.tsv"
jq -r '.candidates[] | [.ua, "selected"] | @tsv' "$WORK/selection.json" >>"$WORK/targets.tsv"
for pat in "${EXTRA_UA[@]:-}"; do
  [[ -z "$pat" ]] && continue
  printf '%s\t%s\n' "$pat" "requested" >>"$WORK/targets.tsv"
done

# ---------------------------------------------------------------------------------------
# Phase B -- per-candidate signal battery. One query per candidate, aliased sub-queries.
# `like` matching is used so a --ua-like pattern and an exact UA share one code path;
# an exact UA string contains no % or _ wildcards, so it matches only itself.
# ---------------------------------------------------------------------------------------
cat >"$WORK/profile.graphql" <<'GQL'
query($zone: String!, $start: Time!, $end: Time!, $rawStart: Time!, $ua: String!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      total: httpRequestsAdaptiveGroups(
        limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua}
      ) { count sum { visits } avg { sampleInterval } }

      decision: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { botManagementDecision } }

      scoreSrc: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { botScoreSrcName } }

      asn: httpRequestsAdaptiveGroups(
        limit: 200, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { clientASNDescription clientCountryName botManagementDecision } }

      country: httpRequestsAdaptiveGroups(
        limit: 100, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { clientCountryName } }

      ua: httpRequestsAdaptiveGroups(
        limit: 50, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { userAgent userAgentBrowser userAgentOS clientDeviceType } }

      status: httpRequestsAdaptiveGroups(
        limit: 60, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { edgeResponseStatus securityAction securitySource } }

      paths: httpRequestsAdaptiveGroups(
        limit: 500, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath } }

      contentTypes: httpRequestsAdaptiveGroups(
        limit: 40, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { edgeResponseContentTypeName } }

      daily: httpRequestsAdaptiveGroups(
        limit: 40, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [date_ASC]
      ) { count dimensions { date } }

      hourly: httpRequestsAdaptiveGroups(
        limit: 800, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [datetimeHour_ASC]
      ) { count dimensions { datetimeHour } }

      cache: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { cacheStatus } }

      query: httpRequestsAdaptiveGroups(
        limit: 60, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestQueryParameterNames } }

      referer: httpRequestsAdaptiveGroups(
        limit: 40, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { clientRefererHost } }

      robots: httpRequestsAdaptiveGroups(
        limit: 5,
        filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua,
                 clientRequestPath: "/robots.txt"}
      ) { count }

      rum: httpRequestsAdaptiveGroups(
        limit: 5,
        filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua,
                 clientRequestPath: "/cdn-cgi/rum"}
      ) { count }

      categories: httpRequestsAdaptiveGroups(
        limit: 20, filter: {datetime_geq: $start, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [count_DESC]
      ) { count dimensions { verifiedBotCategory } }

      # Raw rows, short window: inter-arrival timing and navigation order, neither of
      # which the Groups dataset can express.
      raw: httpRequestsAdaptive(
        limit: 10000,
        filter: {datetime_geq: $rawStart, datetime_lt: $end, userAgent_like: $ua},
        orderBy: [datetime_ASC]
      ) { datetime clientIP clientRequestPath clientRequestReferer edgeResponseStatus }
    }
  }
}
GQL

: >"$WORK/profiles.jsonl"
while IFS=$'\t' read -r ua origin; do
  [[ -z "$ua" ]] && continue
  v="$(jq -n --arg z "$ZONE_ID" --arg s "$START" --arg e "$END" --arg r "$RAW_START" --arg u "$ua" \
    '{zone:$z,start:$s,end:$e,rawStart:$r,ua:$u}')"
  gql "$WORK/profile.graphql" "$v" "profile[${ua:0:40}]" >"$WORK/p.json"

  # Derived signals, computed here rather than in the report so the numbers in the
  # document and the numbers in the JSON cannot drift apart.
  # -c is load-bearing: profiles.jsonl is read back a line at a time below, so a
  # pretty-printed profile would be read as JSON fragments.
  jq -c --arg ua "$ua" --arg origin "$origin" --argjson rawDays "$RAW_DAYS" '
    (.data.viewer.zones[0] // {}) as $z
    | ($z.total[0].count // 0) as $tot
    | ([$z.decision[]? | {key: .dimensions.botManagementDecision, value: .count}] | from_entries) as $dec
    | (($dec.automated // 0) + ($dec.likely_automated // 0)) as $auto
    | ([$z.contentTypes[]? | select(.dimensions.edgeResponseContentTypeName == "html") | .count] | add // 0) as $html
    | ([$z.contentTypes[]? | select(.dimensions.edgeResponseContentTypeName
         | . == "js" or . == "css" or . == "woff2" or . == "woff" or . == "svg"
           or . == "png" or . == "jpeg" or . == "webp" or . == "gif" or . == "ico")
         | .count] | add // 0) as $asset
    | ([$z.raw[]?] | group_by(.clientIP)
        | map({ip: .[0].clientIP, n: length,
               gaps: ([ . as $rows | range(1; ($rows | length))
                        | (($rows[.].datetime | fromdateiso8601) - ($rows[. - 1].datetime | fromdateiso8601)) ])})
        | map(select(.n >= 5))
        | map(. + {
            median_gap: (if (.gaps | length) > 0 then (.gaps | sort | .[(length / 2) | floor]) else null end),
            # Coefficient of variation of inter-arrival gaps. Human browsing is bursty
            # (high CV); a scheduled client is regular (low CV). Reported, not thresholded.
            cv: (if (.gaps | length) > 1 then
                   ((.gaps | add / length) as $m
                    | if $m > 0 then (((.gaps | map(pow(. - $m; 2)) | add) / (.gaps | length) | sqrt) / $m)
                      else null end)
                 else null end)
          })
        | sort_by(-.n)) as $ips
    | {
        user_agent: $ua,
        selection_origin: $origin,
        requests: $tot,
        estimated_visits: ($z.total[0].sum.visits // null),
        avg_sample_interval: ($z.total[0].avg.sampleInterval // null),
        bot_decision: $dec,
        automated_share_pct: (if $tot > 0 then (($auto * 1000 / $tot | round) / 10) else null end),
        likely_human_share_pct: (if $tot > 0 then ((($dec.likely_human // 0) * 1000 / $tot | round) / 10) else null end),
        bot_score_source: ([$z.scoreSrc[]? | {key: .dimensions.botScoreSrcName, value: .count}] | from_entries),
        ua_variants: [$z.ua[]? | {ua: .dimensions.userAgent, browser: .dimensions.userAgentBrowser,
                                 os: .dimensions.userAgentOS, device: .dimensions.clientDeviceType, count: .count}],
        asn: [$z.asn[]? | {asn: .dimensions.clientASNDescription, country: .dimensions.clientCountryName,
                           decision: .dimensions.botManagementDecision, count: .count}],
        country: [$z.country[]? | {country: .dimensions.clientCountryName, count: .count}],
        status_action: [$z.status[]? | {status: .dimensions.edgeResponseStatus,
                                       action: .dimensions.securityAction,
                                       source: .dimensions.securitySource, count: .count}],
        paths: [$z.paths[]? | {path: .dimensions.clientRequestPath, count: .count}],
        path_diversity: ([$z.paths[]?] | length),
        content_types: [$z.contentTypes[]? | {type: .dimensions.edgeResponseContentTypeName, count: .count}],
        html_requests: $html,
        asset_requests: $asset,
        html_to_asset_ratio: (if $asset > 0 then (($html * 100 / $asset | round) / 100) else null end),
        daily: [$z.daily[]? | {date: .dimensions.date, count: .count}],
        days_present: ([$z.daily[]?] | length),
        hourly_buckets_present: ([$z.hourly[]?] | length),
        cache_status: [$z.cache[]? | {status: .dimensions.cacheStatus, count: .count}],
        query_parameter_sets: [$z.query[]? | {params: .dimensions.clientRequestQueryParameterNames, count: .count}],
        referer_hosts: [$z.referer[]? | {host: .dimensions.clientRefererHost, count: .count}],
        robots_txt_requests: ($z.robots[0].count // 0),
        rum_beacon_requests: ($z.rum[0].count // 0),
        verified_bot_categories: [$z.categories[]? | {category: .dimensions.verifiedBotCategory, count: .count}],
        timing: {
          raw_window_days: $rawDays,
          raw_rows: ([$z.raw[]?] | length),
          distinct_ips_ge5_requests: ($ips | length),
          top_ips: ($ips[0:15] | map({ip, requests: .n, median_gap_s: .median_gap, gap_cv: .cv}))
        },
        navigation: {
          entry_paths: ([$z.raw[]?] | group_by(.clientIP) | map(.[0].clientRequestPath)
                        | group_by(.) | map({path: .[0], sessions: length}) | sort_by(-.sessions) | .[0:10]),
          referer_present_pct: (([$z.raw[]?] | length) as $n
            | if $n > 0 then ((([$z.raw[]? | select((.clientRequestReferer // "") != "")] | length) * 1000 / $n | round) / 10) else null end)
        }
      }
  ' "$WORK/p.json" >>"$WORK/profiles.jsonl"
done <"$WORK/targets.tsv"

# ---------------------------------------------------------------------------------------
# Phase C -- §8.8 legitimate scripted and non-browser access inventory.
# ---------------------------------------------------------------------------------------
cat >"$WORK/scripted.graphql" <<'GQL'
query($zone: String!, $start: Time!, $end: Time!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      sitemap: httpRequestsAdaptiveGroups(
        limit: 300,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath_like: "%sitemap%"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus verifiedBotCategory userAgent } }

      feeds: httpRequestsAdaptiveGroups(
        limit: 300,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath_like: "%/feed%"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus userAgent } }

      structured: httpRequestsAdaptiveGroups(
        limit: 300,
        filter: {datetime_geq: $start, datetime_lt: $end,
                 edgeResponseContentTypeName_in: ["rss", "xml", "json", "atom"]},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseContentTypeName edgeResponseStatus } }

      pdfs: httpRequestsAdaptiveGroups(
        limit: 200,
        filter: {datetime_geq: $start, datetime_lt: $end, edgeResponseContentTypeName: "pdf"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus botManagementDecision } }

      wellKnown: httpRequestsAdaptiveGroups(
        limit: 60,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath_like: "/.well-known/%"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus } }

      integrations: httpRequestsAdaptiveGroups(
        limit: 200,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath_like: "/assistant/api/%"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus } }

      employment: httpRequestsAdaptiveGroups(
        limit: 200,
        filter: {datetime_geq: $start, datetime_lt: $end, clientRequestPath_like: "/employment-application/%"},
        orderBy: [count_DESC]
      ) { count dimensions { clientRequestPath edgeResponseStatus } }

      scripted: httpRequestsAdaptiveGroups(
        limit: 300, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [count_DESC]
      ) { count dimensions { userAgent edgeResponseStatus verifiedBotCategory } }
    }
  }
}
GQL

gql "$WORK/scripted.graphql" "$VARS" scripted >"$WORK/scripted.json"

# ---------------------------------------------------------------------------------------
# Phase D -- classification and tripwire.
#
# CRITICAL: uptime monitoring inside a candidate population, or missing from its own
#           verified-bot category. Either means the analysis is unsafe to act on.
# WARN:     search engines, social preview, accessibility, security scanners, webhooks,
#           translation and validator tooling inside a candidate population. Each line
#           must be explained before any enforcement is even discussed.
# ---------------------------------------------------------------------------------------
CRITICAL_RE='Better Stack|BetterUptime|Better Uptime|UptimeRobot|Pingdom|StatusCake'
WARN_RE='Googlebot|Google-Adwords|Google-InspectionTool|Storebot-Google|AdsBot-Google|GoogleOther|Feedfetcher|bingbot|BingPreview|Baiduspider|DuckDuckBot|YandexBot|Applebot|facebookexternalhit|Twitterbot|Slack-ImgProxy|Slackbot|Iframely|LinkedInBot|Discordbot|WhatsApp|TelegramBot|SecurityScanner|CloudflareWebScanner|Qualys|Detectify|Proofpoint|Barracuda|Mimecast|MSOffice|Microsoft Office|SkypeUriPreview|Bitdefender|Symantec|FortiGate|translate|Google-Read-Aloud|W3C_Validator|validator|NVDA|JAWS|Dragon|feedly|Inoreader|NewsBlur|Tiny Tiny RSS|NetNewsWire|Miniflux'

: >"$WORK/tripwire.tsv"
while IFS= read -r prof; do
  ua="$(jq -r '.user_agent' <<<"$prof")"
  # A candidate's own UA cannot be the tripwire; check the variants and the verified-bot
  # categories that landed inside the matched population.
  jq -r --arg ua "$ua" '
    (.ua_variants[]? | "variant\t" + $ua + "\t" + (.count|tostring) + "\t" + .ua),
    (.verified_bot_categories[]? | select(.category != "") | "category\t" + $ua + "\t" + (.count|tostring) + "\t" + .category)
  ' <<<"$prof" >>"$WORK/tripwire.tsv"
done <"$WORK/profiles.jsonl"

crit="$(grep -Ei "$CRITICAL_RE" "$WORK/tripwire.tsv" || true)"
warn="$(grep -Ei "$WARN_RE" "$WORK/tripwire.tsv" || true)"

# Cross-category integrity: Cloudflare recategorises verified bots without notice, and a
# silent recategorisation is the mechanism that would break the site's own monitoring.
mon_ok="$(jq -r '[.data.viewer.zones[0].verifiedBots[]?
  | select(.dimensions.verifiedBotCategory == "Monitoring & Analytics")
  | .dimensions.userAgent] | map(select(test("Better Stack|UptimeRobot"; "i"))) | length' "$WORK/census.json")"
craw_ok="$(jq -r '[.data.viewer.zones[0].verifiedBots[]?
  | select(.dimensions.verifiedBotCategory == "Search Engine Crawler")
  | .dimensions.userAgent] | map(select(test("Googlebot|bingbot|Baiduspider|DuckDuckBot"; "i"))) | length' "$WORK/census.json")"

EXIT=0
[[ -n "$crit" || -n "$warn" ]] && EXIT=2
[[ "$mon_ok" -eq 0 || "$craw_ok" -eq 0 ]] && EXIT=2
((${#GQL_ERRORS[@]} > 0)) && EXIT=2

# ---------------------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------------------
emit_json() {
  jq -n \
    --arg zone "$ZONE_ID" --arg start "$START" --arg end "$END" --argjson days "$DAYS" \
    --arg raw_start "$RAW_START" --argjson raw_days "$RAW_DAYS" \
    --argjson floor "$MIN_REQUESTS" --argjson share "$AUTO_SHARE" --argjson exit "$EXIT" \
    --argjson mon_ok "$mon_ok" --argjson craw_ok "$craw_ok" \
    --slurpfile census "$WORK/census.json" \
    --slurpfile selection "$WORK/selection.json" \
    --slurpfile scripted "$WORK/scripted.json" \
    --slurpfile profiles <(jq -s '.' "$WORK/profiles.jsonl") \
    --rawfile crit_raw <(printf '%s' "$crit") \
    --rawfile warn_raw <(printf '%s' "$warn") \
    --argjson gql_errors "$(printf '%s\n' "${GQL_ERRORS[@]:-}" | jq -R . | jq -s 'map(select(length > 0))')" \
    '{
      zone: $zone,
      window: {start: $start, end: $end, days: $days,
               raw_window: {start: $raw_start, days: $raw_days}},
      selection_criteria: {min_requests: $floor, automated_share_pct: $share,
                           note: "browser-claiming UA only; browser version staleness is not a criterion"},
      measurement_limits: {
        denied_dimensions: ["botScore", "botScoreBucketBy10", "ja4", "ja3Hash",
                            "jsDetectionPassed", "botDetectionTags"],
        substitutions: {
          bot_score: "botManagementDecision (5-bucket)",
          js_execution: "/cdn-cgi/rum request counts + botScoreSrcName js_fingerprinting",
          ja4: null,
          cookie_behaviour: "cacheStatus dynamic/bypass (weak proxy only)"
        }
      },
      status: (if $exit == 0 then "clean" else "review_required" end),
      gql_errors: $gql_errors,
      census: {
        total_requests: ($census[0].data.viewer.zones[0].total[0].count // 0),
        estimated_visits: ($census[0].data.viewer.zones[0].total[0].sum.visits // null),
        avg_sample_interval: ($census[0].data.viewer.zones[0].total[0].avg.sampleInterval // null),
        bot_decision: [$census[0].data.viewer.zones[0].decision[]? | {decision: .dimensions.botManagementDecision, count: .count}],
        bot_score_source: [$census[0].data.viewer.zones[0].scoreSrc[]? | {source: .dimensions.botScoreSrcName, count: .count}],
        verified_bot_categories: [$census[0].data.viewer.zones[0].categories[]? | {category: .dimensions.verifiedBotCategory, count: .count}],
        verified_bots: [$census[0].data.viewer.zones[0].verifiedBots[]? | {category: .dimensions.verifiedBotCategory, ua: .dimensions.userAgent, status: .dimensions.edgeResponseStatus, count: .count}],
        content_types: [$census[0].data.viewer.zones[0].contentTypes[]? | {type: .dimensions.edgeResponseContentTypeName, count: .count}],
        rum_beacon: [$census[0].data.viewer.zones[0].rum[]? | {status: .dimensions.edgeResponseStatus, count: .count}],
        robots_txt: [$census[0].data.viewer.zones[0].robots[]? | {status: .dimensions.edgeResponseStatus, decision: .dimensions.botManagementDecision, count: .count}]
      },
      selection: $selection[0],
      profiles: $profiles[0],
      scripted_access: {
        sitemap: [$scripted[0].data.viewer.zones[0].sitemap[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, category: .dimensions.verifiedBotCategory, ua: .dimensions.userAgent, count: .count}],
        feeds: [$scripted[0].data.viewer.zones[0].feeds[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, ua: .dimensions.userAgent, count: .count}],
        structured_responses: [$scripted[0].data.viewer.zones[0].structured[]? | {path: .dimensions.clientRequestPath, type: .dimensions.edgeResponseContentTypeName, status: .dimensions.edgeResponseStatus, count: .count}],
        pdfs: [$scripted[0].data.viewer.zones[0].pdfs[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, decision: .dimensions.botManagementDecision, count: .count}],
        well_known: [$scripted[0].data.viewer.zones[0].wellKnown[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, count: .count}],
        assistant_api: [$scripted[0].data.viewer.zones[0].integrations[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, count: .count}],
        employment_api: [$scripted[0].data.viewer.zones[0].employment[]? | {path: .dimensions.clientRequestPath, status: .dimensions.edgeResponseStatus, count: .count}],
        by_user_agent: [$scripted[0].data.viewer.zones[0].scripted[]? | {ua: .dimensions.userAgent, status: .dimensions.edgeResponseStatus, category: .dimensions.verifiedBotCategory, count: .count}]
      },
      tripwire: {
        critical: ($crit_raw | select(length > 0) // "" | split("\n") | map(select(length > 0))),
        warn:     ($warn_raw | select(length > 0) // "" | split("\n") | map(select(length > 0))),
        monitoring_category_intact: ($mon_ok > 0),
        crawler_category_intact: ($craw_ok > 0)
      }
    }'
}

if [[ -n "$OUT_DIR" ]]; then
  mkdir -p "$OUT_DIR"
  emit_json >"$OUT_DIR/browser-spoofing-analysis.json"
fi

if ((JSON_ONLY)); then
  emit_json
  exit "$EXIT"
fi

TOTAL="$(jq -r '.data.viewer.zones[0].total[0].count // 0' "$WORK/census.json")"

echo "=========================================================================="
echo " Browser-spoofing and scripted-access analysis (report §8.7 / §8.8)"
echo " zone   $ZONE_ID"
echo " window $START -> $END  (${DAYS}d)   raw sub-window ${RAW_DAYS}d"
echo "=========================================================================="
echo
echo "--- measurement limits on this plan ---"
echo "  DENIED (Enterprise-only): botScore, botScoreBucketBy10, ja4, ja3Hash,"
echo "                            jsDetectionPassed, botDetectionTags"
echo "  bot score      -> substituted with botManagementDecision (5-bucket)"
echo "  JS execution   -> substituted with /cdn-cgi/rum counts + botScoreSrcName"
echo "  JA4            -> NO SUBSTITUTE. The report's JA4 gate cannot be evaluated."
echo "  cookies        -> no dimension exists; cacheStatus is a weak proxy only"
echo
printf 'total requests in window: %s\n' "$TOTAL"
echo
echo "--- zone bot-decision mix ---"
jq -r '.data.viewer.zones[0].decision[]? |
  "  \(.count | tostring | (" " * (9 - length)) + .)  \(.dimensions.botManagementDecision)"' "$WORK/census.json"
echo
echo "--- JS-execution baseline ---"
jq -r '.data.viewer.zones[0].rum[]? | "  /cdn-cgi/rum  \(.dimensions.edgeResponseStatus)  \(.count)"' "$WORK/census.json"
echo
echo "--- candidate selection (>=${MIN_REQUESTS} req, >=${AUTO_SHARE}% automated buckets, browser-claiming UA) ---"
echo "    NOTE: browser-version staleness is deliberately NOT a selection criterion."
jq -r '[.candidates[]][0:20][] |
  "  \(.total | tostring | (" " * (8 - length)) + .)  auto=\((.auto_pct * 10 | round) / 10)%  human=\(.human)  \(.ua[0:95])"' \
  "$WORK/selection.json"
echo
echo "--- ambiguous: browser-claiming, over volume floor, BELOW the automated threshold ---"
jq -r '[.ambiguous[]][0:20][] |
  "  \(.total | tostring | (" " * (8 - length)) + .)  auto=\((.auto_pct * 10 | round) / 10)%  human=\(.human)  \(.ua[0:95])"' \
  "$WORK/selection.json"
echo

while IFS= read -r prof; do
  jq -r '
    "== candidate: " + (.user_agent[0:100]) + "   [" + .selection_origin + "]",
    "   requests \(.requests)   path-diversity \(.path_diversity)   days-present \(.days_present)   hourly-buckets \(.hourly_buckets_present)",
    "   bot decision:        " + ([.bot_decision | to_entries[] | "\(.key)=\(.value)"] | join(" ")),
    "   automated share:     \(.automated_share_pct)%   likely_human \(.likely_human_share_pct)%",
    "   score source:        " + ([.bot_score_source | to_entries[] | "\(.key)=\(.value)"] | join(" ")),
    "   html:asset ratio:    \(.html_to_asset_ratio // "n/a")   (html \(.html_requests) / assets \(.asset_requests))",
    "   robots.txt fetches:  \(.robots_txt_requests)      /cdn-cgi/rum beacons: \(.rum_beacon_requests)",
    "   referer present:     \(.navigation.referer_present_pct // "n/a")%   distinct IPs (>=5 req): \(.timing.distinct_ips_ge5_requests)",
    "   cache status:        " + ([.cache_status[] | "\(.status)=\(.count)"] | join(" ")),
    "   verified categories: " + (([.verified_bot_categories[] | select(.category != "") | "\(.category)=\(.count)"] | join(" ")) | if . == "" then "(none)" else . end),
    "   status / action:",
    ([.status_action[0:8][] | "     \(.count | tostring | (" " * (7 - length)) + .)  http=\(.status)  \(.action)/\(.source)"] | join("\n")),
    "   top ASNs:",
    ([.asn[0:10][] | "     \(.count | tostring | (" " * (7 - length)) + .)  \(.decision)  \(.country)  \(.asn)"] | join("\n")),
    "   top paths:",
    ([.paths[0:8][] | "     \(.count | tostring | (" " * (7 - length)) + .)  \(.path[0:70])"] | join("\n")),
    "   daily:               " + ([.daily[] | "\(.date[5:])=\(.count)"] | join(" ")),
    "   per-IP inter-arrival (median seconds / CV; low CV = scheduled, high = bursty):",
    (([.timing.top_ips[0:8][] | "     \(.ip)  n=\(.requests)  median=\(.median_gap_s // "n/a")s  cv=\((if .gap_cv == null then "n/a" else ((.gap_cv * 100 | round) / 100 | tostring) end))"] | join("\n")) | if . == "" then "     (no IP with >=5 raw rows in the raw sub-window)" else . end),
    ""
  ' <<<"$prof"
done <"$WORK/profiles.jsonl"

echo "--- §8.8 legitimate scripted access: /sitemap.xml ---"
jq -r '[.data.viewer.zones[0].sitemap[]? | select(.dimensions.clientRequestPath == "/sitemap.xml")]
  | group_by((.dimensions.edgeResponseStatus | tostring) + "|" + .dimensions.verifiedBotCategory)
  | map({
      k: (("http=" + (.[0].dimensions.edgeResponseStatus | tostring)) + "  "
          + (if .[0].dimensions.verifiedBotCategory == "" then "(unverified client)"
             else .[0].dimensions.verifiedBotCategory end)),
      n: (map(.count) | add)
    })
  | sort_by(-.n) | .[] | "  \(.n | tostring | (" " * (7 - length)) + .)  \(.k)"' "$WORK/scripted.json"
echo
echo "--- §8.8 RSS / feed endpoints (does anything consume them?) ---"
jq -r '[.data.viewer.zones[0].feeds[]?]
  | group_by(.dimensions.clientRequestPath)
  | map({p: .[0].dimensions.clientRequestPath, n: (map(.count) | add)})
  | sort_by(-.n) | .[0:20][] | "  \(.n | tostring | (" " * (7 - length)) + .)  \(.p[0:70])"' "$WORK/scripted.json"
echo
echo "--- §8.8 JSON / XML responses by path ---"
jq -r '[.data.viewer.zones[0].structured[]?]
  | group_by(.dimensions.clientRequestPath)
  | map({p: .[0].dimensions.clientRequestPath, t: .[0].dimensions.edgeResponseContentTypeName, n: (map(.count) | add)})
  | sort_by(-.n) | .[0:20][] | "  \(.n | tostring | (" " * (7 - length)) + .)  \(.t)  \(.p[0:65])"' "$WORK/scripted.json"
echo
echo "--- §8.8 public PDFs ---"
jq -r '[.data.viewer.zones[0].pdfs[]?] | group_by(.dimensions.edgeResponseStatus)
  | map({s: .[0].dimensions.edgeResponseStatus, n: (map(.count) | add)}) | sort_by(-.n)
  | .[] | "  \(.n | tostring | (" " * (7 - length)) + .)  http=\(.s)"' "$WORK/scripted.json"
echo
echo "--- §8.8 monitoring, preview, security, accessibility (must keep working) ---"
jq -r '[.data.viewer.zones[0].verifiedBots[]?]
  | group_by(.dimensions.verifiedBotCategory)
  | map({c: .[0].dimensions.verifiedBotCategory, n: (map(.count) | add)})
  | sort_by(-.n) | .[] | "  \(.n | tostring | (" " * (8 - length)) + .)  \(.c)"' "$WORK/census.json"
echo
echo "--- protected-traffic tripwire ---"
if [[ -n "$crit" ]]; then
  echo "  CRITICAL -- uptime monitoring appears inside a candidate population:"
  printf '%s\n' "$crit" | sed 's/^/    /'
else
  echo "  ok  no Better Stack / UptimeRobot / Pingdom / StatusCake inside any candidate"
fi
if [[ -n "$warn" ]]; then
  echo "  WARN -- protected traffic inside a candidate population:"
  printf '%s\n' "$warn" | sed 's/^/    /'
  echo "         Every line must be explained before enforcement is discussed."
else
  echo "  ok  no search-engine / preview / security / accessibility / RSS / translation UA"
  echo "      inside any candidate population"
fi
echo
echo "--- cross-category integrity ---"
if [[ "$mon_ok" -gt 0 ]]; then
  echo "  ok  Better Stack / UptimeRobot still classified Monitoring & Analytics ($mon_ok rows)"
else
  echo "  FAIL  no Better Stack / UptimeRobot under Monitoring & Analytics -- recategorised?"
fi
if [[ "$craw_ok" -gt 0 ]]; then
  echo "  ok  Googlebot / bingbot / Baiduspider / DuckDuckBot still Search Engine Crawler ($craw_ok rows)"
else
  echo "  FAIL  major search engines missing from Search Engine Crawler -- recategorised?"
fi
echo
if ((${#GQL_ERRORS[@]} > 0)); then
  echo "--- GraphQL errors (a denied dimension is a finding, not a crash) ---"
  printf '  %s\n' "${GQL_ERRORS[@]}"
  echo
fi
[[ -n "$OUT_DIR" ]] && echo "json written: $OUT_DIR/browser-spoofing-analysis.json"
echo "status=$( ((EXIT==0)) && echo clean || echo review_required )"
exit "$EXIT"
