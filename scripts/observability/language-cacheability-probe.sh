#!/usr/bin/env bash
set -euo pipefail

# Probes anonymous cacheability headers across a fixed path matrix, for the
# browser-language-negotiation cacheability fix (see
# docs/pantheon-cloudflare-preimplementation-validation.md section 8.9B).
#
# Probes the Pantheon platform hostname, not idaholegalaid.org: the Cloudflare
# WAF returns 403 to scripted HTTP clients on the public hostname, so public
# probes yield no cache data.

SITE="idaho-legal-aid-services"
ENV=""
LABEL=""
BASE=""
ACCEPT_LANGUAGE="en-US,en;q=0.9"
PAUSE="0"

usage() {
  cat <<'EOF'
Usage: scripts/observability/language-cacheability-probe.sh --env ENV [options]

Records anonymous cacheability headers for a fixed path matrix and prints a
sanitized transcript on stdout. Redirect to a file to capture evidence.

Options:
  --env ENV            Pantheon environment: dev | test | live. Required
                       unless --base is given.
  --site NAME          Pantheon site name. Default: idaho-legal-aid-services
  --base URL           Probe an explicit base URL instead of deriving the
                       Pantheon platform hostname from --env.
  --label TEXT         Free-text label recorded in the transcript header,
                       e.g. "before-change" or "after-change pass 1".
  --accept-language V  Accept-Language header to send.
                       Default: en-US,en;q=0.9
  --pause SECONDS      Sleep before probing, to let a cache warm. Default: 0
  -h, --help           Show this help.

Every request is an anonymous GET with no cookies. Cookie values, bearer
tokens and cf-ray identifiers are redacted; Set-Cookie is reported by name
only so an unexpected anonymous session cookie is still visible.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env) ENV="${2:-}"; shift 2 ;;
    --env=*) ENV="${1#*=}"; shift ;;
    --site) SITE="${2:-}"; shift 2 ;;
    --site=*) SITE="${1#*=}"; shift ;;
    --base) BASE="${2:-}"; shift 2 ;;
    --base=*) BASE="${1#*=}"; shift ;;
    --label) LABEL="${2:-}"; shift 2 ;;
    --label=*) LABEL="${1#*=}"; shift ;;
    --accept-language) ACCEPT_LANGUAGE="${2:-}"; shift 2 ;;
    --accept-language=*) ACCEPT_LANGUAGE="${1#*=}"; shift ;;
    --pause) PAUSE="${2:-}"; shift 2 ;;
    --pause=*) PAUSE="${1#*=}"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown argument: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ -z "$BASE" ]]; then
  if [[ -z "$ENV" ]]; then
    printf 'Either --env or --base is required.\n\n' >&2
    usage >&2
    exit 2
  fi
  BASE="https://${ENV}-${SITE}.pantheonsite.io"
fi
BASE="${BASE%/}"

# The path matrix. Ordinary anonymous English interior pages first (these are
# the ones the fix is meant to make cacheable), then the language-prefixed
# paths that must stay working, then a nonexistent path in each shape.
PATHS=(
  "/"
  "/legal-help/housing"
  "/forms"
  "/donate"
  "/resources/probate"
  "/contact/offices/boise-office"
  "/es"
  "/es/resources/quiebra"
  "/this-page-does-not-exist-ilas-probe"
  "/es/esta-pagina-no-existe-ilas-probe"
)

INTERESTING='^(HTTP/|x-drupal-cache|x-drupal-dynamic-cache|cache-control|age|vary|content-language|location|surrogate-key|surrogate-control|set-cookie|x-served-by|x-cache|server):'

# Redacts anything that could carry a secret or identify the probing client.
sanitize() {
  sed -E \
    -e 's/^([Ss]et-[Cc]ookie: *[^=;]+)=[^;]*/\1=[REDACTED]/' \
    -e 's/^([Aa]uthorization|[Cc]ookie|[Xx]-[Cc]srf-[Tt]oken): *.*/\1: [REDACTED]/' \
    -e 's/^([Cc]f-[Rr]ay): *.*/\1: [REDACTED]/' \
    -e 's/\b([0-9]{1,3}\.[0-9]{1,3})\.[0-9]{1,3}\.[0-9]{1,3}\b/\1.x.x/g'
}

if [[ "$PAUSE" != "0" ]]; then
  sleep "$PAUSE"
fi

printf '# Language cacheability probe\n'
printf 'timestamp_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'base=%s\n' "$BASE"
printf 'accept_language=%s\n' "$ACCEPT_LANGUAGE"
[[ -n "$LABEL" ]] && printf 'label=%s\n' "$LABEL"
printf 'note=anonymous GET, no cookies sent; cookie values, tokens, cf-ray and client IP redacted\n'
printf '\n'

for path in "${PATHS[@]}"; do
  printf '$ curl -sSI -H "Accept-Language: %s" %s%s\n' "$ACCEPT_LANGUAGE" "$BASE" "$path"
  curl -sS -I \
    --max-time 30 \
    --cookie-jar /dev/null \
    -H "Accept-Language: ${ACCEPT_LANGUAGE}" \
    "${BASE}${path}" 2>&1 \
    | grep -iE "$INTERESTING" \
    | sanitize \
    || printf 'ERROR: request failed\n'
  printf '\n'
done

# The intended behaviour change: Accept-Language: es on an unprefixed URL must
# now stay English. Recorded here so before/after transcripts are comparable.
for path in "/" "/forms"; do
  printf '$ curl -sSI -H "Accept-Language: es-ES,es;q=0.9" %s%s\n' "$BASE" "$path"
  curl -sS -I \
    --max-time 30 \
    --cookie-jar /dev/null \
    -H "Accept-Language: es-ES,es;q=0.9" \
    "${BASE}${path}" 2>&1 \
    | grep -iE "$INTERESTING" \
    | sanitize \
    || printf 'ERROR: request failed\n'
  printf '\n'
done

# Language coverage: the other two prefixes must keep resolving.
for path in "/sw" "/nl"; do
  printf '$ curl -sSI %s%s\n' "$BASE" "$path"
  curl -sS -I \
    --max-time 30 \
    --cookie-jar /dev/null \
    -H "Accept-Language: ${ACCEPT_LANGUAGE}" \
    "${BASE}${path}" 2>&1 \
    | grep -iE "$INTERESTING" \
    | sanitize \
    || printf 'ERROR: request failed\n'
  printf '\n'
done
