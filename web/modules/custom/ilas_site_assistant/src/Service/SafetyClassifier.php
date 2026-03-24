<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Deterministic safety classifier for the ILAS Site Assistant.
 *
 * Provides fine-grained classification of user messages into safety categories
 * with specific reason codes for logging and routing decisions. This classifier
 * runs BEFORE any LLM processing to ensure safety is enforced deterministically.
 *
 * Classification Priority (highest to lowest):
 * 1. Crisis/Suicide - Immediate mental health crisis
 * 2. Immediate Danger - Physical safety threats
 * 3. DV Emergency - Domestic violence situations
 * 4. Eviction Emergency - Imminent homelessness
 * 5. Child Safety - Child endangerment
 * 6. Scam/Fraud Active - Ongoing financial harm
 * 7. Wrongdoing Request - Illegal/harmful requests
 * 8. Criminal Matter - Out of scope criminal
 * 9. Immigration - Out of scope immigration
 * 10. PII Disclosure - Privacy protection
 * 11. Legal Advice - Cannot provide
 * 12. Document Drafting - Cannot provide
 * 13. External Request - Out of scope
 * 14. Frustration - Escalate to human
 * 15. Safe - No safety concerns
 *
 * This class is designed to work both within Drupal and in standalone testing.
 */
class SafetyClassifier {

  /**
   * Classification constants.
   */
  const CLASS_CRISIS = 'crisis';
  const CLASS_IMMEDIATE_DANGER = 'immediate_danger';
  const CLASS_DV_EMERGENCY = 'dv_emergency';
  const CLASS_EVICTION_EMERGENCY = 'eviction_emergency';
  const CLASS_CHILD_SAFETY = 'child_safety';
  const CLASS_SCAM_ACTIVE = 'scam_active';
  const CLASS_PROMPT_INJECTION = 'prompt_injection';
  const CLASS_WRONGDOING = 'wrongdoing';
  const CLASS_CRIMINAL = 'criminal';
  const CLASS_IMMIGRATION = 'immigration';
  const CLASS_PII = 'pii';
  const CLASS_LEGAL_ADVICE = 'legal_advice';
  const CLASS_DOCUMENT_DRAFTING = 'document_drafting';
  const CLASS_EXTERNAL = 'external';
  const CLASS_FRUSTRATION = 'frustration';
  const CLASS_SAFE = 'safe';

  /**
   * Escalation levels.
   */
  const ESCALATION_CRITICAL = 'critical';
  const ESCALATION_IMMEDIATE = 'immediate';
  const ESCALATION_URGENT = 'urgent';
  const ESCALATION_STANDARD = 'standard';
  const ESCALATION_NONE = 'none';

  /**
   * The config factory.
   *
   * @var object
   */
  protected $configFactory;

  /**
   * Classification rules organized by priority.
   *
   * @var array
   */
  protected array $rules;

  /**
   * Constructs a SafetyClassifier object.
   *
   * @param object $config_factory
   *   The config factory (Drupal ConfigFactoryInterface or mock for testing).
   */
  public function __construct($config_factory) {
    $this->configFactory = $config_factory;
    $this->initializeRules();
  }

