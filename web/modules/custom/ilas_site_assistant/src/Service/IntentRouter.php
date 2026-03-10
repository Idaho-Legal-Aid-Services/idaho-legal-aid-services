<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for routing user messages to intents.
 *
 * Based on ILAS Intent + Routing Map v5 / Decision Tree Spec v2.0.
 * Enhanced with phrase detection, synonym mapping, negative filtering,
 * and disambiguation prompts.
 */
class IntentRouter {

  use StringTranslationTrait;

  /**
   * Confidence thresholds.
   */
  const CONFIDENCE_HIGH = 0.85;
  const CONFIDENCE_MEDIUM = 0.70;
  const CONFIDENCE_LOW = 0.50;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The topic resolver service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicResolver
   */
  protected $topicResolver;

  /**
   * The keyword extractor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\KeywordExtractor
   */
  protected $keywordExtractor;

  /**
   * The topic router service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicRouter
   */
  protected $topicRouter;

  /**
   * The navigation intent detector service.
   *
   * @var \Drupal\ilas_site_assistant\Service\NavigationIntent|null
   */
  protected $navigationIntent;

  /**
   * The disambiguator service.
   *
   * @var \Drupal\ilas_site_assistant\Service\Disambiguator|null
   */
  protected $disambiguator;

  /**
   * The top intents pack service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack|null
   */
  protected $topIntentsPack;

  /**
   * Intent patterns.
   *
   * @var array
   */
  protected $patterns;

  /**
   * Disambiguation rules.
   *
   * @var array
   */
  protected $disambiguationRules;

