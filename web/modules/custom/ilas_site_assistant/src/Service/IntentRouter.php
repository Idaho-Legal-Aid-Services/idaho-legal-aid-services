<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for routing user messages to intents.
 *
 * Based on ILAS Intent + Routing Map v5.
 */
class IntentRouter {

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
   * Intent patterns.
   *
   * @var array
   */
  protected $patterns;

  /**
   * Constructs an IntentRouter object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TopicResolver $topic_resolver) {
    $this->configFactory = $config_factory;
    $this->topicResolver = $topic_resolver;
    $this->initializePatterns();
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
        ],
        'keywords' => ['hi', 'hello', 'hey', 'greetings'],
      ],

      // Eligibility intent (separate from apply).
      'eligibility' => [
        'patterns' => [
          '/\b(do\s*i\s*qualify|am\s*i\s*eligible|eligibility|who\s*(can|is\s*able\s*to)\s*(get|qualify))/i',
          '/\b(who\s*can\s*get\s*help|who\s*do\s*you\s*(help|serve))/i',
          '/\b(income\s*(limit|requirement|guideline)|qualify\s*for\s*(help|services))/i',
          '/\b(can\s*i\s*get\s*help|can\s*you\s*help\s*me)/i',
        ],
        'keywords' => ['qualify', 'eligible', 'eligibility', 'who can get help'],
      ],

      // Apply for help intent.
      'apply' => [
        'patterns' => [
          '/\b(apply|application|sign\s*up)\s*(for)?\s*(help|assistance|services)?/i',
          '/\bhow\s*(do\s*i|can\s*i|to)\s*(apply|get\s*started)/i',
          '/\bneed\s*(legal)?\s*(help|assistance|a\s*lawyer|an?\s*attorney)/i',
          '/\bget\s*started/i',
          '/\bwant\s*to\s*apply/i',
          // Attorney/lawyer seeking patterns.
          '/\b(find|get|need|looking\s*for)\s*(a|an)?\s*(lawyer|attorney|legal\s*(help|aid|assistance))/i',
          '/\bhow\s*(do\s*i|can\s*i|to)\s*(find|get)\s*(a|an)?\s*(lawyer|attorney)/i',
        ],
        'keywords' => ['apply', 'application', 'sign up', 'get help', 'need help', 'get started'],
      ],

      // Hotline intent.
      'hotline' => [
        'patterns' => [
          '/\b(call|phone|hotline|advice\s*line|talk\s*to)/i',
          '/\bcontact\s*(a|the)?\s*(lawyer|attorney|someone)/i',
          '/\bspeak\s*(with|to)\s*(someone|a\s*person)/i',
          '/\bphone\s*number/i',
        ],
        'keywords' => ['call', 'phone', 'hotline', 'advice line', 'talk', 'speak', 'phone number'],
      ],

      // Offices intent.
      'offices' => [
        'patterns' => [
          '/\b(office|location|address|where\s*(are\s*you|is))/i',
          '/\b(near\s*me|closest|nearby)/i',
          '/\bvisit\s*(in\s*person|your\s*office)/i',
        ],
        'keywords' => ['office', 'offices', 'location', 'address', 'near me', 'visit'],
      ],

      // Services overview intent.
      'services' => [
        'patterns' => [
          '/\b(what\s*(do\s*you|does\s*ilas)\s*do|what\s*services)/i',
          '/\b(types?\s*of\s*(help|services|cases)|areas?\s*of\s*(law|practice))/i',
          '/\b(what\s*(kind|type)\s*of\s*(help|cases)|practice\s*areas?)/i',
          '/\bservices\s*(overview|offered|available)/i',
        ],
        'keywords' => ['services', 'what do you do', 'types of help', 'practice areas'],
      ],

      // FAQ intent.
      'faq' => [
        'patterns' => [
          '/\b(faq|frequently\s*asked|common\s*question)/i',
          '/\b(do\s*you\s*have|is\s*there)\s*(a|any)?\s*(question|answer)/i',
          // Definitional questions.
          '/\bwhat\s+(does|do|is|are|\'s)\s+.{2,}/i',
          '/\b(what\s+is\s+)?(the\s+)?difference\s+between/i',
          '/\bdefine\s+|definition\s+of/i',
          '/\bmeaning\s+of\b/i',
          '/\bexplain\s+(what|the)/i',
          // How does X work / How do I questions (informational).
          '/\bhow\s+(does|do|can)\s+.{2,}\s+(work|mean)/i',
        ],
        'keywords' => ['faq', 'question', 'questions', 'frequently asked'],
      ],

      // Forms intent.
      'forms' => [
        'patterns' => [
          '/\b(find|get|need|download|where)\s*(a|the|is|are)?\s*form/i',
          '/\bform\s*(for|to|about)/i',
          '/\bapplication\s*form/i',
          '/\b(eviction|divorce|custody|guardianship)\s*(form|paperwork)/i',
        ],
        'keywords' => ['form', 'forms', 'paperwork', 'document', 'application form'],
      ],

      // Guides intent.
      'guides' => [
        'patterns' => [
          '/\b(find|get|need|read|where)\s*(a|the|is|are)?\s*guide/i',
          '/\bguide\s*(for|to|about|on)/i',
          '/\bhow\s*to\s*(guide|manual)/i',
          '/\bstep[\s-]*by[\s-]*step/i',
        ],
        'keywords' => ['guide', 'guides', 'manual', 'instructions', 'how-to', 'step by step'],
      ],

      // Resources intent.
      'resources' => [
        'patterns' => [
          '/\b(find|get|need|where)\s*(a|the|is|are)?\s*resource/i',
          '/\bresource\s*(for|about|on)/i',
          '/\bdownload|printable|pdf/i',
        ],
        'keywords' => ['resource', 'resources', 'download', 'printable'],
      ],

      // Risk Detector intent (standalone).
      'risk_detector' => [
        'patterns' => [
          '/\b(risk\s*(detector|assessment|quiz|tool))/i',
          '/\b(legal\s*risk|check\s*my\s*risk)/i',
          '/\b(senior|elder)\s*(risk|quiz|assessment)/i',
        ],
        'keywords' => ['risk detector', 'risk assessment', 'risk quiz', 'legal risk'],
      ],

      // Donate intent.
      'donate' => [
        'patterns' => [
          '/\b(donate|donation|give|support|contribute)/i',
          '/\bhow\s*(can\s*i|to)\s*(help|support|give)/i',
        ],
        'keywords' => ['donate', 'donation', 'give', 'support', 'contribute', 'gift'],
      ],

      // Feedback intent.
      'feedback' => [
        'patterns' => [
          '/\b(feedback|complaint|suggest|comment)/i',
          '/\b(tell|share)\s*(my|your)?\s*(experience|story)/i',
        ],
        'keywords' => ['feedback', 'complaint', 'suggestion', 'comment'],
      ],

      // === SERVICE AREA / TOPIC INTENTS ===

      // Housing.
      'topic_housing' => [
        'patterns' => [
          '/\b(housing|eviction|landlord|tenant|rent|lease|apartment|home)/i',
          '/\bkick(ed|ing)?\s*(me)?\s*out/i',
          '/\bforeclou?sure/i',
          '/\b(section\s*8|hud|public\s*housing)/i',
        ],
        'keywords' => ['housing', 'eviction', 'landlord', 'tenant', 'rent', 'lease', 'foreclosure'],
        'service_area' => 'housing',
      ],

      // Family.
      'topic_family' => [
        'patterns' => [
          '/\b(family|divorce|custody|child\s*support|visitation|adoption)/i',
          '/\b(separation|domestic|guardian)/i',
          '/\bprotection\s*order/i',
          '/\b(paternity|parenting\s*(time|plan))/i',
        ],
        'keywords' => ['family', 'divorce', 'custody', 'child support', 'visitation', 'adoption', 'domestic', 'protection order'],
        'service_area' => 'family',
      ],

      // Seniors.
      'topic_seniors' => [
        'patterns' => [
          '/\b(senior|elderly|older\s*adult)\s*(legal|law|issue|help)?/i',
          '/\belder\s*(care|abuse|law)/i',
          '/\b(nursing\s*home|assisted\s*living)/i',
          '/\b(guardianship|conservator)/i',
        ],
        'keywords' => ['senior', 'seniors', 'elderly', 'older adult', 'elder law', 'nursing home', 'guardianship'],
        'service_area' => 'seniors',
      ],

      // Benefits / Health.
      'topic_benefits' => [
        'patterns' => [
          '/\b(medicaid|medicare|snap|food\s*stamps|ssi|ssdi|tanf)/i',
          '/\b(benefits?|public\s*(assistance|benefits))/i',
          '/\b(denied\s*(benefits|coverage|claim))/i',
          '/\b(disability\s*(benefits|claim|appeal))/i',
        ],
        'keywords' => ['medicaid', 'medicare', 'snap', 'ssi', 'ssdi', 'benefits', 'food stamps', 'tanf'],
        'service_area' => 'health',
      ],

      // Health.
      'topic_health' => [
        'patterns' => [
          '/\b(health|medical|insurance|healthcare)/i',
          '/\b(health\s*(insurance|coverage|care))/i',
          '/\b(medical\s*(bills?|debt|issue))/i',
          '/\b(disability|disabled)/i',
        ],
        'keywords' => ['health', 'medical', 'insurance', 'disability', 'healthcare'],
        'service_area' => 'health',
      ],

      // Consumer.
      'topic_consumer' => [
        'patterns' => [
          '/\b(consumer|debt|collection|credit|scam|fraud)/i',
          '/\b(bankruptcy|garnishment|repossession)/i',
          '/\bbill\s*collector/i',
          '/\b(identity\s*theft|stolen\s*identity)/i',
          '/\b(payday\s*loan|predatory\s*lending)/i',
        ],
        'keywords' => ['consumer', 'debt', 'collection', 'credit', 'scam', 'fraud', 'bankruptcy', 'identity theft'],
        'service_area' => 'consumer',
      ],

      // Civil Rights.
      'topic_civil_rights' => [
        'patterns' => [
          '/\b(civil\s*rights|discrimination|harassment)/i',
          '/\b(unfair|illegal)\s*(treatment|firing|termination)/i',
          '/\b(employment\s*(discrimination|rights)|workplace\s*discrimination)/i',
          '/\b(voting|voting\s*rights)/i',
        ],
        'keywords' => ['civil rights', 'discrimination', 'harassment', 'unfair treatment', 'voting rights', 'workplace'],
        'service_area' => 'civil_rights',
      ],
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
   *   Intent array with 'type' and additional data.
   */
  public function route(string $message, array $context = []) {
    $message_lower = strtolower($message);

    // Check for greeting first (only if message is very short).
    if (strlen($message) < 30 && $this->matchesIntent($message, 'greeting')) {
      return ['type' => 'greeting'];
    }

    // Check primary intents in priority order.
    $primary_intents = [
      'eligibility',
      'apply',
      'hotline',
      'offices',
      'services',
      'risk_detector',
      'donate',
      'feedback',
      'faq',
      'forms',
      'guides',
      'resources',
    ];

    foreach ($primary_intents as $intent) {
      if ($this->matchesIntent($message, $intent)) {
        return ['type' => $intent];
      }
    }

    // Check topic/service area intents.
    $topic_intents = [
      'topic_housing',
      'topic_family',
      'topic_seniors',
      'topic_benefits',
      'topic_health',
      'topic_consumer',
      'topic_civil_rights',
    ];

    foreach ($topic_intents as $intent) {
      if ($this->matchesIntent($message, $intent)) {
        return [
          'type' => 'service_area',
          'area' => $this->patterns[$intent]['service_area'],
          'intent_source' => $intent,
        ];
      }
    }

    // Try to detect topic from message using taxonomy.
    $topic = $this->topicResolver->resolveFromText($message);
    if ($topic) {
      return [
        'type' => 'topic',
        'topic_id' => $topic['id'],
        'topic' => $topic['name'],
      ];
    }

    // Check if message looks like a question about finding something.
    if (preg_match('/\b(where|how|find|get|need|looking\s*for)\b/i', $message)) {
      // Try resources as fallback.
      return [
        'type' => 'resources',
        'topic' => $message,
      ];
    }

    // Default: unknown intent (triggers fallback).
    return ['type' => 'unknown'];
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

    $pattern_data = $this->patterns[$intent];

    // Check regex patterns.
    if (!empty($pattern_data['patterns'])) {
      foreach ($pattern_data['patterns'] as $pattern) {
        if (preg_match($pattern, $message)) {
          return TRUE;
        }
      }
    }

    // Check keywords.
    if (!empty($pattern_data['keywords'])) {
      $message_lower = strtolower($message);
      foreach ($pattern_data['keywords'] as $keyword) {
        if (strpos($message_lower, strtolower($keyword)) !== FALSE) {
          return TRUE;
        }
      }
    }

    return FALSE;
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

}
