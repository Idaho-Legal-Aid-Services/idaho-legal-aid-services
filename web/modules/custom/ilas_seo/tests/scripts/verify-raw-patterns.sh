#!/usr/bin/env bash
#
# verify-raw-patterns.sh — CI guard against dangerous |raw patterns in templates.
#
# Searches b5subtheme templates for .value|raw patterns that bypass text format
# filters. Exits 1 if any dangerous patterns are found.
#
# Safe patterns that are EXCLUDED:
#   - json_encode|raw  (JSON serialization, not HTML injection)
#   - attributes|raw   (Drupal attribute objects)
#
# Usage:
#   bash web/modules/custom/ilas_seo/tests/scripts/verify-raw-patterns.sh

set -euo pipefail

THEME_DIR="web/themes/custom/b5subtheme/templates"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"
SEARCH_DIR="$PROJECT_ROOT/$THEME_DIR"

if [ ! -d "$SEARCH_DIR" ]; then
  echo "ERROR: Template directory not found: $SEARCH_DIR"
  exit 1
fi

echo "Scanning $THEME_DIR for dangerous |raw patterns..."
echo ""

# Pattern: field access .value|raw (with optional whitespace)
# This catches: paragraph.field_*.value|raw, item.field_*.value|raw, etc.
DANGEROUS=$(grep -rn '\.value\s*|raw' "$SEARCH_DIR" \
  --include='*.twig' \
  | grep -v 'json_encode|raw' \
  | grep -v 'attributes' \
  || true)

if [ -n "$DANGEROUS" ]; then
  echo "FAIL: Found dangerous .value|raw patterns:"
  echo ""
  echo "$DANGEROUS"
  echo ""
  echo "These patterns bypass Drupal's text format filters (XSS risk)."
  echo "Fix: Use {{ content.field_* }} or #processed_text render arrays."
  exit 1
fi

echo "PASS: No dangerous .value|raw patterns found in templates."
exit 0
