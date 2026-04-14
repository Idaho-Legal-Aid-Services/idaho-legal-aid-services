<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Shared response builder for the site assistant.
 *
 * Produces a canonical response contract used by both the API controller
 * and the evaluation harness. Every response includes:
 *   - intent_selected (string)
 *   - intent_confidence (float)
 *   - response_mode: navigate | topic | answer | clarify | fallback
 *   - primary_action: {label, url} (required for navigate/topic modes)
 *   - secondary_actions[] (optional)
 *   - answer_text (string, optional but should not replace actions)
 *   - reason_code (string)
 *   - type (legacy response type for backwards compat)
 *
 * This class has NO Drupal service dependencies and can be used standalone
 * in eval harnesses, Drush commands, or unit tests.
 */
class ResponseBuilder {

  /**
   * Response mode constants.
   */
  const MODE_NAVIGATE = 'navigate';
  const MODE_TOPIC = 'topic';
  const MODE_ANSWER = 'answer';
  const MODE_CLARIFY = 'clarify';
  const MODE_FALLBACK = 'fallback';

  /**
   * Canonical URL map.
   *
   * @var array
   */
  protected $canonicalUrls;

  /**
   * Optional Top Intents Pack for sub-topic responses.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack|null
   */
  protected $topIntentsPack;

  /**
   * Constructs a ResponseBuilder.
   *
   * @param array $canonical_urls
   *   Canonical URL map resolved at runtime.
   * @param \Drupal\ilas_site_assistant\Service\TopIntentsPack|null $top_intents_pack
   *   Optional Top Intents Pack for sub-topic fallback responses.
   */
  public function __construct(array $canonical_urls, ?TopIntentsPack $top_intents_pack = NULL) {
    $this->canonicalUrls = $canonical_urls;
    if (!isset($this->canonicalUrls['service_areas']) || !is_array($this->canonicalUrls['service_areas'])) {
      $this->canonicalUrls['service_areas'] = [];
    }
    $this->topIntentsPack = $top_intents_pack;
  }

  /**
   * Maps canonical intent names to legacy switch-case labels.
   *
   * @return array
   *   Mapping of canonical → legacy intent names.
   */
  public static function getIntentAliases(): array {
    return [
      'apply_for_help' => 'apply',
      'legal_advice_line' => 'hotline',
      'offices_contact' => 'offices',
      'donations' => 'donate',
      'forms_finder' => 'forms',
      'forms_inventory' => 'forms_inventory',
      'guides_inventory' => 'guides_inventory',
      'services_inventory' => 'services_inventory',
      'guides_finder' => 'guides',
      'services_overview' => 'services',
    ];
  }

  /**
   * Normalizes an intent type to its legacy switch label.
   *
   * @param string $intent_type
   *   The intent type (canonical or legacy).
   *
   * @return string
   *   The normalized legacy intent label.
   */
  public static function normalizeIntentType(string $intent_type): string {
    $aliases = self::getIntentAliases();
    return $aliases[$intent_type] ?? $intent_type;
  }

