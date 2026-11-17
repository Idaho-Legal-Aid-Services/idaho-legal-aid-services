#!/usr/bin/env php
<?php

/**
 * @file
 * Standalone test runner for AcronymExpander and TypoCorrector.
 *
 * Usage:
 *   php run_acronym_typo_tests.php [--verbose]
 */

// Autoload: try Drupal project root, then web root.
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

echo "=== ILAS Acronym & Typo Correction Test Suite ===\n\n";

// Initialize services.
$expander = new AcronymExpander(NULL);
$topic_router = new TopicRouter(NULL);
$corrector = new TypoCorrector(NULL, $topic_router, $expander);

echo "Acronym map: " . count($expander->getAcronymMap()) . " entries\n";
echo "Typo vocabulary: " . $corrector->getVocabularySize() . " entries\n\n";

$pass = 0;
$fail = 0;
$total = 0;
$failures = [];

// =========================================
// ACRONYM EXPANSION TESTS (42 cases)
// =========================================
echo "--- Acronym Expansion Tests ---\n\n";

$acronym_tests = [
  // Domestic / Safety (5).
  ['I need help with DV', 'domestic violence', 'DV -> domestic violence'],
  ['my DV situation is bad', 'domestic violence', 'DV in sentence context'],
  ['need a PO against my ex', 'protection order', 'PO -> protection order'],
  ['how do I get a CPO', 'civil protection order', 'CPO -> civil protection order'],
  ['file a TRO', 'temporary restraining order', 'TRO -> temporary restraining order'],

  // Legal / Court (4).
  ['I need a POA for my mom', 'power of attorney', 'POA -> power of attorney'],
  ['DPOA for elderly parent', 'durable power of attorney', 'DPOA -> durable power of attorney'],
  ['need MPOA forms', 'medical power of attorney', 'MPOA -> medical power of attorney'],
  ['what is a GAL', 'guardian ad litem', 'GAL -> guardian ad litem'],

  // Benefits / Programs (10).
  ['apply for SSI', 'supplemental security income', 'SSI -> supplemental security income'],
  ['denied SSDI benefits', 'social security disability insurance', 'SSDI -> social security disability insurance'],
  ['help with SNAP', 'nutrition assistance', 'SNAP -> nutrition assistance'],
  ['lost my EBT card', 'electronic benefits transfer', 'EBT -> electronic benefits transfer'],
  ['need WIC help', 'women infants children', 'WIC -> women infants children'],
  ['TANF application', 'temporary assistance', 'TANF -> temporary assistance'],
  ['apply for LIHEAP', 'energy assistance', 'LIHEAP -> energy assistance'],
  ['HUD housing', 'housing and urban development', 'HUD -> housing and urban development'],
  ['SS benefits denied', 'social security', 'SS -> social security'],
  ['help with SSD claim', 'social security disability', 'SSD -> social security disability'],

  // Health (3).
  ['ACA enrollment', 'affordable care act', 'ACA -> affordable care act'],
  ['CHIP for my kids', 'children health insurance', 'CHIP -> children health insurance'],
  ['VA benefits denied', 'veterans affairs', 'VA -> veterans affairs'],

  // Consumer / Debt (5).
  ['report to FTC', 'federal trade commission', 'FTC -> federal trade commission'],
  ['FDCPA violation', 'fair debt collection', 'FDCPA -> fair debt collection'],
  ['FCRA dispute', 'fair credit reporting', 'FCRA -> fair credit reporting'],
  ['file BK', 'bankruptcy', 'BK -> bankruptcy'],
  ['CH7 vs CH13', 'chapter 7', 'CH7 -> chapter 7'],

  // Family Law (3).
  ['CS modification', 'child support', 'CS -> child support'],
  ['CPS took my kids', 'child protective services', 'CPS -> child protective services'],
  ['ICPC transfer', 'interstate compact', 'ICPC -> interstate compact'],

  // Employment / Civil Rights (5).
  ['file EEOC complaint', 'equal employment opportunity', 'EEOC -> equal employment opportunity'],
  ['ADA accommodation', 'americans with disabilities', 'ADA -> americans with disabilities'],
  ['FMLA leave denied', 'family medical leave', 'FMLA -> family medical leave'],
  ['FLSA wage violation', 'fair labor standards', 'FLSA -> fair labor standards'],
  ['OSHA complaint', 'occupational safety', 'OSHA -> occupational safety'],

  // Idaho-Specific (3).
  ['what does ILAS do', 'idaho legal aid services', 'ILAS -> idaho legal aid services'],
  ['help from IDHW', 'idaho department of health', 'IDHW -> idaho department of health'],
  ['DHW benefits', 'idaho department of health', 'DHW -> idaho department of health'],

  // General Legal (2).
  ['going pro se', 'self represented', 'pro se -> self represented'],
  ['pro bono lawyer', 'free legal help', 'pro bono -> free legal help'],

  // Misc (2).
  ['denied UI benefits', 'unemployment insurance', 'UI -> unemployment insurance'],
  ['IRS sent a letter', 'internal revenue service', 'IRS -> internal revenue service'],
];