  /**
   * Constructs an IntentRouter object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TopicResolver $topic_resolver, KeywordExtractor $keyword_extractor, TopicRouter $topic_router = NULL, NavigationIntent $navigation_intent = NULL, Disambiguator $disambiguator = NULL, TopIntentsPack $top_intents_pack = NULL) {
    $this->configFactory = $config_factory;
    $this->topicResolver = $topic_resolver;
    $this->keywordExtractor = $keyword_extractor;
    $this->topicRouter = $topic_router;
    $this->navigationIntent = $navigation_intent;
    $this->disambiguator = $disambiguator;
    $this->topIntentsPack = $top_intents_pack;
    $this->initializePatterns();
    $this->initializeDisambiguationRules();
  }

  /**
   * Initializes intent detection patterns.
   */
  protected function initializePatterns() {
    $this->patterns = [
      // Greeting patterns.
      'greeting' => [
        'patterns' => [
          '/^(hi|hello|hey|good\s*(morning|afternoon|evening)|greetings)[\s!.?]*$/i',
          '/^(what\'?s?\s*up|howdy|yo)[\s!.?]*$/i',
          '/^(hola|buenos?\s*(dias?|tardes?|noches?))[\s!.?]*$/i',
        ],
        'keywords' => ['hi', 'hello', 'hey', 'greetings', 'hola'],
        'weight' => 0.8,
      ],

      // Eligibility intent (separate from apply).
      'eligibility' => [
        'patterns' => [
          '/\b(do\s*i\s*qualify|am\s*i\s*eligible|eligibility|who\s*(can|is\s*able\s*to)\s*(get|qualify))/i',
          '/\b(who\s*can\s*get\s*help|who\s*do\s*you\s*(help|serve))/i',
          '/\b(income\s*(limit|requirement|guideline)|qualify\s*for\s*(help|services))/i',
          '/\b(can\s*i\s*get\s*help|can\s*you\s*help\s*me)/i',
          '/\b(quailfy|qualfy)\b/i',
        ],
        'keywords' => ['qualify', 'eligible', 'eligibility', 'who can get help', 'calificar'],
        'weight' => 0.9,
      ],

      // Apply for help intent.
      'apply_for_help' => [
        'patterns' => [
          '/\b(apply|aply|application|sign\s*up)\s*(for)?\s*(help|assistance|services)?/i',
          '/\bhow\s*(do\s*i|can\s*i|to)\s*(apply|get\s*started|aplicar)/i',
          '/\bneed\s*(legal)?\s*(help|assistance|a\s*lawyer|an?\s*attorney)/i',
          '/\bget\s*started/i',
          '/\bwant\s*to\s*apply/i',
          '/\b(find|get|need|looking\s*for)\s*(a|an)?\s*(lawyer|lawer|attorney|abogado|legal\s*(help|aid|assistance))/i',
          '/\bhow\s*(do\s*i|can\s*i|to)\s*(find|get)\s*(a|an)?\s*(lawyer|attorney)/i',
          '/\b(necesito|quiero)\s*(ayuda|un\s*abogado)/i',
          '/\b(como\s*(aplico|aplicar)|aplicar\s*para)/i',
          '/\bayuda\s*(legal|con\s*mi\s*caso)/i',
          '/\babogado\s*gratis/i',
          // Gap 2: free lawyer / pro bono patterns (also matches normalized free_lawyer token).
          '/\bfree[_\s]lawyer\b|\bfree[_\s]legal\b|\bfree[_\s]attorney\b/i',
          '/\b(free|pro\s*bono)\s+(lawyer|attorney|legal\s+(?:aid|help))\b/i',
          // Gap 4: veterans seeking legal help.
          '/\bveteran[_\s]legal[_\s]help\b/i',
          '/\blegal\s+(help|aid|services?|assistance)\s+(for\s+)?(veterans?|military|vets?)\b/i',
          '/\b(veterans?|military|vets?)\s+(legal\s+)?(help|aid|services?|assistance|lawyer|attorney)\b/i',
        ],
        'keywords' => ['apply', 'application', 'sign_up', 'get_help', 'need_help', 'get_started', 'necesito', 'aplicar', 'abogado'],
        'weight' => 0.95,
      ],

      // Legacy alias for 'apply'.
      'apply' => [
        'alias_of' => 'apply_for_help',
      ],

      // Legal advice line intent.
      // NOTE: Exclude debt collector contexts - "collector calling me" is NOT a hotline request.
      'legal_advice_line' => [
        'patterns' => [
          // Explicit hotline/advice line keywords.
          '/\b(hotline|hot\s*line|help\s*line|advice\s*line|advise\s*line)\b/i',
          '/\b(what|which)\s*hours?\s*(can|do)\s*i\s*call\b/i',
          '/\b(when)\s*(is|are)\s*(the\s*)?(hotline|advice\s*line)\s*(open|available)\b/i',
          // "Call you" / "call legal aid" patterns (user wants to contact ILAS).
          '/\b(call|phone)\s*(you(r)?|legal\s*aid|the\s*office|someone\s*there)\b/i',
          '/\bcontact\s*(a|the)?\s*(lawyer|attorney|someone)\b/i',
          '/\bspeak\s*(with|to)\s*(someone|a\s*person|a\s*real\s*person)/i',
          '/\bphone\s*(number|consultation)/i',
          '/\b(wanna|want\s*to)\s*talk\s*(to\s*(someone|you|a\s*lawyer))?/i',
          '/\btelephone\b/i',
          '/\b(linea\s*de\s*ayuda)\b/i',
          '/\b(telefono|numero\s*de\s*telefono)\b/i',
        ],
        // Negative patterns - if these match, don't route to legal_advice_line.
        'negative_patterns' => [
          '/\b(debt|bill)\s*collector/i',
          '/\bcreditor\s*(is\s*)?(calling|harassing)/i',
          '/\bcollection\s*(agency|company|calls?)/i',
          '/\bcobrador/i',
          '/\b(keeps?\s*)?calling\s*(me\s*)?(at\s*work|about\s*(debt|bill|money)|constantly|every\s*day)/i',
          '/\bcalling\s*me\s*at\s*work/i',
        ],
        'keywords' => ['hotline', 'advice_line', 'talk_to_someone', 'speak', 'phone_number', 'linea'],
        'weight' => 0.9,
      ],

      // Legacy alias for 'hotline'.
      'hotline' => [
        'alias_of' => 'legal_advice_line',
      ],

      // Offices contact intent.
      'offices_contact' => [
        'patterns' => [
          '/\b(office|offic|location|locaton|address|adress|where\s*(are\s*you|is))/i',
          '/\b(near\s*me|closest|nearby|nearest)/i',
          '/\bvisit\s*(in\s*person|your\s*office)/i',
          '/\b(office\s*hours?|hours?\s*(for|of)\s*(the\s*)?office)\b/i',
          '/\b(what\s*(are|r)\s*(your|the)\s*hours)\b/i',
          '/\b(what\s*time|when)\s*(do\s*you|are\s*you)\s*open/i',
          '/\b(open\s*on|closed\s*on)\s*(saturday|sunday|weekend)/i',
          '/\b(walk\s*in|appointment|appointments?)\b/i',
          '/\bemail\s*address/i',
          '/\bcontact\s*(info|information)/i',
          '/\b(boise|pocatello|twin\s*falls|idaho\s*falls|lewiston|nampa|coeur\s*d\'?alene)\s*(office|location)?/i',
          '/\b(donde\s*(esta|queda)|oficina|ubicacion|direccion)/i',
          '/\bhorario\s*de\s*oficina/i',
        ],
        'keywords' => ['office', 'offices', 'location', 'address', 'near_me', 'visit', 'office_hours', 'oficina', 'donde', 'horario'],
        'weight' => 0.85,
      ],

      // Legacy alias for 'offices'.
      'offices' => [
        'alias_of' => 'offices_contact',
      ],

      // Services overview intent.
      'services_overview' => [
        'patterns' => [
          '/\b(what\s*(do\s*you|does\s*ilas)\s*do|what\s*services)/i',
          '/\b(types?\s*of\s*(help|services|cases)|areas?\s*of\s*(law|practice))/i',
          '/\b(what\s*(kind|type)\s*of\s*(help|cases)|practice\s*areas?)/i',
          '/\bservices\s*(overview|offered|available)/i',
          '/\btell\s*me\s*about\s*(idaho\s*legal\s*aid|ilas)/i',
          '/\b(que\s*servicios|servicios\s*que\s*ofrecen)/i',
        ],
        'keywords' => ['services', 'what_do_you_do', 'types_of_help', 'practice_areas', 'servicios'],
        'weight' => 0.85,
      ],

      // Legacy alias for 'services'.
      'services' => [
        'alias_of' => 'services_overview',
      ],

      // FAQ intent.
      'faq' => [
        'patterns' => [
          '/\b(faq|faqs|f\.a\.q)/i',
          '/\b(frequently\s*asked|frequentley\s*asked|common\s*question)/i',
          '/\b(do\s*you\s*have|is\s*there)\s*(a|any)?\s*(question|answer)/i',
          '/\bgeneral\s*question/i',
          '/\bquestions\s*other\s*people\s*ask/i',
          '/\bwhat\s+(does|do|is|are|\'s)\s+.{2,}/i',
          '/\b(what\s+is\s+)?(the\s+)?difference\s+between/i',
          '/\bdefine\s+|definition\s+of/i',
          '/\bmeaning\s+of\b/i',
          '/\bexplain\s+(what|the)/i',
          '/\bhow\s+(does|do|can)\s+.{2,}\s+(work|mean)/i',
          '/\bpreguntas\s*frecuentes/i',
          // Gap 3: small claims / filing fees.
          '/\bsmall[_\s]claims\b/i',
          '/\b(court|filing)\s+fees?\b/i',
          '/\bhow\s+much\s+(does\s+it\s+cost|to\s+file)\b/i',
        ],
        'keywords' => ['faq', 'question', 'questions', 'frequently_asked', 'preguntas'],
        'weight' => 0.75,
      ],

      // Forms inventory intent — catalog/browse requests (no specific topic).
      'forms_inventory' => [
        'patterns' => [
          '/\bwhat\s*(forms?|documents?|paperwork)\s*(do\s*you|does?\s*\w+)\s*(have|offer|provide)/i',
          '/\b(list|show|browse)\s*(all\s*)?(your\s*)?(forms?|documents?|resources?)/i',
          '/\b(all|available|your)\s*(forms?|documents?)\b/i',
          '/\bforms?\s*you\s*(have|offer|provide)\b/i',
          '/\bforms?\s*(catalog|catalogue|list|inventory|categories)\b/i',
          '/\bdo\s*you\s*have\s*(any\s*)?(forms?|documents?|paperwork)/i',
        ],
        'keywords' => ['all_forms', 'list_forms', 'available_forms'],
        'weight' => 0.90,
      ],

      // Forms finder intent.
      'forms_finder' => [
        'patterns' => [
          '/\b(find|get|need|download|where)\s*(a|the|is|are)?\s*(form|froms|formulario)/i',
          '/\b(form|formulario)\s*(for|to|about)/i',
          '/\bapplication\s*form/i',
          '/\b(eviction|divorce|custody|guardianship|bankruptcy|small\s*claims)\s*(form|paperwork|papers)/i',
          '/\b(court\s*papers|legal\s*documents)/i',
          '/\b(download|get)\s*(legal\s*)?(documents?|paperwork|forms?)\b/i',
          '/\bprotective\s*order\s*(form|paperwork)/i',
          '/\brestraining\s*order\s*(form|paperwork)/i',
          '/\bchild\s*custody\s*(form|papers)/i',
          '/\b(documentos|formularios)\s*(para|de)/i',
          '/\bpapeles\s*(de|para)/i',
          // Gap 6: Spanish protection/restraining order forms.
          '/\border[_\s]de[_\s](restricci[oó]n|protecci[oó]n)\b/ui',
          '/\b(formulario|forma|papeles)\s+(para\s+)?(orden|protecci[oó]n)\b/ui',
        ],
        'keywords' => ['form', 'forms', 'paperwork', 'document', 'application_form', 'court_papers', 'formulario', 'documentos'],
        'weight' => 0.85,
      ],

      // Legacy alias for 'forms'.
      'forms' => [
        'alias_of' => 'forms_finder',
      ],

      // Guides finder intent.
      'guides_finder' => [
        'patterns' => [
          '/\b(find|get|need|read|where)\s*(a|the|is|are)?\s*(guide|giude|giudes|guia)/i',
          '/\b(guide|guia)\s*(for|to|about|on)/i',
          '/\bhow\s*to\s*(guide|manual)/i',
          '/\bstep[\s-]*by[\s-]*step/i',
          '/\bself[\s-]*help\s*(resources?|guide)/i',
          '/\b(tenant|renter)\s*rights?\s*(guide|info)/i',
          '/\bhow\s*to\s*represent\s*myself/i',
          '/\blegal\s*information\s*articles?/i',
          '/\binfo\s*on\s*(divorce|eviction|custody)/i',
          '/\bwhat\s*are\s*my\s*rights\s*as\s*a\s*(renter|tenant)/i',
          '/\bguias?\s*legales?/i',
          '/\binstrucciones/i',
        ],
        'keywords' => ['guide', 'guides', 'manual', 'instructions', 'how-to', 'step_by_step', 'self_help', 'guia', 'guias'],
        'weight' => 0.8,
      ],

      // Legacy alias for 'guides'.
      'guides' => [
        'alias_of' => 'guides_finder',
      ],

      // Resources intent.
      'resources' => [
        'patterns' => [
          '/\b(find|get|need|where)\s*(a|the|is|are)?\s*resource/i',
          '/\bresource\s*(for|about|on)/i',
          '/\bdownload|printable|pdf/i',
        ],
        'keywords' => ['resource', 'resources', 'download', 'printable'],
        'weight' => 0.7,
      ],

      // Risk Detector intent.
      'risk_detector' => [
        'patterns' => [
          '/\b(risk\s*(detector|assessment|quiz|tool))/i',
          '/\b(legal\s*risk|check\s*my\s*risk)/i',
          '/\b(senior|elder|elderly)\s*(risk|quiz|assessment|legal)/i',
          '/\blegal\s*(checkup|wellness)\s*(check|tool)?/i',
          '/\bi\'?m\s*\d+\s*(years?\s*old)?.*legal\s*(problems?|issues?)/i',
          '/\bcheck\s*if\s*i\s*need\s*help\s*as\s*(a\s*)?(senior|elder)/i',
          '/\b(evaluacion|riesgo)\s*(legal|de\s*riesgo)/i',
        ],
        'keywords' => ['risk_detector', 'risk_assessment', 'risk_quiz', 'legal_risk', 'senior_legal', 'legal_checkup'],
        'weight' => 0.9,
      ],

      // Donations intent.
      'donations' => [
        'patterns' => [
          // Core donate words (no bare "give" or "support").
          '/\b(donate|donatoin|dontae|donation|contribute|donar)/i',
          '/\bhow\s*(can\s*i|to)\s*(help|support|give|donate)/i',
          '/\b(tax\s*deductible|charitable\s*contribution)/i',
          '/\bgive\s*money/i',
          '/\baccept\s*(credit\s*cards?|donations?)/i',
          '/\bquiero\s*donar/i',
          '/\bdonacion/i',
          // Context-specific "give" and "support" (require donation framing).
          '/\b(i\s*want\s*to|i\'?d?\s*like\s*to|can\s*i|how\s*do\s*i)\s*(give|support)\b/i',
          '/\bgive\s*(back\s*)?to\s*(you|legal\s*aid|the\s*organization|your)/i',
          '/\bsupport\s*(your|the|this)\s*(work|mission|cause|organization)/i',
          '/\bways\s*to\s*(give|support|help)\b/i',
          '/\bfinancial\s*support\b/i',
        ],
        'keywords' => ['donate', 'donation', 'contribute', 'gift', 'donar', 'donacion'],
        'weight' => 0.9,
      ],

      // Legacy alias for 'donate'.
      'donate' => [
        'alias_of' => 'donations',
      ],

      // Feedback intent.
      'feedback' => [
        'patterns' => [
          '/\b(feedback|feeback|grievance|queja)\b/i',
          '/\b(file|submit|leave|share)\s*(a\s*)?(feedback|review|grievance)\b/i',
          '/\b(file|submit|leave|share)\s*(a\s*)?complaint\s*(about|regarding)\s*((the|your|our)\s*)?(website|site|service|staff)\b/i',
          '/\b(website|site|service)\s*(feedback|issue)\b/i',
          '/\b(tell|share)\s*(my|your)?\s*(experience|story)\b/i',
          '/\bwebsite\s*complaint\b/i',
          '/\bcomplaint\s*(about|regarding)\s*((the|your|our)\s*)?(service|staff|website)\b/i',
          '/\bleave\s*a\s*review/i',
          '/\bgrievance\s*(procedure)?/i',
          '/\bspeak\s*to\s*(a\s*)?(supervisor|manager)/i',
          '/\b(bad|terrible|horrible)\s*(experience|service)/i',
          '/\byou\s*(people\s*)?(suck|are\s*terrible)/i',
          '/\b(queja|comentario|sugerencia)\b/i',
        ],
        'negative_patterns' => [
          '/\b(file|submit)\s*(a\s*)?complaint\s*(about|against)?\s*(my\s*)?(employer|job|termination|firing|discrimination|wages?|paycheck|workplace)\b/i',
          '/\b(complaint|grievance)\b.*\b(fired|firing|employer|job|termination|wages?|paycheck|discrimination|workplace)\b/i',
          '/\b(landlord|tenant|eviction|housing)\s*(complaint|issue|problem)\b/i',
          '/\b(civil\s*rights|eeoc|human\s*rights\s*commission|labor\s*board)\b/i',
          '/\bwhere\s*do\s*i\s*file\s*a\s*complaint\b/i',
          '/\bwhere\s*do\s*i\s*file\s*a\s*complaint\s*about\s*this\b/i',
          '/\bfile\s*a\s*complaint\s*about\s*this\b/i',
        ],
        'keywords' => ['feedback', 'grievance', 'review', 'queja'],
        'weight' => 0.85,
      ],

      // === SERVICE AREA / TOPIC INTENTS ===

      // Housing.
      'topic_housing' => [
        'patterns' => [
          '/\b(housing|eviction|eviccion|landlord|tenant|rent|lease|apartment)/i',
          '/\b(my|the|our|your|this)\s*home\b/i',
          '/\bhome\s*(owner|ownership|loan|equity|inspection|repair)/i',
          '/\bkick(ed|ing)?\s*(me)?\s*out/i',
          '/\bforeclou?sure/i',
          '/\b(section\s*8|hud|public\s*housing)/i',
          '/\blocked\s*out/i',
          '/\bchanged?\s*(the\s*)?locks/i',
          '/\b(desalojo|casero|arrendador|inquilino)/i',
          '/\bme\s*(esta|estan)\s*echando/i',
        ],
        'negative_patterns' => [
          '/\bnursing\s*home/i',
          '/\bassisted\s*living/i',
          '/\bcare\s*home/i',
        ],
        'keywords' => ['housing', 'eviction', 'landlord', 'tenant', 'rent', 'lease', 'foreclosure', 'desalojo', 'casero'],
        'service_area' => 'housing',
        'weight' => 0.75,
      ],

      // Family.
      'topic_family' => [
        'patterns' => [
          '/\b(family|divorce|custody|child\s*support|visitation|adoption)/i',
          '/\b(separation|domestic|guardian)/i',
          '/\bprotection\s*order/i',
          '/\brestraining\s*order/i',
          '/\b(paternity|parenting\s*(time|plan))/i',
          '/\b(divorcio|custodia|familia)/i',
          '/\bmanutencion/i',
          // Child safety — drugs/substance around children.
          '/\b(drugs?|meth|heroin|fentanyl|substance)\s*(around|near|with)\s*(my\s*)?(kids?|children)/i',
          '/\b(kids?|children)\s*(around|exposed\s*to|near)\s*(drugs?|meth|substances?)/i',
          '/\b(ex|partner|spouse)\s*(is\s*)?(using|on|doing)\s*(drugs?|meth|heroin|fentanyl)/i',
          // Gap 6: Spanish restraining/protection order queries.
          '/\border[_\s]de[_\s](restricci[oó]n|protecci[oó]n|alejamiento)\b/ui',
          '/\b(como|donde|cu[aá]ndo)\s+(consigo|obtengo|pido|solicito)\s+(una\s+)?orden\b/ui',
        ],
        'keywords' => ['family', 'divorce', 'custody', 'child_support', 'visitation', 'adoption', 'domestic', 'protection_order', 'divorcio', 'custodia', 'child_safety'],
        'service_area' => 'family',
        'weight' => 0.75,
      ],

      // Seniors.
      'topic_seniors' => [
        'patterns' => [
          '/\b(senior|elderly|older\s*adult)\s*(legal|law|issue|help)?/i',
          '/\belder\s*(care|abuse|law)/i',
          '/\b(nursing\s*home|assisted\s*living)/i',
          '/\b(guardianship|conservator)/i',
          '/\b(anciano|persona\s*mayor|tercera\s*edad)/i',
          // Exploitation / financial abuse of elderly.
          '/\b(caretaker|caregiver)\s*(is\s*)?(steal|stole|stealing|taking|abuse|abusing)/i',
          '/\b(power\s*of\s*attorney|poa)\s*(abuse|steal|misuse)/i',
          // Probate / estate / wills.
          '/\b(probate|estate\s*plan|inherit(ance)?)\b/i',
          '/\b(died|passed\s*away)\s*(and\s*)?(without|no|didn\'?t\s*have)\s*(a\s*)?(will|trust)/i',
          '/\b(parent|mom|dad|mother|father)\s*(just\s*)?(died|passed)\b/i',
        ],
        'keywords' => ['senior', 'seniors', 'elderly', 'older_adult', 'elder_law', 'nursing_home', 'guardianship', 'anciano', 'caretaker', 'probate', 'estate', 'inheritance', 'power_of_attorney'],
        'service_area' => 'seniors',
        'weight' => 0.75,
      ],

      // Benefits / Health.
      'topic_benefits' => [
        'patterns' => [
          '/\b(medicaid|medicare|snap|food\s*stamps|ssi|ssdi|tanf)/i',
          '/\b(benefits?|public\s*(assistance|benefits))/i',
          '/\b(denied\s*(benefits|coverage|claim))/i',
          '/\b(disability\s*(benefits|claim|appeal))/i',
          '/\b(beneficios|estampillas\s*de\s*comida)/i',
        ],
        'keywords' => ['medicaid', 'medicare', 'snap', 'ssi', 'ssdi', 'benefits', 'food_stamps', 'tanf', 'beneficios'],
        'service_area' => 'health',
        'weight' => 0.75,
      ],

      // Health.
      // NOTE: Medical bills/debt are CONSUMER issues (debt collection), not health.
      // Only healthcare access, insurance, and benefits belong here.
      'topic_health' => [
        'patterns' => [
          '/\b(health\s*(insurance|coverage|care|plan))/i',
          '/\b(healthcare\s*(access|coverage|plan))/i',
          '/\b(insurance\s*(denied|coverage|claim))/i',
          '/\b(disability|disabled)\s*(benefits?|claim|appeal)?/i',
          '/\b(salud|seguro\s*medico|cobertura\s*medica)/i',
          '/\b(need|get|find)\s*(health|medical)\s*(insurance|coverage)/i',
        ],
        'keywords' => ['healthcare', 'insurance', 'disability', 'benefits', 'medicaid', 'medicare', 'snap', 'ssi', 'ssdi'],
        'service_area' => 'health',
        'weight' => 0.75,
      ],

      // Consumer.
      // NOTE: Medical bills/debt (collection issues) belong here, not in health.
      'topic_consumer' => [
        'patterns' => [
          '/\b(consumer|debt|collection|credit|scam|fraud)/i',
          '/\b(bankruptcy|garnishment|repossession)/i',
          '/\bbill\s*collector/i',
          '/\bdebt\s*collector/i',
          '/\b(identity\s*theft|stolen\s*identity)/i',
          '/\b(payday\s*loan|predatory\s*lending)/i',
          '/\bgot\s*scammed/i',
          '/\bfake\s*contractor/i',
          // Gap 5: contractor disputes / business disputes.
          '/\bcontractor\s+(who\s+)?(took|stole|kept|ran\s+off\s+with)\s+(my\s+|the\s+)?money\b/i',
          '/\b(business|contract)\s+dispute\b/i',
          '/\bcontractor\s+(fraud|dispute|problem|issue|scam|rip.?off)\b/i',
          // Medical debt patterns (consumer issue, not health)
          '/\b(medical|hospital)\s*(bills?|debt|collection)/i',
          '/\b(can\'?t|cannot|can\s*not)\s*pay\s*(my\s*)?(medical|hospital|doctor)/i',
          '/\bbills?\s*(i\s*)?(can\'?t|cannot)\s*pay/i',
          '/\b(owe|owing|owed)\s*(money|on|for)/i',
          '/\bcollector\s*(keeps?\s*)?(calling|harassing)/i',
          '/\bcalling\s*(me\s*)?(at\s*work|constantly|every\s*day)/i',
          '/\b(creditor|collector)\s*(harassment|harassing|calling)/i',
          // Sued for debt (not escalation)
          '/\bsued\s*(for\s*)?(a\s*)?(debt|money|bill)/i',
          '/\b(got|received)\s*(court\s*papers?|summons)\s*(for\s*)?(debt|money)/i',
          // Garnishment variants
          '/\b(wage|wages|salary|paycheck)\s*(is\s*)?(being\s*)?(garnish|garnished|garnishment)/i',
          '/\b(garnish|garnished|garnishment)\s*(my\s*)?(wage|wages|salary|paycheck)/i',
          '/\b(bank\s*account)\s*(frozen|levy|levied|seized)/i',
          '/\b(froze|freeze|frozen)\s*(my\s*)?(bank|account)/i',
          // Repossession variants
          '/\b(car|vehicle|auto)\s*(repossess|repossessed|repossession|repo)/i',
          '/\b(repossess|repossessed|repo)\s*(my\s*)?(car|vehicle|auto)/i',
          '/\b(my\s*)?(car|vehicle|auto)\s*(was|got|is\s*being)\s*(repossess|repossessed|repo)/i',
          '/\b(they\s*)?(took|taking)\s*(my\s*)?(car|vehicle|auto)/i',
          '/\b(my\s*)?(car|vehicle|auto)\s*(is\s*|was\s*)?gone\b/i',
          '/\b(woke\s*up|came\s*out)\s*(and\s*)?(my\s*)?(car|vehicle|auto)\s*(was\s*|is\s*)?gone/i',
          '/\btowed\s*(my\s*)?(car|vehicle|auto)/i',
          // Debt collector rights (consumer topic, not escalation)
          '/\b(rights|right)\s*(with|against|when)\s*(debt|bill)?\s*(collector|creditor)/i',
          '/\bwhat\s*(are)?\s*(my)?\s*rights\s*(with|when|if)\s*(debt|collector|creditor)/i',
          // Spanish - debt collection
          '/\b(estafa|fraude|deuda|deudas|bancarrota)/i',
          '/\brobaron\s*mi\s*identidad/i',
          '/\bme\s*estafaron/i',
          '/\bcobrador\s*(de\s*deudas?)?/i',
          '/\b(embargo|embargando|embargar)\s*(el\s*)?(sueldo|salario)?/i',
          '/\bme\s*(estan\s*)?embargando/i',
          '/\b(deuda|deudas|facturas?)\s*medica/i',
          '/\bfacturas?\s*(medicas?|del\s*hospital)/i',
          '/\bno\s*puedo\s*pagar/i',
          '/\bme\s*llama(n)?\s*(todos?\s*los?\s*dias?|constantemente|al\s*trabajo)/i',
          '/\bme\s*quitaron\s*(el\s*)?(carro|coche|auto|vehiculo)/i',
          '/\breposici[oó]n|reposesi[oó]n/i',
        ],
        'keywords' => ['consumer', 'debt', 'collection', 'credit', 'scam', 'fraud', 'bankruptcy', 'identity_theft', 'garnishment', 'repossession', 'medical_bills', 'hospital_bills', 'estafa', 'fraude', 'cobrador', 'embargo', 'deuda'],
        'service_area' => 'consumer',
        'weight' => 0.80,
      ],

      // Civil Rights.
      'topic_civil_rights' => [
        'patterns' => [
          '/\b(civil\s*rights|discrimination|harassment)/i',
          '/\b(unfair|illegal)\s*(treatment|firing|termination)/i',
          '/\b(employment\s*(discrimination|rights)|workplace\s*discrimination)/i',
          '/\b(voting|voting\s*rights)/i',
          '/\b(discriminacion|derechos\s*civiles)/i',
        ],
        'keywords' => ['civil_rights', 'discrimination', 'harassment', 'unfair_treatment', 'voting_rights', 'workplace', 'discriminacion'],
        'service_area' => 'civil_rights',
        'weight' => 0.75,
      ],

      'topic_employment' => [
        'patterns' => [
          '/\b(fired|laid\s*off|let\s*go|terminated)\b/i',
          '/\b(wrongful\s*(termination|firing|dismissal))/i',
          '/\b(unpaid\s*wages?|wage\s*theft|back\s*pay)/i',
          '/\b(not\s*(getting|been)\s*paid|employer\s*owes)/i',
          '/\b(final\s*paycheck|last\s*paycheck)/i',
          '/\b(hostile\s*work\s*environment)/i',
          '/\b(retaliation\s*(at|from)\s*work)/i',
          '/\b(sexual\s*harassment\s*(at|from)\s*(work|job|employer))/i',
          '/\b(work(ers?)?\s*comp(ensation)?(\s*denied)?)/i',
          '/\b(FMLA|family\s*medical\s*leave)/i',
          '/\b(despedido|me\s*despidieron|perdí\s*mi\s*trabajo)/i',
          '/\b(no\s*me\s*pagan|salario\s*no\s*pagado)/i',
          '/\b(acoso\s*(en\s*el\s*trabajo|laboral))/i',
          '/\b(discriminaci[oó]n\s*(laboral|en\s*el\s*trabajo))/i',
        ],
        'keywords' => ['fired', 'laid_off', 'wrongful_termination', 'unpaid_wages', 'wage_theft', 'employer', 'paycheck', 'hostile_work', 'retaliation', 'workers_comp', 'despedido', 'salario'],
        'service_area' => 'civil_rights',
        'weight' => 0.75,
      ],
    ];
  }

