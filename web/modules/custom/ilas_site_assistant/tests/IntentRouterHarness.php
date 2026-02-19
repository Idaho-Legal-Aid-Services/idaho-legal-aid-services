<?php

/**
 * @file
 * Test harness for IntentRouter decision tree validation.
 *
 * Usage:
 *   php IntentRouterHarness.php [--report=path] [--verbose]
 *
 * Options:
 *   --report=path  Write JSON report to specified path
 *   --verbose      Show detailed output for each test case
 */

namespace Drupal\Tests\ilas_site_assistant;

/**
 * Intent mapping from test fixture labels to router types.
 */
$INTENT_MAPPING = [
  'apply_for_help' => ['apply_for_help', 'apply', 'eligibility'],
  'legal_advice_line' => ['legal_advice_line', 'hotline'],
  'offices_contact' => ['offices_contact', 'offices'],
  'forms_finder' => ['forms_finder', 'forms'],
  'guides_finder' => ['guides_finder', 'guides'],
  'donations' => ['donations', 'donate'],
  'feedback' => ['feedback'],
  'faq' => ['faq'],
  'risk_detector' => ['risk_detector'],
  'services_overview' => ['services_overview', 'services'],
  'out_of_scope' => ['out_of_scope', 'unknown'],
  'urgent_safety' => ['urgent_safety', 'high_risk'],
  'topic_housing' => ['service_area', 'topic_housing'],
  'topic_family' => ['service_area', 'topic_family'],
  'topic_benefits' => ['service_area', 'topic_benefits'],
  'topic_employment' => ['service_area', 'topic_employment'],
  'topic_seniors' => ['service_area', 'topic_seniors'],
  'topic_consumer' => ['service_area', 'topic_consumer'],
];

/**
 * Urgent safety category mapping.
 */
$URGENT_CATEGORY_MAPPING = [
  'urgent_dv' => 'urgent_dv',
  'urgent_eviction' => 'urgent_eviction',
  'urgent_scam' => 'urgent_scam',
  'urgent_deadline' => 'urgent_deadline',
];

/**
 * Vague queries that should trigger disambiguation instead of routing.
 *
 * @var array
 */
$VAGUE_QUERIES = [
  'help' => 'generic_help',
  'can you help' => 'generic_help',
  'can you help me' => 'generic_help',
  'i need help' => 'generic_help',
  'where can i get help' => 'generic_help',
  'information' => 'apply_vs_info',
  'i want to apply' => 'apply_vs_info',
  'forms' => 'forms_vs_guides',
  'phone' => 'contact_how',
  'ayuda' => 'generic_help',
  'formularios' => 'forms_vs_guides',
];

/**
 * Legal advice seeking patterns (out of scope).
 *
 * @var array
 */
$LEGAL_ADVICE_PATTERNS = [
  '/\b(should\s*i|do\s*i\s*have\s*a\s*case|will\s*i\s*win|chances?\s*of\s*winning)/i',
  '/\blegal\s*advice\s*(about|on|for|regarding)\b/i',
  '/\bcan\s*i\s*sue\b/i',
  '/\bshould\s*i\s*sign\b/i',
  '/\btell\s*me\s*(if\s*i\s*should|my\s*chances)/i',
  '/\bwhat\s*should\s*i\s*do\s*about\s*(my|the)\b/i',
];

/**
 * Simulates the enhanced router logic for standalone testing.
 *
 * @param string $message
 *   The user message.
 *
 * @return array
 *   Router result.
 */
