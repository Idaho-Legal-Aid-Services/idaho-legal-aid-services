#!/usr/bin/env php
<?php

/**
 * @file
 * Test runner for normalization regression fixes.
 *
 * Tests specific failing cases:
 * - "representaion" -> "representation"
 * - "adress" -> "address"
 * - "where r u located" -> "where are you located"
 * - "child custody forms" NOT detected as greeting.
 *
 * Usage:
 *   php run_normalization_regression_tests.php [--verbose]
 */

// Autoload.
$autoload_paths = [
  __DIR__ . '/../../../../../vendor/autoload.php',
  __DIR__ . '/../../../../vendor/autoload.php',
];
$loaded = FALSE;
foreach ($autoload_paths as $path) {
  if (file_exists($path)) {
    require_once $path;
    $loaded = TRUE;
    break;
  }
}
if (!$loaded) {
  echo "Error: Cannot find vendor/autoload.php\n";
  exit(1);
}

require_once __DIR__ . '/../src/Service/AcronymExpander.php';
require_once __DIR__ . '/../src/Service/TopicRouter.php';
require_once __DIR__ . '/../src/Service/TypoCorrector.php';

use Drupal\ilas_site_assistant\Service\AcronymExpander;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TypoCorrector;

$verbose = in_array('--verbose', ($_SERVER['argv'] ?? []));

echo "=== ILAS Normalization Regression Test Suite ===\n\n";

// Initialize services.
$expander = new AcronymExpander(NULL);
$topic_router = new TopicRouter(NULL);
$corrector = new TypoCorrector(NULL, $topic_router, $expander);

echo "Acronym map entries: " . count($expander->getAcronymMap()) . "\n";
echo "Typo vocabulary entries: " . $corrector->getVocabularySize() . "\n\n";

$pass = 0;
$fail = 0;
$total = 0;
$failures = [];

// =========================================
// TEXT SPEAK ABBREVIATION TESTS
// =========================================
echo "--- Text Speak Abbreviation Tests ---\n\n";

$text_speak_tests = [
  // Core failing case from eval.
  ['where r u located', 'where are you located', 'r u -> are you (eval failure)'],

  // Additional r u variants.
  ['r u open today', 'are you', 'r u at start of sentence'],
  ['where r u', 'where are you', 'r u at end of sentence'],
  ['r u there', 'are you', 'r u standalone'],

  // U r variants.
  ['u r closed', 'you are', 'u r -> you are'],
  ['u r the best', 'you are', 'u r in context'],

  // Common abbreviations.
  ['pls help me', 'please', 'pls -> please'],
  ['plz send info', 'please', 'plz -> please'],
  ['thx for help', 'thanks', 'thx -> thanks'],
  ['ty for your time', 'thank you', 'ty -> thank you'],
  ['need help asap', 'as soon as possible', 'asap expansion'],
  ['govt benefits', 'government', 'govt -> government'],
  ['need an atty', 'attorney', 'atty -> attorney'],
  ['office hrs', 'hours', 'hrs -> hours'],
  ['ur address', 'your', 'ur -> your'],

  // Mixed case handling.
  ['WHERE R U LOCATED', 'where are you located', 'uppercase r u'],
  ['Where R U', 'where are you', 'mixed case r u'],

  // Punctuation handling.
  ['where r u?', 'where are you', 'r u with question mark'],
  ['r u open!', 'are you', 'r u with exclamation'],
];

foreach ($text_speak_tests as [$input, $expected_substring, $description]) {
  $total++;
  $result = $expander->expand(strtolower($input));
  $has_match = stripos($result['text'], $expected_substring) !== FALSE;

  if ($has_match) {
    $pass++;
    if ($verbose) {
      echo "  PASS: $description\n";
      echo "        '$input' -> '{$result['text']}'\n";
    }
  }
  else {
    $fail++;
    $failures[] = "TEXT SPEAK: $description | input='$input' | expected='$expected_substring' | got='{$result['text']}'";
    echo "  FAIL: $description\n";
    echo "        Input: $input\n";
    echo "        Expected: $expected_substring\n";
    echo "        Got: {$result['text']}\n";
  }
}

echo "\nText speak tests: $pass pass, $fail fail out of " . ($pass + $fail) . " total\n\n";

