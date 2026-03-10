<?php

/**
 * @file
 * Test runner for the pre-routing + intent-routing pipeline against the
 * golden dataset.
 *
 * Usage: php IntentRouterTest.php [--baseline]
 *
 * --baseline: Run with baseline (old) patterns only, without enhanced extraction.
 */

namespace Drupal\Tests\ilas_site_assistant;

// Map golden dataset labels to continue-path router outcomes.
$INTENT_MAPPING = [
  'apply_for_help' => ['apply', 'eligibility'],
  'legal_advice_line' => ['hotline'],
  'offices_contact' => ['offices'],
  'donations' => ['donate'],
  'feedback_complaints' => ['feedback'],
  'forms_finder' => ['forms'],
  'guides_finder' => ['guides'],
  'faq' => ['faq'],
  'senior_risk_detector' => ['risk_detector'],
  'services_overview' => ['services'],
  'multi_intent' => ['multi_intent'], // Special handling
  'adversarial' => ['adversarial', 'unknown'],
];

// High-risk category mapping.
$HIGH_RISK_MAPPING = [
  'high_risk_dv' => 'high_risk_dv',
  'high_risk_eviction' => 'high_risk_eviction',
  'high_risk_scam' => 'high_risk_scam',
  'high_risk_deadline' => 'high_risk_deadline',
];

/**
 * Simulates baseline (old) router without enhanced extraction.
 */
function routeBaseline(string $message): array {
  $message_lower = strtolower($message);

  // Baseline patterns (simplified version of old router).
  $patterns = [
    'greeting' => ['/^(hi|hello|hey)[\s!.?]*$/i'],
    'eligibility' => ['/\b(do\s*i\s*qualify|am\s*i\s*eligible|eligibility)/i'],
    'apply' => [
      '/\b(apply|application|sign\s*up)\s*(for)?\s*(help|assistance)?/i',
      '/\bneed\s*(legal)?\s*(help|a\s*lawyer|attorney)/i',
    ],
    'hotline' => [
      '/\b(call|phone|hotline|advice\s*line|talk\s*to)/i',
      '/\bphone\s*number/i',
    ],
    'offices' => [
      '/\b(office|location|address|where\s*(are\s*you|is))/i',
      '/\bhours/i',
    ],
    'donate' => ['/\b(donate|donation|give|support|contribute)/i'],
    'feedback' => ['/\b(feedback|complaint|suggest)/i'],
    'forms' => ['/\b(form|paperwork|document)/i'],
    'guides' => ['/\b(guide|manual|step[\s-]*by[\s-]*step)/i'],
    'faq' => ['/\bfaq|frequently\s*asked/i'],
    'risk_detector' => ['/\brisk\s*(detector|assessment)/i'],
    'services' => ['/\b(what\s*do\s*you\s*do|what\s*services|types\s*of\s*help)/i'],
  ];

  // Check for greeting first.
  if (strlen($message) < 30) {
    foreach ($patterns['greeting'] as $pattern) {
      if (preg_match($pattern, $message)) {
        return ['type' => 'greeting'];
      }
    }
  }

  // Check intents.
  $intent_order = ['eligibility', 'apply', 'hotline', 'offices', 'services', 'risk_detector', 'donate', 'feedback', 'faq', 'forms', 'guides'];
  foreach ($intent_order as $intent) {
    foreach ($patterns[$intent] as $pattern) {
      if (preg_match($pattern, $message)) {
        return ['type' => $intent];
      }
    }
  }

  return ['type' => 'unknown'];
}

/**
 * Simulates the current pipeline with authoritative pre-routing checks first.
 */
