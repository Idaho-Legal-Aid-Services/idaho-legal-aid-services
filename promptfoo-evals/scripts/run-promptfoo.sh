#!/usr/bin/env bash
# ILAS Site Assistant — Promptfoo runner (Linux / macOS)
# Usage:
#   bash scripts/run-promptfoo.sh eval                          # run default evaluation
#   bash scripts/run-promptfoo.sh eval promptfooconfig.deep.yaml  # run with alternate config
#   bash scripts/run-promptfoo.sh view                          # open results viewer
#   bash scripts/run-promptfoo.sh                               # defaults to eval
set -euo pipefail

# ── Privacy / offline defaults ───────────────────────────────────────────────
export PROMPTFOO_DISABLE_TELEMETRY=1
export PROMPTFOO_DISABLE_UPDATE=1
export PROMPTFOO_DISABLE_REMOTE_GENERATION=true
export PROMPTFOO_DISABLE_SHARING=1
export PROMPTFOO_SELF_HOSTED=1

# ── Resolve paths ────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
EVALS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG="$EVALS_DIR/promptfooconfig.yaml"

# ── Optional config override (2nd arg) ──────────────────────────────────────
CONFIG_OVERRIDE="${2:-}"
if [ -n "$CONFIG_OVERRIDE" ]; then
  CONFIG="$EVALS_DIR/$CONFIG_OVERRIDE"
fi

# ── Project-local eval DB ─────────────────────────────────────────────────────
export PROMPTFOO_CONFIG_DIR="$EVALS_DIR/.promptfoo"

if [ ! -f "$CONFIG" ]; then
  echo "ERROR: Config not found at $CONFIG" >&2
  exit 1
fi

# ── Ensure output directory exists ───────────────────────────────────────────
mkdir -p "$EVALS_DIR/output"

# ── Run ──────────────────────────────────────────────────────────────────────
ACTION="${1:-eval}"

case "$ACTION" in
  eval)
    echo "Running Promptfoo evaluation..."
    OUTPUT_FILE="${PROMPTFOO_OUTPUT_FILE:-$EVALS_DIR/output/results.json}"
    npx promptfoo@latest eval --config "$CONFIG" --output "$OUTPUT_FILE"
    echo "Done. Results written to $OUTPUT_FILE"
    ;;
  view)
    PORT=${PROMPTFOO_PORT:-15500}
    MAX_PORT=$((PORT + 10))
    while ss -ltn 2>/dev/null | grep -q ":${PORT} " && [ "$PORT" -le "$MAX_PORT" ]; do
      echo "Port $PORT is in use, trying next..."
      PORT=$((PORT + 1))
    done
    if [ "$PORT" -gt "$MAX_PORT" ]; then
      echo "ERROR: No free port in range ${PROMPTFOO_PORT:-15500}-$MAX_PORT" >&2
      exit 1
    fi
    echo ""
    echo "Viewer running at http://localhost:${PORT}"
    echo "Open this URL in your Windows browser."
    echo "Press Ctrl+C to stop the viewer."
    echo ""
    npx promptfoo@latest view --port "$PORT" --yes
    ;;
  *)
    echo "Unknown action: $ACTION" >&2
    echo "Usage: $0 [eval|view]" >&2
    exit 1
    ;;
esac
