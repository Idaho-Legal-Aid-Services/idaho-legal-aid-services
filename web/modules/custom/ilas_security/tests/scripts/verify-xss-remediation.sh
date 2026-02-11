#!/bin/bash
# Verify XSS remediation for findings H-3, M-9, M-11.
# Run from project root: bash web/modules/custom/ilas_security/tests/scripts/verify-xss-remediation.sh

set -euo pipefail
ERRORS=0

echo "=== XSS Remediation Verification (H-3, M-9, M-11) ==="
echo ""

# H-3: smart-faq-enhanced.js must NOT use .html() for highlighting
FAQ_JS="web/themes/custom/b5subtheme/js/smart-faq-enhanced.js"
echo "[H-3] Checking $FAQ_JS ..."

if grep -n '\.html(' "$FAQ_JS" | grep -vP '^\d+:\s*//' | grep -vP '^\d+:\s*\*' >/dev/null 2>&1; then
  echo "  FAIL: .html() call found in $FAQ_JS:"
  grep -n '\.html(' "$FAQ_JS" | grep -vP '^\d+:\s*//' | grep -vP '^\d+:\s*\*'
  ERRORS=$((ERRORS + 1))
else
  echo "  PASS: No .html() calls found"
fi

if grep -qP 'new RegExp\(`\(' "$FAQ_JS" 2>/dev/null; then
  echo "  FAIL: Unsanitized RegExp interpolation found in $FAQ_JS"
  ERRORS=$((ERRORS + 1))
else
  echo "  PASS: No unsanitized RegExp interpolation"
fi

if grep -q 'escapeRegExp' "$FAQ_JS"; then
  echo "  PASS: escapeRegExp helper found"
else
  echo "  FAIL: escapeRegExp helper not found"
  ERRORS=$((ERRORS + 1))
fi

if grep -q 'TreeWalker\|createTreeWalker' "$FAQ_JS"; then
  echo "  PASS: TreeWalker-based highlighting found"
else
  echo "  FAIL: TreeWalker not found — highlighting may still use .html()"
  ERRORS=$((ERRORS + 1))
fi

echo ""

# M-9a: premium-application.js draft save messages
PREM_JS="web/themes/custom/b5subtheme/js/premium-application.js"
echo "[M-9] Checking $PREM_JS (draft save messages) ..."

# Check lines 1330-1350 for .html() with response.message
if sed -n '1330,1350p' "$PREM_JS" | grep -q '\.html.*response\.message\|\.html.*msg'; then
  echo "  FAIL: .html() with server response found in draft save handler"
  ERRORS=$((ERRORS + 1))
else
  echo "  PASS: No .html() with server responses in draft save handler"
fi

if sed -n '1330,1350p' "$PREM_JS" | grep -q 'createTextNode'; then
  echo "  PASS: Uses createTextNode for server messages"
else
  echo "  WARN: createTextNode not found in draft save handler — verify manually"
fi

echo ""

# M-9b: donation-inquiry.js showSubmissionMessage + renderSuccess
DON_JS="web/themes/custom/b5subtheme/js/donation-inquiry.js"
echo "[M-9] Checking $DON_JS (showSubmissionMessage, renderSuccess) ..."

if grep -n 'showSubmissionMessage' "$DON_JS" | head -1 | grep -q 'function'; then
  # Check that showSubmissionMessage does not use template literals with ${message}
  # Get the function body (next 20 lines after definition)
  LINE=$(grep -n 'function showSubmissionMessage' "$DON_JS" | head -1 | cut -d: -f1)
  if sed -n "${LINE},$((LINE+20))p" "$DON_JS" | grep -q '\${message}'; then
    echo "  FAIL: showSubmissionMessage uses template literal with \${message}"
    ERRORS=$((ERRORS + 1))
  else
    echo "  PASS: showSubmissionMessage does not use unsafe template interpolation"
  fi
fi

if grep -n 'function renderSuccess' "$DON_JS" | head -1 | grep -q 'function'; then
  LINE=$(grep -n 'function renderSuccess' "$DON_JS" | head -1 | cut -d: -f1)
  if sed -n "${LINE},$((LINE+20))p" "$DON_JS" | grep -q '\${message'; then
    echo "  FAIL: renderSuccess uses template literal with \${message}"
    ERRORS=$((ERRORS + 1))
  else
    echo "  PASS: renderSuccess does not use unsafe template interpolation"
  fi
fi

echo ""

# M-11: ilas_hotspot.module must use Html::escape / Xss::filterAdmin
HOTSPOT_MOD="web/modules/custom/ilas_hotspot/ilas_hotspot.module"
echo "[M-11] Checking $HOTSPOT_MOD ..."

if grep -q 'Html::escape' "$HOTSPOT_MOD"; then
  echo "  PASS: Html::escape() used for output escaping"
else
  echo "  FAIL: Html::escape() not found"
  ERRORS=$((ERRORS + 1))
fi

if grep -q 'Xss::filterAdmin' "$HOTSPOT_MOD"; then
  echo "  PASS: Xss::filterAdmin() used for content sanitization"
else
  echo "  FAIL: Xss::filterAdmin() not found"
  ERRORS=$((ERRORS + 1))
fi

# Check that raw concatenation of $hotspot['icon'] and $hotspot['title'] is gone
if grep -P "'\. \\\$hotspot\['icon'\]" "$HOTSPOT_MOD" >/dev/null 2>&1; then
  echo "  FAIL: Raw \$hotspot['icon'] concatenation still present"
  ERRORS=$((ERRORS + 1))
else
  echo "  PASS: No raw \$hotspot['icon'] concatenation"
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