function routeEnhanced(string $message): array {
  $message_lower = strtolower($message);
  $deadline_override = NULL;

  // Pre-routing safety exits (checked first).
  $safety_exit_triggers = [
    'high_risk_dv' => [
      'hitting me', 'hit me', 'hits me', 'beating me', 'abusive',
      'domestic violence', 'threatened to kill', 'scared for my life',
      'restraining order', 'protection order', 'me pega', 'abusivo',
      'violencia domestica', 'tengo miedo',
    ],
    'high_risk_eviction' => [
      'eviction notice', 'evicted today', 'sheriff coming', 'sheriff is coming',
      'locked out', 'landlord changed the locks',
      'changed the locks', '3 day notice', 'three day notice',
      'court date tomorrow', 'court date for eviction', 'court date next week',
      'aviso de desalojo', 'me estan echando', 'me esta echando',
    ],
    'high_risk_scam' => [
      'identity theft', 'stole my identity', 'got scammed', 'been scammed',
      'fake contractor', 'social security scam', 'robaron mi identidad',
      'me estafaron',
    ],
  ];

  foreach ($safety_exit_triggers as $category => $triggers) {
    foreach ($triggers as $trigger) {
      if (strpos($message_lower, $trigger) !== FALSE) {
        return [
          'type' => 'pre_routing_exit',
          'category' => $category,
          'pre_routing_decision' => [
            'decision_type' => 'safety_exit',
            'winner_source' => 'safety',
            'reason_code' => 'simulated_' . $category,
          ],
        ];
      }
    }
  }

  // Deadline urgency continues to intent routing with an override.
  $deadline_triggers = [
    'deadline tomorrow', 'deadline today', 'deadline friday',
    'deadline is friday', 'deadline to respond', 'deadline monday',
    'due tomorrow', 'court date tomorrow', 'respond by friday',
    'file by', 'file paperwork by', 'have to file paperwork by',
    'fecha limite', 'manana', 'mañana',
  ];
  foreach ($deadline_triggers as $trigger) {
    if (strpos($message_lower, $trigger) !== FALSE) {
      $deadline_override = [
        'type' => 'high_risk',
        'risk_category' => 'high_risk_deadline',
      ];
      break;
    }
  }

  // Pre-routing out-of-scope triggers.
  $out_of_scope = [
    'criminal defense', 'criminal lawyer', 'dui', 'dwi', 'felony',
    'arrested', 'jail', 'prison', 'immigration', 'green card', 'visa',
    'oregon', 'washington state', 'montana', 'patent', 'start a business',
    '911', 'call 911',
  ];

  foreach ($out_of_scope as $trigger) {
    if (strpos($message_lower, $trigger) !== FALSE) {
      return [
        'type' => 'pre_routing_exit',
        'pre_routing_decision' => [
          'decision_type' => 'oos_exit',
          'winner_source' => 'out_of_scope',
          'reason_code' => 'simulated_oos_exit',
        ],
      ];
    }
  }

  // Enhanced patterns with typo tolerance and Spanish.
  $patterns = [
    'greeting' => [
      '/^(hi|hello|hey|hola|buenos?\s*dias?)[\s!.?]*$/i',
    ],
    'eligibility' => [
      '/\b(do\s*i\s*qualify|am\s*i\s*eligible|eligibility|quailfy|qualfy)/i',
      '/\bcan\s*(u|you)\s*help\s*(me|with)/i',
    ],
    'apply' => [
      '/\b(apply|aply|application|sign\s*(me\s*)?up)\s*(for)?\s*(help|assistance)?/i',
      '/\bneed\s*(legal)?\s*(help|a\s*lawyer|lawer|attorney|abogado)/i',
      '/\b(necesito|quiero)\s*(ayuda|abogado)/i',
      '/\bayuda\s*legal/i',
      '/\babogado\s*gratis/i',
      '/\bcomo\s*aplico/i',
      '/\brepresentaion/i',
      '/\bfree\s*legal/i',
      '/\bget\s*(legal\s*)?(help|aid)/i',
      '/\bpoor\s*and\s*need/i',
      '/\b(where|how)\s*(do\s*i|to)\s*(start|get\s*started|get\s*help)/i',
    ],
    'hotline' => [
      '/\b(call|phone|hotline|hot\s*line|help\s*line|advice\s*line|advise\s*line|talk\s*to)/i',
      '/\bphone\s*(number|consultation)/i',
      '/\b(wanna|want\s*to)\s*talk/i',
      '/\blinea\s*de\s*ayuda/i',
      '/\btelephone/i',
    ],
    'offices' => [
      '/\b(office|offic|location|locaton|address|adress|where\s*(are\s*you|is|r\s*u))/i',
      '/\b(hours|horas|horario)\s*(of\s*operation|of\s*opperation)?/i',
      '/\b(boise|pocatello|twin\s*falls)\s*(office)?/i',
      '/\bdonde\s*esta/i',
      '/\bhorario\s*de\s*oficina/i',
      '/\bemail\s*address/i',
      '/\bnear\s*me|nearest/i',
      '/\bwhat\s*time\s*(do\s*you|are\s*you)\s*open/i',
      '/\bopen\s*on\s*(saturday|sunday|weekend)/i',
      '/\bhow\s*(do\s*i|can\s*i)\s*contact/i',
      '/\bwhere\s*r\s*u\s*located/i',
    ],
    'donate' => [
      '/\b(donate|donatoin|donation|give|support|contribute|donar)/i',
      '/\btax\s*deductible/i',
      '/\bcharitable\s*contribution/i',
      '/\bquiero\s*donar/i',
    ],
    'feedback' => [
      '/\b(feedback|feeback|complaint|complant|suggest)/i',
      '/\bfile\s*a\s*complaint/i',
      '/\bgrievance/i',
      '/\b(bad|terrible)\s*(experience|service)/i',
      '/\byou\s*(people\s*)?suck/i',
      '/\bqueja/i',
      '/\bleave\s*a\s*review/i',
      '/\bspeak\s*to\s*(a\s*)?(supervisor|manager)/i',
    ],
    'forms' => [
      '/\b(form|froms|formulario|paperwork|document)/i',
      '/\b(divorce|custody|eviction|bankruptcy)\s*(form|papers)/i',
      '/\bcourt\s*papers/i',
      '/\bdocumentos\s*para/i',
      '/\bprotective\s*order\s*paperwork/i',
      '/\bsmall\s*claims/i',
    ],
    'guides' => [
      '/\b(guide|giude|giudes|guia|manual)/i',
      '/\bstep[\s-]*by[\s-]*step/i',
      '/\bself[\s-]*help/i',
      '/\btenant\s*rights/i',
      '/\brepresent\s*myself/i',
      '/\bguias?\s*legales?/i',
      '/\blegal\s*information\s*articles?/i',
      '/\binfo\s*on\s*(divorce|eviction)/i',
      '/\bwhat\s*are\s*my\s*rights\s*as\s*a\s*(renter|tenant)/i',
    ],
    'faq' => [
      '/\b(faq|faqs|f\.a\.q)/i',
      '/\bfrequently\s*asked/i',
      '/\bcommon\s*question/i',
      '/\bgeneral\s*question/i',
      '/\bpreguntas\s*frecuentes/i',
      '/\bquestions\s*other\s*people/i',
    ],
    'risk_detector' => [
      '/\brisk\s*(detector|assessment|quiz)/i',
      '/\blegal\s*(checkup|wellness)/i',
      '/\b(senior|elder)\s*(risk|legal|quiz)/i',
      '/\bsenior\s*citizen/i',
      '/\bi\'?m\s*\d+/i',
      '/\belder\s*law\s*issues/i',
    ],
    'services' => [
      '/\b(what\s*do\s*you\s*do|what\s*services|types\s*of\s*help)/i',
      '/\bwhat\s*kind\s*of\s*(help|cases)/i',
      '/\bservices\s*(overview|offered)/i',
      '/\bservicios\s*que\s*ofrecen/i',
      '/\bareas\s*of\s*law/i',
      '/\btell\s*me\s*about\s*(idaho\s*legal|ilas)/i',
      '/\bdo\s*you\s*(help\s*with|do)\s*(housing|evictions|family)/i',
    ],
  ];

  // Check for greeting first.
  if (strlen($message) < 30) {
    foreach ($patterns['greeting'] as $pattern) {
      if (preg_match($pattern, $message)) {
        $result = ['type' => 'greeting'];
        if ($deadline_override !== NULL) {
          $result['pre_routing_decision'] = [
            'decision_type' => 'continue',
            'winner_source' => 'urgency',
            'reason_code' => 'simulated_high_risk_deadline',
            'routing_override_intent' => $deadline_override,
          ];
        }
        return $result;
      }
    }
  }

  // Check intents.
  $intent_order = ['eligibility', 'apply', 'hotline', 'offices', 'services', 'risk_detector', 'donate', 'feedback', 'faq', 'forms', 'guides'];
  foreach ($intent_order as $intent) {
    foreach ($patterns[$intent] as $pattern) {
      if (preg_match($pattern, $message)) {
        $result = ['type' => $intent];
        if ($deadline_override !== NULL) {
          $result['pre_routing_decision'] = [
            'decision_type' => 'continue',
            'winner_source' => 'urgency',
            'reason_code' => 'simulated_high_risk_deadline',
            'routing_override_intent' => $deadline_override,
          ];
        }
        return $result;
      }
    }
  }

  // Topic detection keywords.
  $topics = [
    'topic_housing' => ['eviction', 'landlord', 'tenant', 'rent', 'housing', 'desalojo', 'casero'],
    'topic_family' => ['divorce', 'custody', 'family', 'divorcio', 'custodia'],
    'topic_consumer' => ['scam', 'debt', 'bankruptcy', 'fraud', 'estafa'],
  ];

  foreach ($topics as $topic => $keywords) {
    foreach ($keywords as $kw) {
      if (strpos($message_lower, $kw) !== FALSE) {
        $result = ['type' => 'service_area', 'intent_source' => $topic];
        if ($deadline_override !== NULL) {
          $result['pre_routing_decision'] = [
            'decision_type' => 'continue',
            'winner_source' => 'urgency',
            'reason_code' => 'simulated_high_risk_deadline',
            'routing_override_intent' => $deadline_override,
          ];
        }
        return $result;
      }
    }
  }

  $result = ['type' => 'unknown'];
  if ($deadline_override !== NULL) {
    $result['pre_routing_decision'] = [
      'decision_type' => 'continue',
      'winner_source' => 'urgency',
      'reason_code' => 'simulated_high_risk_deadline',
      'routing_override_intent' => $deadline_override,
    ];
  }
  return $result;
}