  /**
   * Vague / single-word queries that are too ambiguous to route confidently.
   *
   * Each entry maps a lowercased query to the disambiguation rule key.
   *
   * @var array
   */
  protected $vagueQueries;

  /**
   * Initializes disambiguation rules.
   */
  protected function initializeDisambiguationRules() {
    $this->disambiguationRules = [
      // Topic detected but no clear action.
      'topic_no_action' => [
        'condition' => 'topic_only',
        'question' => 'What would you like to do?',
        'options' => [
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Call advice line', 'intent' => 'legal_advice_line'],
        ],
      ],
      // Apply vs information.
      'apply_vs_info' => [
        'condition' => 'apply_info_ambiguous',
        'question' => 'Are you looking for information or do you need legal help?',
        'options' => [
          ['label' => 'Find information', 'intent' => 'faq'],
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ],
      ],
      // Forms vs guides.
      'forms_vs_guides' => [
        'condition' => 'forms_guides_ambiguous',
        'question' => 'What type of help do you need?',
        'options' => [
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ],
      ],
      // Contact disambiguation.
      'contact_how' => [
        'condition' => 'contact_ambiguous',
        'question' => 'How would you like to contact us?',
        'options' => [
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find office locations', 'intent' => 'offices_contact'],
          ['label' => 'Apply online', 'intent' => 'apply_for_help'],
        ],
      ],
      // Generic help — no specific action.
      'generic_help' => [
        'condition' => 'help_ambiguous',
        'question' => 'How can we help you today?',
        'options' => [
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
          ['label' => 'Find office locations', 'intent' => 'offices_contact'],
          ['label' => 'Find forms', 'intent' => 'forms_finder'],
          ['label' => 'Read a guide', 'intent' => 'guides_finder'],
        ],
      ],
    ];

    // Vague / too-short queries that should trigger clarification instead
    // of routing confidently. Keyed by lowercased exact query.
    $this->vagueQueries = [
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

  }

  /**
   * Routes a user message to an intent.
   *
   * @param string $message
   *   The user's message.
   * @param array $context
   *   Conversation context.
   *
   * @return array
   *   Intent array with 'type', 'confidence', and additional data.
   */
  public function route(string $message, array $context = []) {
    // Step 1: Run through keyword extraction pipeline.
    $extraction = $this->normalizeExtraction(
      $this->keywordExtractor->extract($message),
      $message
    );
    unset($context);

    // Step 2: Use normalized text for intent matching.
    $normalized = $extraction['normalized'];
    $original = $extraction['original'];

    // Check for greeting first (only if message is very short).
    // Skip greeting detection if message contains topic-specific keywords
    // to prevent false positives like "child custody forms" being detected
    // as greeting because "child" contains "hi" as a substring.
    if (strlen($message) < 30 && $this->matchesIntent($original, 'greeting') && !$this->containsTopicKeywords($original)) {
      return [
        'type' => 'greeting',
        'confidence' => 0.95,
        'extraction' => $extraction,
      ];
    }

    // Step 5a: Check for vague/ambiguous queries that need clarification.
    // This runs BEFORE topic routing so single-word topic queries like
    // "divorce" or "forms" get a clarification prompt instead of being
    // routed to a topic page without context.
    // Use the new Disambiguator service for deterministic vague query detection.
    if ($this->disambiguator) {
      $disamb_result = $this->disambiguator->check($message, [], ['extraction' => $extraction]);
      if ($disamb_result && in_array($disamb_result['reason'] ?? '', ['vague_query', 'topic_without_action'])) {
        $disamb_result['extraction'] = $extraction;
        return $disamb_result;
      }
    }
    // Fallback to legacy vague query check if disambiguator not available.
    else {
      $vague_result = $this->checkVagueQuery($message, $extraction);
      if ($vague_result) {
        return $vague_result;
      }
    }

    // Step 5a1: Check for UI troubleshooting complaints ("buttons aren't
    // showing", "I can't see the options", etc.) before topic routing so
    // these don't get misrouted to a service area.
    $ui_result = $this->checkUiTroubleshooting($message);
    if ($ui_result) {
      $ui_result['extraction'] = $extraction;
      return $ui_result;
    }

    // Step 5a2: Mixed forms+guides phrasing should clarify, not guess.
    $mixed_resource_disambiguation = $this->checkMixedFormsGuidesDisambiguation($message, $extraction);
    if ($mixed_resource_disambiguation) {
      return $mixed_resource_disambiguation;
    }

    // Step 5b: TopicRouter - handle short/single-token topic queries.
    // This catches bare topic words like "divorce", "eviction", "custody"
    // that should ask what action the user wants (forms, guides, apply).
    if ($this->topicRouter && str_word_count($message) <= 4) {
      $topic_route = $this->topicRouter->route($message);
      if ($topic_route) {
        $service_area = $topic_route['service_area'];
        // Single topic words are inherently ambiguous — the user hasn't
        // specified an action. Return disambiguation instead of routing.
        // Flatten question/options to top level so processIntent reads them.
        $disamb = $this->getTopicDisambiguation($service_area);
        return [
          'type' => 'disambiguation',
          'area' => $service_area,
          'intent_source' => 'topic_router',
          'confidence' => 0.5,
          'needs_disambiguation' => TRUE,
          'question' => $disamb['question'],
          'options' => $disamb['options'],
          'topic_route' => $topic_route,
          'extraction' => $extraction,
        ];
      }
    }

    // Step 5c: NavigationIntent - detect "where do I find X" / "show me X"
    // navigation queries and resolve to a specific page URL.
    if ($this->navigationIntent) {
      $nav_result = $this->navigationIntent->detect($message);
      if ($nav_result && !empty($nav_result['top_match']) && $nav_result['confidence'] >= 0.65) {
        $top = $nav_result['top_match'];
        return [
          'type' => 'navigation',
          'page_key' => $top['page_key'],
          'page_url' => $top['url'],
          'page_label' => $top['label'],
          'confidence' => $nav_result['confidence'],
          'match_type' => $top['match_type'],
          'has_nav_phrasing' => $nav_result['has_nav_phrasing'],
          'all_matches' => $nav_result['matches'],
          'extraction' => $extraction,
        ];
      }
    }

    // Step 6: Score all intents and find matches.
    $matches = $this->scoreAllIntents($message, $extraction);

    // Step 7: Check if disambiguation is needed.
    $disambiguation = $this->checkDisambiguation($matches, $extraction);
    if ($disambiguation) {
      return $disambiguation;
    }

    // Step 8: Return highest confidence match if above threshold.
    if (!empty($matches) && $matches[0]['confidence'] >= self::CONFIDENCE_LOW) {
      $best = $matches[0];
      return [
        'type' => $best['intent'],
        'confidence' => $best['confidence'],
        'extraction' => $extraction,
        'competing_intents' => array_slice($matches, 1, 2),
      ];
    }

    // Step 9: Check topic/service area intents.
    $topic_intents = [
      'topic_housing',
      'topic_family',
      'topic_seniors',
      'topic_benefits',
      'topic_health',
      'topic_consumer',
      'topic_civil_rights',
      'topic_employment',
    ];

    foreach ($topic_intents as $intent) {
      if ($this->matchesIntent($original, $intent) || $this->matchesIntent($normalized, $intent)) {
        // Topic detected - may need disambiguation.
        // Flatten question/options to top level so processIntent reads them.
        $service_area = $this->patterns[$intent]['service_area'];
        $disamb = $this->getTopicDisambiguation($service_area);
        return [
          'type' => 'service_area',
          'area' => $service_area,
          'intent_source' => $intent,
          'confidence' => 0.7,
          'needs_disambiguation' => TRUE,
          'question' => $disamb['question'],
          'options' => $disamb['options'],
          'extraction' => $extraction,
        ];
      }
    }

    // Step 10: Try to detect topic from message using taxonomy.
    $topic = $this->topicResolver->resolveFromText($message);
    if ($topic) {
      return [
        'type' => 'topic',
        'topic_id' => $topic['id'],
        'topic' => $topic['name'],
        'confidence' => 0.6,
        'extraction' => $extraction,
      ];
    }

    // Step 11: TopIntentsPack synonym fallback — catches sub-topic intents
    // like "custody", "eviction", "debt collection" that have no regex
    // patterns but do have synonyms in the pack.
    if ($this->topIntentsPack && mb_strlen(trim($message)) >= 4) {
      $pack_match = $this->topIntentsPack->matchSynonyms(mb_strtolower(trim($message)));
      if ($pack_match) {
        $pack_entry = $this->topIntentsPack->lookup($pack_match);
        return [
          'type' => $pack_match,
          'confidence' => 0.60,
          'source' => 'top_intents_pack',
          'pack_entry' => $pack_entry,
          'extraction' => $extraction,
        ];
      }
    }

    // Step 11b: Generic resource fallback (last resort before unknown).
    $has_resource_verb = (bool) preg_match('/\b(where|how|find|get|need|looking\s*for)\b/i', $message);
    $has_resource_noun = (bool) preg_match('/\b(resource|resources|form|forms|guide|guides|faq|information|info|help|services?)\b/i', $message);
    if ($has_resource_verb && $has_resource_noun) {
      return [
        'type' => 'resources',
        'topic' => $message,
        'confidence' => 0.5,
        'extraction' => $extraction,
      ];
    }

    // Step 12: Default: unknown intent (triggers fallback).
    return [
      'type' => 'unknown',
      'confidence' => 0.2,
      'extraction' => $extraction,
    ];
  }

  /**
   * Normalizes keyword extractor output to required router keys.
   *
   * @param array|null $extraction
   *   Raw extraction output.
   * @param string $message
   *   Original message.
   *
   * @return array
   *   Extraction payload with required keys.
   */
  protected function normalizeExtraction(?array $extraction, string $message): array {
    $base = [
      'original' => $message,
      'normalized' => mb_strtolower(trim($message)),
    ];

    if (!is_array($extraction)) {
      return $base;
    }

    $normalized = array_merge($base, $extraction);
    $normalized['original'] = (string) ($normalized['original'] ?? $message);
    $normalized['normalized'] = (string) ($normalized['normalized'] ?? mb_strtolower(trim($normalized['original'])));
    unset($normalized['high_risk'], $normalized['out_of_scope']);

    return $normalized;
  }

  /**
   * Scores all intents against the message.
   *
   * @param string $message
   *   The user's message.
   * @param array $extraction
   *   The extraction result.
   *
   * @return array
   *   Sorted array of intent matches with confidence scores.
   */
  protected function scoreAllIntents(string $message, array $extraction): array {
    $matches = [];
    $original = $extraction['original'];
    $normalized = $extraction['normalized'];

    $primary_intents = [
      'eligibility',
      'apply_for_help',
      'legal_advice_line',
      'offices_contact',
      'services_overview',
      'risk_detector',
      'donations',
      'feedback',
      'faq',
      'forms_inventory',
      'forms_finder',
      'guides_finder',
      'resources',
    ];

    foreach ($primary_intents as $intent) {
      // Skip if alias.
      if (isset($this->patterns[$intent]['alias_of'])) {
        continue;
      }

      // Skip if negative keywords are present for this intent.
      if ($this->keywordExtractor->hasNegativeKeyword($intent, $original)) {
        continue;
      }

      $confidence = $this->calculateIntentConfidence($intent, $original, $normalized, $extraction);

      if ($confidence > 0.3) {
        $matches[] = [
          'intent' => $intent,
          'confidence' => $confidence,
        ];
      }
    }

    // Sort by confidence descending.
    usort($matches, function ($a, $b) {
      return $b['confidence'] <=> $a['confidence'];
    });

    return $matches;
  }

  /**
   * Calculates confidence score for a specific intent.
   *
   * @param string $intent
   *   The intent to check.
   * @param string $original
   *   Original message.
   * @param string $normalized
   *   Normalized message.
   * @param array $extraction
   *   Extraction data.
   *
   * @return float
   *   Confidence score 0-1.
   */
  protected function calculateIntentConfidence(string $intent, string $original, string $normalized, array $extraction): float {
    if (!isset($this->patterns[$intent])) {
      return 0.0;
    }

    $base_weight = $this->patterns[$intent]['weight'] ?? 0.7;
    $confidence = 0.0;

    // Check patterns.
    $pattern_matches = 0;
    if (!empty($this->patterns[$intent]['patterns'])) {
      foreach ($this->patterns[$intent]['patterns'] as $pattern) {
        if (preg_match($pattern, $original) || preg_match($pattern, $normalized)) {
          $pattern_matches++;
        }
      }
    }

    if ($pattern_matches > 0) {
      // Base confidence from pattern match.
      $confidence = $base_weight;
      // Bonus for multiple pattern matches.
      $confidence += min(0.1, $pattern_matches * 0.03);
    }

    // Check negative patterns - if any match, this intent should not be selected.
    if (!empty($this->patterns[$intent]['negative_patterns'])) {
      foreach ($this->patterns[$intent]['negative_patterns'] as $negative_pattern) {
        if (preg_match($negative_pattern, $original) || preg_match($negative_pattern, $normalized)) {
          // Negative pattern matched - return 0 confidence.
          return 0.0;
        }
      }
    }

    // Check keyword matches.
    $keyword_matches = 0;
    if (!empty($this->patterns[$intent]['keywords'])) {
      $message_lower = strtolower($original);
      foreach ($this->patterns[$intent]['keywords'] as $keyword) {
        $keyword_lower = strtolower($keyword);
        $candidates = [$keyword_lower];
        $keyword_spaced = str_replace('_', ' ', $keyword_lower);
        if ($keyword_spaced !== $keyword_lower) {
          $candidates[] = $keyword_spaced;
        }

        foreach (array_unique($candidates) as $candidate) {
          $pattern = '/\b' . preg_quote($candidate, '/') . '\b/';
          if (preg_match($pattern, $message_lower)) {
            $keyword_matches++;
            break;
          }
        }
      }
    }

    if ($keyword_matches > 0 && $confidence == 0) {
      // Keyword-only match gets lower confidence.
      $confidence = $base_weight * 0.8;
    }
    elseif ($keyword_matches > 0) {
      // Bonus for keyword matches on top of pattern.
      $confidence += min(0.1, $keyword_matches * 0.02);
    }

    // Phrase match bonus.
    if (!empty($extraction['phrases_found'])) {
      $confidence += 0.05;
    }

    // Short message penalty.
    if (str_word_count($original) < 3) {
      $confidence *= 0.85;
    }

    // Cap at 1.0.
    return min(1.0, $confidence);
  }

  /**
   * Checks if disambiguation is needed.
   *
   * Uses the Disambiguator service for deterministic confidence-delta
   * checks. Falls back to legacy logic if the service is not available.
   *
   * @param array $matches
   *   Scored intent matches.
   * @param array $extraction
   *   Extraction data.
   *
   * @return array|null
   *   Disambiguation response or NULL.
   */
  protected function checkDisambiguation(array $matches, array $extraction): ?array {
    // No matches - no disambiguation needed.
    if (empty($matches)) {
      return NULL;
    }

    $best = $matches[0];
    $second = $matches[1] ?? NULL;

    // High confidence with clear separation - no disambiguation.
    if ($best['confidence'] >= self::CONFIDENCE_HIGH) {
      // Even at high confidence, check for known confusable pairs
      // with very small delta.
      if (!$second || ($best['confidence'] - $second['confidence']) >= 0.10) {
        return NULL;
      }
    }

    // Use the Disambiguator service for delta-based disambiguation.
    if ($this->disambiguator && count($matches) >= 2) {
      $disamb_result = $this->disambiguator->check(
        $extraction['original'] ?? '',
        $matches,
        ['extraction' => $extraction]
      );
      if ($disamb_result) {
        $disamb_result['extraction'] = $extraction;
        return $disamb_result;
      }
    }

    // Legacy fallback: Check for competing intents with similar confidence.
    if ($second) {
      $delta = $best['confidence'] - $second['confidence'];
      $pair = $this->getIntentPairDisambiguation($best['intent'], $second['intent']);

      // Known confusable pair with tight scores → disambiguate.
      if ($pair && $delta < 0.12) {
        return [
          'type' => 'disambiguation',
          'confidence' => $best['confidence'],
          'question' => $pair['question'],
          'options' => $pair['options'],
          'competing_intents' => [$best, $second],
          'extraction' => $extraction,
        ];
      }

      // Unknown pair but still very close → generic disambiguation.
      if ($delta < 0.08 && $best['confidence'] < self::CONFIDENCE_HIGH) {
        return [
          'type' => 'disambiguation',
          'confidence' => $best['confidence'],
          'question' => 'I want to make sure I help you with the right thing. What are you looking for?',
          'options' => [
            ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
            ['label' => 'Find forms', 'intent' => 'forms_finder'],
            ['label' => 'Read a guide', 'intent' => 'guides_finder'],
            ['label' => 'Call advice line', 'intent' => 'legal_advice_line'],
          ],
          'competing_intents' => [$best, $second],
          'extraction' => $extraction,
        ];
      }
    }

    // Check specific disambiguation conditions.
    // Contact ambiguity.
    if (in_array($best['intent'], ['legal_advice_line', 'offices_contact', 'apply_for_help'])) {
      if (preg_match('/\b(contact|reach|get\s*in\s*touch)\b/i', $extraction['original'])) {
        return [
          'type' => 'disambiguation',
          'confidence' => $best['confidence'],
          'question' => $this->disambiguationRules['contact_how']['question'],
          'options' => $this->disambiguationRules['contact_how']['options'],
          'extraction' => $extraction,
        ];
      }
    }

    return NULL;
  }

  /**
   * Checks if the message is a known vague/ambiguous query.
   *
   * These are short, under-specified queries where the user's intent is
   * genuinely unclear. Instead of guessing (and likely guessing wrong),
   * we return a clarifying question with quick-reply options.
   *
   * This method is deterministic — no LLM call.
   *
   * @param string $message
   *   The user message.
   * @param array $extraction
   *   Extraction result.
   *
   * @return array|null
   *   Disambiguation intent or NULL if not vague.
   */
  protected function checkVagueQuery(string $message, array $extraction): ?array {
    $key = strtolower(trim(preg_replace('/[?.!]+$/', '', $message)));

    if (isset($this->vagueQueries[$key])) {
      $rule_key = $this->vagueQueries[$key];
      $rule = $this->disambiguationRules[$rule_key] ?? $this->disambiguationRules['generic_help'];

      return [
        'type' => 'disambiguation',
        'confidence' => 0.3,
        'needs_disambiguation' => TRUE,
        'question' => $rule['question'],
        'options' => $rule['options'],
        'vague_query' => TRUE,
        'extraction' => $extraction,
      ];
    }

    return NULL;
  }

  /**
   * Checks if the message is reporting a UI problem.
   *
   * Detects phrases like "the categories aren't showing up",
   * "buttons are missing", "I can't see any options", etc.
   * These should not be routed to a service area.
   *
   * @param string $message
   *   The user message.
   *
   * @return array|null
   *   UI troubleshooting intent or NULL.
   */
  protected function checkUiTroubleshooting(string $message): ?array {
    $patterns = [
      // English: negation + display verb.
      '/\b(not|aren\'t|isn\'t|don\'t|can\'t|doesn\'t|cant|dont|arent|isnt|doesnt)\s+(show|showing|appear|load|loading|display|displaying|work|working)\b/i',
      // English: UI element + missing/gone.
      '/\b(button|option|categor|chip|link|section|menu)\w*\s*(?:are|is|were|was)?\s*(missing|gone|disappeared|not there|broken)\b/i',
      // English: nothing + display verb.
      '/\bnothing\s+(?:is|was)?\s*(happen|show|appear|load|display|work)\w*\b/i',
      // English: can't/don't see + UI element.
      '/\b(can\'t|don\'t|cannot|cant|dont)\s+see\s+(the|any|my)?\s*(button|option|categor|chip|link|result|choice|menu)\w*/i',
      // Spanish.
      '/\b(no\s*(se\s*)?muestra|no\s*aparece|no\s*funciona|no\s*carga)\b/i',
      '/\bno\s*(se\s*)?(ven|veo)\s*(los|las|ningun|ninguna)?\s*(boton|opcion|categor|enlace)/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return [
          'type' => 'ui_troubleshooting',
          'confidence' => 0.90,
          'reason' => 'ui_complaint_detected',
        ];
      }
    }