foreach ($acronym_tests as [$input, $expected_substring, $description]) {
  $total++;
  $result = $expander->expand($input);
  $has_match = stripos($result['text'], $expected_substring) !== FALSE;
  $has_expansion = !empty($result['expansions']);

  if ($has_match && $has_expansion) {
    $pass++;
    if ($verbose) {
      echo "  PASS: $description\n";
    }
  }
  else {
    $fail++;
    $failures[] = "ACRONYM: $description | input='$input' | expected='$expected_substring' | got='{$result['text']}'";
    echo "  FAIL: $description\n";
    echo "        Input: $input\n";
    echo "        Expected: $expected_substring\n";
    echo "        Got: {$result['text']}\n";
  }
}

// No-expansion tests (should NOT expand).
$no_expand_tests = [
  ['I need help with divorce', 'No acronyms present'],
  ['eviction notice received', 'No acronyms present'],
  ['how to file bankruptcy', 'No acronyms present'],
  ['custody forms please', 'No acronyms present'],
  ['hello how are you', 'Greeting, no acronyms'],
];

foreach ($no_expand_tests as [$input, $description]) {
  $total++;
  $result = $expander->expand($input);
  if (empty($result['expansions'])) {
    $pass++;
    if ($verbose) {
      echo "  PASS: No false expand - $description\n";
    }
  }
  else {
    $fail++;
    $failures[] = "ACRONYM FALSE POS: $description | input='$input'";
    echo "  FAIL: False expansion for '$input'\n";
  }
}

echo "\nAcronym tests: $pass pass, $fail fail out of $total total\n\n";

// =========================================
// TYPO CORRECTION TESTS (40 cases)
// =========================================
echo "--- Typo Correction Tests ---\n\n";

$acronym_pass = $pass;
$acronym_fail = $fail;
$pass = 0;
$fail = 0;
$typo_total = 0;