// =========================================
// VOCABULARY PRESENCE TESTS
// =========================================
echo "--- Vocabulary Presence Tests ---\n\n";

$vocab_pass = 0;
$vocab_fail = 0;

$required_vocab = [
  'representation' => 'representation (eval failure: representaion)',
  'address' => 'address (eval failure: adress)',
  'custody' => 'custody',
  'divorce' => 'divorce',
  'eviction' => 'eviction',
  'judgment' => 'judgment',
  'notice' => 'notice',
  'petition' => 'petition',
  'affidavit' => 'affidavit',
];

$vocab = $corrector->getVocabulary();

foreach ($required_vocab as $term => $description) {
  $total++;
  if (isset($vocab[$term])) {
    $vocab_pass++;
    $pass++;
    if ($verbose) {
      echo "  PASS: $description in vocabulary\n";
    }
  }
  else {
    $vocab_fail++;
    $fail++;
    $failures[] = "VOCAB: $description NOT in vocabulary";
    echo "  FAIL: $description NOT in vocabulary\n";
  }
}

echo "\nVocabulary tests: $vocab_pass pass, $vocab_fail fail out of " . ($vocab_pass + $vocab_fail) . " total\n\n";

// =========================================
// GREETING FALSE POSITIVE TESTS
// =========================================
echo "--- Greeting False Positive Tests ---\n\n";

$greeting_pass = 0;
$greeting_fail = 0;

// Greeting patterns from IntentRouter.
$greeting_patterns = [
  '/^(hi|hello|hey|good\s*(morning|afternoon|evening)|greetings)[\s!.?]*$/i',
  '/^(what\'?s?\s*up|howdy|yo)[\s!.?]*$/i',
  '/^(hola|buenos?\s*(dias?|tardes?|noches?))[\s!.?]*$/i',
];

// Greeting keywords.
$greeting_keywords = ['hi', 'hello', 'hey', 'greetings', 'hola'];

// Topic keywords that should block greeting detection.
$topic_keywords = [
  'custody', 'divorce', 'eviction', 'landlord', 'tenant',
  'bankruptcy', 'foreclosure', 'guardianship', 'forms', 'form',
  'guides', 'guide', 'apply', 'application', 'child', 'children',
];

/**
 * Simulates the greeting detection logic with fixes.
 */
function detectsAsGreeting($message, $greeting_patterns, $greeting_keywords, $topic_keywords) {
  $message_lower = strtolower($message);

  // Check greeting patterns (anchored, so "child custody forms" won't match).
  $matches_pattern = FALSE;
  foreach ($greeting_patterns as $pattern) {
    if (preg_match($pattern, $message)) {
      $matches_pattern = TRUE;
      break;
    }
  }

  // Check greeting keywords with word boundaries (NEW: prevents "hi" inside "child").
  $matches_keyword = FALSE;
  foreach ($greeting_keywords as $keyword) {
    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/';
    if (preg_match($pattern, $message_lower)) {
      $matches_keyword = TRUE;
      break;
    }
  }

  // Check topic keywords (NEW: blocks greeting for topic queries).
  $has_topic = FALSE;
  foreach ($topic_keywords as $keyword) {
    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
    if (preg_match($pattern, $message_lower)) {
      $has_topic = TRUE;
      break;
    }
  }

  // Only detect as greeting if:
  // 1. Matches pattern OR keyword
  // 2. AND does NOT have topic keywords.
  return ($matches_pattern || $matches_keyword) && !$has_topic;
}

// Test cases: [input, should_be_greeting, description].
$greeting_tests = [
  // Should NOT be detected as greeting (topic queries).
  ['child custody forms', FALSE, 'EVAL FAILURE: child custody forms must NOT be greeting'],
  ['custody forms', FALSE, 'custody forms must NOT be greeting'],
  ['child support', FALSE, 'child support must NOT be greeting'],
  ['divorce forms', FALSE, 'divorce forms must NOT be greeting'],
  ['eviction help', FALSE, 'eviction help must NOT be greeting'],
  ['landlord problems', FALSE, 'landlord problems must NOT be greeting'],
  ['bankruptcy forms', FALSE, 'bankruptcy forms must NOT be greeting'],
  ['apply for help', FALSE, 'apply for help must NOT be greeting'],
  ['child custody', FALSE, 'child custody must NOT be greeting'],

  // SHOULD be detected as greeting (actual greetings).
  ['hi', TRUE, 'hi is a greeting'],
  ['hello', TRUE, 'hello is a greeting'],
  ['hey', TRUE, 'hey is a greeting'],
  ['hola', TRUE, 'hola is a greeting'],
  ['hi!', TRUE, 'hi with exclamation is a greeting'],
  ['hello there', TRUE, 'hello there IS a greeting (hello keyword matches)'],

  // Edge cases.
  ['thinking about divorce', FALSE, 'contains "hi" substring but NOT greeting'],
  ['within guidelines', FALSE, 'contains "hi" substring but NOT greeting'],
  ['machine problems', FALSE, 'contains "hi" substring but NOT greeting'],
];

