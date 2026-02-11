#!/bin/bash
# Verify access control hardening for findings M-1, L-4, L-5, M-2, M-6.
# Run from project root: bash web/modules/custom/ilas_security/tests/scripts/verify-access-control.sh

set -euo pipefail
ERRORS=0

echo "=== Access Control Hardening Verification (M-1, L-4, L-5, M-2, M-6) ==="
echo ""

# M-1: services.yml must disable super user
SERVICES="web/sites/default/services.yml"
echo "[M-1] Checking $SERVICES ..."

if [ ! -f "$SERVICES" ]; then
  echo "  FAIL: $SERVICES does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'security.enable_super_user: false' "$SERVICES"; then
    echo "  PASS: Super user access policy disabled"
  else
    echo "  FAIL: security.enable_super_user is not set to false"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-4: services.yml must reduce cookie_lifetime
echo ""
echo "[L-4] Checking session cookie lifetime ..."

if [ -f "$SERVICES" ]; then
  if grep -q 'cookie_lifetime: 604800' "$SERVICES"; then
    echo "  PASS: cookie_lifetime set to 604800 (7 days)"
  else
    echo "  FAIL: cookie_lifetime not set to 604800"
    ERRORS=$((ERRORS + 1))
  fi
  if grep -q 'gc_maxlifetime: 604800' "$SERVICES"; then
    echo "  PASS: gc_maxlifetime set to 604800"
  else
    echo "  FAIL: gc_maxlifetime not set to 604800"
    ERRORS=$((ERRORS + 1))
  fi
else
  echo "  FAIL: $SERVICES does not exist"
  ERRORS=$((ERRORS + 2))
fi

# L-5: services.yml must set cookie_samesite explicitly (since overriding
# session.storage.options replaces the entire parameter from core.services.yml)
echo ""
echo "[L-5] Checking cookie_samesite ..."

if [ -f "$SERVICES" ]; then
  if grep -q "cookie_samesite: Lax" "$SERVICES"; then
    echo "  PASS: cookie_samesite explicitly set to Lax"
  else
    echo "  FAIL: cookie_samesite not set to Lax in $SERVICES"
    ERRORS=$((ERRORS + 1))
  fi
else
  echo "  FAIL: $SERVICES does not exist"
  ERRORS=$((ERRORS + 1))
fi

# M-6: authenticated role must NOT have bypass honeypot or skip CAPTCHA
ROLE_FILE="config/user.role.authenticated.yml"
echo ""
echo "[M-6] Checking $ROLE_FILE ..."

if [ ! -f "$ROLE_FILE" ]; then
  echo "  FAIL: $ROLE_FILE does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'bypass honeypot protection' "$ROLE_FILE"; then
    echo "  FAIL: 'bypass honeypot protection' still in authenticated role"
    ERRORS=$((ERRORS + 1))
  else
    echo "  PASS: 'bypass honeypot protection' removed"
  fi

  if grep -q 'skip CAPTCHA' "$ROLE_FILE"; then
    echo "  FAIL: 'skip CAPTCHA' still in authenticated role"
    ERRORS=$((ERRORS + 1))
  else
    echo "  PASS: 'skip CAPTCHA' removed"
  fi
fi

# M-2: draft save must validate CSRF token
CONTROLLER="web/modules/custom/employment_application/src/Controller/EmploymentApplicationController.php"
DRAFT_JS="web/themes/custom/b5subtheme/js/premium-application.js"
echo ""
echo "[M-2] Checking CSRF validation in draft save ..."

if grep -q "csrfToken->validate.*employment_application_form" "$CONTROLLER" | head -2 | wc -l | grep -q '0'; then
  # Use a different approach
  :
fi

# Check controller has CSRF validation in saveDraft context
DRAFT_START=$(grep -n 'function saveDraft' "$CONTROLLER" | head -1 | cut -d: -f1)
if [ -n "$DRAFT_START" ]; then
  DRAFT_END=$((DRAFT_START + 30))
  if sed -n "${DRAFT_START},${DRAFT_END}p" "$CONTROLLER" | grep -q "csrfToken->validate"; then
    echo "  PASS: saveDraft() validates CSRF token"
  else
    echo "  FAIL: saveDraft() does not validate CSRF token"
    ERRORS=$((ERRORS + 1))
  fi
else
  echo "  FAIL: saveDraft() method not found"
  ERRORS=$((ERRORS + 1))
fi

# Check JS sends form_token in draft save request
DRAFT_JS_LINE=$(grep -n 'draft/save' "$DRAFT_JS" | head -1 | cut -d: -f1)
if [ -n "$DRAFT_JS_LINE" ]; then
  AJAX_END=$((DRAFT_JS_LINE + 10))
  if sed -n "${DRAFT_JS_LINE},${AJAX_END}p" "$DRAFT_JS" | grep -q 'form_token'; then
    echo "  PASS: JS sends form_token in draft save request"
  else
    echo "  FAIL: JS does not send form_token in draft save request"
    ERRORS=$((ERRORS + 1))
  fi
else
  echo "  FAIL: draft/save URL not found in JS"
  ERRORS=$((ERRORS + 1))
fi

echo ""
echo "=== Summary ==="
if [ "$ERRORS" -eq 0 ]; then
  echo "All checks passed."
  exit 0
else
  echo "$ERRORS check(s) failed."
  exit 1
fi