  /**
   * Builds a canonical response from an intent.
   *
   * This is the single source of truth for mapping intents to response
   * structures. Both the API controller and eval harness must use this.
   *
   * @param array $intent
   *   The routed intent with at least 'type' key.
   * @param string $message
   *   The user's message (used for fallback context).
   *
   * @return array
   *   Canonical response contract.
   */
  public function buildFromIntent(array $intent, string $message = ''): array {
    $intent_type = self::normalizeIntentType($intent['type'] ?? 'unknown');
    $original_intent = $intent['type'] ?? 'unknown';

    $response = [
      'intent_selected' => $original_intent,
      'intent_confidence' => $intent['confidence'] ?? 0.85,
      'response_mode' => self::MODE_FALLBACK,
      'primary_action' => NULL,
      'secondary_actions' => [],
      'answer_text' => '',
      'reason_code' => 'intent_' . $original_intent,
      // Legacy type field for backwards compat.
      'type' => 'fallback',
    ];

    switch ($intent_type) {
      case 'navigation':
        // NavigationIntent detected a page-finding query.
        $page_url = $intent['page_url'] ?? $this->canonicalUrls['apply'];
        $page_label = $intent['page_label'] ?? 'View Page';
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => $page_label,
          'url' => $page_url,
        ];
        $response['answer_text'] = 'Here is the page you\'re looking for:';
        $response['reason_code'] = 'navigation_page_match';
        break;

      case 'apply':
        $online_app_url = $this->canonicalUrls['online_application'] ?? $this->canonicalUrls['apply'];
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'apply_cta';
        $response['primary_action'] = [
          'label' => 'Start online application',
          'url' => $online_app_url,
        ];
        $response['secondary_actions'] = [
          ['label' => 'Call (208) 746-7541', 'url' => 'tel:208-746-7541'],
          ['label' => 'Find an office', 'url' => $this->canonicalUrls['offices']],
        ];
        $response['answer_text'] = 'Idaho Legal Aid Services offers three ways to apply—choose what works best for you.';
        $response['reason_code'] = 'direct_navigation_apply';
        break;

      case 'hotline':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => 'Contact Hotline',
          'url' => $this->canonicalUrls['hotline'],
        ];
        $response['answer_text'] = 'Our Legal Advice Line can help, and Spanish interpretation is available. Here\'s the information:';
        $response['reason_code'] = 'direct_navigation_hotline';
        break;

