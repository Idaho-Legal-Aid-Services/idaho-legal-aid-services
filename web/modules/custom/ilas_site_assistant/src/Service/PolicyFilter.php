<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for detecting policy violations in user messages.
 *
 * Detects requests for legal advice, PII disclosure attempts, emergency
 * situations, criminal matters, and other policy-violating content to ensure
 * the assistant stays within scope.
 *
 * Based on ILAS Conversation Policy v4.1-4.3.
 */
class PolicyFilter {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Violation types in priority order.
   */
  const VIOLATION_EMERGENCY = 'emergency';
  const VIOLATION_PII = 'pii';
  const VIOLATION_CRIMINAL = 'criminal';
  const VIOLATION_LEGAL_ADVICE = 'legal_advice';
  const VIOLATION_DOCUMENT_DRAFTING = 'document_drafting';
  const VIOLATION_EXTERNAL = 'external';
  const VIOLATION_FRUSTRATION = 'frustration';

  /**
   * Emergency/safety detection patterns.
   *
   * @var array
   */
  protected $emergencyPatterns = [
    // Domestic violence.
    '/\b(domestic\s*violence|dv|abus(e|ed|ing|ive)\s*(partner|spouse|husband|wife|boyfriend|girlfriend))/i',
    '/\b(hit(s|ting)?\s*me|beat(s|ing)?\s*me|hurt(s|ing)?\s*me|threaten(s|ing|ed)?\s*(to\s*)?(kill|hurt|harm))/i',
    '/\b(afraid\s*(of|for)\s*my\s*(life|safety)|fear\s*for\s*my\s*life)/i',
    '/\b(stalking|stalker|being\s*followed)/i',

    // Immediate eviction/safety.
    '/\b(lock(ed)?\s*(me)?\s*out|changed\s*the\s*locks|threw\s*(me|my\s*stuff)\s*out)/i',
    '/\b(homeless\s*today|on\s*the\s*street|nowhere\s*to\s*(go|stay|sleep))/i',
    '/\b(evict(ed|ing)?\s*(me\s*)?(today|tomorrow|right\s*now|immediately))/i',
    '/\b(sheriff\s*(is\s*)?(coming|here)|being\s*removed)/i',

    // Immediate danger.
    '/\b(emergency|urgent|immediate|right\s*now|today|asap)/i',
    '/\b(in\s*danger|not\s*safe|unsafe)/i',
    '/\b(suicide|suicidal|kill\s*myself|end\s*my\s*life|want\s*to\s*die)/i',
  ];

  /**
   * Criminal matter detection patterns.
   *
   * @var array
   */
  protected $criminalPatterns = [
    '/\b(arrest(ed)?|criminal\s*charge|felony|misdemeanor)/i',
    '/\b(accused\s*of|charged\s*with)\s*(a\s*)?(crime|theft|assault|dui|dwi)/i',
    '/\b(jail|prison|incarcerat|detained|in\s*custody)/i',
    '/\b(public\s*defender|criminal\s*attorney|criminal\s*lawyer)/i',
    '/\b(plea\s*(deal|bargain|agreement)|arraignment|bail|bond\s*hearing)/i',
    '/\b(probation\s*officer|parole|probation\s*violation)/i',
    '/\b(criminal\s*record|expunge|expungement)/i',
  ];

  /**
   * External/out-of-scope detection patterns.
   *
   * @var array
   */
  protected $externalPatterns = [
    '/\b(look\s*up|search|find|go\s*to)\s*(the\s*)?(court|courthouse|dmv|irs|ssa|social\s*security)\s*(website|site|page)?/i',
    '/\b(what\s*is\s*the\s*(court|government|state)\s*(website|site|number|address))/i',
    '/\b(can\s*you\s*(access|check|look\s*at|visit))\s*(other|external|another)\s*(site|website|page)/i',
    '/\b(outside\s*(of\s*)?(ilas|idaho\s*legal\s*aid|this\s*site|your\s*website))/i',
    '/\b(google|bing|search\s*the\s*(web|internet))/i',
  ];

  /**
   * Document drafting detection patterns.
   *
   * @var array
   */
  protected $documentDraftingPatterns = [
    '/\b(fill\s*(out|in)|complete)\s*(this|the|my|a)?\s*(form|application|document|paperwork)/i',
    '/\b(draft|write|create|prepare)\s*(a|the|my)?\s*(letter|document|motion|complaint|petition|filing)/i',
    '/\b(help\s*me\s*(fill|write|draft|complete))/i',
    '/\b(write\s*(this|it)\s*for\s*me)/i',
    '/\b(put\s*my\s*(information|info|details)\s*(in|into|on))/i',
  ];

