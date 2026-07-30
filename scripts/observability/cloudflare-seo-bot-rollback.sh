#!/usr/bin/env bash
#
# Roll back the SEO verified-bot observation change (tracker item 9, validation §8.6/§10.2).
#
# Undoes both halves in the safe order:
#   1. restore the pre-change expression on skip rule 64fae5be (re-adding the
#      "Search Engine Optimization" category), THEN
#   2. delete the observation rule ilas_seo_category_observe.
# Restoring first means SEO-category bots are never, at any instant, without a skip --
# deleting first would briefly expose them to the managed WAF, rate limiter, Drupal
# Hardening and the CN/RU Geo-Challenge.
#
# The prior expression is read verbatim out of the pre-change export, never retyped:
#   docs/evidence/cf-seo-bot-observation/01-waf-custom-before.json  (ruleset version 14)
#
# Dry run is the DEFAULT. Pass --apply to actually write.
#
# Usage:
#   scripts/observability/cloudflare-seo-bot-rollback.sh                # show the plan
#   scripts/observability/cloudflare-seo-bot-rollback.sh --apply        # execute
#   scripts/observability/cloudflare-seo-bot-rollback.sh --verify       # compare live vs export
#
# Options: --apply --verify --export PATH --rule-id ID --token-file PATH --zone-id ID
#
# Needs CLOUDFLARE_API_TOKEN or --token-file with Zone WAF edit on idaholegalaid.org.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ZONE_ID="7aef3c4adc977c9f645472338b031450"
RULESET_ID="f887ac01edd44986aae31e7e6c05c8bb"
SKIP_RULE_ID="64fae5becbce484caf8c43fd58734a45"
OBSERVE_REF="ilas_seo_category_observe"
EXPORT="${REPO_ROOT}/docs/evidence/cf-seo-bot-observation/01-waf-custom-before.json"
TOKEN_FILE="${HOME}/.secrets/cloudflare_api_token"
RULE_ID=""
APPLY=0
VERIFY=0

while (($# > 0)); do
  case "$1" in
    --apply)      APPLY=1;             shift ;;
    --verify)     VERIFY=1;            shift ;;
    --export)     EXPORT="${2:-}";     shift 2 ;;
    --rule-id)    RULE_ID="${2:-}";    shift 2 ;;
    --token-file) TOKEN_FILE="${2:-}"; shift 2 ;;
    --zone-id)    ZONE_ID="${2:-}";    shift 2 ;;
    -h|--help)    sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

command -v jq >/dev/null || { echo "jq is required." >&2; exit 1; }
[[ -s "$EXPORT" ]] || { echo "pre-change export not found: $EXPORT" >&2; exit 1; }

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -s "$TOKEN_FILE" ]]; then
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi
[[ -n "$CF_TOKEN" ]] || { echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2; exit 1; }

BASE="https://api.cloudflare.com/client/v4/zones/${ZONE_ID}/rulesets/${RULESET_ID}"
api() { curl -sS -H "Authorization: Bearer $CF_TOKEN" -H 'Content-Type: application/json' "$@"; }

# --- the fields to restore, taken verbatim from the export ----------------------------
# Both expression and description are restored: the description was also edited by the
# change (it named the SEO category), so restoring only the expression would leave the
# rule describing a scope it no longer has.
PRIOR_EXPR="$(jq -r --arg id "$SKIP_RULE_ID" \
  '.result.rules[] | select(.id == $id) | .expression' "$EXPORT")"
PRIOR_DESC="$(jq -r --arg id "$SKIP_RULE_ID" \
  '.result.rules[] | select(.id == $id) | .description' "$EXPORT")"
[[ -n "$PRIOR_EXPR" && "$PRIOR_EXPR" != "null" ]] || {
  echo "could not read the prior expression for $SKIP_RULE_ID out of $EXPORT" >&2; exit 1; }
grep -q 'Search Engine Optimization' <<<"$PRIOR_EXPR" || {
  echo "sanity check failed: the export's expression does not contain the SEO category." >&2
  echo "  $PRIOR_EXPR" >&2
  exit 1; }

# --- live state -----------------------------------------------------------------------
api "$BASE" >/tmp/cf-rollback-live.$$.json
trap 'rm -f /tmp/cf-rollback-live.$$.json' EXIT
LIVE="/tmp/cf-rollback-live.$$.json"
[[ "$(jq -r .success "$LIVE")" == "true" ]] || { jq -c .errors "$LIVE" >&2; exit 1; }

