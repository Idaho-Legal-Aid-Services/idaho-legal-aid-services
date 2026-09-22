#!/usr/bin/env bash
# tests/ci/theme-css-drift-guard.sh — self-test for scripts/ci/theme-css-drift-guard.sh.
#
# Builds a throwaway git repo with the theme layout and asserts the four
# outcomes: no scss change → 0; scss + css change → 0; scss only → 1 with the
# rebuild command; scss only + THEME_CSS_GUARD_SKIP=1 → 0 and says bypassed.
# Also replays the real regression the guard exists for (a0709f167b) when
# that commit is present in this checkout.
#
# Hand-rolled bash harness, same shape as tests/ci/gates-parity.sh.

# shellcheck disable=SC2317  # helpers are invoked indirectly via run_test()
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
GUARD="$REPO_ROOT/scripts/ci/theme-css-drift-guard.sh"
THEME="web/themes/custom/b5subtheme"

PASS_COUNT=0
FAIL_COUNT=0
TMPDIR_CREATED=""

cleanup() {
  [[ -n "$TMPDIR_CREATED" && -d "$TMPDIR_CREATED" ]] && rm -rf "$TMPDIR_CREATED"
}
trap cleanup EXIT

run_test() {
  local label="$1"
  shift
  printf '\n=== Test: %s ===\n' "$label"
  if "$@"; then PASS_COUNT=$(( PASS_COUNT + 1 )); else FAIL_COUNT=$(( FAIL_COUNT + 1 )); fi
}

# Fixture repo: commit A (baseline) then one commit per scenario off A.
make_fixture() {
  TMPDIR_CREATED="$(mktemp -d)"
  local r="$TMPDIR_CREATED/repo"
  mkdir -p "$r/$THEME/scss" "$r/$THEME/css"
  git -C "$TMPDIR_CREATED" init -q repo
  git -C "$r" config user.email t@example.com
  git -C "$r" config user.name t
  echo '.a{color:red}' > "$r/$THEME/scss/_a.scss"
  echo '.a{color:red}' > "$r/$THEME/css/style.css"
  echo '.h{}' > "$r/$THEME/css/critical.css"
  echo 'x' > "$r/README.md"
  git -C "$r" add -A && git -C "$r" commit -qm baseline
  echo "$r"
}

commit_change() {  # <repo> <label> <files-to-touch...>
  local r="$1" label="$2"; shift 2
  git -C "$r" checkout -q -b "$label" master 2>/dev/null || git -C "$r" checkout -q -b "$label" main
  for f in "$@"; do echo "// $label" >> "$r/$f"; done
  git -C "$r" add -A && git -C "$r" commit -qm "$label"
  git -C "$r" rev-parse HEAD
}

test_no_scss_change() {
  local r; r="$(make_fixture)"
  local base; base="$(git -C "$r" rev-parse HEAD)"
  local head; head="$(commit_change "$r" docs-only README.md)"
  local out; out="$(cd "$r" && bash "$GUARD" "$base" "$head")" || { echo "[FAIL] exited non-zero for a docs-only change" >&2; return 1; }
  grep -q "no theme SCSS changed" <<< "$out" || { echo "[FAIL] unexpected output: $out" >&2; return 1; }
  echo "[ok] docs-only change passes"
}

test_scss_with_rebuild() {
  local r; r="$(make_fixture)"
  local base; base="$(git -C "$r" rev-parse HEAD)"
  local head; head="$(commit_change "$r" rebuilt "$THEME/scss/_a.scss" "$THEME/css/style.css")"
  local out; out="$(cd "$r" && bash "$GUARD" "$base" "$head")" || { echo "[FAIL] exited non-zero although css was rebuilt" >&2; return 1; }
  grep -q "was rebuilt" <<< "$out" || { echo "[FAIL] unexpected output: $out" >&2; return 1; }
  echo "[ok] scss + rebuilt css passes"
}

test_scss_without_rebuild_fails() {
  local r; r="$(make_fixture)"
  local base; base="$(git -C "$r" rev-parse HEAD)"
  local head; head="$(commit_change "$r" stale "$THEME/scss/_a.scss")"
  local rc=0 err
  err="$(cd "$r" && bash "$GUARD" "$base" "$head" 2>&1 >/dev/null)" || rc=$?
  [[ "$rc" -eq 1 ]] || { echo "[FAIL] expected exit 1, got $rc" >&2; return 1; }
  grep -q "npm run build" <<< "$err" || { echo "[FAIL] failure text lacks the rebuild command: $err" >&2; return 1; }
  grep -q "_a.scss" <<< "$err" || { echo "[FAIL] failure text does not name the scss file" >&2; return 1; }
  echo "[ok] scss without rebuild fails with the rebuild command"
}

test_bypass() {
  local r; r="$(make_fixture)"
  local base; base="$(git -C "$r" rev-parse HEAD)"
  local head; head="$(commit_change "$r" comment-only "$THEME/scss/_a.scss")"
  local out; out="$(cd "$r" && THEME_CSS_GUARD_SKIP=1 bash "$GUARD" "$base" "$head")" || { echo "[FAIL] bypass did not exit 0" >&2; return 1; }
  grep -q "BYPASSED" <<< "$out" || { echo "[FAIL] bypass not announced: $out" >&2; return 1; }
  echo "[ok] THEME_CSS_GUARD_SKIP=1 bypasses and says so"
}

test_real_regression() {
  # a0709f167b changed scss/_assistant-widget.scss and no css: the guard must fail on it.
  if ! git -C "$REPO_ROOT" cat-file -e a0709f167b^{commit} 2>/dev/null; then
    echo "[skip] a0709f167b not in this checkout"; return 0
  fi
  local rc=0
  (cd "$REPO_ROOT" && bash "$GUARD" a0709f167b^ a0709f167b >/dev/null 2>&1) || rc=$?
  [[ "$rc" -eq 1 ]] || { echo "[FAIL] guard did not fail on the real regression (rc=$rc)" >&2; return 1; }
  echo "[ok] guard fails on a0709f167b (the regression it exists for)"
}

run_test "docs-only change" test_no_scss_change
run_test "scss with rebuilt css" test_scss_with_rebuild
run_test "scss without rebuild" test_scss_without_rebuild_fails
run_test "bypass" test_bypass
run_test "real regression replay" test_real_regression

printf '\n=== Results: %d passed, %d failed ===\n' "$PASS_COUNT" "$FAIL_COUNT"
[[ "$FAIL_COUNT" -eq 0 ]]
