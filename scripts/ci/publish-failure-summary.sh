#!/usr/bin/env bash
# Publish-gate failure summarizer.
#
# Invoked from EXIT traps in .git/hooks/pre-push and scripts/ci/publish-gate-local.sh.
# Reads promptfoo-evals/output/phpunit-summary.txt to find the first phase that
# exited nonzero; opens its JUnit XML to extract the failed test class/method,
# source file, and assertion message; then prints a final structured block at
# the very bottom of the gate output.
#
# This script never alters the caller's exit code — it always exits 0. The
# caller's $? was captured into argv[1] before the trap fired.
#
# Argv:
#   $1 — upstream exit code (defaults to 0)
#
# Reads:
#   $REPO_ROOT/promptfoo-evals/output/phpunit-summary.txt
#   $REPO_ROOT/promptfoo-evals/output/junit/*.xml
#   $REPO_ROOT/promptfoo-evals/output/structured-error-summary.txt (promptfoo)
#
# Writes:
#   stdout — the summary block.

set -u

UPSTREAM_EXIT="${1:-0}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi

SUMMARY_FILE="$REPO_ROOT/promptfoo-evals/output/phpunit-summary.txt"
JUNIT_DIR="$REPO_ROOT/promptfoo-evals/output/junit"
PROMPTFOO_ERROR_TXT="$REPO_ROOT/promptfoo-evals/output/structured-error-summary.txt"

# PIPE-03: see 03.1-SPEC.md.
# Emit a single machine-grep-able verdict line at the top of stderr.
# Format: PUBLISH-VERDICT <kind> gate=<id> reason=<dash-token> next=<dash-command>
# Contract: single line, top of stderr, ^PUBLISH-VERDICT , spaces in reason/next replaced by dashes.
emit_top_verdict() {
  local kind="$1"
  local gate="$2"
  local reason="$3"
  local next="$4"
  printf 'PUBLISH-VERDICT %s gate=%s reason=%s next=%s\n' \
    "$kind" "$gate" "${reason// /-}" "${next// /-}" >&2
}

# The PHP parser does the heavy lifting: scan the summary file for the first
# phase with nonzero exit_code, open its JUnit XML, walk all JUnit XMLs for
# warning/deprecation/skipped counts, emit a single tab-separated record.
PHP_PARSER='
$summaryFile = $argv[1];
$junitDir    = $argv[2];
$upstream    = (int) $argv[3];

$phases = [];
$junitForPhase = [];
$drift = "";
if (is_readable($summaryFile)) {
  foreach (file($summaryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match("/^phase=([A-Za-z0-9_]+) exit_code=(-?\d+)/", $line, $m)) {
      $phases[] = ["name" => $m[1], "code" => (int) $m[2]];
    } elseif (preg_match("/^junit_([A-Za-z0-9_]+)=(.+)$/", $line, $m)) {
      $junitForPhase[$m[1]] = $m[2];
    } elseif (preg_match("/^drift=(.+)$/", $line, $m)) {
      $drift = $m[1];
    }
  }
}

$blockerPhase = null;
foreach ($phases as $p) {
  if ($p["code"] !== 0) { $blockerPhase = $p; break; }
}
if (!$blockerPhase && $upstream !== 0) {
  $blockerPhase = ["name" => "unknown", "code" => $upstream];
}

$totals = ["warning" => 0, "deprecation" => 0, "skipped" => 0];
$junitFiles = is_dir($junitDir) ? glob($junitDir . "/*.xml") : [];
foreach ($junitFiles as $f) {
  if (!is_readable($f) || filesize($f) === 0) continue;
  libxml_use_internal_errors(true);
  $xml = simplexml_load_file($f);
  if ($xml === false) continue;
  // PHPUnit 11 emits <testsuites><testsuite>...</testsuite></testsuites>.
  // Walk all testcase elements regardless of nesting.
  $cases = $xml->xpath("//testcase");
  if ($cases) {
    foreach ($cases as $tc) {
      if (isset($tc->skipped)) $totals["skipped"]++;
      if (isset($tc->warning)) $totals["warning"]++;
      // PHPUnit 11 routes deprecations through <error type="…Deprecation">.
      foreach ($tc->error ?? [] as $err) {
        $type = (string) $err["type"];
        if (stripos($type, "deprecation") !== false) $totals["deprecation"]++;
      }
      if (isset($tc->deprecation)) $totals["deprecation"]++;
    }
  }
}