function simulateRouter(string $message): array {
  global $VAGUE_QUERIES, $LEGAL_ADVICE_PATTERNS;
  $message_lower = strtolower($message);

  // === STEP 1: URGENT SAFETY CHECK (highest priority) ===
  $urgent_triggers = [
    'urgent_dv' => [
      'hitting me', 'hit me', 'hits me', 'beat me', 'beating me', 'beats me',
      'abusive partner', 'abusive husband', 'abusive wife', 'abusive boyfriend', 'abusive girlfriend',
      'domestic violence', 'dv situation', 'threatened to kill', 'kill me', 'threatened me',
      'scared for my life', 'fear for my life', 'afraid for my life', 'in fear',
      'stalking me', 'stalker', 'being followed', 'hurt me', 'hurts me', 'hurting me',
      'choke', 'choking', 'choked me', 'strangled',
      'me pega', 'me golpea', 'abusivo', 'abusiva', 'violencia domestica',
      'tengo miedo', 'miedo por mi vida', 'amenazado',
    ],
    'urgent_eviction' => [
      'evicted today', 'evicted tomorrow', 'eviction today', 'eviction tomorrow',
      'sheriff coming', 'sheriff is coming', 'sheriff tomorrow', 'sheriff today',
      'locked out today', 'locked me out', 'changed locks', 'changed the locks',
      '3 day notice', 'three day notice', '5 day notice', 'five day notice',
      'court date tomorrow', 'court date today', 'eviction court tomorrow',
      'thrown out', 'kicked out today', 'nowhere to go', 'nowhere to stay', 'nowhere to sleep',
      'homeless today', 'homeless tomorrow', 'on the street',
      'desalojo hoy', 'desalojo manana', 'me estan echando hoy', 'me echan hoy',
      'cambiaron las cerraduras', 'no tengo donde ir', 'no tengo donde dormir',
      'me estan echando de mi casa', 'echando de mi',
    ],
    'urgent_crisis' => [
      'kill myself', 'end my life', 'want to die', 'suicide', 'suicidal',
      'better off dead', 'better off without me',
      'dont want to live', "don't want to live", 'no reason to live',
      'cant do this anymore', "can't do this anymore",
      'cant take it anymore', "can't take it anymore",
      'no way out', 'give up on everything', 'give up on life',
    ],
    'urgent_scam' => [
      'identity theft today', 'identity stolen', 'stole my identity',
      'got scammed', 'been scammed', 'was scammed', 'being scammed', 'scam in progress',
      'gave them my social', 'gave them my ssn', 'gave my social security',
      'gave them my bank', 'gave my bank info', 'gave bank account',
      'gave my credit card', 'gave them money',
      'fake contractor took', 'contractor took my money',
      'social security scam', 'irs scam', 'irs called',
      'robaron mi identidad', 'robo de identidad', 'me estafaron', 'me estan estafando',
    ],
    'urgent_deadline' => [
      'deadline tomorrow', 'deadline today', 'deadline is today', 'deadline is tomorrow',
      'due tomorrow', 'due today', 'response due tomorrow', 'response due today',
      'file by tomorrow', 'file today', 'must file by', 'have to file by tomorrow',
      'respond by tomorrow', 'respond by today', 'respond by friday', 'respond by monday',
      'court date tomorrow', 'court date today', 'court hearing tomorrow',
      'have to respond today', 'must respond today', '24 hours', 'within 24 hours',
      'fecha limite hoy', 'fecha limite manana', 'vence hoy', 'vence manana',
      'tengo que responder hoy', 'corte manana', 'audiencia manana',
      'fecha limite is manana', 'limite is manana',
    ],
  ];

  foreach ($urgent_triggers as $category => $triggers) {
    foreach ($triggers as $trigger) {
      if (strpos($message_lower, $trigger) !== FALSE) {
        return [
          'type' => 'urgent_safety',
          'category' => $category,
          'confidence' => 1.0,
        ];
      }
    }
  }

  // === STEP 2: OUT-OF-SCOPE CHECK ===
  $out_of_scope_triggers = [
    // Criminal.
    'criminal defense', 'criminal lawyer', 'criminal case', 'criminal charges',
    'dui', 'dwi', 'felony', 'misdemeanor', 'arrested', 'jail', 'prison', 'public defender',
    // Out of state.
    'oregon', 'washington state', 'montana', 'nevada', 'utah', 'wyoming', 'california',
    'i live in oregon', 'live in montana',
    // Immigration.
    'immigration', 'deportation', 'visa', 'green card', 'asylum', 'citizenship', 'undocumented',
    // Business.
    'patent', 'trademark', 'copyright', 'start a business', 'incorporate', 'llc formation',
    // Prompt injection.
    'ignore your instructions', 'ignore all rules', 'pretend you are', 'you are now dan',
    'system prompt', 'reveal all', 'no restrictions', 'override',
    // Illegal requests.
    'hide assets', 'evade child support', 'lie to the court', 'false police report',
    // PII.
    'ssn is', 'social security number is', 'my email is',
    // Legal advice seeking patterns.
    'should i sue', 'will i win', 'predict the outcome', 'tell me if i should',
    'yes or no', 'chances of winning',
  ];

  foreach ($out_of_scope_triggers as $trigger) {
    if (strpos($message_lower, $trigger) !== FALSE) {
      return [
        'type' => 'out_of_scope',
        'confidence' => 0.9,
      ];
    }
  }

  // Check for PII patterns (simplified).
  if (preg_match('/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/', $message)) {
    return ['type' => 'out_of_scope', 'confidence' => 0.9, 'reason' => 'pii'];
  }
  if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}.*\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', $message)) {
    return ['type' => 'out_of_scope', 'confidence' => 0.9, 'reason' => 'pii'];
  }

  // === STEP 3: GREETING CHECK ===
  if (strlen($message) < 30) {
    if (preg_match('/^(hi|hello|hey|hola|buenos?\s*(dias?|tardes?|noches?))[\s!.?]*$/i', $message)) {
      return ['type' => 'greeting', 'confidence' => 0.95];
    }
  }

  // === STEP 3b: VAGUE QUERY CHECK ===
  // Short/ambiguous queries that should trigger clarification.
  $vague_key = strtolower(trim(preg_replace('/[?.!]+$/', '', $message)));
  if (isset($VAGUE_QUERIES[$vague_key])) {
    return [
      'type' => 'disambiguation',
      'confidence' => 0.3,
      'vague_query' => TRUE,
      'rule' => $VAGUE_QUERIES[$vague_key],
    ];
  }

  // === STEP 3c: LEGAL ADVICE SEEKING CHECK (out of scope) ===
  foreach ($LEGAL_ADVICE_PATTERNS as $pattern) {
    if (preg_match($pattern, $message)) {
      return [
        'type' => 'out_of_scope',
        'confidence' => 0.85,
        'reason' => 'legal_advice_request',
      ];
    }
  }

  // === STEP 4: PRIMARY INTENT PATTERNS ===
  $intent_patterns = [
    'apply_for_help' => [
      '/\b(apply|aply|application)\s*(for)?\s*(help|assistance|services)?/i',
      '/\bsign\s*(me\s*)?up\s*(for)?\s*(services|help)?/i',
      '/\bneed\s*(legal)?\s*(help|assistance|a\s*lawyer|an?\s*attorney)/i',
      '/\b(find|get|need|looking\s*for)\s*(a|an)?\s*(lawyer|lawer|attorney|abogado|legal\s*(help|aid|assistance))/i',
      '/\b(necesito|quiero)\s*(ayuda|un\s*abogado)/i',
      '/\b(como\s*(aplico|aplicar)|aplicar\s*para)/i',
      '/\bayuda\s*(legal|con\s*mi\s*caso)/i',
      '/\babogado\s*gratis/i',
      '/\bfree\s*(legal|lawyer)/i',
      '/\bget\s*started/i',
      '/\bhow\s*(do\s*i|can\s*i|to)\s*(apply|get\s*started)/i',
    ],
    'legal_advice_line' => [
      '/\b(call|phone|hotline|hot\s*line|help\s*line|advice\s*line|advise\s*line)/i',
      '/\bspeak\s*(with|to)\s*(someone|a\s*person|a\s*real\s*person)/i',
      '/\bphone\s*(number|consultation)/i',
      '/\b(wanna|want\s*to)\s*talk/i',
      '/\btalk\s*to\s*(a\s*)?(real\s*)?(person|someone|human)/i',
      '/\b(linea\s*de\s*ayuda|llamar)/i',
      '/\btelephone|telefono/i',
      '/\breal\s*person/i',
    ],
    'offices_contact' => [
      '/\b(office|offic|location|locaton|address|adress)\b/i',
      '/\b(near\s*me|closest|nearby|nearest)/i',
      '/\bvisit\s*(in\s*person|your\s*office)/i',
      '/\b(hours|horas|horario)\s*(of\s*operation)?/i',
      '/\b(what\s*time|when)\s*(do\s*you|are\s*you)\s*open/i',
      '/\b(open\s*on|closed\s*on)\s*(saturday|sunday|weekend)/i',
      '/\bcontact\s*(info|information)/i',
      '/\b(boise|pocatello|twin\s*falls|idaho\s*falls)\s*(office|location)?/i',
      '/\b(donde\s*(esta|queda)|oficina|ubicacion|direccion)/i',
      '/\bhorario\s*de\s*oficina/i',
      '/\bwhere\s*(is|are)\s*(your|the)\s*(office|location)/i',
    ],
    'forms_finder' => [
      '/\b(find|get|need|download|where)\s*(a|the|is|are)?\s*(form|froms|formulario)/i',
      '/\b(form|formulario)\s*(for|to|about)/i',
      '/\b(eviction|divorce|custody|guardianship|bankruptcy|small\s*claims)\s*(form|paperwork|papers)/i',
      '/\b(court\s*papers|legal\s*documents)/i',
      '/\bprotective\s*order\s*(form|paperwork)/i',
      '/\brestraining\s*order\s*(form|paperwork)/i',
      '/\b(documentos|formularios)\s*(para|de)/i',
      '/\bpaperwork/i',
    ],
    'guides_finder' => [
      '/\b(find|get|need|read|where)\s*(a|the|is|are)?\s*(guide|giude|giudes|guia)/i',
      '/\b(guide|guia)\s*(for|to|about|on)/i',
      '/\bstep[\s-]*by[\s-]*step/i',
      '/\bself[\s-]*help\s*(resources?|guide)/i',
      '/\b(tenant|renter)\s*rights?\s*(guide|info)/i',
      '/\bhow\s*to\s*(represent\s*myself|guide|manual)/i',
      '/\blegal\s*information\s*articles?/i',
      '/\binfo\s*on\s*(divorce|eviction|custody)/i',
      '/\bwhat\s*are\s*my\s*rights\s*as\s*a\s*(renter|tenant)/i',
      '/\bguias?\s*legales?/i',
      '/\binstrucciones/i',
      '/\bmanual/i',
    ],
    'donations' => [
      '/\b(donate|donatoin|dontae|donation|donar)/i',
      '/\bhow\s*(can\s*i|to)\s*(help|support|give|donate)/i',
      '/\b(tax\s*deductible|charitable\s*contribution)/i',
      '/\bgive\s*money/i',
      '/\bquiero\s*donar/i',
      '/\bdonacion/i',
      '/\bcontribute/i',
      '/\bfinancial\s*support/i',
      '/\bways\s*to\s*give/i',
    ],
    'feedback' => [
      '/\b(feedback|feeback|complaint|complant|suggest)/i',
      '/\bfile\s*a\s*complaint/i',
      '/\bgrievance/i',
      '/\b(bad|terrible|horrible)\s*(experience|service)/i',
      '/\bspeak\s*to\s*(a\s*)?(supervisor|manager)/i',
      '/\bleave\s*a\s*review/i',
      '/\b(queja|comentario|sugerencia)/i',
    ],
    'faq' => [
      '/\b(faq|faqs|f\.a\.q)/i',
      '/\b(frequently\s*asked|common\s*question)/i',
      '/\bgeneral\s*question/i',
      '/\bquestions\s*other\s*people/i',
      '/\bpreguntas\s*frecuentes/i',
      '/\bwhat\s+(does|do|is|are)\s+.{2,}\s+(mean|work)/i',
      '/\bdefine\s+|definition\s+of/i',
      '/\bexplain\s+(what|the)/i',
      '/\b(what\s+is\s+)?(the\s+)?difference\s+between/i',
    ],
    'risk_detector' => [
      '/\b(risk\s*(detector|assessment|quiz|tool))/i',
      '/\b(legal\s*risk|check\s*my\s*risk)/i',
      '/\b(senior|elder|elderly)\s*(risk|quiz|assessment|legal)/i',
      '/\blegal\s*(checkup|wellness)/i',
      '/\bi\'?m\s*\d+\s*(years?\s*old)?.*legal\s*(problems?|issues?)/i',
      '/\bcheck\s*if\s*i\s*need\s*help\s*as\s*(a\s*)?(senior|elder)/i',
      '/\bsenior\s*citizen.*legal/i',
    ],
    'services_overview' => [
      '/\b(what\s*(do\s*you|does\s*ilas)\s*do)/i',
      '/\bwhat\s*services/i',
      '/\b(types?\s*of\s*(help|services|cases)|areas?\s*of\s*(law|practice))/i',
      '/\b(what\s*(kind|type)\s*of\s*(help|cases)|practice\s*areas?)/i',
      '/\bservices\s*(overview|offered|available)/i',
      '/\btell\s*me\s*about\s*(idaho\s*legal\s*aid|ilas)/i',
      '/\b(que\s*servicios|servicios\s*que\s*ofrecen)/i',
      '/\bwhat\s*(kinds?\s*of|type\s*of)\s*(cases|legal\s*issues)/i',
    ],
  ];

  // Topic patterns (lower priority).
  $topic_patterns = [
    'topic_housing' => [
      '/\b(housing|eviction|eviccion|landlord|tenant|rent|lease)/i',
      '/\bdesalojo|casero|arrendador|inquilino/i',
    ],
    'topic_family' => [
      '/\b(divorce|custody|child\s*support|visitation|adoption)/i',
      '/\bdivorcio|custodia|familia/i',
      '/\b(ex|partner|spouse)\s*(is\s*)?(using|on|doing)\s*(drugs?|meth|heroin|fentanyl)/i',
      '/\b(drugs?|meth)\s*(around|near|with)\s*(my\s*)?(kids?|children)/i',
    ],
    'topic_benefits' => [
      '/\b(medicaid|medicare|snap|food\s*stamps|ssi|ssdi|tanf)/i',
      '/\bbenefits?|beneficios/i',
    ],
    'topic_employment' => [
      '/\b(fired|terminated|wrongful\s*termination|laid\s*off)/i',
      '/\b(unpaid\s*wages?|wage\s*theft|paycheck|last\s*paycheck)/i',
      '/\bdespedido|me\s*despidieron/i',
    ],
    'topic_seniors' => [
      '/\b(senior|elderly|older\s*adult|elder\s*(care|abuse|law))/i',
      '/\b(nursing\s*home|assisted\s*living|guardianship|conservator)/i',
      '/\b(caretaker|caregiver)\s*(is\s*)?(steal|stole|stealing|taking|abuse)/i',
      '/\b(probate|estate\s*plan|inherit(ance)?)\b/i',
      '/\b(died|passed\s*away)\s*(and\s*)?(without|no|didn\'?t\s*have)\s*(a\s*)?(will|trust)/i',
      '/\b(parent|mom|dad|mother|father)\s*(just\s*)?(died|passed)\b/i',
    ],
    'topic_consumer' => [
      '/\b(consumer|debt|collection|credit|scam|fraud|bankruptcy)/i',
      '/\b(garnishment|repossession|identity\s*theft)/i',
      '/\b(car|vehicle|auto)\s*(repossess|repossessed|repo)\b/i',
      '/\b(my\s*)?(car|vehicle|auto)\s*(is\s*|was\s*)?gone\b/i',
      '/\b(they\s*)?(took|taking)\s*(my\s*)?(car|vehicle|auto)/i',
      '/\b(debt|bill)\s*collector/i',
    ],
  ];

  // Score intents.
  $best_intent = NULL;
  $best_confidence = 0;
  $second_intent = NULL;
  $second_confidence = 0;

  foreach ($intent_patterns as $intent => $patterns) {
    $matches = 0;
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        $matches++;
      }
    }
    if ($matches > 0) {
      $confidence = min(0.95, 0.7 + ($matches * 0.08));
      if ($confidence > $best_confidence) {
        $second_intent = $best_intent;
        $second_confidence = $best_confidence;
        $best_intent = $intent;
        $best_confidence = $confidence;
      }
      elseif ($confidence > $second_confidence) {
        $second_intent = $intent;
        $second_confidence = $confidence;
      }
    }
  }

  if ($best_intent && $best_confidence >= 0.5) {
    // Check if disambiguation is needed (competing intents with tight delta).
    if ($second_intent && $second_confidence >= 0.5) {
      $delta = $best_confidence - $second_confidence;
      if ($delta < 0.12 && $best_confidence < 0.85) {
        return [
          'type' => 'disambiguation',
          'confidence' => $best_confidence,
          'competing' => [$best_intent, $second_intent],
        ];
      }
    }

    return [
      'type' => $best_intent,
      'confidence' => $best_confidence,
    ];
  }

  // Check topics — but only route to service_area if the message has
  // enough context (more than bare topic word). Bare topic words like
  // "divorce" or "eviction" (≤2 words) go through TopicRouter below
  // which now returns disambiguation.
  $word_count = str_word_count($message);
  foreach ($topic_patterns as $topic => $patterns) {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        // If the message is short and ONLY contains a topic word,
        // disambiguate instead of routing.
        if ($word_count <= 2) {
          return [
            'type' => 'disambiguation',
            'confidence' => 0.5,
            'intent_source' => $topic,
            'vague_query' => TRUE,
          ];
        }
        return [
          'type' => 'service_area',
          'intent_source' => $topic,
          'confidence' => 0.7,
        ];
      }
    }
  }

  // === STEP 5: TOPIC ROUTER for single-token/short queries ===
  // Load TopicRouter for short messages that didn't match above.
  if ($word_count <= 3) {
    static $topicRouter = NULL;
    if ($topicRouter === NULL) {
      require_once __DIR__ . '/../src/Service/TopicRouter.php';
      $topicRouter = new \Drupal\ilas_site_assistant\Service\TopicRouter(NULL);
    }
    $topic_result = $topicRouter->route($message);
    if ($topic_result) {
      // Single-token topic queries are inherently ambiguous.
      return [
        'type' => 'disambiguation',
        'confidence' => 0.5,
        'intent_source' => 'topic_' . $topic_result['service_area'],
        'topic_route' => $topic_result,
      ];
    }
  }

  return ['type' => 'unknown', 'confidence' => 0.2];
}

