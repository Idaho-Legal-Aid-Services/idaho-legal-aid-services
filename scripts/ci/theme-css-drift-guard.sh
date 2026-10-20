#!/usr/bin/env bash
# scripts/ci/theme-css-drift-guard.sh — fail when theme SCSS changed but the
# compiled CSS did not.
#
# The b5subtheme ships its compiled CSS in git (Pantheon deploys via Composer
# and never runs npm), so an SCSS edit that is not followed by `npm run build`
# is a silent no-op in production. Nothing else in CI notices; this does.
#
# Usage:
#   theme-css-drift-guard.sh <base> <head>     # git range base..head
#   theme-css-drift-guard.sh --staged          # index vs HEAD (pre-commit use)
#
# Exit 0 when no theme SCSS changed, or when css/style.css or css/critical.css
# changed alongside it. Exit 1 otherwise, printing the rebuild command.
#
# Bypass (comment-only SCSS edits, or a deliberate split commit):
#   THEME_CSS_GUARD_SKIP=1   — env var; the reason is printed and the caller
#                              records it in the gate summary.
# Pure git, no npm, well under a second.

set -euo pipefail

THEME_DIR="web/themes/custom/b5subtheme"
SCSS_GLOB="${THEME_DIR}/scss/"
CSS_OUTPUTS=("${THEME_DIR}/css/style.css" "${THEME_DIR}/css/critical.css")

usage() {
  sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
  exit 2
}

if [[ "${1:-}" == "--staged" ]]; then
  changed="$(git diff --cached --name-only)"
  range_label="staged changes"
elif [[ $# -eq 2 ]]; then
  git cat-file -e "${1}^{commit}" 2>/dev/null || { echo "theme-css-drift-guard: unknown base commit '$1'" >&2; exit 2; }
  git cat-file -e "${2}^{commit}" 2>/dev/null || { echo "theme-css-drift-guard: unknown head commit '$2'" >&2; exit 2; }
  changed="$(git diff --name-only "$1" "$2")"
  range_label="$1..$2"
else
  usage
fi

scss_changed="$(printf '%s\n' "$changed" | grep -E "^${SCSS_GLOB}" || true)"
if [[ -z "$scss_changed" ]]; then
  echo "theme-css-drift-guard: no theme SCSS changed in ${range_label}; nothing to check."
  exit 0
fi

css_changed=""
for out in "${CSS_OUTPUTS[@]}"; do
  if printf '%s\n' "$changed" | grep -qx "$out"; then
    css_changed="$out"
    break
  fi
done

if [[ -n "$css_changed" ]]; then
  echo "theme-css-drift-guard: SCSS changed and ${css_changed} was rebuilt in ${range_label}. OK."
  exit 0
fi

if [[ "${THEME_CSS_GUARD_SKIP:-}" == "1" ]]; then
  echo "theme-css-drift-guard: SCSS changed without a CSS rebuild in ${range_label} — BYPASSED via THEME_CSS_GUARD_SKIP=1 (comment-only edit or deliberate split)."
  printf '  %s\n' $scss_changed
  exit 0
fi

cat >&2 <<EOF
theme-css-drift-guard: FAIL — theme SCSS changed in ${range_label} but neither
${CSS_OUTPUTS[0]} nor ${CSS_OUTPUTS[1]} did.
The compiled CSS is what ships; without a rebuild this SCSS change is a no-op in production.

Changed SCSS:
$(printf '  %s\n' $scss_changed)

Rebuild and commit the output together with the SCSS (see ${THEME_DIR}/BUILD.md):
  cd ${THEME_DIR} && npm ci --omit=optional && npm run build
  git add ${THEME_DIR}/css/style.css ${THEME_DIR}/css/critical.css ${THEME_DIR}/mix-manifest.json

Comment-only SCSS edit? Re-run with THEME_CSS_GUARD_SKIP=1.
EOF
exit 1
