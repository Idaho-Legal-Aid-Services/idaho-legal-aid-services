#!/usr/bin/env bash
#
# CMS-fingerprinting smoke test.
#
# Asserts:
#   - Drupal/CMS doc paths return 403 or 404 (NOT 200) at the edge.
#   - /robots.txt still returns 200.
#   - Homepage returns 200 and does not emit the Drupal generator meta tag.
#
# Response bodies are never logged (only status codes / boolean checks),
# so a regression cannot leak file contents through CI logs.
#
# Usage:
#   BASE_URL=https://dev-idaho-legal-aid-services.pantheonsite.io bash scripts/security/fingerprint-smoke.sh
#
# Exits non-zero on any failure.

set -u

BASE_URL="${BASE_URL:-}"
if [[ -z "${BASE_URL}" ]]; then
  echo "ERROR: BASE_URL is required (e.g. https://example.pantheonsite.io)" >&2
  exit 2
fi
BASE_URL="${BASE_URL%/}"

CURL_OPTS=(-sS -o /dev/null -L --max-redirs 0 -w "%{http_code}" --connect-timeout 10 --max-time 20)

fail=0
pass=0

# Paths that must NOT return 200.
BLOCKED_PATHS=(
  /core/CHANGELOG.txt
  /core/INSTALL.txt
  /core/INSTALL.mysql.txt
  /core/INSTALL.pgsql.txt
  /core/INSTALL.sqlite.txt
  /core/MAINTAINERS.txt
  /core/UPDATE.txt
  /core/USAGE.txt
  /core/COPYRIGHT.txt
  /core/LICENSE.txt
  /INSTALL.txt
  /CHANGELOG.txt
  /README.txt
  /web.config
  /composer.json
  /composer.lock
  /yarn.lock
  /package.json
  /sites/default/settings.php
  /sites/default/services.yml
)

echo "BASE_URL=${BASE_URL}"
echo "--- blocked-path checks (expect non-200) ---"
for path in "${BLOCKED_PATHS[@]}"; do
  code="$(curl "${CURL_OPTS[@]}" "${BASE_URL}${path}" || echo 000)"
  if [[ "${code}" == "200" ]]; then
    printf "FAIL  %s -> %s (expected non-200)\n" "${path}" "${code}"
    fail=$((fail + 1))
  else
    printf "ok    %s -> %s\n" "${path}" "${code}"
    pass=$((pass + 1))
  fi
done

echo "--- allow-list checks ---"

# robots.txt must remain reachable.
code="$(curl "${CURL_OPTS[@]}" "${BASE_URL}/robots.txt" || echo 000)"
if [[ "${code}" == "200" ]]; then
  printf "ok    /robots.txt -> %s\n" "${code}"
  pass=$((pass + 1))
else
  printf "FAIL  /robots.txt -> %s (expected 200)\n" "${code}"
  fail=$((fail + 1))
fi

# Homepage must render 200.
code="$(curl "${CURL_OPTS[@]}" "${BASE_URL}/" || echo 000)"
if [[ "${code}" == "200" ]]; then
  printf "ok    / -> %s\n" "${code}"
  pass=$((pass + 1))
else
  printf "FAIL  / -> %s (expected 200)\n" "${code}"
  fail=$((fail + 1))
fi

# Homepage must NOT advertise Drupal in <meta name="generator">.
# We grep for the pattern but never echo the response body.
if curl -sS --connect-timeout 10 --max-time 20 "${BASE_URL}/" \
   | grep -Eqi 'name="generator"[^>]*content="[^"]*Drupal'; then
  printf "FAIL  / emits Drupal generator meta tag\n"
  fail=$((fail + 1))
else
  printf "ok    / has no Drupal generator meta tag\n"
  pass=$((pass + 1))
fi

echo "---"
printf "passed=%d failed=%d\n" "${pass}" "${fail}"
exit $(( fail > 0 ? 1 : 0 ))
