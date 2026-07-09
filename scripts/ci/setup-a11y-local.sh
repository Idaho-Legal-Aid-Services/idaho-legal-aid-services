#!/usr/bin/env bash

# Bring up a Drupal site from the checked-out commit and seed it with the
# minimum content the a11y test suite needs. Used by the a11y-local-gate CI
# job; also reusable on a freshly-installed local DDEV environment.
#
# Defaults:
#   - composer install (always)
#   - theme build (always, unless SKIP_THEME_BUILD=1)
#   - drush si --existing-config: only if Drupal is not already installed
#   - seed content: only if drush si just ran, OR if FORCE_SEED=1
#
# Env vars:
#   FORCE_SEED=1           Always run the seed script (overwrites front page).
#   SKIP_THEME_BUILD=1     Skip the b5subtheme webpack build step.
#   SKIP_COMPOSER=1        Skip composer install (assume vendor/ is up to date).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

log() { printf '\n=== %s ===\n' "$*"; }

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev not found on PATH" >&2
  exit 1
fi

log "DDEV status"
ddev status >/dev/null 2>&1 || ddev start

if [[ "${SKIP_COMPOSER:-0}" != "1" ]]; then
  log "composer install"
  ddev composer install --no-interaction --no-progress --prefer-dist
fi

if [[ "${SKIP_THEME_BUILD:-0}" != "1" ]]; then
  log "build b5subtheme"
  # --omit=optional: the imagemin family (native binary downloads that flake
  # on GitHub rate limits) is optional and unused by the prod webpack build.
  # NOTE: no comments/backticks inside the quoted block — ddev exec re-evaluates
  # the string and executes backticks even in comment position.
  ddev exec --dir /var/www/html/web/themes/custom/b5subtheme bash -c '
    set -euo pipefail
    if [[ ! -d node_modules ]]; then
      npm ci --no-audit --no-fund --omit=optional
    fi
    npm run prod
  '
fi

run_seed=0
if ddev drush status --field=bootstrap 2>/dev/null | grep -qx 'Successful'; then
  log "Drupal already installed; skipping drush si"
  if [[ "${FORCE_SEED:-0}" == "1" ]]; then
    run_seed=1
  fi
else
  log "drush si --existing-config"
  ddev drush site:install --existing-config -y \
    --account-pass=admin \
    --account-name=admin \
    --site-name='ILAS A11Y CI'
  run_seed=1
fi

if [[ "$run_seed" == "1" ]]; then
  log "seed a11y content"
  ddev drush php:script scripts/ci/seed-a11y-content.php
  ddev drush cache:rebuild
else
  log "seed skipped (Drupal already installed; set FORCE_SEED=1 to override)"
fi

log "site URL"
ddev describe -j | python3 -c '
import json, sys
print(json.load(sys.stdin)["raw"]["primary_url"])
'