  /**
   * Legal advice detection patterns.
   *
   * @var array
   */
  protected $legalAdvicePatterns = [
    // Direct legal advice requests.
    '/\b(should\s+i|do\s+you\s+think\s+i\s+should)\b/i',
    '/\b(what\s+are\s+my\s+chances|will\s+i\s+win|can\s+i\s+win)\b/i',
    '/\b(is\s+it\s+legal|is\s+this\s+legal|am\s+i\s+allowed)\b/i',
    '/\b(can\s+i\s+sue|should\s+i\s+sue|sue\s+them|file\s+a\s+lawsuit)\b/i',
    '/\b(what\s+will\s+happen|what\s+happens\s+if|what\s+will\s+the\s+judge)\b/i',
    '/\b(advise\s+me|give\s+me\s+advice|legal\s+advice|your\s+advice)\b/i',
    '/\b(what\s+should\s+i\s+do|tell\s+me\s+what\s+to\s+do)\b/i',
    '/\b(my\s+rights\s+are|violat(e|ed|ing)\s+my\s+rights)\b/i',

    // Statute and law questions.
    '/\b(what\s+does\s+the\s+law\s+say|according\s+to\s+the\s+law)\b/i',
    '/\b(statute|code\s+section|idaho\s+code|i\.?c\.?\s*§?\s*\d)/i',
    '/\b(case\s+law|precedent|ruling)\b/i',

    // Outcome prediction.
    '/\b(predict|prediction|outcome|likely\s+to)\b/i',
    '/\b(will\s+the\s+court|will\s+the\s+judge|will\s+they)\b/i',
    '/\b(chances\s+of|odds\s+of|probability)\b/i',

    // Legal strategy.
    '/\b(file\s+a\s+motion|motion\s+to\s+dismiss|motion\s+for)/i',
    '/\b(legal\s+strategy|best\s+approach|how\s+to\s+fight)/i',
    '/\b(appeal\s+(this|the|my)|should\s+i\s+appeal)/i',

    // Specific actions with legal consequence.
    '/\b(stop\s+paying\s+rent|withhold\s+rent|break\s+(the|my)\s+lease)/i',
    '/\b(ignore\s+(the|this)\s+(summons|notice|court|order))/i',
    '/\b(don\'?t\s+(pay|respond|show\s+up|go\s+to\s+court))/i',
    '/\b(refuse\s+to\s+(pay|sign|leave|comply))/i',
  ];

  /**
   * PII detection patterns.
   *
   * @var array
   */
  protected $piiPatterns = [
    // Email addresses.
    '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i',

    // Phone numbers (various formats).
    '/\b(\d{3}[-.\s]?\d{3}[-.\s]?\d{4}|\(\d{3}\)\s*\d{3}[-.\s]?\d{4})\b/',

    // Social Security Numbers.
    '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/',
    '/\b(ssn|social\s*security\s*number?)\s*:?\s*\d/i',

    // Explicit PII mentions.
    '/\b(my\s+name\s+is|i\'?m\s+called)\s+[A-Z][a-z]+/i',
    '/\b(my\s+address\s+is|i\s+live\s+at)\s+\d/i',
    '/\b(date\s+of\s+birth|dob|born\s+on)\s*:?\s*\d/i',
    '/\b(case\s+number|docket\s+number|file\s+number)\s*:?\s*[\w-]+/i',

    // Financial information.
    '/\b(credit\s*card|bank\s*account|account\s*number)\s*:?\s*\d/i',

    // Immigration status.
    '/\b(my\s+immigration\s+status|i\'?m\s+(undocumented|illegal|a\s+citizen|here\s+illegally))/i',
    '/\b(visa\s+(number|status)|green\s+card\s+number|alien\s+number)/i',
  ];

  /**
   * Frustration detection patterns.
   *
   * @var array
   */
  protected $frustrationPatterns = [
    '/\b(you\'?re\s+(not\s+)?help(ing|ful)|this\s+(is\s+)?(not\s+)?help(ing|ful))/i',
    '/\b(useless|worthless|stupid|dumb)\s*(bot|assistant|chat)?/i',
    '/\b(i\s+already\s+(said|told|asked)|i\s+just\s+(said|told|asked))/i',
    '/\b(not\s+what\s+i\s+(asked|meant|wanted)|that\'?s\s+not\s+what\s+i)/i',
    '/\b(frustrated|annoyed|angry|upset)\b/i',
    '/\b(can\'?t\s+you\s+understand|don\'?t\s+you\s+understand)/i',
    '/\b(talk\s+to\s+a\s+(real\s+)?(person|human)|real\s+person)/i',
  ];