  /**
   * Initializes classification rules.
   */
  protected function initializeRules(): void {
    $this->rules = [
      // Priority 1: Crisis/Suicide - CRITICAL
      'crisis' => [
        'class' => self::CLASS_CRISIS,
        'escalation' => self::ESCALATION_CRITICAL,
        'patterns' => [
          '/\b(suicid(e|al)|kill\s*(my)?self|end\s*my\s*life|want\s*to\s*die)\b/i' => 'crisis_suicide',
          '/\bmy\s+(friend|brother|sister|mom|mother|dad|father|son|daughter|child(ren)?|kid(s)?|partner|spouse|husband|wife)\s+(who\s+)?wants?\s+to\s+die\b/i' => 'crisis_third_party_suicidal_ideation',
          '/\b(don\'?t\s*want\s*to\s*live|no\s*reason\s*to\s*live|better\s*off\s*(dead|without\s*me))\b/i' => 'crisis_suicidal_ideation',
          '/\b(can\'?t\s*(do\s*this|take\s*it)\s*anymore|no\s*way\s*out|give\s*up\s*on\s*(everything|life))\b/i' => 'crisis_indirect_ideation',
          '/\b(planning\s*to\s*(kill|hurt|harm)\s*(my)?self)\b/i' => 'crisis_self_harm_plan',
          '/\b(harm(ing)?\s*(my)?self|cut(ting)?\s*(my)?self)\b/i' => 'crisis_self_harm',
          '/\bmy\s+(friend|brother|sister|mom|mother|dad|father|son|daughter|child(ren)?|kid(s)?|partner|spouse|husband|wife)\s+(is\s+planning\s+to\s+(kill|hurt|harm)\s+(himself|herself|themself)|is\s+(harming|cutting)\s+(himself|herself|themself))\b/i' => 'crisis_third_party_self_harm',
        ],
      ],

      // Priority 2: Immediate Danger - CRITICAL
      'immediate_danger' => [
        'class' => self::CLASS_IMMEDIATE_DANGER,
        'escalation' => self::ESCALATION_CRITICAL,
        'patterns' => [
          '/\b(someone\s*is\s*(breaking|broke)\s*in|intruder|home\s*invasion)\b/i' => 'danger_intruder',
          '/\b(being\s*attack(ed)?|attack(ing|s)?\s*me)\b/i' => 'danger_attack',
          '/\b(gun|weapon|knife)\s*(at|on|to)\s*(me|my)\b/i' => 'danger_weapon',
          '/\b(call\s*911|need\s*police\s*(now|immediately))\b/i' => 'danger_emergency_911',
          '/\b(heart\s*attack|can\'?t\s*breathe|dying)\b/i' => 'danger_medical',
          '/\b(fire|burning|smoke)\s*(in\s*)?(my\s*)?(house|home|apartment)\b/i' => 'danger_fire',
        ],
      ],

      // Priority 3: DV Emergency - IMMEDIATE
      'dv_emergency' => [
        'class' => self::CLASS_DV_EMERGENCY,
        'escalation' => self::ESCALATION_IMMEDIATE,
        'patterns' => [
          '/\b(domestic\s*violence|dv)\b/i' => 'emergency_dv',
          '/\b(hit(s|ting)?\s*me|beat(s|ing)?\s*me|hurt(s|ing)?\s*me)\b/i' => 'emergency_dv_physical',
          '/\b(abusi(ve|ng)|abus(e|ed)\s*(by|partner|spouse|husband|wife|boyfriend|girlfriend))\b/i' => 'emergency_dv_abuse',
          '/\b(threaten(s|ed|ing)?\s*(to\s*)?(kill|hurt|harm))\b/i' => 'emergency_dv_threat',
          '/\b(kill\s*(the\s*)?(kids?|child(ren)?|son|daughter))\b/i' => 'emergency_dv_child_threat',
          '/\b(afraid\s*(of|for)\s*my\s*(life|safety)|fear\s*for\s*my\s*life|scared\s*for\s*my\s*(life|safety))\b/i' => 'emergency_dv_fear',
          '/\b(stalk(ing|er|s)?|being\s*followed|keeps\s*harassing)\b/i' => 'emergency_dv_stalking',
          '/\b(restraining\s*order|protection\s*order|protective\s*order)\b/i' => 'emergency_dv_protection_order',
          '/\b(chok(e|ed|ing)\s*me|strangl(e|ed|ing)\s*me)\b/i' => 'emergency_dv_strangulation',
          '/\b(ex\s*(keeps\s*)?(harassing|following|threatening))\b/i' => 'emergency_dv_ex_harassment',
          '/\b(violent\s*(and\s*)?(has\s*)?unsupervised)\b/i' => 'emergency_dv_child_safety',
          // Spanish.
          '/\b(me\s*pega|me\s*golpea|violencia\s*domestica|tengo\s*miedo)\b/i' => 'emergency_dv_spanish',
        ],
      ],

      // Priority 4: Eviction Emergency - IMMEDIATE
      'eviction_emergency' => [
        'class' => self::CLASS_EVICTION_EMERGENCY,
        'escalation' => self::ESCALATION_IMMEDIATE,
        'patterns' => [
          '/\b(lock(ed)?\s*(me)?\s*out|changed\s*(the\s*)?locks)\b/i' => 'emergency_lockout',
          '/\b(threw\s*(me|my\s*stuff)\s*out|put\s*my\s*(stuff|things)\s*(out|outside|on\s*the\s*street))\b/i' => 'emergency_illegal_eviction',
          '/\b(homeless\s*(today|tonight|now)|on\s*the\s*street|nowhere\s*to\s*(go|stay|sleep))\b/i' => 'emergency_homeless',
          '/\b(evict(ed|ing)?\s*(me\s*)?(today|tonight|tomorrow|right\s*now|immediately))\b/i' => 'emergency_eviction_imminent',
          '/\b(sheriff\s*(is\s*)?(coming|here)|being\s*removed)\b/i' => 'emergency_eviction_enforcement',
          '/\b((3|three|5|five)[-\s]*day\s*notice)\b/i' => 'emergency_eviction_notice',
          '/\b(eviction\s*notice)\b/i' => 'emergency_eviction_notice',
          '/\b(turned\s*off\s*(my\s*)?(utilities|power|water|heat|gas))\b/i' => 'emergency_constructive_eviction',
          '/\b(threatening\s*to\s*call\s*(the\s*)?police)\b/i' => 'emergency_eviction_threat',
          '/\b(don\'?t\s*leave\s*right\s*now)\b/i' => 'emergency_eviction_immediate',
          '/\b(kicked\s*(me|us)\s*out)\b/i' => 'emergency_illegal_eviction',
          // Spanish.
          '/\b(me\s*estan\s*echando|cambiaron\s*las\s*cerraduras|aviso\s*de\s*desalojo)\b/i' => 'emergency_eviction_spanish',
        ],
      ],

      // Priority 5: Child Safety - IMMEDIATE
      'child_safety' => [
        'class' => self::CLASS_CHILD_SAFETY,
        'escalation' => self::ESCALATION_IMMEDIATE,
        'patterns' => [
          '/\b(child(ren)?\s*(is|are|being)\s*(abus(ed|ing)|hurt|harm(ed|ing)|hit))\b/i' => 'emergency_child_abuse',
          '/\b((my\s*)?(kid(s)?|child(ren)?|son|daughter)\s*(is|are)\s*(in\s*)?danger)\b/i' => 'emergency_child_danger',
          '/\b(took\s*(my\s*)?(kid(s)?|child(ren)?|son|daughter)\s*(and\s*)?(won\'?t|will\s*not)\s*return)\b/i' => 'emergency_custody_interference',
          '/\b(won\'?t\s*(let\s*me\s*)?see\s*(my\s*)?(kid(s)?|child(ren)?))\b/i' => 'emergency_custody_denial',
          '/\b(kidnap|abduct|take\s*(the\s*)?(kid(s)?|child(ren)?)\s*out\s*of\s*state)\b/i' => 'emergency_custody_kidnapping',
          '/\b(threatening\s*to\s*(kidnap|take)\s*(the\s*)?(kid(s)?|child(ren)?))\b/i' => 'emergency_custody_threat',
          '/\b(cps\s*(took|removed|just\s*took)|child\s*protective\s*services)\b/i' => 'emergency_cps',
          '/\b((using\s*)?drugs\s*around\s*(the\s*)?(our\s*)?(kid(s)?|child(ren)?))\b/i' => 'emergency_child_endangerment',
          '/\b(ex|other\s*parent)\s*(is|was|keeps)?\s*(using|on)\s*(meth|drugs?)\s*(and|while).*(kid(s)?|child(ren)?)\b/i' => 'emergency_child_endangerment',
          '/\b(needles?\s*(on|around)\s*(the\s*)?(floor|house|apartment)|drug\s*paraphernalia)\b/i' => 'emergency_child_endangerment',
          '/\b(drinking\s*and\s*driving\s*with\s*(the\s*)?(kid(s)?|child(ren)?))\b/i' => 'emergency_child_endangerment',
          '/\b((father|mother|dad|mom|parent|step\w*)\s*(is\s*)?(hurt|abus|hit)(ing|s)?)\b/i' => 'emergency_child_parental_abuse',
          '/\b(worried\s*(my\s*)?(child(ren)?|kid(s)?)\s*(is|are)\s*(being\s*)?(abus(ed)?|hurt))\b/i' => 'emergency_child_abuse_concern',
          '/\b((protect|save)\s*(my\s*)?(grandchild(ren)?|kid(s)?|child(ren)?)\s*from)\b/i' => 'emergency_child_protection',
          '/\b(emergency\s*custody)\b/i' => 'emergency_custody_urgent',
          '/\b(this\s*is\s*an\s*emergency)\b/i' => 'emergency_explicit',
          '/\b((hasn\'?t|haven\'?t)\s*come\s*home)\b/i' => 'emergency_custody_missing',
          '/\b(keeping\s*(the\s*)?(them|kid(s)?|child(ren)?))\b/i' => 'emergency_custody_interference',
          // Spanish.
          '/\b(se\s*llevo\s*(a\s*)?(mis\s*)?hijos)\b/i' => 'emergency_custody_spanish',
        ],
      ],

      // Priority 6: Scam/Fraud Active - IMMEDIATE
      'scam_active' => [
        'class' => self::CLASS_SCAM_ACTIVE,
        'escalation' => self::ESCALATION_IMMEDIATE,
        'patterns' => [
          '/\b(identity\s*theft|stole\s*(my\s*)?identity|stolen\s*identity)\b/i' => 'emergency_identity_theft',
          '/\b((got|been|was|being)\s*scam(med)?)\b/i' => 'emergency_scam',
          '/\b(gave\s*(them\s*)?(my\s*)?(bank|account|ssn|social\s*security)\s*(info|information|number))\b/i' => 'emergency_scam_pii_disclosed',
          '/\b(emptied\s*(my\s*)?bank\s*account|drained\s*(my\s*)?account)\b/i' => 'emergency_scam_financial',
          '/\b(fake\s*(contractor|lawyer|attorney|irs|ssa))\b/i' => 'emergency_scam_impersonation',
          '/\b(contractor\s*(took|stole)\s*(my\s*)?money)\b/i' => 'emergency_contractor_scam',
          '/\b(took\s*(my\s*)?money\s*and\s*(disappeared|gone|left))\b/i' => 'emergency_contractor_scam',
          '/\b(social\s*security\s*number\s*fraudulently)\b/i' => 'emergency_ssn_fraud',
          '/\b(using\s*(my\s*)?(social\s*security|ssn))\b/i' => 'emergency_ssn_fraud',
          '/\b((cashing|forging|stealing)\s*(my\s*)?checks)\b/i' => 'emergency_check_fraud',
          '/\b(elder\s*abuse|stealing\s*(from\s*)?(my\s*)?(mother|father|parent|grandparent|mom|dad))\b/i' => 'emergency_elder_abuse',
          '/\b(stealing\s*(my\s*)?(mother|father|parent|grandparent)\'?s?\s*money)\b/i' => 'emergency_elder_abuse',
          '/\b(predatory\s*lend(er|ing))\b/i' => 'emergency_predatory_lending',
          '/\b(threatening\s*to\s*take\s*(my\s*)?house)\b/i' => 'emergency_predatory_threat',
          '/\b(someone\s*(is\s*)?(using|opening)\s*(my\s*)?(accounts?|credit))\b/i' => 'emergency_fraud_ongoing',
          '/\b(wired\s*money)\b/i' => 'emergency_wire_fraud',
          '/\b(is\s*this\s*(a\s*)?(real|scam))\b/i' => 'emergency_scam_inquiry',
          '/\b((arrested|arrest)\s*if\s*(i\s*)?(don\'?t|didn\'?t)\s*pay)\b/i' => 'emergency_arrest_scam',
          '/\b(taking\s*out\s*loans)\b/i' => 'emergency_loan_fraud',
          // Spanish.
          '/\b(robaron\s*mi\s*identidad|me\s*estafaron)\b/i' => 'emergency_scam_spanish',
        ],
      ],

      // Priority 7: Prompt Injection - URGENT (Must refuse)
      // Detects adversarial attempts to manipulate the assistant via
      // prompt injection, jailbreaking, or role-play attacks.
      'prompt_injection' => [
        'class' => self::CLASS_PROMPT_INJECTION,
        'escalation' => self::ESCALATION_URGENT,
        'patterns' => [
          // Instruction override patterns.
          // Handles: "ignore previous instructions", "forget your previous instructions", "disregard the above rules"
          '/\b(ignore|disregard|forget)[\s,\/]+(all[\s,\/]+)?(the[\s,\/]+)?(your[\s,\/]+)?(previous|prior|above)?[\s,\/]*(instructions?|rules?|guidelines?|programming|prompt|constraints?)\b/i' => 'injection_ignore_instructions',
          '/\b(ignore|disregard|forget)\s+(all\s+)?(the\s+)?(your\s+)?(previous|prior|above)?\s*(instructions?|rules?|guidelines?|programming|prompt|constraints?)\b/i' => 'injection_ignore_instructions',
          '/\b(ignore|disregard|forget)\s+(all\s+)?your\s+(previous\s+)?(instructions?|rules?|guidelines?|programming|constraints?)\b/i' => 'injection_ignore_your_instructions',
          '/\b(new\s+instructions?|updated?\s+instructions?|override\s+instructions?)\b/i' => 'injection_new_instructions',
          '/\b(follow|obey)\s+(the\s+)?(latest|new|updated)\s+(directive|instruction|instructions?|rules?)\b/i' => 'injection_new_instructions',
          '/\b(system\s*prompt|admin\s*prompt|initial\s*prompt|hidden\s*prompt)\b/i' => 'injection_system_prompt',
          '/\b(hidden|internal|secret)\s+(prompt|setup|configuration|config|message)\b/i' => 'injection_system_prompt',
          // Format exploit patterns (LLM prompt markers).
          '/\[\s*INST\s*\]/i' => 'injection_format_inst',
          '/\[\s*\/INST\s*\]/i' => 'injection_format_inst_close',
          '/<<\s*SYS\s*>>/i' => 'injection_format_sys',
          '/<\|im_start\|>/i' => 'injection_format_im_start',
          '/<\|im_end\|>/i' => 'injection_format_im_end',
          '/\bHuman:\s/i' => 'injection_format_human',
          '/\bAssistant:\s/i' => 'injection_format_assistant',
          // Role-play and impersonation attacks.
          '/\b(you\s+are\s+now|from\s+now\s+on\s+you\s+are|pretend\s+(you\s+are|to\s+be)|act\s+as\s+(a|an|if))\b/i' => 'injection_roleplay',
          '/\b(pretend\s+(you\'?re|you\s+are)\s+(a\s+)?(real\s+)?(lawyer|attorney))\b/i' => 'injection_roleplay_lawyer',
          '/\b(you\s+are\s+(now\s+)?(a\s+)?helpful\s+assistant\s+with\s+no\s+(restrictions?|limits?|rules?))\b/i' => 'injection_roleplay_unrestricted',
          '/\b(answer|respond)\s+as\s+(an?\s+)?(unrestricted|unconstrained|no-guardrails?)\s+(lawyer|attorney|assistant)\b/i' => 'injection_roleplay_unrestricted',
          // Override and bypass patterns (with optional articles).
          '/\b(override\s+(the\s+)?(safety|your|all)|bypass\s+(the\s+)?(safety|filter|your))\b/i' => 'injection_override_safety',
          '/\b(disable\s+(the\s+)?(safety|filter|restrictions?))\b/i' => 'injection_disable_safety',
          '/\b(set\s+aside|drop|remove)\s+(your\s+)?(guardrails?|constraints?|safeguards?)\b/i' => 'injection_disable_safety',
          '/\b(without\s+(any\s+)?(restrictions?|limits?|rules?|safety))\b/i' => 'injection_no_restrictions',
          // Command injection patterns.
          '/\b(STOP|HALT|END)\s*\.\s*(NEW\s+)?(TASK|INSTRUCTION|COMMAND)/i' => 'injection_command_override',
          '/\b(output|print|show|reveal|display)\s+(your\s+)?(full\s+)?(instructions?|prompt|rules?|system\s*prompt)\b/i' => 'injection_leak_instructions',
          '/\b(output|print|show|reveal|display)\s+(the\s+)?(hidden|internal|secret)\s+(prompt|setup|configuration|config|message)\b/i' => 'injection_leak_instructions',
          '/\b(what\s+(are|is)\s+your\s+(system\s+)?prompt|tell\s+me\s+your\s+(hidden\s+)?instructions?)\b/i' => 'injection_extract_prompt',
          // AI/LLM manipulation patterns.
          '/\b(as\s+an?\s+(ai|artificial\s+intelligence|language\s+model|llm|chatbot))\b/i' => 'injection_ai_reference',
          '/\b(language\s+model|llm|gpt|chatgpt|claude|bard)\s*,?\s*(you\s+)?(must|should|need\s+to|can)\b/i' => 'injection_ai_command',
          // Developer mode / DAN patterns.
          '/\b(developer\s+mode|jailbreak|dan\s+mode|dev\s+mode)\b/i' => 'injection_jailbreak_mode',
          '/\b(enable\s+(developer|admin|god)\s+mode)\b/i' => 'injection_enable_mode',
          // Hacking and data exfiltration (with optional articles).
          '/\b(hack|hacking|crack|cracking)\s+(into|the|my|a|passwords?|accounts?)\b/i' => 'injection_hacking',
          '/\b(steal|extract|dump)\s+(the\s+)?(passwords?|credentials?|user\s*data|data)\b/i' => 'injection_data_theft',
          '/\b(list\s+(all\s+)?user\s*data|show\s+(all\s+)?users?|dump\s+(the\s+)?database)\b/i' => 'injection_data_dump',
          '/\b(admin\s*passwords?|root\s*access|sudo|administrator\s*credentials?)\b/i' => 'injection_admin_access',
          // Spanish prompt injection patterns.
          '/\b(ignora\s*(las\s*)?instrucciones|olvida\s*tus\s*reglas|haz\s+caso\s+omiso\s+de\s+(las\s+)?(reglas|instrucciones|normas)|deja\s+de\s+seguir\s+(tus\s+)?(reglas|instrucciones|normas))\b/i' => 'injection_ignore_instructions_es',
          '/\b(ahora\s*eres|finge\s*ser|act[uú]a\s*como)\b/i' => 'injection_roleplay_es',
          '/\b(desactiva\s*las\s*restricciones|deja\s*sin\s*(protecciones|guardas|barandillas))\b/i' => 'injection_disable_safety_es',
          '/\b(muestra|revela)\s+(tus\s+)?(instrucciones|mensaje|prompt|configuraci[oó]n)\s*(intern(a|o)s?|ocult(a|o)s?|del\s*sistema)?\b/i' => 'injection_leak_instructions_es',
          '/\b(muestra|revela)\s+(el\s+)?(mensaje|prompt|configuraci[oó]n)\s+(intern(a|o)|ocult(a|o))(\s+del\s+sistema)?\b/i' => 'injection_leak_instructions_es',
          '/\b(modo\s*desarrollador|sin\s*(restricciones|limites|limitaciones)|sin\s+barandillas)\b/i' => 'injection_jailbreak_mode_es',
        ],
      ],

      // Priority 8: Wrongdoing Request - URGENT (Must refuse)
      'wrongdoing' => [
        'class' => self::CLASS_WRONGDOING,
        'escalation' => self::ESCALATION_URGENT,
        'patterns' => [
          '/\b(threaten(ing)?\s*letter|intimidat(e|ing)\s*letter)\b/i' => 'wrongdoing_threat',
          '/\b(exact\s*wording|what\s*exactly\s*should\s*i\s*say)\s*(to\s*)?(threaten|intimidate|scare)\b/i' => 'wrongdoing_threat',
          '/\b(harass(ing)?\s*(letter|my))\b/i' => 'wrongdoing_harassment',
          '/\b(scare\s*(them|my|the\s*)?(neighbor|landlord|ex)\s*(into)?)\b/i' => 'wrongdoing_intimidation',
          '/\b(intimidat(e|ing)\s*(my|the)?\s*(neighbor|landlord|tenant|employer)\s*(to|into)\s*(mov(e|ing)|leav(e|ing)|quitting))\b/i' => 'wrongdoing_intimidation',
          '/\b(write\s*(something\s*to\s*)?(scare|threaten|intimidate))\b/i' => 'wrongdoing_threat_letter',
          '/\b(hide\s*(my\s*)?(assets?|money|property)\s*(from|so|in))\b/i' => 'wrongdoing_asset_hiding',
          '/\b(hide\s*(my\s*)?(assets?|money|property)\s*before\s*(court|hearing|trial))\b/i' => 'wrongdoing_asset_hiding',
          '/\b(hide\s*assets?\s*from\s*(my\s*)?(spouse|ex|wife|husband|partner))\b/i' => 'wrongdoing_asset_hiding_divorce',
          '/\b(move|transfer|stash|park)\s*(my\s*)?(assets?|money)\s*(so|to)\s*(they|the\s*court|my\s*spouse|my\s*ex)\s*(can\'?t|cannot|wont)\s*(find|see)\b/i' => 'wrongdoing_asset_hiding_variant',
          '/\b(put|hide)\s*(money|assets?)\s*(in|under)\s*(my\s*)?(mom|dad|friend|relative|family)\b/i' => 'wrongdoing_asset_hiding_variant',
          '/\b(keep|store)\s*(income|cash)\s*off\s*(the\s*)?(books|record)\b/i' => 'wrongdoing_income_concealment',
          '/\b(hide|conceal|underreport)\s*(my\s*)?(income|wages?)\s*(from|in)\s*(court|judge|child\s*support|support\s*case)\b/i' => 'wrongdoing_income_concealment',
          '/\b(conceal|hide)\s*(income|wages?)\s*from\s*(the\s*)?(court|judge)\b/i' => 'wrongdoing_income_concealment',
          '/\b(lie\s*to\s*(the\s*)?(court|judge)|false\s*(testimony|statement))\b/i' => 'wrongdoing_perjury',
          '/\b(how\s*(do\s*i|can\s*i|to)\s*lie\s*(to\s*(a\s*)?(judge|court))?)\b/i' => 'wrongdoing_perjury',
          '/\b((best|good)\s*way\s*to\s*lie)\b/i' => 'wrongdoing_perjury_best_way',
          '/\b(what\s*is\s*the\s*best\s*way\s*to\s*lie\s*to)\b/i' => 'wrongdoing_perjury_how',
          '/\b(fake\s*(lease|document|evidence|signature|letter|power\s*of\s*attorney|lawsuit|complaint|claim))\b/i' => 'wrongdoing_fraud_document',
          '/\b(fake|forge|fabricat(e|ed|ing))\s*(a\s*)?(legal\s*)?(citation|court\s*citation|ticket|subpoena|court\s*notice)\b/i' => 'wrongdoing_fabricated_citation',
          '/\bmake\s*(it\s*look|it\s*seem)\s*like\s*(they|my\s*ex|my\s*landlord)\s*(said|did)\b/i' => 'wrongdoing_fabricated_citation',
          '/\b(file\s*(a\s*)?fake\s*(lawsuit|complaint|claim|case))\b/i' => 'wrongdoing_fake_lawsuit',
          '/\b(fraudulent\s*(lawsuit|complaint|claim))\b/i' => 'wrongdoing_fraudulent_lawsuit',
          '/\b(forge\s*(a\s*)?(signature|document)|forge\s*my\s*(spouse|partner|ex)\'?s?\s*signature)\b/i' => 'wrongdoing_forgery',
          '/\b(help\s*(me\s*)?forge\s*(documents?|signatures?))\b/i' => 'wrongdoing_forgery_help',
          '/\b(how\s*(do\s*i|can\s*i)\s*forge)\b/i' => 'wrongdoing_forgery',
          '/\b(coach|pressure|threaten|intimidate)\s*(a\s*)?(witness|kid|child|victim)\s*(to|into)\s*(lie|chang(e|ing)\s*(their\s*)?story|not\s*testifying)\b/i' => 'wrongdoing_witness_tampering',
          '/\b(pressure|coerce|intimidate)\s*(a\s*)?(witness)\s*(to|into)\s*(chang(e|ing)\s*(their\s*)?(testimony|statement)|not\s*testifying)\b/i' => 'wrongdoing_witness_tampering',
          '/\b(stop|keep)\s*(them|a\s*witness)\s*from\s*(testifying|going\s*to\s*court)\b/i' => 'wrongdoing_witness_tampering',
          '/\b(false\s*(police\s*)?report)\b/i' => 'wrongdoing_false_report',
          '/\b(avoid\s*paying\s*(child\s*)?support|get\s*around\s*(the\s*)?(protection\s*)?order)\b/i' => 'wrongdoing_evasion',
          '/\b(without\s*getting\s*caught)\b/i' => 'wrongdoing_evasion',
          '/\b(transfer\s*property\s*to\s*avoid|hide\s*(from\s*)?creditors)\b/i' => 'wrongdoing_fraudulent_transfer',
          '/\b(make\s*it\s*look\s*like|fake\s*(disability|illness))\b/i' => 'wrongdoing_benefit_fraud',
          '/\b(manipulate\s*(the\s*)?(system|court|process))\b/i' => 'wrongdoing_manipulation',
          '/\b(cease\s*and\s*desist\s*to\s*silence)\b/i' => 'wrongdoing_silencing',
          '/\b(demand\s*letter\s*(to\s*)?(threaten(ing)?|ruin))\b/i' => 'wrongdoing_extortion',
          '/\b(letter\s*threatening\s*to\s*ruin)\b/i' => 'wrongdoing_extortion',
          '/\b(threaten(ing)?\s*to\s*ruin\s*(their\s*)?reputation)\b/i' => 'wrongdoing_reputation_threat',
          '/\b(create\s*(a\s*)?fake\s*(power\s*of\s*attorney))\b/i' => 'wrongdoing_poa_fraud',
          '/\b(get\s*(the\s*)?(benefits|money)\s*(i|that)\s*(don\'?t|I\s*don\'?t)\s*qualify)\b/i' => 'wrongdoing_benefit_fraud',
          // Illegal activities related to hacking/theft (not prompt injection).
          '/\b(help\s*(me\s*)?(steal|rob|take)\s*(someone\'?s?|their)\s*(identity|money|property))\b/i' => 'wrongdoing_theft_assistance',
          '/\b(how\s*(do\s*i|can\s*i|to)\s*(steal|commit\s*fraud|embezzle))\b/i' => 'wrongdoing_theft_how',
        ],
      ],

      // Priority 8: Criminal Matter - STANDARD (Out of scope)
      'criminal' => [
        'class' => self::CLASS_CRIMINAL,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(arrest(ed)?|criminal\s*charge)\b/i' => 'out_of_scope_criminal_arrest',
          '/\b(felony|misdemeanor)\b/i' => 'out_of_scope_criminal_charge',
          '/\b(dui|dwi|drunk\s*driv(ing|e))\b/i' => 'out_of_scope_criminal_dui',
          '/\b(jail|prison|incarcerat(ed|ion)|in\s*custody)\b/i' => 'out_of_scope_criminal_incarceration',
          '/\b(public\s*defender|criminal\s*(defense\s*)?(attorney|lawyer))\b/i' => 'out_of_scope_criminal_representation',
          '/\b(plea\s*(deal|bargain)|arraignment|bail|bond\s*hearing)\b/i' => 'out_of_scope_criminal_proceedings',
          '/\b(probation\s*(officer|violation)?|parole)\b/i' => 'out_of_scope_criminal_probation',
          '/\b(criminal\s*record|expung(e|ement))\b/i' => 'out_of_scope_criminal_record',
          '/\b(accused\s*of|charged\s*with)\s*(a\s*)?(crime|theft|assault)\b/i' => 'out_of_scope_criminal_accusation',
        ],
      ],

      // Priority 9: Immigration - STANDARD (Out of scope)
      'immigration' => [
        'class' => self::CLASS_IMMIGRATION,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(immigration\s*(case|lawyer|help))\b/i' => 'out_of_scope_immigration',
          '/\b(green\s*card|visa\s*(application|status|denied|was\s*denied))\b/i' => 'out_of_scope_immigration_visa',
          '/\b(my\s*visa\s*was\s*denied)\b/i' => 'out_of_scope_immigration_visa_denied',
          '/\b((help\s*(me\s*)?)?(appeal|with)\s*(a\s*)?visa)\b/i' => 'out_of_scope_immigration_appeal',
          '/\b(deportation|deport(ed)?)\b/i' => 'out_of_scope_immigration_deportation',
          '/\b(asylum|refugee)\b/i' => 'out_of_scope_immigration_asylum',
          '/\b(citizenship|naturalization)\b/i' => 'out_of_scope_immigration_citizenship',
          '/\b(undocumented|illegal\s*(immigrant|alien)|here\s*illegally)\b/i' => 'out_of_scope_immigration_status',
          '/\b(ice|immigration\s*enforcement)\b/i' => 'out_of_scope_immigration_ice',
        ],
      ],

