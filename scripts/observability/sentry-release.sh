#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
THEME_DIR="${ROOT_DIR}/web/themes/custom/b5subtheme"
THEME_DEPLOY_ROOT="themes/custom/b5subtheme"
SITE_NAME="${PANTHEON_SITE_NAME:-}"
TARGET_ENV="${PANTHEON_ENVIRONMENT:-}"
SENTRY_ORG_SLUG="${SENTRY_ORG_SLUG:-}"
SENTRY_PROJECT_SLUG_BROWSER="${SENTRY_PROJECT_SLUG_BROWSER:-}"
RELEASE_NAME="${SENTRY_RELEASE:-}"
UPLOAD_ROOT=""
STAGING_DIR=""

usage() {
  cat <<'EOF'
Usage: sentry-release.sh [--site <site>] [--env <env>] [--release <release>] [--org <org>] [--project <browser-project>]

Resolves the Pantheon deployment identifier when possible, then creates/finalizes
the matching Sentry release and uploads any source maps found in the custom
theme build output.
EOF
}

cleanup() {
  if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
    rm -rf "$STAGING_DIR"
  fi
}

trap cleanup EXIT

while [[ $# -gt 0 ]]; do
  case "$1" in
    --site)
      SITE_NAME="$2"
      shift 2
      ;;
    --env)
      TARGET_ENV="$2"
      shift 2
      ;;
    --release)
      RELEASE_NAME="$2"
      shift 2
      ;;
    --org)
      SENTRY_ORG_SLUG="$2"
      shift 2
      ;;
    --project)
      SENTRY_PROJECT_SLUG_BROWSER="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${SENTRY_AUTH_TOKEN:-}" ]]; then
  echo "SENTRY_AUTH_TOKEN is required." >&2
  exit 1
fi

if [[ -z "$SENTRY_ORG_SLUG" || -z "$SENTRY_PROJECT_SLUG_BROWSER" ]]; then
  echo "SENTRY_ORG_SLUG and SENTRY_PROJECT_SLUG_BROWSER are required." >&2
  exit 1
fi

if [[ -z "$RELEASE_NAME" && -n "$SITE_NAME" && -n "$TARGET_ENV" ]]; then
  RELEASE_NAME="$(terminus remote:drush "${SITE_NAME}.${TARGET_ENV}" -- php:eval 'echo getenv("PANTHEON_DEPLOYMENT_IDENTIFIER") ?: "";' 2>/dev/null | tr -d '\r')"
fi

if [[ -z "$RELEASE_NAME" ]]; then
  echo "Unable to determine a release name. Pass --release or provide Pantheon site/env." >&2
  exit 1
fi

STAGING_DIR="$(mktemp -d)"
UPLOAD_ROOT="${STAGING_DIR}/${THEME_DEPLOY_ROOT}"
mkdir -p "$UPLOAD_ROOT"

for asset_dir in css js; do
  if [[ -d "${THEME_DIR}/${asset_dir}" ]]; then
    mkdir -p "${UPLOAD_ROOT}/${asset_dir}"
    cp -R "${THEME_DIR}/${asset_dir}/." "${UPLOAD_ROOT}/${asset_dir}/"
  fi
done

if ! find "$UPLOAD_ROOT" -type f -name '*.map' -print -quit | grep -q .; then
  echo "No source maps found under deployable theme assets in ${UPLOAD_ROOT}; build the theme before uploading release ${RELEASE_NAME}." >&2
  exit 1
fi

RELEASES_CLI=(npm exec --yes @sentry/cli -- releases --auth-token "$SENTRY_AUTH_TOKEN" --org "$SENTRY_ORG_SLUG" --project "$SENTRY_PROJECT_SLUG_BROWSER")
SOURCEMAPS_CLI=(npm exec --yes @sentry/cli -- sourcemaps --auth-token "$SENTRY_AUTH_TOKEN" --org "$SENTRY_ORG_SLUG" --project "$SENTRY_PROJECT_SLUG_BROWSER" --release "$RELEASE_NAME")

"${RELEASES_CLI[@]}" new "$RELEASE_NAME" || true

if git -C "$ROOT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  "${RELEASES_CLI[@]}" set-commits "$RELEASE_NAME" --auto || true
fi

"${SOURCEMAPS_CLI[@]}" upload "$UPLOAD_ROOT" \
  --ext map \
  --ext js \
  --ext css \
  --url-prefix "~/${THEME_DEPLOY_ROOT}" \
  --strip-prefix "$UPLOAD_ROOT" \
  --wait

"${RELEASES_CLI[@]}" finalize "$RELEASE_NAME"

echo "Sentry release ${RELEASE_NAME} prepared for ${SENTRY_PROJECT_SLUG_BROWSER}."