$blockerDetail = null;
if ($blockerPhase) {
  $name = $blockerPhase["name"];
  $junit = $junitForPhase[$name] ?? ($junitDir . "/" . $name . ".xml");
  if (is_readable($junit) && filesize($junit) > 0) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($junit);
    if ($xml !== false) {
      $cases = $xml->xpath("//testcase");
      if ($cases) {
        foreach ($cases as $tc) {
          $hasFailure = isset($tc->failure);
          $errType = "";
          $hasNonDeprecationError = false;
          foreach ($tc->error ?? [] as $err) {
            $errType = (string) $err["type"];
            if (stripos($errType, "deprecation") === false) {
              $hasNonDeprecationError = true;
              break;
            }
          }
          if ($hasFailure || $hasNonDeprecationError) {
            $msg = "";
            if ($hasFailure) {
              $f = $tc->failure;
              $msg = (string) ($f["message"] ?? "");
              if ($msg === "") $msg = trim((string) $f);
            } elseif ($hasNonDeprecationError) {
              foreach ($tc->error as $err) {
                $type = (string) $err["type"];
                if (stripos($type, "deprecation") === false) {
                  $msg = (string) ($err["message"] ?? "");
                  if ($msg === "") $msg = trim((string) $err);
                  break;
                }
              }
            }
            // Collapse to first non-empty line, hard-cap length.
            $msg = preg_replace("/\s+/", " ", $msg);
            if (strlen($msg) > 320) $msg = substr($msg, 0, 317) . "...";

            $blockerDetail = [
              "phase"    => $name,
              "junit"    => $junit,
              "class"    => (string) ($tc["classname"] ?? ""),
              "name"     => (string) ($tc["name"] ?? ""),
              "file"     => (string) ($tc["file"] ?? ""),
              "line"     => (string) ($tc["line"] ?? ""),
              "message"  => $msg,
            ];
            break;
          }
        }
      }
    }
  }
  if ($blockerDetail === null) {
    $blockerDetail = [
      "phase" => $name,
      "junit" => is_readable($junit) ? $junit : "",
      "class" => "", "name" => "", "file" => "", "line" => "", "message" => "",
    ];
  }
}

$out = [];
$out[] = "BLOCKER\t" . ($blockerDetail ? "1" : "0");
$out[] = "UPSTREAM_EXIT\t" . $upstream;
$out[] = "WARNINGS\t" . $totals["warning"];
$out[] = "DEPRECATIONS\t" . $totals["deprecation"];
$out[] = "SKIPPED\t" . $totals["skipped"];
$out[] = "DRIFT\t" . $drift;
if ($blockerDetail) {
  foreach (["phase","junit","class","name","file","line","message"] as $k) {
    $out[] = "B_" . strtoupper($k) . "\t" . str_replace(["\t","\n","\r"], [" "," "," "], $blockerDetail[$k]);
  }
}
echo implode("\n", $out) . "\n";
'

# Run the parser and read into associative array.
declare -A R=()
while IFS=$'\t' read -r key value; do
  [[ -z "$key" ]] && continue
  R["$key"]="$value"
done < <(php -d display_errors=0 -r "$PHP_PARSER" -- "$SUMMARY_FILE" "$JUNIT_DIR" "$UPSTREAM_EXIT" 2>/dev/null || true)

# Fallback: if php failed entirely and the gate failed, still emit a minimal block.
if [[ -z "${R[UPSTREAM_EXIT]:-}" ]]; then
  R[UPSTREAM_EXIT]="$UPSTREAM_EXIT"
  R[BLOCKER]="0"
  if [[ "$UPSTREAM_EXIT" != "0" ]]; then R[BLOCKER]="1"; fi
  R[WARNINGS]=0
  R[DEPRECATIONS]=0
  R[SKIPPED]=0
  R[DRIFT]=""
fi

phase="${R[B_PHASE]:-unknown}"

# Map phase → reproduction command. Falls back to a generic hint if unknown.
repro_for_phase() {
  local p="$1" method="$2" file="$3"
  case "$p" in
    composer_dry_run)
      echo "composer install --no-interaction --no-progress --prefer-dist --dry-run"
      ;;
    vc_pure)
      echo "vendor/bin/phpunit -c phpunit.pure.xml"
      ;;
    vc_custom_unit)
      echo "vendor/bin/phpunit -c phpunit.xml --testsuite custom-unit"
      ;;
    vc_unit)
      echo "bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh --profile basic"
      ;;
    vc_drupal_unit)
      echo "vendor/bin/phpunit --testsuite drupal-unit"
      ;;
    vc_kernel)
      if [[ -n "$file" ]]; then
        echo "bash scripts/ci/run-host-phpunit.sh $file"
      else
        echo "bash scripts/ci/run-host-phpunit.sh <kernel-test-file>"
      fi
      ;;
    assistant_functional)
      if [[ -n "$method" ]]; then
        echo "bash scripts/ci/phase-1d-fast.sh --filter $method"
      else
        echo "bash scripts/ci/phase-1d-fast.sh"
      fi
      ;;
    conversation_intent_fixture)
      echo "vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --group ilas_site_assistant --filter ConversationIntentFixtureUnitTest web/modules/custom/ilas_site_assistant/tests/src/Unit/ConversationIntentFixtureUnitTest.php"
      ;;
    promptfoo_runtime)
      echo "npm run test:promptfoo:runtime"
      ;;
    promptfoo|deep_promptfoo)
      echo "see $PROMPTFOO_ERROR_TXT"
      ;;
    *)
      echo "(no repro mapping for phase '$p' — see logs)"
      ;;
  esac
}