      case 'offices':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => 'Find Offices',
          'url' => $this->canonicalUrls['offices'],
        ];
        $response['answer_text'] = 'Find an office near you:';
        $response['reason_code'] = 'direct_navigation_offices';
        break;

      case 'donate':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => 'Donate',
          'url' => $this->canonicalUrls['donate'],
        ];
        $response['answer_text'] = 'Thank you for considering a donation! Here\'s how you can help:';
        $response['reason_code'] = 'direct_navigation_donate';
        break;

      case 'feedback':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => 'Give Feedback',
          'url' => $this->canonicalUrls['feedback'],
        ];
        $response['answer_text'] = 'We value your feedback:';
        $response['reason_code'] = 'direct_navigation_feedback';
        break;

      case 'forms_inventory':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'forms_inventory';
        $response['primary_action'] = [
          'label' => 'Browse All Forms',
          'url' => $this->canonicalUrls['forms'],
        ];
        $response['answer_text'] = 'We have forms and resources organized by legal topic. Choose a category:';
        $response['reason_code'] = 'forms_inventory';
        break;

      case 'guides_inventory':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'guides_inventory';
        $response['primary_action'] = [
          'label' => 'Browse All Guides',
          'url' => $this->canonicalUrls['guides'],
        ];
        $response['answer_text'] = 'We have self-help guides organized by legal topic. Choose a category:';
        $response['reason_code'] = 'guides_inventory';
        break;

      case 'services_inventory':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'services_inventory';
        $response['primary_action'] = [
          'label' => 'Apply for Help',
          'url' => $this->canonicalUrls['apply'],
        ];
        $response['secondary_actions'] = $this->buildServiceAreaActions();
        $response['answer_text'] = 'Idaho Legal Aid Services provides free civil legal help in these areas:';
        $response['reason_code'] = 'services_inventory';
        break;

      case 'forms':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'resources';
        $response['primary_action'] = [
          'label' => 'Find Forms',
          'url' => $this->canonicalUrls['forms'],
        ];
        $response['answer_text'] = 'Here are some forms that might help:';
        $response['reason_code'] = 'resource_match_found';
        // Note: the controller may override type to 'form_finder_clarify'
        // for bare/generic requests with no topic keywords.
        break;

      case 'guides':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'resources';
        $response['primary_action'] = [
          'label' => 'Find Guides',
          'url' => $this->canonicalUrls['guides'],
        ];
        $response['answer_text'] = 'Here are some guides that might help:';
        $response['reason_code'] = 'resource_match_found';
        // Note: the controller may override type to 'guide_finder_clarify'
        // for bare/generic requests with no topic keywords.
        break;

      case 'faq':
        $response['response_mode'] = self::MODE_ANSWER;
        $response['type'] = 'faq';
        $response['primary_action'] = [
          'label' => 'Browse FAQs',
          'url' => $this->canonicalUrls['faq'],
        ];
        $response['answer_text'] = 'I found some FAQs that might help:';
        $response['reason_code'] = 'faq_match_found';
        break;

      case 'services':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'services_overview';
        $response['primary_action'] = [
          'label' => 'Apply for Help',
          'url' => $this->canonicalUrls['apply'],
        ];
        $response['secondary_actions'] = array_merge(
          [['label' => 'Our Services', 'url' => $this->canonicalUrls['services']]],
          $this->buildServiceAreaActions()
        );
        $response['answer_text'] = 'Idaho Legal Aid Services provides free civil legal help in areas including housing, family law, consumer issues, public benefits, and more.';
        $response['reason_code'] = 'intent_services';
        break;

      case 'eligibility':
        $response['response_mode'] = self::MODE_ANSWER;
        $response['type'] = 'eligibility';
        $response['primary_action'] = [
          'label' => 'Apply for Help',
          'url' => $this->canonicalUrls['apply'],
        ];
        $response['secondary_actions'] = [
          ['label' => 'Legal Advice Line', 'url' => $this->canonicalUrls['hotline']],
          ['label' => 'Our Services', 'url' => $this->canonicalUrls['services']],
        ];
        $response['answer_text'] = 'ILAS provides free legal help to low-income Idahoans. Eligibility is generally based on income and the type of legal issue.';
        $response['reason_code'] = 'intent_eligibility';
        break;

      case 'risk_detector':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => 'Take the Assessment',
          'url' => $this->canonicalUrls['senior_risk_detector'],
        ];
        $response['answer_text'] = 'Our Legal Risk Detector can help identify potential legal issues you may be facing:';
        $response['reason_code'] = 'direct_navigation_risk_detector';
        break;

      case 'greeting':
        $response['response_mode'] = self::MODE_ANSWER;
        $response['type'] = 'greeting';
        $response['answer_text'] = '';
        $response['reason_code'] = 'greeting';
        break;

      case 'thanks':
        $response['response_mode'] = self::MODE_ANSWER;
        $response['type'] = 'acknowledgement';
        $response['answer_text'] = 'You\'re welcome. If you need something else, tell me the legal issue or choose a topic.';
        $response['reason_code'] = 'gratitude_acknowledged';
        break;

      case 'high_risk':
        $risk_category = $intent['risk_category'] ?? 'unknown';
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'escalation';
        $response['reason_code'] = 'high_risk_' . $risk_category;

        // Default primary action for high-risk is apply.
        $primary_url = $this->canonicalUrls['apply'];

        // Deadline → hotline.
        if ($risk_category === 'high_risk_deadline') {
          $primary_url = $this->canonicalUrls['hotline'];
        }

        $response['primary_action'] = [
          'label' => 'Apply for Help',
          'url' => $primary_url,
        ];
        $response['secondary_actions'] = [
          ['label' => 'Call Hotline', 'url' => $this->canonicalUrls['hotline']],
        ];

        // Add category-specific secondary actions.
        if ($risk_category === 'high_risk_dv') {
          $response['secondary_actions'][] = [
            'label' => 'National DV Hotline: 1-800-799-7233',
            'url' => 'tel:18007997233',
          ];
          $response['secondary_actions'][] = [
            'label' => 'Protection Order Forms',
            'url' => $this->canonicalUrls['forms'],
          ];
        }
        elseif ($risk_category === 'high_risk_scam') {
          $response['secondary_actions'][] = [
            'label' => 'Report to FTC',
            'url' => 'https://reportfraud.ftc.gov',
          ];
        }
        $response['answer_text'] = $this->getHighRiskMessage($risk_category);
        break;

      case 'urgent_safety':
        // Urgent safety exits come from the authoritative pre-routing decision.
        // Map category to high-risk handling.
        $category = $intent['category'] ?? 'urgent_dv';
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'escalation';
        $response['reason_code'] = 'urgent_safety_' . $category;

        // Map urgent_safety categories to response config.
        $category_config = [
          'urgent_dv' => [
            'primary_url' => $this->canonicalUrls['apply'],
            'primary_label' => 'Apply for Help',
            'risk_key' => 'high_risk_dv',
            'secondary' => [
              ['label' => 'National DV Hotline: 1-800-799-7233', 'url' => 'tel:18007997233'],
              ['label' => 'Protection Order Forms', 'url' => $this->canonicalUrls['forms']],
            ],
          ],
          'urgent_eviction' => [
            'primary_url' => $this->canonicalUrls['apply'],
            'primary_label' => 'Apply for Help',
            'risk_key' => 'high_risk_eviction',
            'secondary' => [
              ['label' => 'Call Hotline', 'url' => $this->canonicalUrls['hotline']],
            ],
          ],
          'urgent_scam' => [
            'primary_url' => $this->canonicalUrls['apply'],
            'primary_label' => 'Apply for Help',
            'risk_key' => 'high_risk_scam',
            'secondary' => [
              ['label' => 'Report to FTC', 'url' => 'https://reportfraud.ftc.gov'],
            ],
          ],
          'urgent_deadline' => [
            'primary_url' => $this->canonicalUrls['hotline'],
            'primary_label' => 'Call Legal Advice Line',
            'risk_key' => 'high_risk_deadline',
            'secondary' => [
              ['label' => 'Apply for Help', 'url' => $this->canonicalUrls['apply']],
            ],
          ],
        ];

        $config = $category_config[$category] ?? $category_config['urgent_dv'];

        $response['primary_action'] = [
          'label' => $config['primary_label'],
          'url' => $config['primary_url'],
        ];
        $response['secondary_actions'] = $config['secondary'];
        $response['answer_text'] = $this->getHighRiskMessage($config['risk_key']);
        break;

      case 'out_of_scope':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'escalation';
        $response['primary_action'] = [
          'label' => 'Our Services',
          'url' => $this->canonicalUrls['services'],
        ];
        $response['secondary_actions'] = [
          ['label' => 'Call Hotline', 'url' => $this->canonicalUrls['hotline']],
          ['label' => 'Idaho State Bar Referral', 'url' => 'https://isb.idaho.gov/ilrs/'],
        ];
        $response['answer_text'] = 'This type of legal matter may be outside our service area.';
        $response['reason_code'] = 'out_of_scope';
        break;

      case 'disambiguation':
      case 'clarify':
        $response['response_mode'] = self::MODE_CLARIFY;
        $response['type'] = 'fallback';
        $response['primary_action'] = [
          'label' => 'Apply for Legal Help',
          'url' => $this->canonicalUrls['apply'],
        ];
        $response['secondary_actions'] = [
          ['label' => 'Find Forms', 'url' => $this->canonicalUrls['forms']],
          ['label' => 'Search FAQs', 'url' => $this->canonicalUrls['faq']],
          ['label' => 'Call Hotline', 'url' => $this->canonicalUrls['hotline']],
        ];
        $response['answer_text'] = 'I want to make sure I understand. Could you tell me more about what you\'re looking for?';
        $response['reason_code'] = 'clarification_needed';
        break;

      case 'service_area':
        $area = $intent['area'] ?? '';
        $url = $this->canonicalUrls['service_areas'][$area] ?? $this->canonicalUrls['services'];
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'navigation';
        $response['primary_action'] = [
          'label' => ucfirst(str_replace('_', ' ', $area)) . ' Legal Help',
          'url' => $url,
        ];
        $response['answer_text'] = 'Here\'s our ' . str_replace('_', ' ', $area) . ' legal help page:';
        $response['reason_code'] = 'direct_navigation_service_area';
        break;

      case 'topic':
        $response['response_mode'] = self::MODE_TOPIC;
        $response['type'] = 'topic';
        $response['primary_action'] = [
          'label' => 'Learn More',
          'url' => $this->canonicalUrls['services'],
        ];
        $response['reason_code'] = 'topic_match';
        break;

      case 'resources':
        $response['response_mode'] = self::MODE_NAVIGATE;
        $response['type'] = 'resources';
        $response['primary_action'] = [
          'label' => 'Browse Resources',
          'url' => $this->canonicalUrls['resources'],
        ];
        $response['answer_text'] = 'Here are some resources that might help:';
        $response['reason_code'] = 'resource_match_found';
        break;

      default:
        // Before generic fallback, check TopIntentsPack for sub-topic data.
        if ($this->topIntentsPack) {
          $pack_entry = $this->topIntentsPack->lookup($original_intent);
          if ($pack_entry) {
            $response['response_mode'] = self::MODE_TOPIC;
            $response['type'] = 'topic';
            $response['answer_text'] = $pack_entry['answer_text'] ?? '';
            $response['reason_code'] = 'intent_pack_' . $original_intent;
            if (!empty($pack_entry['primary_action'])) {
              $response['primary_action'] = $pack_entry['primary_action'];
            }
            break;
          }
        }

        // Unknown / fallback.
        $response['response_mode'] = self::MODE_FALLBACK;
        $response['type'] = 'fallback';
        $response['primary_action'] = [
          'label' => 'Apply for Help',
          'url' => $this->canonicalUrls['apply'],
        ];
        $response['secondary_actions'] = $this->buildServiceAreaActions();
        $response['answer_text'] = 'I\'m not sure I understood. Are you looking for help with one of these areas?';
        $response['reason_code'] = 'no_match_fallback';
        break;
    }

    return $response;
  }

  /**
   * Builds service area secondary actions.
   *
   * @return array
   *   Array of secondary action links.
   */
  protected function buildServiceAreaActions(): array {
    $areas = $this->canonicalUrls['service_areas'] ?? [];
    $actions = [];
    foreach ($areas as $area => $url) {
      $actions[] = [
        'label' => ucfirst(str_replace('_', ' ', $area)),
        'url' => $url,
      ];
    }
    return $actions;
  }

  /**
   * Returns appropriate message for high-risk situations.
   *
   * @param string $risk_category
   *   The risk category.
   *
   * @return string
   *   Safety message.
   */
  protected function getHighRiskMessage(string $risk_category): string {
    $messages = [
      'high_risk_dv' => 'We understand you may be in a difficult situation. Your safety is important. Idaho Legal Aid can help with protection orders and safety planning. If you are in immediate danger, please call 911.',
      'high_risk_eviction' => 'If you\'ve received an eviction notice, it\'s important to act quickly. Idaho Legal Aid can help you understand your rights and respond to the notice. Please apply for help or call our hotline as soon as possible.',
      'high_risk_scam' => 'We\'re sorry to hear you may have been the victim of a scam or identity theft. Idaho Legal Aid can help you understand your options and take steps to protect yourself. Please apply for help right away.',
      'high_risk_deadline' => 'With an urgent legal deadline, please call our Legal Advice Line immediately for assistance. Time-sensitive legal matters require prompt attention.',
      'high_risk_utility' => 'If your utilities have been or are about to be shut off, Idaho Legal Aid may be able to help. Please apply for help or call our hotline immediately.',
    ];

    return $messages[$risk_category] ?? 'We see this may be an urgent situation. Please apply for help or call our Legal Advice Line for immediate assistance.';
  }

  /**
   * Extracts the primary action URL from a canonical response.
   *
   * Convenience method for eval harness and tests.
   *
   * @param array $response
   *   Canonical response from buildFromIntent().
   *
   * @return string|null
   *   The primary action URL, or NULL.
   */
  public static function extractPrimaryActionUrl(array $response): ?string {
    return $response['primary_action']['url'] ?? NULL;
  }

  /**
   * Maps a response mode to legacy response types for backwards compat.
   *
   * @param string $mode
   *   The response mode.
   *
   * @return string
   *   Legacy response type.
   */
  public static function modeToLegacyType(string $mode): string {
    $map = [
      self::MODE_NAVIGATE => 'navigation',
      self::MODE_TOPIC => 'topic',
      self::MODE_ANSWER => 'faq',
      self::MODE_CLARIFY => 'fallback',
      self::MODE_FALLBACK => 'fallback',
    ];
    return $map[$mode] ?? 'fallback';
  }

  /**
   * Resolves an intent name to its canonical URL.
   *
   * @param string $intent_name
   *   The intent type name.
   *
   * @return string|null
   *   The URL or NULL.
   */
  public function resolveIntentUrl(string $intent_name): ?string {
    $intent_url_map = [
      'navigation' => NULL,
      'apply' => 'apply',
      'apply_for_help' => 'apply',
      'hotline' => 'hotline',
      'legal_advice_line' => 'hotline',
      'offices' => 'offices',
      'offices_contact' => 'offices',
      'donate' => 'donate',
      'donations' => 'donate',
      'feedback' => 'feedback',
      'forms' => 'forms',
      'forms_finder' => 'forms',
      'forms_inventory' => 'forms',
      'guides_inventory' => 'guides',
      'services_inventory' => 'apply',
      'guides' => 'guides',
      'guides_finder' => 'guides',
      'services' => 'services',
      'services_overview' => 'services',
      'eligibility' => 'apply',
      'risk_detector' => 'senior_risk_detector',
      'faq' => 'faq',
      'resources' => 'resources',
    ];

    if (isset($intent_url_map[$intent_name]) && isset($this->canonicalUrls[$intent_url_map[$intent_name]])) {
      return $this->canonicalUrls[$intent_url_map[$intent_name]];
    }

    // Check service area intents.
    if (str_starts_with($intent_name, 'topic_')) {
      $area = substr($intent_name, 6);
      if (isset($this->canonicalUrls['service_areas'][$area])) {
        return $this->canonicalUrls['service_areas'][$area];
      }
    }

    if (isset($this->canonicalUrls['service_areas'][$intent_name])) {
      return $this->canonicalUrls['service_areas'][$intent_name];
    }

    return NULL;
  }

  /**
   * Enforces canonical URL for hard-route intents.
   *
   * This is the critical method to prevent URL drift. Call this AFTER all
   * enrichment processing to ensure hard-route intents always emit their
   * canonical URL.
   *
   * @param array $response
   *   The response array (must have 'type' and 'primary_action').
   * @param array $intent
   *   The full intent array.
   *
   * @return array
   *   The response with canonical URL enforced (if hard-route).
   */
  public function enforceHardRouteUrl(array $response, array $intent): array {
    $registry = new HardRouteRegistry($this->canonicalUrls);
    return $registry->enforceCanonicalUrl($response, $intent);
  }

  /**
   * Enforces canonical URL with authoritative override intent awareness.
   *
   * This MUST be called AFTER all response enrichment to prevent URL drift.
   *
   * @param array $response
   *   The response array.
   * @param array $intent
   *   The full routed intent array.
   * @param array|null $override_intent
   *   Optional authoritative override intent from PreRoutingDecisionEngine.
   *
   * @return array
   *   The response with canonical URL enforced.
   */
  public function enforceHardRouteUrlWithOverrideIntent(array $response, array $intent, ?array $override_intent = NULL): array {
    $registry = new HardRouteRegistry($this->canonicalUrls);
    return $registry->enforceCanonicalUrlWithOverrideIntent($response, $intent, $override_intent);
  }

  /**
   * Validates that a response has the correct canonical URL.
   *
   * @param array $response
   *   The response array.
   * @param array $intent
   *   The full intent array.
   *
   * @return array
   *   Validation result with 'valid', 'expected', 'actual', 'message' keys.
   */
  public function validateHardRouteUrl(array $response, array $intent): array {
    $registry = new HardRouteRegistry($this->canonicalUrls);
    return $registry->validateCanonicalUrl($response, $intent);
  }

  /**
   * Validates a response with authoritative override intent awareness.
   *
   * @param array $response
   *   The response array.
   * @param array $intent
   *   The full routed intent array.
   * @param array|null $override_intent
   *   Optional authoritative override intent from PreRoutingDecisionEngine.
   *
   * @return array
   *   Validation result.
   */
  public function validateHardRouteUrlWithOverrideIntent(array $response, array $intent, ?array $override_intent = NULL): array {
    $registry = new HardRouteRegistry($this->canonicalUrls);
    $effective_intent = $override_intent ?? $intent;
    $result = $registry->validateCanonicalUrl($response, $effective_intent);

    if ($override_intent !== NULL && ($override_intent['type'] ?? 'unknown') !== ($intent['type'] ?? 'unknown')) {
      $result['override_intent'] = $override_intent['type'] ?? 'unknown';
    }

    return $result;
  }

}