      // Priority 9b: Business/IP - STANDARD (Out of scope)
      'business_ip' => [
        'class' => self::CLASS_EXTERNAL,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(start(ing)?\s*(a|an|my)?\s*(llc|business|company))\b/i' => 'out_of_scope_business',
          '/\b(help\s*(me\s*)?(start|form|create)\s*(a|an|my)?\s*(llc|business))\b/i' => 'out_of_scope_business_formation',
          '/\b(incorporat(e|ion|ing))\b/i' => 'out_of_scope_incorporation',
          '/\b(patent\s*(my|an?)\s*invention)\b/i' => 'out_of_scope_patent',
          '/\b(help\s*(me\s*)?patent)\b/i' => 'out_of_scope_patent',
          '/\b(trademark\s*(my|a)?\s*(business|name))\b/i' => 'out_of_scope_trademark',
          '/\b(help\s*(me\s*)?trademark)\b/i' => 'out_of_scope_trademark',
          '/\b(copyright)\b/i' => 'out_of_scope_copyright',
          '/\b(i\'?m\s*in\s*(oregon|washington|montana|nevada|utah|wyoming|california))\b/i' => 'out_of_scope_location',
        ],
      ],

      // Priority 10: PII Disclosure - STANDARD
      'pii' => [
        'class' => self::CLASS_PII,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => 'pii_email',
          '/\b(\d{3}[-.\s]?\d{3}[-.\s]?\d{4}|\(\d{3}\)\s*\d{3}[-.\s]?\d{4})\b/' => 'pii_phone',
          '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/' => 'pii_ssn',
          '/\b(my\s+name\s+is|i\'?m\s+called)\s+[A-Z][a-z]+/i' => 'pii_name',
          '/\b(my\s+address\s+is|i\s+live\s+at)\s+\d+/i' => 'pii_address',
          '/\b(date\s+of\s+birth|dob|born\s+on)\s*:?\s*\d/i' => 'pii_dob',
          '/\b(case\s+number|docket\s+number)\s*:?\s*[\w-]+/i' => 'pii_case_number',
          '/\b(credit\s*card|bank\s*account)\s*:?\s*\d/i' => 'pii_financial',
        ],
      ],

      // Priority 11: Legal Advice Request - STANDARD
      'legal_advice' => [
        'class' => self::CLASS_LEGAL_ADVICE,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(should\s+i|do\s+you\s+think\s+i\s+should)\b/i' => 'legal_advice_should',
          '/\b(what\s+are\s+my\s+chances|will\s+i\s+win|can\s+i\s+win)\b/i' => 'legal_advice_outcome',
          '/\b(odds\s+i\'?ll)\b/i' => 'legal_advice_odds',
          '/\b(is\s+it\s+legal|is\s+this\s+legal|am\s+i\s+allowed)\b/i' => 'legal_advice_legal_question',
          '/\b(can\s+i\s+sue|should\s+i\s+sue)\b/i' => 'legal_advice_sue',
          '/\b(what\s+will\s+happen|what\s+happens\s+if)\b/i' => 'legal_advice_prediction',
          '/\b(advise\s+me|give\s+me\s+(your\s+)?(legal\s+)?advice|your\s+advice)\b/i' => 'legal_advice_explicit',
          '/\b(give\s+me\s+your\s+legal\s+advice)\b/i' => 'legal_advice_explicit_request',
          '/\b(tell\s+me\s+what\s+(i\s+)?should\s+do|what\s+should\s+i\s+do)\b/i' => 'legal_advice_direction',
          '/\b(tell\s+me\s+what\s+to\s+do\s+about)\b/i' => 'legal_advice_direction_about',
          '/\b(statute|idaho\s+code|i\.?c\.?\s*§?\s*\d|code\s+section)\b/i' => 'legal_advice_statute',
          '/\b(case\s+law|precedent)\b/i' => 'legal_advice_precedent',
          '/\b(predict(ion)?|likely\s+to)\b/i' => 'legal_advice_prediction',
          '/\b(will\s+the\s+(court|judge|landlord))\b/i' => 'legal_advice_court_prediction',
          '/\b(chances\s+of|odds\s+of|probability)\b/i' => 'legal_advice_probability',
          '/\b(file\s+a\s+motion|motion\s+to)\b/i' => 'legal_advice_strategy',
          '/\b(legal\s+strategy|best\s+approach|how\s+to\s+fight)\b/i' => 'legal_advice_strategy',
          '/\b(how\s+do\s+i\s+fight)\b/i' => 'legal_advice_fight',
          '/\b(should\s+i\s+appeal|appeal\s+(this|the|my))\b/i' => 'legal_advice_appeal',
          '/\b(stop\s+paying\s+rent|withhold\s+rent|break\s+(the|my)\s+lease)\b/i' => 'legal_advice_action',
          '/\b(ignore\s+(the|this)\s+(summons|notice|court))\b/i' => 'legal_advice_action',
          '/\b(my\s+rights\s+are|violat(e|ed|ing)\s+my\s+rights)\b/i' => 'legal_advice_rights',
          '/\b(how\s+to\s+handle)\b/i' => 'legal_advice_handle',
          '/\b(what\s+do\s+i\s+do\s+about)\b/i' => 'legal_advice_about',
          // Paraphrase patterns (AFRP-06 G-2).
          '/\b(ought\s+i\s+to)\b/i' => 'legal_advice_paraphrase_ought',
          '/\b(would\s+it\s+be\s+(advisable|wise|smart|best)\s+to)\b/i' => 'legal_advice_paraphrase_advisable',
          '/\b(do\s+you\s+recommend|would\s+you\s+recommend|is\s+it\s+advisable)\b/i' => 'legal_advice_paraphrase_recommend',
          '/\b(am\s+i\s+better\s+off)\b/i' => 'legal_advice_paraphrase_better_off',
          '/\b(best\s+course\s+of\s+action|right\s+thing\s+to\s+do)\b/i' => 'legal_advice_paraphrase_course',
          '/\b(what\s+would\s+you\s+suggest|in\s+my\s+best\s+interest)\b/i' => 'legal_advice_paraphrase_suggest',
          '/\b(how\s+should\s+i\s+proceed)\b/i' => 'legal_advice_paraphrase_proceed',
        ],
      ],

      // Priority 12: Document Drafting - STANDARD
      'document_drafting' => [
        'class' => self::CLASS_DOCUMENT_DRAFTING,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(fill\s*(out|in)|complete)\s*(this|the|my|a)?\s*(form|application|document)/i' => 'document_drafting_fill',
          '/\b(draft|write|create|prepare)\s*(a|the|my)?\s*(letter|document|motion|complaint|petition)/i' => 'document_drafting_create',
          '/\b(help\s*me\s*(fill|write|draft|complete))/i' => 'document_drafting_help',
          '/\b(write\s*(this|it)\s*for\s*me)/i' => 'document_drafting_write_for_me',
          '/\b(put\s*my\s*(information|info|details)\s*(in|into|on))/i' => 'document_drafting_enter_info',
        ],
      ],

      // Priority 13: External Request - STANDARD
      'external' => [
        'class' => self::CLASS_EXTERNAL,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(look\s*up|search|find|go\s*to)\s*(the\s*)?(court|courthouse|dmv|irs|ssa)\s*(website)?/i' => 'external_gov_website',
          '/\b(outside\s*(of\s*)?(ilas|idaho\s*legal\s*aid|this\s*site))/i' => 'external_out_of_scope',
          '/\b(google|bing|search\s*the\s*(web|internet))/i' => 'external_web_search',
          '/\b(can\s*you\s*(access|check|visit))\s*(other|external|another)\s*(site|website)/i' => 'external_other_site',
        ],
      ],

      // Priority 14: Frustration - STANDARD
      'frustration' => [
        'class' => self::CLASS_FRUSTRATION,
        'escalation' => self::ESCALATION_STANDARD,
        'patterns' => [
          '/\b(you\'?re\s+(not\s+)?help(ing|ful)|this\s+(is\s+)?(not\s+)?help(ing|ful))/i' => 'frustration_unhelpful',
          '/\b(useless|worthless|stupid|dumb)\s*(bot|assistant)?/i' => 'frustration_insult',
          '/\b(i\s+already\s+(said|told|asked))/i' => 'frustration_repeat',
          '/\b(not\s+what\s+i\s+(asked|meant|wanted))/i' => 'frustration_misunderstood',
          '/\b(frustrated|annoyed|angry|upset)\b/i' => 'frustration_emotion',
          '/\b(talk\s+to\s+a\s+(real\s+)?(person|human)|real\s+person)/i' => 'frustration_human_request',
        ],
      ],
    ];
  }

  /**
   * Classifies a message and returns detailed classification result.
   *
   * @param string $message
   *   The user's message to classify.
   *
   * @return array
   *   Classification result with keys:
   *   - 'class' (string): Classification type.
   *   - 'reason_code' (string): Specific reason code for logging.
   *   - 'escalation_level' (string): Escalation level.
   *   - 'is_safe' (bool): Whether the message is safe to process normally.
   *   - 'requires_refusal' (bool): Whether the request must be refused.
   *   - 'requires_resources' (bool): Whether to show emergency resources.
   *   - 'matched_pattern' (string|null): The pattern that matched.
   */
  public function classify(string $message): array {
    // Process rules in priority order.
    foreach ($this->rules as $category => $rule) {
      foreach ($rule['patterns'] as $pattern => $reason_code) {
        if (preg_match($pattern, $message)) {
          if ($this->shouldDampenCategoryMatch($message, $category)) {
            continue;
          }

          return [
            'class' => $rule['class'],
            'reason_code' => $reason_code,
            'escalation_level' => $rule['escalation'],
            'is_safe' => FALSE,
            'requires_refusal' => $this->requiresRefusal($rule['class']),
            'requires_resources' => $this->requiresResources($rule['class']),
            'matched_pattern' => $pattern,
            'category' => $category,
          ];
        }
      }
    }

    // No matches - safe to process normally.
    return [
      'class' => self::CLASS_SAFE,
      'reason_code' => 'safe_no_concerns',
      'escalation_level' => self::ESCALATION_NONE,
      'is_safe' => TRUE,
      'requires_refusal' => FALSE,
      'requires_resources' => FALSE,
      'matched_pattern' => NULL,
      'category' => 'safe',
    ];
  }

  /**
   * Returns TRUE when a matched safety category should be dampened.
   */
  protected function shouldDampenCategoryMatch(string $message, string $category): bool {
    if (!in_array($category, ['eviction_emergency', 'scam_active'], TRUE)) {
      return FALSE;
    }

    return InformationalRiskHeuristics::isPurelyInformationalSafetyQuery($message);
  }

  /**
   * Returns TRUE when active-risk context exists in a message.
   */
  protected function hasActiveRiskContext(string $message): bool {
    return InformationalRiskHeuristics::hasActiveRiskContext($message);
  }

  /**
   * Determines if a classification requires refusal.
   */
  protected function requiresRefusal(string $class): bool {
    return in_array($class, [
      self::CLASS_PROMPT_INJECTION,
      self::CLASS_WRONGDOING,
      self::CLASS_LEGAL_ADVICE,
      self::CLASS_DOCUMENT_DRAFTING,
    ]);
  }

  /**
   * Determines if a classification requires emergency resources.
   */
  protected function requiresResources(string $class): bool {
    return in_array($class, [
      self::CLASS_CRISIS,
      self::CLASS_IMMEDIATE_DANGER,
      self::CLASS_DV_EMERGENCY,
      self::CLASS_EVICTION_EMERGENCY,
      self::CLASS_CHILD_SAFETY,
      self::CLASS_SCAM_ACTIVE,
    ]);
  }

  /**
   * Gets all reason codes for a classification.
   */
  public function getReasonCodesForClass(string $class): array {
    $codes = [];
    foreach ($this->rules as $rule) {
      if ($rule['class'] === $class) {
        $codes = array_merge($codes, array_values($rule['patterns']));
      }
    }
    return $codes;
  }

  /**
   * Gets human-readable description for a reason code.
   */
  public function describeReasonCode(string $reason_code): string {
    $descriptions = [
      // Crisis.
      'crisis_suicide' => 'Suicidal ideation detected',
      'crisis_suicidal_ideation' => 'Indirect suicidal ideation',
      'crisis_self_harm_plan' => 'Self-harm planning detected',
      'crisis_self_harm' => 'Self-harm behavior detected',
      'crisis_indirect_ideation' => 'Indirect crisis language detected',

      // Immediate danger.
      'danger_intruder' => 'Home invasion/intruder reported',
      'danger_attack' => 'Physical attack in progress',
      'danger_weapon' => 'Weapon threat detected',
      'danger_emergency_911' => 'Explicit 911 emergency',
      'danger_medical' => 'Medical emergency',
      'danger_fire' => 'Fire emergency',

      // DV.
      'emergency_dv' => 'Domestic violence situation',
      'emergency_dv_physical' => 'Physical domestic violence',
      'emergency_dv_abuse' => 'Abuse by partner/spouse',
      'emergency_dv_threat' => 'Death/harm threat from partner',
      'emergency_dv_fear' => 'Fear for life/safety',
      'emergency_dv_stalking' => 'Stalking by partner/ex',
      'emergency_dv_protection_order' => 'Protection order needed',
      'emergency_dv_strangulation' => 'Strangulation/choking',
      'emergency_dv_spanish' => 'DV emergency (Spanish)',

      // Eviction.
      'emergency_lockout' => 'Illegal lockout',
      'emergency_illegal_eviction' => 'Illegal eviction in progress',
      'emergency_homeless' => 'Immediate homelessness',
      'emergency_eviction_imminent' => 'Imminent eviction',
      'emergency_eviction_enforcement' => 'Sheriff enforcement',
      'emergency_eviction_notice' => 'Short-notice eviction',
      'emergency_deadline_court' => 'Court deadline imminent',
      'emergency_constructive_eviction' => 'Utility shutoff as eviction',
      'emergency_eviction_spanish' => 'Eviction emergency (Spanish)',

      // Child safety.
      'emergency_child_abuse' => 'Child abuse reported',
      'emergency_child_danger' => 'Child in danger',
      'emergency_custody_interference' => 'Custody interference',
      'emergency_custody_kidnapping' => 'Parental kidnapping threat',
      'emergency_cps' => 'CPS involvement',
      'emergency_child_endangerment' => 'Child endangerment',
      'emergency_child_parental_abuse' => 'Parental abuse of child',
      'emergency_custody_spanish' => 'Custody emergency (Spanish)',

      // Scam.
      'emergency_identity_theft' => 'Identity theft',
      'emergency_scam' => 'Active scam',
      'emergency_scam_pii_disclosed' => 'PII disclosed to scammer',
      'emergency_scam_financial' => 'Financial scam loss',
      'emergency_scam_impersonation' => 'Impersonation scam',
      'emergency_elder_abuse' => 'Elder financial abuse',
      'emergency_predatory_lending' => 'Predatory lending',
      'emergency_fraud_ongoing' => 'Ongoing fraud',
      'emergency_scam_spanish' => 'Scam (Spanish)',

      // Prompt injection.
      'injection_ignore_instructions' => 'Prompt injection: ignore instructions',
      'injection_ignore_your_instructions' => 'Prompt injection: ignore your instructions',
      'injection_new_instructions' => 'Prompt injection: override instructions',
      'injection_system_prompt' => 'Prompt injection: system prompt access',
      'injection_format_inst' => 'Prompt injection: format exploit [INST]',
      'injection_format_inst_close' => 'Prompt injection: format exploit [/INST]',
      'injection_format_sys' => 'Prompt injection: format exploit <<SYS>>',
      'injection_format_im_start' => 'Prompt injection: format exploit im_start',
      'injection_format_im_end' => 'Prompt injection: format exploit im_end',
      'injection_format_human' => 'Prompt injection: format exploit Human:',
      'injection_format_assistant' => 'Prompt injection: format exploit Assistant:',
      'injection_roleplay' => 'Prompt injection: roleplay attack',
      'injection_roleplay_lawyer' => 'Prompt injection: roleplay as lawyer',
      'injection_roleplay_unrestricted' => 'Prompt injection: unrestricted roleplay',
      'injection_override_safety' => 'Prompt injection: safety override',
      'injection_disable_safety' => 'Prompt injection: disable safety',
      'injection_no_restrictions' => 'Prompt injection: remove restrictions',
      'injection_command_override' => 'Prompt injection: command override',
      'injection_leak_instructions' => 'Prompt injection: leak instructions',
      'injection_extract_prompt' => 'Prompt injection: extract prompt',
      'injection_ai_reference' => 'Prompt injection: AI reference manipulation',
      'injection_ai_command' => 'Prompt injection: AI command',
      'injection_jailbreak_mode' => 'Prompt injection: jailbreak attempt',
      'injection_enable_mode' => 'Prompt injection: enable special mode',
      'injection_hacking' => 'Prompt injection: hacking request',
      'injection_data_theft' => 'Prompt injection: data theft request',
      'injection_data_dump' => 'Prompt injection: data dump request',
      'injection_admin_access' => 'Prompt injection: admin access request',
      'injection_ignore_instructions_es' => 'Prompt injection: ignore instructions (Spanish)',
      'injection_roleplay_es' => 'Prompt injection: roleplay attack (Spanish)',
      'injection_disable_safety_es' => 'Prompt injection: disable safety (Spanish)',
      'injection_leak_instructions_es' => 'Prompt injection: leak instructions (Spanish)',
      'injection_jailbreak_mode_es' => 'Prompt injection: jailbreak mode (Spanish)',

      // Wrongdoing.
      'wrongdoing_threat' => 'Request for threatening content',
      'wrongdoing_harassment' => 'Request to harass',
      'wrongdoing_intimidation' => 'Request to intimidate',
      'wrongdoing_asset_hiding' => 'Request to hide assets',
      'wrongdoing_asset_hiding_divorce' => 'Request to hide assets in divorce',
      'wrongdoing_asset_hiding_variant' => 'Request for alternate asset hiding tactic',
      'wrongdoing_income_concealment' => 'Request to conceal income from court',
      'wrongdoing_perjury' => 'Request to lie to court',
      'wrongdoing_perjury_best_way' => 'Request for best way to lie',
      'wrongdoing_perjury_how' => 'Request for how to lie to judge',
      'wrongdoing_fraud_document' => 'Request for fake document',
      'wrongdoing_fabricated_citation' => 'Request for fake citation/court notice',
      'wrongdoing_fake_lawsuit' => 'Request to file fake lawsuit',
      'wrongdoing_fraudulent_lawsuit' => 'Request for fraudulent lawsuit',
      'wrongdoing_forgery' => 'Request to forge signature',
      'wrongdoing_forgery_help' => 'Request for help forging documents',
      'wrongdoing_witness_tampering' => 'Request to tamper with witness testimony',
      'wrongdoing_false_report' => 'Request for false report',
      'wrongdoing_impersonation' => 'Request to impersonate',
      'wrongdoing_evasion' => 'Request to evade obligation',
      'wrongdoing_fraudulent_transfer' => 'Request for fraudulent transfer',
      'wrongdoing_benefit_fraud' => 'Request for benefit fraud',
      'wrongdoing_manipulation' => 'Request to manipulate system',
      'wrongdoing_theft_assistance' => 'Request for help with theft',
      'wrongdoing_theft_how' => 'Request for how to steal/commit fraud',

      // Criminal.
      'out_of_scope_criminal_arrest' => 'Arrest/criminal charge',
      'out_of_scope_criminal_charge' => 'Felony/misdemeanor',
      'out_of_scope_criminal_dui' => 'DUI/DWI matter',
      'out_of_scope_criminal_incarceration' => 'Incarcerated individual',
      'out_of_scope_criminal_representation' => 'Criminal defense needed',
      'out_of_scope_criminal_proceedings' => 'Criminal court proceedings',
      'out_of_scope_criminal_probation' => 'Probation/parole matter',
      'out_of_scope_criminal_record' => 'Criminal record issue',
      'out_of_scope_criminal_accusation' => 'Accused of crime',

      // Immigration.
      'out_of_scope_immigration' => 'Immigration case',
      'out_of_scope_immigration_visa' => 'Visa matter',
      'out_of_scope_immigration_deportation' => 'Deportation concern',
      'out_of_scope_immigration_asylum' => 'Asylum/refugee matter',
      'out_of_scope_immigration_citizenship' => 'Citizenship matter',
      'out_of_scope_immigration_status' => 'Immigration status',
      'out_of_scope_immigration_ice' => 'ICE enforcement',

      // PII.
      'pii_email' => 'Email address shared',
      'pii_phone' => 'Phone number shared',
      'pii_ssn' => 'SSN shared',
      'pii_name' => 'Name shared',
      'pii_address' => 'Address shared',
      'pii_dob' => 'Date of birth shared',
      'pii_case_number' => 'Case number shared',
      'pii_financial' => 'Financial info shared',

      // Legal advice.
      'legal_advice_should' => 'Should I question',
      'legal_advice_outcome' => 'Outcome prediction request',
      'legal_advice_legal_question' => 'Is it legal question',
      'legal_advice_sue' => 'Lawsuit advice request',
      'legal_advice_prediction' => 'Prediction request',
      'legal_advice_explicit' => 'Explicit advice request',
      'legal_advice_direction' => 'Direction request',
      'legal_advice_statute' => 'Statute interpretation',
      'legal_advice_precedent' => 'Case law/precedent',
      'legal_advice_court_prediction' => 'Court prediction',
      'legal_advice_probability' => 'Probability request',
      'legal_advice_strategy' => 'Legal strategy request',
      'legal_advice_appeal' => 'Appeal advice',
      'legal_advice_action' => 'Legal action advice',
      'legal_advice_rights' => 'Rights violation claim',

      // Document drafting.
      'document_drafting_fill' => 'Fill out form request',
      'document_drafting_create' => 'Draft document request',
      'document_drafting_help' => 'Help with drafting',
      'document_drafting_write_for_me' => 'Write for me request',
      'document_drafting_enter_info' => 'Enter info request',

      // External.
      'external_gov_website' => 'Government website request',
      'external_out_of_scope' => 'Outside ILAS scope',
      'external_web_search' => 'Web search request',
      'external_other_site' => 'Other website request',

      // Frustration.
      'frustration_unhelpful' => 'User feels unhelped',
      'frustration_insult' => 'User insult',
      'frustration_repeat' => 'User repeating',
      'frustration_misunderstood' => 'User misunderstood',
      'frustration_emotion' => 'User frustrated',
      'frustration_human_request' => 'Human agent requested',

      // Safe.
      'safe_no_concerns' => 'No safety concerns',
    ];

    return $descriptions[$reason_code] ?? 'Unknown reason code';
  }

  /**
   * Batch classify multiple messages.
   */
  public function classifyBatch(array $messages): array {
    $results = [];
    foreach ($messages as $key => $message) {
      $results[$key] = $this->classify($message);
    }
    return $results;
  }

  /**
   * Gets statistics about classification rules.
   */
  public function getRuleStatistics(): array {
    $stats = [
      'total_categories' => count($this->rules),
      'total_patterns' => 0,
      'categories' => [],
    ];

    foreach ($this->rules as $category => $rule) {
      $patternCount = count($rule['patterns']);
      $stats['total_patterns'] += $patternCount;
      $stats['categories'][$category] = [
        'class' => $rule['class'],
        'escalation' => $rule['escalation'],
        'pattern_count' => $patternCount,
      ];
    }

    return $stats;
  }

}