# Phase number annotation, purely cosmetic.
phase_label() {
  case "$1" in
    composer_dry_run)            echo "composer_dry_run (pre-push parity)" ;;
    vc_pure)                     echo "vc_pure (VC-PURE)" ;;
    vc_custom_unit)              echo "vc_custom_unit (VC-PURE, other custom modules)" ;;
    vc_unit)                     echo "vc_unit (Phase 1)" ;;
    vc_drupal_unit)              echo "vc_drupal_unit (Phase 1b)" ;;
    vc_kernel)                   echo "vc_kernel (Phase 1c)" ;;
    assistant_functional)        echo "assistant_functional (Phase 1d)" ;;
    conversation_intent_fixture) echo "conversation_intent_fixture (Phase 1e)" ;;
    promptfoo_runtime)           echo "promptfoo_runtime (Phase 1f)" ;;
    promptfoo)                   echo "promptfoo (abuse evals)" ;;
    deep_promptfoo)              echo "deep_promptfoo" ;;
    *)                           echo "$1" ;;
  esac
}

BAR="════════════════════════════════════════════════════════════════════"

# PIPE-03: emit_top_verdict is called once here, before the existing structured block,
# so the verdict line is the FIRST thing on stderr from this script.
if [[ "${R[BLOCKER]}" == "1" ]]; then
  _v_gate="${R[B_PHASE]:-unknown}"
  _v_method="${R[B_NAME]:-}"
  _v_file="${R[B_FILE]:-}"
  _v_repro="$(repro_for_phase "$_v_gate" "$_v_method" "$_v_file")"
  emit_top_verdict FAIL "$_v_gate" "${R[B_MESSAGE]:-gate-failed}" "$_v_repro"
elif [[ -n "${R[DRIFT]:-}" ]]; then
  emit_top_verdict WARN sync-check drift-detected see-drift-line-below
elif [[ -f "$SUMMARY_FILE" ]] && grep -q "^bypass=" "$SUMMARY_FILE" 2>/dev/null; then
  emit_top_verdict BYPASS --no-verify operator-explicit-override "$SUMMARY_FILE"
else
  emit_top_verdict PASS all ok none
fi

echo ""
if [[ "${R[BLOCKER]}" == "1" ]]; then
  method="${R[B_NAME]:-}"
  file="${R[B_FILE]:-}"
  cls="${R[B_CLASS]:-}"
  msg="${R[B_MESSAGE]:-}"
  junit="${R[B_JUNIT]:-}"
  repro="$(repro_for_phase "$phase" "$method" "$file")"

  echo "$BAR"
  echo "                       PUBLISH GATE BLOCKER"
  echo "$BAR"
  printf "  Phase:        %s\n" "$(phase_label "$phase")"
  if [[ -n "$file" ]]; then
    if [[ -n "${R[B_LINE]:-}" ]]; then
      printf "  Suite file:   %s:%s\n" "$file" "${R[B_LINE]}"
    else
      printf "  Suite file:   %s\n" "$file"
    fi
  fi
  if [[ -n "$cls" ]];   then printf "  Test class:   %s\n" "$cls"; fi
  if [[ -n "$method" ]];then printf "  Test method:  %s\n" "$method"; fi
  if [[ -n "$msg" ]];   then printf "  Assertion:    %s\n" "$msg"; fi
  if [[ -n "$junit" ]]; then printf "  JUnit log:    %s\n" "$junit"; fi
  printf "  Phase log:    %s\n" "$SUMMARY_FILE"
  printf "  Reproduce:    %s\n" "$repro"
  echo "$BAR"
fi

# Always print the categorization counts + drift, even on success.
warn="${R[WARNINGS]:-0}"
dep="${R[DEPRECATIONS]:-0}"
skip="${R[SKIPPED]:-0}"
drift="${R[DRIFT]:-}"

if [[ "${R[BLOCKER]}" != "1" && "$warn" == "0" && "$dep" == "0" && "$skip" == "0" && -z "$drift" ]]; then
  # Clean run, no informational signal worth surfacing.
  exit 0
fi

if [[ "${R[BLOCKER]}" != "1" ]]; then
  echo "$BAR"
  echo "                  PUBLISH GATE — informational"
  echo "$BAR"
fi
printf "  Warnings:     %s   (PHPUnit warnings — non-blocking)\n" "$warn"
printf "  Deprecations: %s   (informational — non-blocking)\n" "$dep"
printf "  Skipped:      %s   (informational — non-blocking)\n" "$skip"
if [[ -n "$drift" ]]; then
  printf "  Remote drift: %s\n" "$drift"
fi
echo "$BAR"

exit 0