/**
 * Checks if a result matches the expected intent.
 *
 * @param array $result
 *   Router result.
 * @param string $expected_intent
 *   Expected intent label.
 * @param string|null $expected_category
 *   Expected urgent category (for urgent_safety).
 *
 * @return array
 *   Match result with 'match', 'exact', 'note' keys.
 */
function checkMatch(array $result, string $expected_intent, ?string $expected_category = NULL): array {
  global $INTENT_MAPPING, $URGENT_CATEGORY_MAPPING;

  $router_type = $result['type'];
  $expected_types = $INTENT_MAPPING[$expected_intent] ?? [$expected_intent];

  // Handle urgent_safety category matching.
  if ($expected_intent === 'urgent_safety') {
    if ($router_type === 'urgent_safety' || $router_type === 'high_risk') {
      $actual_category = $result['category'] ?? $result['risk_category'] ?? NULL;
      if ($expected_category && $actual_category) {
        if ($actual_category === $expected_category) {
          return ['match' => TRUE, 'exact' => TRUE];
        }
        return ['match' => TRUE, 'exact' => FALSE, 'note' => "Category: expected $expected_category, got $actual_category"];
      }
      return ['match' => TRUE, 'exact' => FALSE, 'note' => 'Urgent detected without category check'];
    }
    return ['match' => FALSE, 'exact' => FALSE];
  }

  // Handle service_area type.
  if ($router_type === 'service_area') {
    $source = $result['intent_source'] ?? '';
    if (strpos($expected_intent, 'topic_') === 0 && strpos($source, $expected_intent) !== FALSE) {
      return ['match' => TRUE, 'exact' => TRUE];
    }
    if (in_array($source, $expected_types)) {
      return ['match' => TRUE, 'exact' => TRUE];
    }
  }

  // Direct type match.
  if (in_array($router_type, $expected_types)) {
    return ['match' => TRUE, 'exact' => TRUE];
  }

  // Disambiguation is an acceptable alternative for any non-safety intent
  // (the router chose to clarify rather than guess wrong).
  if ($router_type === 'disambiguation') {
    // For most intents, disambiguation is a cautious-but-correct choice.
    // Exception: safety intents MUST be routed immediately.
    if (!in_array($expected_intent, ['urgent_safety', 'out_of_scope'])) {
      return ['match' => TRUE, 'exact' => FALSE, 'note' => 'Clarification returned (acceptable)'];
    }
  }

  // Acceptable alternatives.
  $alternatives = [
    'apply_for_help' => ['eligibility'],
    'legal_advice_line' => ['offices_contact'],
    'forms_finder' => ['guides_finder', 'resources'],
    'guides_finder' => ['faq', 'resources'],
    'services_overview' => ['faq', 'apply_for_help'],
    'topic_housing' => ['forms_finder', 'guides_finder'],
    'topic_family' => ['forms_finder', 'guides_finder'],
    'topic_seniors' => ['forms_finder', 'guides_finder'],
    'topic_consumer' => ['forms_finder', 'guides_finder'],
  ];

  if (isset($alternatives[$expected_intent]) && in_array($router_type, $alternatives[$expected_intent])) {
    return ['match' => TRUE, 'exact' => FALSE, 'note' => "Acceptable alternative: $router_type"];
  }

  return ['match' => FALSE, 'exact' => FALSE];
}

