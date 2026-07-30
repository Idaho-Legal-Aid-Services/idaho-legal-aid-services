#!/usr/bin/env bash
# Read-only Cloudflare edge cache sampler for idaholegalaid.org
#
# WHY: Cloudflare's WAF returns 403 to requests from the audit machine, so
# CF-Cache-Status cannot be measured from there. Run this from your normal
# network (home/office) and paste the whole output back.
#
# This script ONLY issues GET/HEAD requests. It changes nothing.
#
# Usage:  bash edge-cache-sample.sh 2>&1 | tee edge-sample.txt

set -uo pipefail

UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
HDRS='^HTTP/|^location:|^cf-ray:|^cf-cache-status:|^cache-control:|^age:|^set-cookie:|^vary:|^server:|^x-drupal-cache|^x-drupal-dynamic-cache|^x-pantheon|^x-served-by|^x-cache|^surrogate|^expires:|^cf-apo-via'

echo "edge-cache-sample.sh"
echo "date_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "egress_ip=$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null || echo unknown)"
echo

sample() {
  local label="$1" url="$2"
  echo "################ $label"
  echo "################ $url"
  for pass in 1 2; do
    echo "---- pass $pass ----"
    curl -sS -o /dev/null -D - --max-time 30 -A "$UA" \
      -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
      -H 'Accept-Language: en-US,en;q=0.9' \
      "$url" 2>&1 | grep -iE "$HDRS"
    sleep 2
  done
  echo
}

# --- Required by the audit brief ---
sample "1. Homepage"                 "https://idaholegalaid.org/"
sample "2. www (canonical check)"    "https://www.idaholegalaid.org/"
sample "3. Anonymous content page"   "https://idaholegalaid.org/legal-topics"
sample "4. Landing page"             "https://idaholegalaid.org/get-help"
sample "5. Search page"              "https://idaholegalaid.org/search?search_api_fulltext=divorce"
sample "6. Search + facet"           "https://idaholegalaid.org/search?search_api_fulltext=divorce&f%5B0%5D=topic%3A1"
sample "7. Form page (donate)"       "https://idaholegalaid.org/donate"
sample "8. Login page"               "https://idaholegalaid.org/user/login"
sample "9. Nonexistent URL"          "https://idaholegalaid.org/this-page-does-not-exist-xyz123"
sample "10. Scanner path"            "https://idaholegalaid.org/wp-login.php"
sample "11. Static asset"            "https://idaholegalaid.org/robots.txt"
sample "12. Sitemap"                 "https://idaholegalaid.org/sitemap.xml"
sample "13. Assistant bootstrap"     "https://idaholegalaid.org/assistant/api/session/bootstrap"
sample "14. Pantheon origin direct"  "https://live-idaho-legal-aid-services.pantheonsite.io/"

# --- Cookie-bearing request: does an SSESS cookie force a bypass? ---
echo "################ 15. Homepage WITH a fake session cookie (cache-bypass test)"
curl -sS -o /dev/null -D - --max-time 30 -A "$UA" \
  -H 'Cookie: SSESStestaudit=auditprobe000000000000000000000' \
  "https://idaholegalaid.org/" 2>&1 | grep -iE "$HDRS"
echo

# --- Does a query string fragment the cache? ---
echo "################ 16. Homepage with tracking query string"
curl -sS -o /dev/null -D - --max-time 30 -A "$UA" \
  "https://idaholegalaid.org/?utm_source=audit&utm_medium=probe" 2>&1 | grep -iE "$HDRS"
echo

echo "DONE"