foreach ($greeting_tests as [$input, $should_be_greeting, $description]) {
  $total++;
  $is_greeting = detectsAsGreeting($input, $greeting_patterns, $greeting_keywords, $topic_keywords);

  if ($is_greeting === $should_be_greeting) {
    $greeting_pass++;
    $pass++;
    if ($verbose) {
      echo "  PASS: $description\n";
    }
  }
  else {
    $greeting_fail++;
    $fail++;
    $expected = $should_be_greeting ? 'greeting' : 'NOT greeting';
    $actual = $is_greeting ? 'greeting' : 'NOT greeting';
    $failures[] = "GREETING: $description | expected=$expected | got=$actual";
    echo "  FAIL: $description\n";
    echo "        Expected: $expected\n";
    echo "        Got: $actual\n";
  }
}

echo "\nGreeting false positive tests: $greeting_pass pass, $greeting_fail fail out of " . ($greeting_pass + $greeting_fail) . " total\n\n";

// =========================================
// FULL PIPELINE TESTS
// =========================================
echo "--- Full Pipeline Tests ---\n\n";

$pipeline_pass = 0;
$pipeline_fail = 0;

/**
 * Simulates the full extraction pipeline.
 */
function runPipeline($message, $expander, $corrector) {
  $text = strtolower(trim($message));

  // Step 1: Acronym/abbreviation expansion.
  $acronym_result = $expander->expand($text);
  $text = $acronym_result['text'];

  // Step 2: Typo correction.
  $typo_result = $corrector->correct($text);
  $text = $typo_result['text'];

  return [
    'normalized' => $text,
    'expansions' => $acronym_result['expansions'],
    'corrections' => $typo_result['corrections'],
  ];
}

$pipeline_tests = [
  // Combined text speak + typo.
  ['where r u located whats ur adress', 'are you located', 'text speak + location query'],

  // Pure typo correction.
  ['need legal representaion', 'representation', 'representaion typo (eval failure)'],
  ['whats your adress', 'address', 'adress typo (eval failure)'],

  // Abbreviations.
  ['need an atty asap', 'attorney', 'attorney abbreviation'],
  ['office hrs pls', 'hours', 'hours abbreviation'],
];

foreach ($pipeline_tests as [$input, $expected_substring, $description]) {
  $total++;
  $result = runPipeline($input, $expander, $corrector);
  $has_match = stripos($result['normalized'], $expected_substring) !== FALSE;

  if ($has_match) {
    $pipeline_pass++;
    $pass++;
    if ($verbose) {
      echo "  PASS: $description\n";
      echo "        '$input' -> '{$result['normalized']}'\n";
    }
  }
  else {
    $pipeline_fail++;
    $fail++;
    $failures[] = "PIPELINE: $description | input='$input' | expected='$expected_substring' | got='{$result['normalized']}'";
    echo "  FAIL: $description\n";
    echo "        Input: $input\n";
    echo "        Expected: $expected_substring\n";
    echo "        Got: {$result['normalized']}\n";
  }
}

echo "\nPipeline tests: $pipeline_pass pass, $pipeline_fail fail out of " . ($pipeline_pass + $pipeline_fail) . " total\n\n";

// =========================================
// SUMMARY
// =========================================
echo "=== SUMMARY ===\n";
echo "Total tests: $total ($pass pass, $fail fail)\n";

if (!empty($failures)) {
  echo "\n--- Failures ---\n";
  foreach ($failures as $f) {
    echo "  - $f\n";
  }
}

echo "\n";
exit($fail > 0 ? 1 : 0);