$typo_tests = [
  // Family law typos (10).
  ['custdy', 'custody', 'missing letter in custody'],
  ['cusotdy', 'custody', 'transposed letters in custody'],
  ['custidy', 'custody', 'wrong vowel in custody'],
  ['divorse', 'divorce', 'common misspelling of divorce'],
  ['divorec', 'divorce', 'transposed ending in divorce'],
  ['divroce', 'divorce', 'scrambled middle in divorce'],
  ['gaurdianship', 'guardianship', 'transposed letters in guardianship'],
  ['gurdianship', 'guardianship', 'missing letter in guardianship'],
  ['seperaton', 'separation', 'misspelled separation'],
  ['separtion', 'separation', 'missing letter in separation'],

  // Housing typos (8).
  ['eviciton', 'eviction', 'transposed letters in eviction'],
  ['evicton', 'eviction', 'missing letter in eviction'],
  ['evcition', 'eviction', 'scrambled eviction'],
  ['lanldord', 'landlord', 'transposed letters in landlord'],
  ['landord', 'landlord', 'missing letter in landlord'],
  ['forclosure', 'foreclosure', 'missing letter in foreclosure'],
  ['foreclsoure', 'foreclosure', 'scrambled foreclosure'],
  ['morgage', 'mortgage', 'missing letter in mortgage'],

  // Consumer typos (6).
  ['bankrupcy', 'bankruptcy', 'common misspelling of bankruptcy'],
  ['bankruptsy', 'bankruptcy', 'wrong ending in bankruptcy'],
  ['garnishmet', 'garnishment', 'typo in garnishment'],
  ['reposession', 'repossession', 'missing letter in repossession'],
  ['colection', 'collection', 'missing letter in collection'],
  ['bankruptcey', 'bankruptcy', 'wrong vowel in bankruptcy'],

  // Benefits/health typos (3).
  ['benifits', 'benefits', 'wrong vowel in benefits'],
  ['disabilty', 'disability', 'missing letter in disability'],
  ['insurace', 'insurance', 'missing letter in insurance'],

  // Employment typos (4).
  ['employmnt', 'employment', 'missing letter in employment'],
  ['terminaed', 'terminated', 'missing letter in terminated'],
  ['harrassment', 'harassment', 'double r in harassment'],
  ['discrimation', 'discrimination', 'missing letters in discrimination'],

  // General legal typos (9).
  ['laywer', 'lawyer', 'transposed letters in lawyer'],
  ['attoney', 'attorney', 'missing letter in attorney'],
  ['elgibility', 'eligibility', 'scrambled eligibility'],
  ['eligable', 'eligible', 'misspelled eligible'],
  ['asistance', 'assistance', 'missing letter in assistance'],
  ['complant', 'complaint', 'missing letter in complaint'],
  ['donaton', 'donation', 'missing letter in donation'],
  ['locaton', 'location', 'missing letter in location'],
  ['foreclsure', 'foreclosure', 'scrambled foreclosure variant'],
];

foreach ($typo_tests as [$input, $expected_word, $description]) {
  $total++;
  $typo_total++;
  $result = $corrector->correct(strtolower($input));
  $has_match = stripos($result['text'], $expected_word) !== FALSE;
  $has_correction = !empty($result['corrections']);

  if ($has_match && $has_correction) {
    $pass++;
    if ($verbose) {
      echo "  PASS: $description\n";
    }
  }
  else {
    $fail++;
    $failures[] = "TYPO: $description | input='$input' | expected='$expected_word' | got='{$result['text']}'";
    echo "  FAIL: $description\n";
    echo "        Input: $input\n";
    echo "        Expected: $expected_word\n";
    echo "        Got: {$result['text']}\n";
  }
}

// No-false-correction tests.
$no_correct_tests = [
  ['divorce', 'Correct word should not be corrected'],
  ['custody', 'Correct word should not be corrected'],
  ['eviction', 'Correct word should not be corrected'],
  ['landlord', 'Correct word should not be corrected'],
  ['bankruptcy', 'Correct word should not be corrected'],
  ['help', 'Short word should not be corrected'],
  ['the', 'Stop word should not be corrected'],
  ['pizza', 'Unrelated word should not be corrected'],
  ['computer', 'Unrelated word should not be corrected'],
];

foreach ($no_correct_tests as [$input, $description]) {
  $total++;
  $typo_total++;
  $result = $corrector->correct(strtolower($input));
  if (empty($result['corrections'])) {
    $pass++;
    if ($verbose) {
      echo "  PASS: No false correct - $description\n";
    }
  }
  else {
    $fail++;
    $failures[] = "TYPO FALSE POS: $description | input='$input' | corrected to='{$result['text']}'";
    echo "  FAIL: False correction for '$input'\n";
  }
}

echo "\nTypo tests: $pass pass, $fail fail out of $typo_total total\n\n";

// =========================================
// SUMMARY
// =========================================
$total_pass = $acronym_pass + $pass;
$total_fail = $acronym_fail + $fail;
$total_all = $total;

echo "=== SUMMARY ===\n";
echo "Acronym expansion tests: " . ($total - $typo_total) . " (" . ($acronym_pass) . " pass, " . ($acronym_fail) . " fail)\n";
echo "Typo correction tests:   $typo_total ($pass pass, $fail fail)\n";
echo "Total:                   $total_all ($total_pass pass, $total_fail fail)\n";

if (!empty($failures)) {
  echo "\n--- Failures ---\n";
  foreach ($failures as $f) {
    echo "  - $f\n";
  }
}

echo "\n";
exit($total_fail > 0 ? 1 : 0);