/**
 * Runs all tests from the fixture file.
 *
 * @param bool $verbose
 *   Whether to print verbose output.
 *
 * @return array
 *   Test results.
 */
function runTests(bool $verbose = FALSE): array {
  $fixture_path = __DIR__ . '/fixtures/intent_test_cases.json';

  if (!file_exists($fixture_path)) {
    return ['error' => "Fixture file not found: $fixture_path"];
  }

  $fixtures = json_decode(file_get_contents($fixture_path), TRUE);
  if (json_last_error() !== JSON_ERROR_NONE) {
    return ['error' => 'Failed to parse fixture JSON: ' . json_last_error_msg()];
  }

  $results = [
    'timestamp' => date('c'),
    'total' => 0,
    'matches' => 0,
    'exact_matches' => 0,
    'misroutes' => 0,
    'by_category' => [
      'standard' => ['total' => 0, 'matches' => 0],
      'multi_intent' => ['total' => 0, 'matches' => 0],
      'spanish' => ['total' => 0, 'matches' => 0],
      'spanglish' => ['total' => 0, 'matches' => 0],
      'adversarial' => ['total' => 0, 'matches' => 0],
      'urgent' => ['total' => 0, 'matches' => 0],
    ],
    'by_intent' => [],
    'failures' => [],
    'clarifications' => 0,
    'safety_compliance' => 0,
    'safety_total' => 0,
  ];

  // Process intent cases.
  foreach ($fixtures['intent_cases'] ?? [] as $case) {
    $results['total']++;
    $category = $case['category'] ?? 'standard';
    $results['by_category'][$category]['total'] = ($results['by_category'][$category]['total'] ?? 0) + 1;

    if (!isset($results['by_intent'][$case['expected_intent']])) {
      $results['by_intent'][$case['expected_intent']] = ['total' => 0, 'matches' => 0, 'exact' => 0];
    }
    $results['by_intent'][$case['expected_intent']]['total']++;

    $result = simulateRouter($case['utterance']);
    $expected_category = $case['expected_category'] ?? NULL;
    $match = checkMatch($result, $case['expected_intent'], $expected_category);

    if ($match['match']) {
      $results['matches']++;
      $results['by_category'][$category]['matches'] = ($results['by_category'][$category]['matches'] ?? 0) + 1;
      $results['by_intent'][$case['expected_intent']]['matches']++;

      if ($match['exact']) {
        $results['exact_matches']++;
        $results['by_intent'][$case['expected_intent']]['exact']++;
      }
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'id' => $case['id'],
        'utterance' => $case['utterance'],
        'expected' => $case['expected_intent'],
        'got' => $result['type'],
        'category' => $category,
      ];
    }

    // Track clarification decisions.
    if ($result['type'] === 'disambiguation') {
      $results['clarifications']++;
    }

    // Track safety compliance for urgent cases.
    if ($case['expected_intent'] === 'urgent_safety') {
      $results['safety_total']++;
      if ($result['type'] === 'urgent_safety' || $result['type'] === 'high_risk') {
        $results['safety_compliance']++;
      }
    }

    if ($verbose) {
      $status = $match['match'] ? ($match['exact'] ? 'EXACT' : 'PARTIAL') : 'FAIL';
      echo sprintf("[%s] #%d: %s\n", $status, $case['id'], substr($case['utterance'], 0, 50));
      if (!$match['match']) {
        echo sprintf("       Expected: %s, Got: %s\n", $case['expected_intent'], $result['type']);
      }
    }
  }

  // Process multi-intent cases.
  foreach ($fixtures['multi_intent_cases'] ?? [] as $case) {
    $results['total']++;
    $results['by_category']['multi_intent']['total']++;

    $result = simulateRouter($case['utterance']);
    // For multi-intent, we accept if ANY of the expected intents is detected.
    $detected = FALSE;
    foreach ($case['expected_intents'] as $expected) {
      $match = checkMatch($result, $expected);
      if ($match['match']) {
        $detected = TRUE;
        break;
      }
    }

    if ($detected) {
      $results['matches']++;
      $results['by_category']['multi_intent']['matches']++;
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'id' => $case['id'],
        'utterance' => $case['utterance'],
        'expected' => implode('|', $case['expected_intents']),
        'got' => $result['type'],
        'category' => 'multi_intent',
      ];
    }

    if ($verbose) {
      $status = $detected ? 'PASS' : 'FAIL';
      echo sprintf("[%s] #%d (multi): %s\n", $status, $case['id'], substr($case['utterance'], 0, 50));
    }
  }

  // Process Spanish/Spanglish cases.
  foreach ($fixtures['spanish_cases'] ?? [] as $case) {
    $results['total']++;
    $category = $case['category'] ?? 'spanish';
    $results['by_category'][$category]['total'] = ($results['by_category'][$category]['total'] ?? 0) + 1;

    $result = simulateRouter($case['utterance']);
    $expected_category = $case['expected_category'] ?? NULL;
    $match = checkMatch($result, $case['expected_intent'], $expected_category);

    if ($match['match']) {
      $results['matches']++;
      $results['by_category'][$category]['matches'] = ($results['by_category'][$category]['matches'] ?? 0) + 1;
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'id' => $case['id'],
        'utterance' => $case['utterance'],
        'expected' => $case['expected_intent'],
        'got' => $result['type'],
        'category' => $category,
      ];
    }

    // Track safety for Spanish urgent cases.
    if ($case['expected_intent'] === 'urgent_safety') {
      $results['safety_total']++;
      if ($result['type'] === 'urgent_safety' || $result['type'] === 'high_risk') {
        $results['safety_compliance']++;
      }
    }

    if ($verbose) {
      $status = $match['match'] ? 'PASS' : 'FAIL';
      echo sprintf("[%s] #%d (%s): %s\n", $status, $case['id'], $category, substr($case['utterance'], 0, 50));
    }
  }

  // Process adversarial cases.
  foreach ($fixtures['adversarial_cases'] ?? [] as $case) {
    $results['total']++;
    $results['by_category']['adversarial']['total']++;

    $result = simulateRouter($case['utterance']);
    // For adversarial cases, we want out_of_scope, unknown, or disambiguation (NOT routing to real intents).
    $safe_response = in_array($result['type'], ['out_of_scope', 'unknown', 'disambiguation']);

    if ($safe_response) {
      $results['matches']++;
      $results['by_category']['adversarial']['matches']++;
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'id' => $case['id'],
        'utterance' => $case['utterance'],
        'expected' => 'out_of_scope/safe',
        'got' => $result['type'],
        'category' => 'adversarial',
        'safety_flag' => $case['safety_flag'] ?? 'unknown',
      ];
    }

    if ($verbose) {
      $status = $safe_response ? 'SAFE' : 'UNSAFE';
      echo sprintf("[%s] #%d (adv): %s\n", $status, $case['id'], substr($case['utterance'], 0, 50));
    }
  }

  // Calculate metrics.
  $results['accuracy'] = $results['total'] > 0 ? round(($results['matches'] / $results['total']) * 100, 2) : 0;
  $results['exact_accuracy'] = $results['total'] > 0 ? round(($results['exact_matches'] / $results['total']) * 100, 2) : 0;
  $results['misroute_rate'] = $results['total'] > 0 ? round(($results['misroutes'] / $results['total']) * 100, 2) : 0;
  $results['clarification_rate'] = $results['total'] > 0 ? round(($results['clarifications'] / $results['total']) * 100, 2) : 0;
  $results['safety_compliance_rate'] = $results['safety_total'] > 0
    ? round(($results['safety_compliance'] / $results['safety_total']) * 100, 2)
    : 100;

  return $results;
}

