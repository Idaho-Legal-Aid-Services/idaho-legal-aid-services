#!/usr/bin/env bash
#
# Purge specific URLs from the Cloudflare cache.
#
# Why this exists: Cloudflare has no visibility into Drupal's cache tags and there is no purge
# integration in this codebase (§8.10). Since item 1 made responses cacheable sitewide, 404s on
# static-extension paths are cached at the edge for 24 h. That is fine for paths that stay
# missing — but when a missing file becomes available, or a redirect is added for a URL that is
# currently 404ing, the cached 404 keeps winning until it expires.
#
# The concrete case this was written for is tracker FU-16 / item 7: adding redirects for legacy
# `/sites/idaholegalaid.org/files/*.pdf` URLs. Those URLs 404 today and are edge-cached, so the
# redirect alone will not take effect for up to 24 h.
#
# Deliberately not general-purpose purge automation — at this volume a targeted, explicit purge
# at the moment of change is the right lever (§8.11 rejected broad edge policy; §8.10 is the
# proper fix for tag-driven invalidation).
#
# Usage:
#   scripts/observability/cloudflare-purge-urls.sh /path/one /path/two ...
#   scripts/observability/cloudflare-purge-urls.sh --file urls.txt
#   scripts/observability/cloudflare-purge-urls.sh --dry-run /favicon.ico
#
# Paths are expanded against https://idaholegalaid.org unless already absolute. Needs
# CLOUDFLARE_API_TOKEN or --token-file; the token needs the **Cache Purge** permission
# (granted 2026-07-29 — before that it returned `10000 Authentication error`).
#
# Cloudflare accepts at most 30 URLs per purge call; this script batches automatically.
set -euo pipefail

ZONE_ID="7aef3c4adc977c9f645472338b031450"
BASE_URL="https://idaholegalaid.org"
TOKEN_FILE="${HOME}/.secrets/cloudflare_api_token"
DRY_RUN=false
URLS=()

usage() { sed -n '2,27p' "$0"; }

while (($# > 0)); do
  case "$1" in
    --token-file) TOKEN_FILE="${2:-}"; shift 2 ;;
    --zone-id)    ZONE_ID="${2:-}";    shift 2 ;;
    --base)       BASE_URL="${2%/}";   shift 2 ;;
    --dry-run)    DRY_RUN=true;        shift ;;
    --file)
      [[ -s "${2:-}" ]] || { echo "URL file missing or empty: ${2:-}" >&2; exit 1; }
      while IFS= read -r line; do
        line="${line%%#*}"; line="$(echo "$line" | tr -d '[:space:]')"
        [[ -n "$line" ]] && URLS+=("$line")
      done < "$2"
      shift 2 ;;
    -h|--help) usage; exit 0 ;;
    -*) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    *)  URLS+=("$1"); shift ;;
  esac
done

if ((${#URLS[@]} == 0)); then
  echo "No URLs given." >&2
  usage
  exit 1
fi

# Expand bare paths to absolute URLs.
EXPANDED=()
for u in "${URLS[@]}"; do
  case "$u" in
    http://*|https://*) EXPANDED+=("$u") ;;
    /*)                 EXPANDED+=("${BASE_URL}${u}") ;;
    *)                  EXPANDED+=("${BASE_URL}/${u}") ;;
  esac
done

CF_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
if [[ -z "$CF_TOKEN" && -s "$TOKEN_FILE" ]]; then
  CF_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
fi
if [[ -z "$CF_TOKEN" && "$DRY_RUN" != "true" ]]; then
  echo "CLOUDFLARE_API_TOKEN or --token-file is required." >&2
  exit 1
fi

echo "Purging ${#EXPANDED[@]} URL(s) from zone ${ZONE_ID}:"
printf '  %s\n' "${EXPANDED[@]}"

if "$DRY_RUN"; then
  echo "(dry run — nothing sent)"
  exit 0
fi

# Cloudflare caps a single purge_cache call at 30 files.
batch=()
failed=0
flush_batch() {
  ((${#batch[@]})) || return 0
  local payload
  payload="$(printf '%s\n' "${batch[@]}" | python3 -c "
import json,sys
print(json.dumps({'files': [l.strip() for l in sys.stdin if l.strip()]}))
")"
  local out
  out="$(curl -s -X POST "https://api.cloudflare.com/client/v4/zones/${ZONE_ID}/purge_cache" \
    -H "Authorization: Bearer ${CF_TOKEN}" \
    -H "Content-Type: application/json" \
    --data "$payload")"
  if ! python3 -c "
import json,sys
d=json.loads(sys.argv[1])
if d.get('success'):
    print(f\"  purged {sys.argv[2]} URL(s)\")
else:
    print('  FAILED:', json.dumps(d.get('errors'))[:300]); sys.exit(1)
" "$out" "${#batch[@]}"; then
    failed=1
  fi
  batch=()
}

for u in "${EXPANDED[@]}"; do
  batch+=("$u")
  ((${#batch[@]} == 30)) && flush_batch
done
flush_batch

if ((failed)); then
  echo "One or more purge batches failed." >&2
  exit 1
fi

cat <<'NOTE'

Purge is asynchronous but usually effective within seconds. Verify with a real browser
User-Agent -- Cloudflare's bot rules 403 bare curl on static assets:

  curl -sI -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0' https://idaholegalaid.org/<path>

Expect `cf-cache-status: MISS` on the first request (re-fetched from origin), then HIT.
NOTE