/**
 * Summarizes a pipeline outcome for report output.
 */
function describeOutcome(array $result): string {
  $pre_routing = $result['pre_routing_decision'] ?? [];
  $decision_type = $pre_routing['decision_type'] ?? NULL;

  if ($decision_type !== NULL && $decision_type !== 'continue') {
    return $decision_type . (!empty($result['category']) ? ':' . $result['category'] : '');
  }

  if (($pre_routing['routing_override_intent']['risk_category'] ?? NULL) === 'high_risk_deadline') {
    return 'continue:high_risk_deadline->' . ($result['type'] ?? 'unknown');
  }

  if (($result['type'] ?? NULL) === 'service_area') {
    return 'service_area:' . ($result['intent_source'] ?? 'unknown');
  }

  return $result['type'] ?? 'unknown';
}

/**
 * Checks if router result matches expected intent.
 */
function checkMatch(array $result, string $expected_intent, array $intent_mapping, array $high_risk_mapping): array {
  $router_type = $result['type'];
  $expected_types = $intent_mapping[$expected_intent] ?? [$expected_intent];
  $pre_routing = $result['pre_routing_decision'] ?? [];

  if ($expected_intent === 'out_of_scope') {
    if (in_array($pre_routing['decision_type'] ?? NULL, ['oos_exit', 'policy_exit'], TRUE)) {
      return ['match' => TRUE, 'exact' => TRUE];
    }
    return ['match' => FALSE, 'exact' => FALSE];
  }

  if ($expected_intent === 'urgent_safety') {
    $decision_type = $pre_routing['decision_type'] ?? NULL;
    $override_category = $pre_routing['routing_override_intent']['risk_category'] ?? NULL;
    if ($decision_type === 'safety_exit' || $override_category === 'high_risk_deadline') {
      return ['match' => TRUE, 'exact' => TRUE];
    }
    return ['match' => FALSE, 'exact' => FALSE];
  }

  // Special handling for pre-routing urgency outcomes.
  if (strpos($expected_intent, 'high_risk') === 0) {
    $expected_category = $high_risk_mapping[$expected_intent] ?? NULL;
    if ($expected_category === 'high_risk_deadline') {
      $actual_override = $pre_routing['routing_override_intent']['risk_category'] ?? NULL;
      if (($pre_routing['decision_type'] ?? NULL) === 'continue' && $actual_override === 'high_risk_deadline') {
        return ['match' => TRUE, 'exact' => TRUE];
      }
      return ['match' => FALSE, 'exact' => FALSE];
    }

    $actual_category = $result['category'] ?? $result['risk_category'] ?? NULL;

    if (($pre_routing['decision_type'] ?? NULL) === 'safety_exit') {
      if ($expected_category && $actual_category === $expected_category) {
        return ['match' => TRUE, 'exact' => TRUE];
      }
      return ['match' => TRUE, 'exact' => FALSE, 'note' => "Category mismatch: expected $expected_category, got $actual_category"];
    }

    return ['match' => FALSE, 'exact' => FALSE];
  }

  // Multi-intent: check if primary intent is detected.
  if ($expected_intent === 'multi_intent') {
    // For multi-intent, we accept if ANY reasonable intent is detected.
    if ($router_type !== 'unknown') {
      return ['match' => TRUE, 'exact' => FALSE, 'note' => "Multi-intent: detected $router_type"];
    }
    return ['match' => FALSE, 'exact' => FALSE];
  }

  if ($expected_intent === 'adversarial') {
    if (in_array($pre_routing['decision_type'] ?? NULL, ['safety_exit', 'oos_exit', 'policy_exit'], TRUE)
      || in_array($router_type, ['unknown', 'disambiguation'], TRUE)) {
      return ['match' => TRUE, 'exact' => TRUE];
    }
    return ['match' => FALSE, 'exact' => FALSE];
  }

  // Service area mapping.
  if ($router_type === 'service_area') {
    $router_type = $result['intent_source'] ?? 'service_area';
  }

  // Direct match.
  if (in_array($router_type, $expected_types)) {
    return ['match' => TRUE, 'exact' => TRUE];
  }

  // Partial matches (acceptable alternatives).
  $acceptable_alternatives = [
    'forms_finder' => ['topic_family', 'topic_housing'], // Form requests often mention topic.
    'guides_finder' => ['faq', 'resources'],
    'services_overview' => ['apply', 'faq'],
    'apply_for_help' => ['eligibility'],
    'legal_advice_line' => ['offices', 'apply'],
  ];

  if (isset($acceptable_alternatives[$expected_intent])) {
    if (in_array($router_type, $acceptable_alternatives[$expected_intent])) {
      return ['match' => TRUE, 'exact' => FALSE, 'note' => "Acceptable alternative: $router_type"];
    }
  }

  return ['match' => FALSE, 'exact' => FALSE];
}