LIVE_VERSION="$(jq -r .result.version "$LIVE")"
LIVE_EXPR="$(jq -r --arg id "$SKIP_RULE_ID" '.result.rules[] | select(.id == $id) | .expression' "$LIVE")"
if [[ -z "$RULE_ID" ]]; then
  RULE_ID="$(jq -r --arg r "$OBSERVE_REF" '.result.rules[] | select(.ref == $r) | .id' "$LIVE")"
fi

echo "zone            $ZONE_ID"
echo "ruleset         $RULESET_ID (live version $LIVE_VERSION, export version $(jq -r .result.version "$EXPORT"))"
echo "export          $EXPORT"
echo "observe rule    ${RULE_ID:-<not present>}"
echo
echo "skip rule $SKIP_RULE_ID expression:"
echo "  live    $LIVE_EXPR"
echo "  restore $PRIOR_EXPR"
echo

if ((VERIFY)); then
  rc=0
  if [[ "$LIVE_EXPR" == "$PRIOR_EXPR" ]]; then
    echo "ok    skip rule expression matches the pre-change export"
  else
    echo "diff  skip rule expression differs from the pre-change export"; rc=2
  fi
  LIVE_DESC="$(jq -r --arg id "$SKIP_RULE_ID" '.result.rules[] | select(.id == $id) | .description' "$LIVE")"
  if [[ "$LIVE_DESC" == "$PRIOR_DESC" ]]; then
    echo "ok    skip rule description matches the pre-change export"
  else
    echo "diff  skip rule description differs from the pre-change export"; rc=2
  fi
  if [[ -n "$RULE_ID" ]]; then
    echo "diff  observation rule $RULE_ID is still present"; rc=2
  else
    echo "ok    no observation rule present"
  fi
  echo "other rules vs export:"
  jq -r --arg r "$OBSERVE_REF" --arg s "$SKIP_RULE_ID" \
    '[.result.rules[] | select(.ref != $r and .id != $s) | {id, action, expression}]' "$LIVE" \
    >/tmp/cf-rb-live-others.$$.json
  jq -r --arg s "$SKIP_RULE_ID" \
    '[.result.rules[] | select(.id != $s) | {id, action, expression}]' "$EXPORT" \
    >/tmp/cf-rb-exp-others.$$.json
  if diff -q /tmp/cf-rb-live-others.$$.json /tmp/cf-rb-exp-others.$$.json >/dev/null; then
    echo "ok    rules 2-8 identical to export"
  else
    echo "diff  rules 2-8 differ from export:"; diff /tmp/cf-rb-exp-others.$$.json /tmp/cf-rb-live-others.$$.json || true; rc=2
  fi
  rm -f /tmp/cf-rb-live-others.$$.json /tmp/cf-rb-exp-others.$$.json
  exit "$rc"
fi

if ((!APPLY)); then
  echo "DRY RUN -- nothing written. Re-run with --apply to execute:"
  echo "  1. PATCH  ${BASE}/rules/${SKIP_RULE_ID}   (expression <- export)"
  [[ -n "$RULE_ID" ]] && echo "  2. DELETE ${BASE}/rules/${RULE_ID}"
  exit 0
fi

echo "step 1/2 -- restoring skip rule $SKIP_RULE_ID"
# Cloudflare's rule PATCH is not a partial update: omitting `action` fails with 20015
# ("the action is required to create or update a rule"). Send the live rule verbatim with
# only expression and description swapped back, so action/action_parameters/logging are
# preserved exactly rather than re-declared here.
PATCH_BODY="$(jq --arg id "$SKIP_RULE_ID" --arg e "$PRIOR_EXPR" --arg d "$PRIOR_DESC" \
  '.result.rules[] | select(.id == $id)
   | del(.id, .version, .last_updated, .categories)
   | .expression = $e | .description = $d' "$LIVE")"
[[ -n "$PATCH_BODY" ]] || { echo "skip rule $SKIP_RULE_ID not found live" >&2; exit 1; }
api -X PATCH "${BASE}/rules/${SKIP_RULE_ID}" --data "$PATCH_BODY" \
  | jq -c '{success, errors, version: .result.version}'

if [[ -n "$RULE_ID" ]]; then
  echo "step 2/2 -- deleting observation rule $RULE_ID"
  api -X DELETE "${BASE}/rules/${RULE_ID}" | jq -c '{success, errors, version: .result.version}'
else
  echo "step 2/2 -- skipped, no rule with ref $OBSERVE_REF is present"
fi

echo
echo "re-verifying..."
exec "$0" --verify --export "$EXPORT" --zone-id "$ZONE_ID" --token-file "$TOKEN_FILE"