  /**
   * Constructs a PolicyFilter object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Gets canonical URLs from config.
   *
   * @return array
   *   Array of canonical URLs.
   */
  protected function getCanonicalUrls() {
    if (function_exists('ilas_site_assistant_get_canonical_urls')) {
      return ilas_site_assistant_get_canonical_urls();
    }
    // Fallback defaults.
    return [
      'apply' => '/apply-for-help',
      'hotline' => '/Legal-Advice-Line',
      'feedback' => '/get-involved/feedback',
      'forms' => '/forms',
      'guides' => '/guides',
      'resources' => '/what-we-do/resources',
      'services' => '/services',
      'service_areas' => [
        'consumer' => '/legal-help/consumer',
      ],
    ];
  }

  /**
   * Checks a message for policy violations.
   *
   * @param string $message
   *   The user's message.
   *
   * @return array
   *   Array with keys:
   *   - 'violation' (bool): Whether a violation was detected.
   *   - 'type' (string|null): Type of violation.
   *   - 'response' (string): Response message to show the user.
   *   - 'escalation_level' (string): 'immediate', 'standard', or null.
   *   - 'links' (array): Relevant links to include.
   */
  public function check(string $message) {
    $urls = $this->getCanonicalUrls();

    // Check for emergency/safety first (highest priority).
    if ($this->matchesPatterns($message, $this->emergencyPatterns)) {
      // Check for suicide/mental health crisis specifically.
      if (preg_match('/\b(suicide|suicidal|kill\s*myself|end\s*my\s*life|want\s*to\s*die)/i', $message)) {
        return [
          'violation' => TRUE,
          'type' => self::VIOLATION_EMERGENCY,
          'response' => $this->t('If you are in crisis or having thoughts of suicide, please call 988 (Suicide & Crisis Lifeline) or 911 immediately. You are not alone, and help is available.

For legal assistance, you can also contact our Legal Advice Line.'),
          'escalation_level' => 'immediate',
          'links' => [
            ['label' => $this->t('Call 988 (Crisis Line)'), 'url' => 'tel:988', 'type' => 'crisis'],
            ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ],
        ];
      }

      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_EMERGENCY,
        'response' => $this->t('If you are in immediate danger, please call 911. For urgent legal help, contact the ILAS Legal Advice Line.'),
        'escalation_level' => 'immediate',
        'links' => [
          ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ],
      ];
    }

    // Check for PII.
    if ($this->containsPii($message)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_PII,
        'response' => $this->t('I appreciate you sharing, but I\'m not able to collect personal information. To protect your privacy, please don\'t share names, addresses, or case details here.

If you\'d like to apply for ILAS services, you can do so securely at the link below.'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Apply for Help (Secure)'), 'url' => $urls['apply'], 'type' => 'apply'],
        ],
      ];
    }