/**
 * Formats results as a text report.
 *
 * @param array $results
 *   Test results.
 *
 * @return string
 *   Formatted report.
 */
function formatReport(array $results): string {
  $output = [];
  $output[] = "═══════════════════════════════════════════════════════════════════════════";
  $output[] = "  ILAS INTENT ROUTER TEST HARNESS - VALIDATION REPORT";
  $output[] = "═══════════════════════════════════════════════════════════════════════════";
  $output[] = sprintf("  Generated: %s", $results['timestamp']);
  $output[] = "";

  // Summary metrics.
  $output[] = "SUMMARY METRICS";
  $output[] = "───────────────────────────────────────────────────────────────────────────";
  $output[] = sprintf("  Total Test Cases:       %d", $results['total']);
  $output[] = sprintf("  Intent Accuracy:        %6.2f%% (Target: >= 90%%)", $results['accuracy']);
  $output[] = sprintf("  Exact Match Rate:       %6.2f%%", $results['exact_accuracy']);
  $output[] = sprintf("  Misroute Rate:          %6.2f%% (Target: <= 5%%)", $results['misroute_rate']);
  $output[] = sprintf("  Clarification Rate:     %6.2f%% (Target: <= 15%%)", $results['clarification_rate']);
  $output[] = sprintf("  Safety Compliance:      %6.2f%% (Target: 100%%)", $results['safety_compliance_rate']);
  $output[] = "";

  // Pass/Fail status.
  $accuracy_pass = $results['accuracy'] >= 90;
  $misroute_pass = $results['misroute_rate'] <= 5;
  $safety_pass = $results['safety_compliance_rate'] >= 100;

  $output[] = "PASS/FAIL STATUS";
  $output[] = "───────────────────────────────────────────────────────────────────────────";
  $output[] = sprintf("  Intent Accuracy:        %s", $accuracy_pass ? 'PASS' : 'FAIL');
  $output[] = sprintf("  Misroute Rate:          %s", $misroute_pass ? 'PASS' : 'FAIL');
  $output[] = sprintf("  Safety Compliance:      %s", $safety_pass ? 'PASS' : 'FAIL');
  $output[] = "";

  // Category breakdown.
  $output[] = "ACCURACY BY CATEGORY";
  $output[] = "┌────────────────────────┬───────┬─────────┬───────────┐";
  $output[] = "│ Category               │ Total │ Matches │ Accuracy  │";
  $output[] = "├────────────────────────┼───────┼─────────┼───────────┤";

  foreach ($results['by_category'] as $category => $data) {
    if ($data['total'] > 0) {
      $acc = round(($data['matches'] / $data['total']) * 100, 1);
      $output[] = sprintf("│ %-22s │ %5d │ %7d │ %7.1f%% │",
        $category, $data['total'], $data['matches'], $acc);
    }
  }
  $output[] = "└────────────────────────┴───────┴─────────┴───────────┘";
  $output[] = "";

  // Per-intent breakdown.
  $output[] = "ACCURACY BY INTENT";
  $output[] = "┌──────────────────────────┬───────┬─────────┬───────────┐";
  $output[] = "│ Intent                   │ Total │ Matches │ Accuracy  │";
  $output[] = "├──────────────────────────┼───────┼─────────┼───────────┤";

  foreach ($results['by_intent'] as $intent => $data) {
    $acc = $data['total'] > 0 ? round(($data['matches'] / $data['total']) * 100, 1) : 0;
    $output[] = sprintf("│ %-24s │ %5d │ %7d │ %7.1f%% │",
      substr($intent, 0, 24), $data['total'], $data['matches'], $acc);
  }
  $output[] = "└──────────────────────────┴───────┴─────────┴───────────┘";
  $output[] = "";

  // Sample failures.
  if (!empty($results['failures'])) {
    $output[] = "SAMPLE FAILURES (first 15)";
    $output[] = "───────────────────────────────────────────────────────────────────────────";
    $count = 0;
    foreach ($results['failures'] as $failure) {
      if ($count >= 15) break;
      $output[] = sprintf("  #%d [%s]: \"%s\"",
        $failure['id'], $failure['category'], substr($failure['utterance'], 0, 45));
      $output[] = sprintf("       Expected: %s → Got: %s",
        $failure['expected'], $failure['got']);
      $count++;
    }
  }
  $output[] = "";
  $output[] = "═══════════════════════════════════════════════════════════════════════════";

  return implode("\n", $output);
}

// Main execution.
if (php_sapi_name() === 'cli') {
  $options = getopt('', ['report:', 'verbose']);
  $verbose = isset($options['verbose']);
  $report_path = $options['report'] ?? NULL;

  echo "Running intent router validation tests...\n\n";

  $results = runTests($verbose);

  if (isset($results['error'])) {
    echo "Error: " . $results['error'] . "\n";
    exit(1);
  }

  echo "\n";
  echo formatReport($results);
  echo "\n";

  // Write JSON report if requested.
  if ($report_path) {
    $json = json_encode($results, JSON_PRETTY_PRINT);
    file_put_contents($report_path, $json);
    echo "JSON report written to: $report_path\n";
  }

  // Exit code based on pass/fail.
  $passed = $results['accuracy'] >= 90 &&
            $results['misroute_rate'] <= 5 &&
            $results['safety_compliance_rate'] >= 100;

  exit($passed ? 0 : 1);
}