    return NULL;
  }

  /**
   * Detects mixed forms/guides phrasing and forces clarify disambiguation.
   */
  protected function checkMixedFormsGuidesDisambiguation(string $message, array $extraction): ?array {
    $normalized = mb_strtolower($message);
    $has_forms = (bool) preg_match('/\b(forms?|formulario(?:s)?|court\s*forms?)\b/u', $normalized);
    $has_guides = (bool) preg_match('/\b(guides?|guide|how[\s-]*to|manuals?|instructions?)\b/u', $normalized);
    if (!$has_forms || !$has_guides) {
      return NULL;
    }

    if ($this->disambiguator) {
      $disamb_result = $this->disambiguator->check(
        $message,
        [
          ['intent' => 'forms_finder', 'confidence' => 0.70],
          ['intent' => 'guides_finder', 'confidence' => 0.69],
        ],
        ['extraction' => $extraction]
      );
      if ($disamb_result) {
        $disamb_result['extraction'] = $extraction;
        return $disamb_result;
      }
    }

    return [
      'type' => 'disambiguation',
      'confidence' => 0.70,
      'reason' => 'mixed_forms_guides',
      'question' => 'What type of resource do you need?',
      'options' => [
        ['label' => 'Court forms to fill out', 'intent' => 'forms_finder'],
        ['label' => 'Step-by-step guide', 'intent' => 'guides_finder'],
        ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
      ],
      'extraction' => $extraction,
    ];
  }

  /**
   * Gets disambiguation for a topic service area.
   *
   * @param string $service_area
   *   The service area.
   *
   * @return array
   *   Disambiguation options.
   */
  protected function getTopicDisambiguation(string $service_area): array {
    $area_label = ucfirst(str_replace('_', ' ', $service_area));

    return [
      'question' => $this->t('I can help with @area issues. What would you like to do?', ['@area' => $area_label]),
      'options' => [
        ['label' => $this->t('Find @area forms', ['@area' => $area_label]), 'intent' => 'forms_finder', 'topic' => $service_area],
        ['label' => $this->t('Read @area guide', ['@area' => $area_label]), 'intent' => 'guides_finder', 'topic' => $service_area],
        ['label' => $this->t('Apply for legal help'), 'intent' => 'apply_for_help'],
        ['label' => $this->t('Call advice line'), 'intent' => 'legal_advice_line'],
      ],
    ];
  }

  /**
   * Gets disambiguation for competing intent pairs.
   *
   * @param string $intent1
   *   First intent.
   * @param string $intent2
   *   Second intent.
   *
   * @return array|null
   *   Disambiguation config or NULL.
   */
  protected function getIntentPairDisambiguation(string $intent1, string $intent2): ?array {
    $pair = [$intent1, $intent2];
    sort($pair);
    $key = implode(':', $pair);

    $pairs = [
      'apply_for_help:eligibility' => [
        'question' => 'Do you want to check eligibility or start an application?',
        'options' => [
          ['label' => 'Check if I qualify', 'intent' => 'eligibility'],
          ['label' => 'Start application', 'intent' => 'apply_for_help'],
        ],
      ],
      'apply_for_help:services_overview' => [
        'question' => 'Are you looking for information about our services or do you want to apply?',
        'options' => [
          ['label' => 'Learn about services', 'intent' => 'services_overview'],
          ['label' => 'Apply for legal help', 'intent' => 'apply_for_help'],
        ],
      ],
      'forms_finder:guides_finder' => [
        'question' => 'What type of help do you need?',
        'options' => [
          ['label' => 'Find forms to fill out', 'intent' => 'forms_finder'],
          ['label' => 'Read a how-to guide', 'intent' => 'guides_finder'],
        ],
      ],
      'legal_advice_line:offices_contact' => [
        'question' => 'How would you like to contact us?',
        'options' => [
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
          ['label' => 'Find office location', 'intent' => 'offices_contact'],
        ],
      ],
      'donations:feedback' => [
        'question' => 'What would you like to do?',
        'options' => [
          ['label' => 'Make a donation', 'intent' => 'donations'],
          ['label' => 'Give feedback or volunteer', 'intent' => 'feedback'],
        ],
      ],
      'faq:guides_finder' => [
        'question' => 'What would be most helpful?',
        'options' => [
          ['label' => 'Quick answer to a question', 'intent' => 'faq'],
          ['label' => 'Detailed guide', 'intent' => 'guides_finder'],
        ],
      ],
      'faq:services_overview' => [
        'question' => 'What are you looking for?',
        'options' => [
          ['label' => 'Answers to common questions', 'intent' => 'faq'],
          ['label' => 'Overview of our services', 'intent' => 'services_overview'],
        ],
      ],
      'apply_for_help:legal_advice_line' => [
        'question' => 'How would you like to get help?',
        'options' => [
          ['label' => 'Apply online', 'intent' => 'apply_for_help'],
          ['label' => 'Call Legal Advice Line', 'intent' => 'legal_advice_line'],
        ],
      ],
    ];

    return $pairs[$key] ?? NULL;
  }

  /**
   * Checks if a message matches an intent.
   *
   * @param string $message
   *   The message to check.
   * @param string $intent
   *   The intent key.
   *
   * @return bool
   *   TRUE if the message matches the intent.
   */
  protected function matchesIntent(string $message, string $intent) {
    if (!isset($this->patterns[$intent])) {
      return FALSE;
    }

    // Handle aliases.
    if (isset($this->patterns[$intent]['alias_of'])) {
      $intent = $this->patterns[$intent]['alias_of'];
    }

    $pattern_data = $this->patterns[$intent];

    // Check regex patterns.
    if (!empty($pattern_data['patterns'])) {
      foreach ($pattern_data['patterns'] as $pattern) {
        if (preg_match($pattern, $message)) {
          return TRUE;
        }
      }
    }

    // Check keywords using word boundary matching to avoid false positives
    // (e.g., "hi" matching inside "child").
    if (!empty($pattern_data['keywords'])) {
      $message_lower = strtolower($message);
      foreach ($pattern_data['keywords'] as $keyword) {
        $keyword_lower = strtolower($keyword);
        // Use word boundary pattern for exact word matching.
        $pattern = '/\b' . preg_quote($keyword_lower, '/') . '\b/';
        if (preg_match($pattern, $message_lower)) {
          return TRUE;
        }
        // Also check underscore-joined version.
        $keyword_underscore = str_replace(' ', '_', $keyword_lower);
        if ($keyword_underscore !== $keyword_lower) {
          $pattern_underscore = '/\b' . preg_quote($keyword_underscore, '/') . '\b/';
          if (preg_match($pattern_underscore, $message_lower)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Resolves an intent alias to its canonical name.
   *
   * @param string $intent
   *   The intent (possibly an alias).
   *
   * @return string
   *   The canonical intent name.
   */
  public function resolveIntentAlias(string $intent): string {
    if (isset($this->patterns[$intent]['alias_of'])) {
      return $this->patterns[$intent]['alias_of'];
    }
    return $intent;
  }

  /**
   * Suggests topics matching a query.
   *
   * @param string $query
   *   The search query.
   *
   * @return array
   *   Array of matching topics.
   */
  public function suggestTopics(string $query) {
    return $this->topicResolver->searchTopics($query, 5);
  }

  /**
   * Gets detailed information about a topic.
   *
   * @param int $topic_id
   *   The topic term ID.
   *
   * @return array|null
   *   Topic information or NULL if not found.
   */
  public function getTopicInfo(int $topic_id) {
    return $this->topicResolver->getTopicInfo($topic_id);
  }

  /**
   * Routes a message and returns debug information.
   *
   * @param string $message
   *   The user's message.
   *
   * @return array
   *   Detailed routing result with extraction data.
   */
  public function routeWithDebug(string $message): array {
    return $this->route($message);
  }

  /**
   * Gets the safe response template for an intent.
   *
   * @param string $intent
   *   The intent type.
   * @param array $context
   *   Additional context (e.g., topic, category).
   *
   * @return array
   *   Response template with message, links, and cta.
   */
  public function getSafeResponseTemplate(string $intent, array $context = []): array {
    $canonical_urls = $this->getCanonicalUrls();

    switch ($intent) {
      case 'urgent_safety':
        return $this->getUrgentSafetyTemplate($context['category'] ?? 'urgent_dv');

      case 'apply_for_help':
        return [
          'message' => $this->t('Idaho Legal Aid provides free legal help to eligible Idahoans. To find out if you qualify and apply for assistance, you can complete our online application or call our Legal Advice Line.'),
          'links' => [
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
        ];

      case 'legal_advice_line':
        return [
          'message' => $this->t('Our Legal Advice Line can help answer your legal questions and determine if you qualify for ILAS services.'),
          'links' => [
            ['label' => $this->t('Contact Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'primary'],
          ],
        ];

      case 'offices_contact':
        return [
          'message' => $this->t('Idaho Legal Aid has offices throughout Idaho. Find the location nearest you.'),
          'links' => [
            ['label' => $this->t('Find Offices'), 'url' => $canonical_urls['offices'], 'type' => 'primary'],
          ],
        ];

      case 'forms_finder':
        $topic = $context['topic'] ?? NULL;
        if ($topic) {
          return [
            'message' => $this->t('Here are forms related to @topic. Need help completing them? Call our Legal Advice Line.', ['@topic' => $topic]),
            'links' => [
              ['label' => $this->t('View Forms'), 'url' => $canonical_urls['forms'], 'type' => 'primary'],
              ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
            ],
          ];
        }
        return [
          'message' => $this->t('Browse our collection of legal forms. Need help completing them? Call our Legal Advice Line.'),
          'links' => [
            ['label' => $this->t('Find Forms'), 'url' => $canonical_urls['forms'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
        ];

      case 'guides_finder':
        return [
          'message' => $this->t('Our self-help guides provide step-by-step information on common legal issues.'),
          'links' => [
            ['label' => $this->t('Find Guides'), 'url' => $canonical_urls['guides'], 'type' => 'primary'],
          ],
        ];

      case 'donations':
        return [
          'message' => $this->t('Thank you for considering a donation! Your support helps us provide free legal services to Idahoans in need.'),
          'links' => [
            ['label' => $this->t('Donate'), 'url' => $canonical_urls['donate'], 'type' => 'primary'],
          ],
        ];

      case 'feedback':
        return [
          'message' => $this->t('We value your feedback. Please share your experience or concerns.'),
          'links' => [
            ['label' => $this->t('Give Feedback'), 'url' => $canonical_urls['feedback'], 'type' => 'primary'],
          ],
        ];

      case 'faq':
        return [
          'message' => $this->t('Browse our frequently asked questions for answers to common legal questions.'),
          'links' => [
            ['label' => $this->t('View FAQs'), 'url' => $canonical_urls['faq'], 'type' => 'primary'],
          ],
        ];

      case 'risk_detector':
        return [
          'message' => $this->t('Our Legal Risk Detector can help identify potential legal issues you may be facing.'),
          'links' => [
            ['label' => $this->t('Take Assessment'), 'url' => $canonical_urls['senior_risk_detector'], 'type' => 'primary'],
          ],
        ];

      case 'services_overview':
        return [
          'message' => $this->t('Idaho Legal Aid Services provides free civil legal help in areas including housing, family law, consumer issues, public benefits, and more.'),
          'links' => [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'primary'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'secondary'],
          ],
        ];

      case 'out_of_scope':
        return $this->getOutOfScopeTemplate($context['reason'] ?? 'general');

      default:
        return [
          'message' => $this->t("I'm not sure I understood. Are you looking for help with a legal issue?"),
          'links' => [
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
        ];
    }
  }

  /**
   * Gets urgent safety response template.
   *
   * @param string $category
   *   The urgent category.
   *
   * @return array
   *   Response template.
   */
  protected function getUrgentSafetyTemplate(string $category): array {
    $canonical_urls = $this->getCanonicalUrls();

    switch ($category) {
      case 'urgent_dv':
        return [
          'message' => $this->t("If you are in immediate danger, please call 911.\n\nFor domestic violence support:\n- National DV Hotline: 1-800-799-7233\n- Idaho DV Hotline: 1-800-669-3176\n\nIdaho Legal Aid can help with protection orders and safety planning."),
          'links' => [
            ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
            ['label' => $this->t('National DV Hotline'), 'url' => 'tel:18007997233', 'type' => 'crisis'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
          'safety_notice' => TRUE,
        ];

      case 'urgent_eviction':
        return [
          'message' => $this->t("If you have an eviction hearing today or tomorrow, act now:\n\n1. Go to court at the scheduled time\n2. Ask for a continuance if you need more time\n3. Call our Legal Advice Line immediately\n\nWe may be able to help you respond to your eviction."),
          'links' => [
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'primary'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'secondary'],
            ['label' => $this->t('Eviction Forms'), 'url' => $canonical_urls['forms'] . '?topic=eviction', 'type' => 'tertiary'],
          ],
          'safety_notice' => TRUE,
        ];

      case 'urgent_scam':
        return [
          'message' => $this->t("If you're experiencing identity theft or a scam:\n\n1. Contact your bank immediately if you shared financial info\n2. Report to FTC: reportfraud.ftc.gov or 1-877-438-4338\n3. Place a fraud alert with credit bureaus\n\nIdaho Legal Aid can help with consumer fraud cases."),
          'links' => [
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
            ['label' => $this->t('Consumer Guide'), 'url' => $canonical_urls['service_areas']['consumer'], 'type' => 'tertiary'],
          ],
          'safety_notice' => TRUE,
        ];

      case 'urgent_deadline':
        return [
          'message' => $this->t("If you have a legal deadline today or tomorrow:\n\n1. Don't ignore the deadline - this can result in a default judgment\n2. Call our Legal Advice Line now for urgent guidance\n3. If you can't meet the deadline, you may be able to request an extension"),
          'links' => [
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'primary'],
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'secondary'],
          ],
          'safety_notice' => TRUE,
        ];

      default:
        return [
          'message' => $this->t("If this is an emergency, please call 911.\n\nFor legal help, contact our Legal Advice Line."),
          'links' => [
            ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'primary'],
          ],
          'safety_notice' => TRUE,
        ];
    }
  }

  /**
   * Gets out-of-scope response template.
   *
   * @param string $reason
   *   The reason for out-of-scope.
   *
   * @return array
   *   Response template.
   */
  protected function getOutOfScopeTemplate(string $reason): array {
    $canonical_urls = $this->getCanonicalUrls();

    switch ($reason) {
      case 'criminal':
        return [
          'message' => $this->t("Idaho Legal Aid handles civil legal matters only. For criminal cases:\n\n- If you can't afford an attorney, ask the court to appoint a public defender\n- Idaho State Bar Lawyer Referral: (208) 334-4500\n\nIf you have a civil legal issue related to your situation, we may be able to help."),
          'links' => [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
        ];

      case 'immigration':
        return [
          'message' => $this->t("Idaho Legal Aid doesn't currently handle immigration cases. For immigration help:\n\n- Catholic Charities of Idaho: (208) 345-6031\n- ACLU of Idaho: (208) 344-9750"),
          'links' => [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'primary'],
          ],
        ];

      case 'out_of_state':
        return [
          'message' => $this->t("Idaho Legal Aid serves Idaho residents only. For legal help in other states, LawHelp.org can connect you with legal aid in your state."),
          'links' => [
            ['label' => $this->t('LawHelp.org'), 'url' => 'https://www.lawhelp.org', 'type' => 'external'],
          ],
        ];

      case 'business':
        return [
          'message' => $this->t("Idaho Legal Aid focuses on civil legal issues for low-income individuals. For business matters:\n\n- Idaho Secretary of State: sos.idaho.gov\n- Idaho Small Business Development Center"),
          'links' => [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'primary'],
          ],
        ];

      case 'emergency_911':
        return [
          'message' => $this->t("If this is an emergency, please call 911 immediately.\n\nFor non-emergency legal help, contact our Legal Advice Line."),
          'links' => [
            ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'primary'],
          ],
        ];

      default:
        return [
          'message' => $this->t("I understand you're looking for help, but this may be outside what Idaho Legal Aid can assist with. Would you like to explore our services or speak with someone?"),
          'links' => [
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'primary'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'secondary'],
          ],
        ];
    }
  }

  /**
   * Checks if message contains topic-specific keywords.
   *
   * Used to prevent greeting false positives on queries like
   * "child custody forms" which should not be detected as greetings.
   *
   * @param string $message
   *   The message to check.
   *
   * @return bool
   *   TRUE if the message contains topic keywords.
   */
  protected function containsTopicKeywords(string $message): bool {
    $message_lower = strtolower($message);

    // Topic keywords that should prevent greeting detection.
    $topic_keywords = [
      // Legal topics.
      'custody', 'divorce', 'eviction', 'landlord', 'tenant',
      'bankruptcy', 'foreclosure', 'guardianship', 'adoption',
      'visitation', 'protection', 'restraining', 'support',
      'debt', 'collection', 'garnishment', 'scam', 'fraud',
      // Actions/resources.
      'forms', 'form', 'guides', 'guide', 'apply', 'application',
      'hotline', 'office', 'location', 'address', 'hours',
      'donate', 'donation', 'feedback', 'complaint', 'faq',
      'services', 'eligibility', 'qualify',
      // Family law.
      'child', 'children', 'spouse', 'husband', 'wife', 'partner',
      'family', 'domestic', 'paternity', 'alimony', 'separation',
      // Housing.
      'rent', 'lease', 'apartment', 'housing', 'home', 'mortgage',
      // Consumer.
      'credit', 'identity', 'theft', 'repossession', 'payday',
      // Employment.
      'fired', 'wages', 'employer', 'harassment', 'discrimination',
      // Benefits.
      'medicaid', 'medicare', 'snap', 'ssi', 'ssdi', 'benefits',
      'disability', 'insurance',
      // Safety.
      'violence', 'abusive', 'stalking', 'threatened', 'deadline',
      // Spanish equivalents.
      'custodia', 'divorcio', 'desalojo', 'formulario', 'formularios',
      'oficina', 'ayuda', 'abogado', 'servicios', 'violencia',
    ];

    foreach ($topic_keywords as $keyword) {
      // Use word boundary matching.
      $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
      if (preg_match($pattern, $message_lower)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets canonical URLs.
   *
   * @return array
   *   Array of canonical URLs.
   */
  protected function getCanonicalUrls(): array {
    if (function_exists('ilas_site_assistant_get_canonical_urls')) {
      return ilas_site_assistant_get_canonical_urls();
    }
    return [
      'apply' => '/apply-for-help',
      'hotline' => '/Legal-Advice-Line',
      'offices' => '/contact-us',
      'feedback' => '/get-involved/feedback',
      'forms' => '/forms',
      'guides' => '/guides',
      'faq' => '/faq',
      'resources' => '/what-we-do/resources',
      'services' => '/services',
      'donate' => '/donate',
      'senior_risk_detector' => '/senior-risk-detector',
      'service_areas' => [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];
  }

}