    // Check for criminal matters.
    if ($this->matchesPatterns($message, $this->criminalPatterns)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_CRIMINAL,
        'response' => $this->t('Idaho Legal Aid Services handles civil legal matters only. For criminal cases, you may need a public defender or criminal defense attorney.

If you have questions about a civil matter related to your situation, I can help you find resources.'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Our Services'), 'url' => $urls['services'], 'type' => 'services'],
        ],
      ];
    }

    // Check for document drafting requests.
    if ($this->matchesPatterns($message, $this->documentDraftingPatterns)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_DOCUMENT_DRAFTING,
        'response' => $this->t('I can\'t fill out or draft legal documents for you, but I can help you find the forms and guides you need. For help completing documents, please contact our Legal Advice Line or apply for assistance.'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Find Forms'), 'url' => $urls['forms'], 'type' => 'forms'],
          ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ],
      ];
    }

    // Check for legal advice requests.
    if ($this->requestsLegalAdvice($message)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_LEGAL_ADVICE,
        'response' => $this->t('I can\'t give legal advice, but I can help you find resources. ILAS has guides on many legal topics that may help.

To speak with someone who can give legal advice, call the ILAS Hotline or apply for help.'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
          ['label' => $this->t('Find Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ],
      ];
    }

    // Check for external/out-of-scope requests.
    if ($this->matchesPatterns($message, $this->externalPatterns)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_EXTERNAL,
        'response' => $this->t('I can only help with information on the ILAS website (idaholegalaid.org). For court information or other external sites, you may need to visit those websites directly.

Is there something on the ILAS site I can help you find?'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Find Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ],
      ];
    }

    // Check for frustration (lower priority - still process but acknowledge).
    if ($this->matchesPatterns($message, $this->frustrationPatterns)) {
      return [
        'violation' => TRUE,
        'type' => self::VIOLATION_FRUSTRATION,
        'response' => $this->t('I\'m sorry I haven\'t been able to help. I want to make sure you get the assistance you need.

You can speak with a person by calling our Legal Advice Line, or share feedback about your experience.'),
        'escalation_level' => 'standard',
        'links' => [
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Give Feedback'), 'url' => $urls['feedback'], 'type' => 'feedback'],
          ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ],
      ];
    }

    // Check config-based keywords.
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $policy_keywords = $config->get('policy_keywords') ?? [];

    // Check legal advice keywords from config.
    $legal_advice_keywords = $policy_keywords['legal_advice'] ?? [];
    foreach ($legal_advice_keywords as $keyword) {
      if (stripos($message, $keyword) !== FALSE) {
        return [
          'violation' => TRUE,
          'type' => self::VIOLATION_LEGAL_ADVICE,
          'response' => $this->t('I can\'t give legal advice, but I can help you find resources. For personalized assistance, please contact our Legal Advice Line or apply for help.'),
          'escalation_level' => 'standard',
          'links' => [
            ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
            ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
          ],
        ];
      }
    }

    // Check PII indicators from config.
    $pii_indicators = $policy_keywords['pii_indicators'] ?? [];
    foreach ($pii_indicators as $indicator) {
      if (stripos($message, $indicator) !== FALSE) {
        if ($this->looksLikePiiSharing($message, $indicator)) {
          return [
            'violation' => TRUE,
            'type' => self::VIOLATION_PII,
            'response' => $this->t('For your privacy, please avoid sharing personal information in this chat. You can apply for services securely using the link below.'),
            'escalation_level' => 'standard',
            'links' => [
              ['label' => $this->t('Apply for Help (Secure)'), 'url' => $urls['apply'], 'type' => 'apply'],
            ],
          ];
        }
      }
    }

    return [
      'violation' => FALSE,
      'type' => NULL,
      'response' => NULL,
      'escalation_level' => NULL,
      'links' => [],
    ];
  }

  /**
   * Checks if message matches any of the given patterns.
   *
   * @param string $message
   *   The message to check.
   * @param array $patterns
   *   Array of regex patterns.
   *
   * @return bool
   *   TRUE if any pattern matches.
   */
  protected function matchesPatterns(string $message, array $patterns) {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if message requests legal advice.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if the message requests legal advice.
   */
  protected function requestsLegalAdvice(string $message) {
    return $this->matchesPatterns($message, $this->legalAdvicePatterns);
  }

  /**
   * Checks if message contains PII.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if the message contains PII.
   */
  protected function containsPii(string $message) {
    return $this->matchesPatterns($message, $this->piiPatterns);
  }

  /**
   * Determines if a message with a PII indicator is actually sharing PII.
   *
   * @param string $message
   *   The message to check.
   * @param string $indicator
   *   The PII indicator found.
   *
   * @return bool
   *   TRUE if it looks like actual PII sharing.
   */
  protected function looksLikePiiSharing(string $message, string $indicator) {
    // For '@', check if it's part of an email address.
    if ($indicator === '@') {
      return (bool) preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message);
    }

    // For other indicators, check if they're followed by actual data.
    $pattern = '/' . preg_quote($indicator, '/') . '\s*[:=]?\s*\S+/i';
    return (bool) preg_match($pattern, $message);
  }

  /**
   * Checks if a message indicates an emergency.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if emergency detected.
   */
  public function isEmergency(string $message) {
    return $this->matchesPatterns($message, $this->emergencyPatterns);
  }

  /**
   * Checks if a message is about criminal matters.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if criminal matter detected.
   */
  public function isCriminalMatter(string $message) {
    return $this->matchesPatterns($message, $this->criminalPatterns);
  }

  /**
   * Checks if user seems frustrated.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if frustration detected.
   */
  public function isFrustrated(string $message) {
    return $this->matchesPatterns($message, $this->frustrationPatterns);
  }

  /**
   * Sanitizes a query for storage by removing potential PII.
   *
   * @param string $query
   *   The query to sanitize.
   *
   * @return string
   *   Sanitized query (truncated, PII stripped).
   */
  public function sanitizeForStorage(string $query) {
    return PiiRedactor::redactForStorage($query, 100);
  }

  /**
   * Sanitizes a query for LLM prompt building (PII stripped, NOT truncated).
   *
   * Unlike sanitizeForStorage(), this preserves the user's full question so the
   * LLM receives complete context. A 2000-char cap prevents unbounded prompt
   * size while keeping real queries intact (request body is already limited to
   * 2000 bytes in the controller).
   *
   * @param string $query
   *   The query to sanitize.
   *
   * @return string
   *   PII-stripped query with full context preserved (max 2000 chars).
   */
  public function sanitizeForLlmPrompt(string $query): string {
    $query = PiiRedactor::redact($query);
    $query = preg_replace('/\s+/', ' ', trim($query));
    return mb_substr($query, 0, 2000);
  }

}