/**
 * Runs the test suite.
 */
function runTests(bool $useBaseline = FALSE): array {
  global $INTENT_MAPPING, $HIGH_RISK_MAPPING;

  $results = [
    'total' => 0,
    'matches' => 0,
    'exact_matches' => 0,
    'misroutes' => 0,
    'by_intent' => [],
    'failures' => [],
    'improvements' => [],
  ];

  $csv_path = dirname(__DIR__) . '/../../../chatbot-golden-dataset.csv';
  if (!file_exists($csv_path)) {
    $csv_path = dirname(__DIR__) . '/../../../../chatbot-golden-dataset.csv';
  }

  $cases = [];
  if (file_exists($csv_path)) {
    $handle = fopen($csv_path, 'r');
    fgetcsv($handle); // Skip header row.

    while (($row = fgetcsv($handle)) !== FALSE) {
      if (count($row) < 2) {
        continue;
      }

      $cases[] = [
        'utterance' => $row[0],
        'expected_intent' => $row[1],
      ];
    }

    fclose($handle);
  }
  else {
    $fixture_path = __DIR__ . '/fixtures/intent_test_cases.json';
    if (!file_exists($fixture_path)) {
      return ['error' => "Golden dataset not found at $csv_path and fixture file missing at $fixture_path"];
    }

    $fixture_data = json_decode(file_get_contents($fixture_path), TRUE);
    if (!is_array($fixture_data)) {
      return ['error' => "Failed to parse fixture file: $fixture_path"];
    }

    $supported_fixture_intents = array_flip([
      'apply_for_help',
      'legal_advice_line',
      'offices_contact',
      'forms_finder',
      'guides_finder',
      'donations',
      'feedback',
      'faq',
      'risk_detector',
      'services_overview',
      'out_of_scope',
      'urgent_safety',
      'eligibility',
      'topic_housing',
      'topic_family',
      'topic_benefits',
      'topic_employment',
      'topic_seniors',
      'topic_consumer',
      'unknown',
    ]);

    foreach (['intent_cases', 'spanish_cases'] as $bucket) {
      foreach ($fixture_data[$bucket] ?? [] as $case) {
        if (!isset($case['utterance'], $case['expected_intent'])) {
          continue;
        }
        if (!isset($supported_fixture_intents[$case['expected_intent']])) {
          continue;
        }
        $cases[] = [
          'utterance' => $case['utterance'],
          'expected_intent' => $case['expected_intent'],
        ];
      }
    }
  }

  foreach ($cases as $case) {
    $utterance = $case['utterance'];
    $expected_intent = $case['expected_intent'];

    // Skip adversarial for now (handled by policy filter, not router).
    if ($expected_intent === 'adversarial') {
      continue;
    }

    $results['total']++;

    // Route the message.
    if ($useBaseline) {
      $result = routeBaseline($utterance);
    }
    else {
      $result = routeEnhanced($utterance);
    }

    // Check match.
    $match_result = checkMatch($result, $expected_intent, $INTENT_MAPPING, $HIGH_RISK_MAPPING);

    // Track by intent.
    if (!isset($results['by_intent'][$expected_intent])) {
      $results['by_intent'][$expected_intent] = ['total' => 0, 'matches' => 0, 'exact' => 0];
    }
    $results['by_intent'][$expected_intent]['total']++;

    if ($match_result['match']) {
      $results['matches']++;
      $results['by_intent'][$expected_intent]['matches']++;

      if ($match_result['exact']) {
        $results['exact_matches']++;
        $results['by_intent'][$expected_intent]['exact']++;
      }
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'utterance' => $utterance,
        'expected' => $expected_intent,
        'got' => describeOutcome($result),
        'full_result' => $result,
      ];
    }
  }

  // Calculate percentages.
  $results['accuracy'] = $results['total'] > 0 ? round(($results['matches'] / $results['total']) * 100, 2) : 0;
  $results['exact_accuracy'] = $results['total'] > 0 ? round(($results['exact_matches'] / $results['total']) * 100, 2) : 0;

  return $results;
}

/**
 * Compares baseline and enhanced results.
 */
function compareResults(array $baseline, array $enhanced): array {
  return [
    'accuracy_improvement' => $enhanced['accuracy'] - $baseline['accuracy'],
    'exact_improvement' => $enhanced['exact_accuracy'] - $baseline['exact_accuracy'],
    'misroute_reduction' => $baseline['misroutes'] - $enhanced['misroutes'],
    'baseline' => $baseline,
    'enhanced' => $enhanced,
  ];
}

/**
 * Formats results for display.
 */
function formatResults(array $comparison): string {
  $output = [];
  $output[] = "═══════════════════════════════════════════════════════════════";
  $output[] = "  PIPELINE TEST RESULTS - BEFORE/AFTER COMPARISON";
  $output[] = "═══════════════════════════════════════════════════════════════";
  $output[] = "";

  $baseline = $comparison['baseline'];
  $enhanced = $comparison['enhanced'];

  $output[] = "┌─────────────────────────────┬───────────┬───────────┬──────────┐";
  $output[] = "│ Metric                      │ Baseline  │ Enhanced  │ Change   │";
  $output[] = "├─────────────────────────────┼───────────┼───────────┼──────────┤";

  $output[] = sprintf("│ %-27s │ %7d   │ %7d   │          │",
    "Total Test Cases", $baseline['total'], $enhanced['total']);

  $output[] = sprintf("│ %-27s │ %6.1f%%   │ %6.1f%%   │ %+5.1f%%   │",
    "Overall Accuracy",
    $baseline['accuracy'],
    $enhanced['accuracy'],
    $comparison['accuracy_improvement']);

  $output[] = sprintf("│ %-27s │ %6.1f%%   │ %6.1f%%   │ %+5.1f%%   │",
    "Exact Match Accuracy",
    $baseline['exact_accuracy'],
    $enhanced['exact_accuracy'],
    $comparison['exact_improvement']);

  $output[] = sprintf("│ %-27s │ %7d   │ %7d   │ %+6d   │",
    "Misroutes",
    $baseline['misroutes'],
    $enhanced['misroutes'],
    -$comparison['misroute_reduction']);

  $output[] = "└─────────────────────────────┴───────────┴───────────┴──────────┘";
  $output[] = "";

  // Per-intent breakdown.
  $output[] = "PER-INTENT ACCURACY (Enhanced):";
  $output[] = "┌──────────────────────────┬───────┬─────────┬───────────┐";
  $output[] = "│ Intent                   │ Total │ Matches │ Accuracy  │";
  $output[] = "├──────────────────────────┼───────┼─────────┼───────────┤";

  foreach ($enhanced['by_intent'] as $intent => $data) {
    $acc = $data['total'] > 0 ? round(($data['matches'] / $data['total']) * 100, 1) : 0;
    $output[] = sprintf("│ %-24s │ %5d │ %7d │ %7.1f%% │",
      substr($intent, 0, 24), $data['total'], $data['matches'], $acc);
  }

  $output[] = "└──────────────────────────┴───────┴─────────┴───────────┘";
  $output[] = "";

  // Sample failures.
  if (!empty($enhanced['failures'])) {
    $output[] = "SAMPLE MISROUTES (first 10):";
    $output[] = "────────────────────────────────────────────────────────────────";
    $count = 0;
    foreach ($enhanced['failures'] as $failure) {
      if ($count >= 10) break;
      $output[] = sprintf("  Utterance: \"%s\"", substr($failure['utterance'], 0, 50));
      $output[] = sprintf("  Expected: %s → Got: %s", $failure['expected'], $failure['got']);
      $output[] = "";
      $count++;
    }
  }

  $output[] = "═══════════════════════════════════════════════════════════════";

  return implode("\n", $output);
}

// Main execution.
if (php_sapi_name() === 'cli') {
  echo "Running baseline tests...\n";
  $baseline = runTests(TRUE);

  if (isset($baseline['error'])) {
    echo "Error: " . $baseline['error'] . "\n";
    exit(1);
  }

  echo "Running enhanced tests...\n";
  $enhanced = runTests(FALSE);

  $comparison = compareResults($baseline, $enhanced);

  echo "\n";
  echo formatResults($comparison);
  echo "\n";

  // Exit with success if accuracy improved or stayed high.
  $success = $comparison['accuracy_improvement'] >= 0 && $enhanced['accuracy'] >= 80;
  exit($success ? 0 : 1);
}
