<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Site Assistant API endpoints.
 */
class AssistantApiController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The intent router service.
   *
   * @var \Drupal\ilas_site_assistant\Service\IntentRouter
   */
  protected $intentRouter;

  /**
   * The FAQ index service.
   *
   * @var \Drupal\ilas_site_assistant\Service\FaqIndex
   */
  protected $faqIndex;

  /**
   * The resource finder service.
   *
   * @var \Drupal\ilas_site_assistant\Service\ResourceFinder
   */
  protected $resourceFinder;

  /**
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected $policyFilter;

  /**
   * The analytics logger service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AnalyticsLogger
   */
  protected $analyticsLogger;

  /**
   * The LLM enhancer service.
   *
   * @var \Drupal\ilas_site_assistant\Service\LlmEnhancer
   */
  protected $llmEnhancer;

  /**
   * Constructs an AssistantApiController object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    IntentRouter $intent_router,
    FaqIndex $faq_index,
    ResourceFinder $resource_finder,
    PolicyFilter $policy_filter,
    AnalyticsLogger $analytics_logger,
    LlmEnhancer $llm_enhancer
  ) {
    $this->configFactory = $config_factory;
    $this->intentRouter = $intent_router;
    $this->faqIndex = $faq_index;
    $this->resourceFinder = $resource_finder;
    $this->policyFilter = $policy_filter;
    $this->analyticsLogger = $analytics_logger;
    $this->llmEnhancer = $llm_enhancer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ilas_site_assistant.intent_router'),
      $container->get('ilas_site_assistant.faq_index'),
      $container->get('ilas_site_assistant.resource_finder'),
      $container->get('ilas_site_assistant.policy_filter'),
      $container->get('ilas_site_assistant.analytics_logger'),
      $container->get('ilas_site_assistant.llm_enhancer')
    );
  }

  /**
   * Handles incoming chat messages.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with assistant reply.
   */
  public function message(Request $request) {
    // Validate content type.
    $content_type = $request->headers->get('Content-Type');
    if (strpos($content_type, 'application/json') === FALSE) {
      return new JsonResponse(['error' => 'Invalid content type'], 400);
    }

    // Parse request body.
    $content = $request->getContent();
    if (strlen($content) > 2000) {
      return new JsonResponse(['error' => 'Request too large'], 413);
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['message'])) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }

    $user_message = $this->sanitizeInput($data['message']);
    $context = $data['context'] ?? [];

    // Check policy violations first.
    $policy_result = $this->policyFilter->check($user_message);
    if ($policy_result['violation']) {
      $this->analyticsLogger->log('policy_violation', $policy_result['type']);
      return new JsonResponse([
        'type' => 'escalation',
        'escalation_type' => $policy_result['type'],
        'escalation_level' => $policy_result['escalation_level'],
        'message' => $policy_result['response'],
        'links' => $policy_result['links'] ?? [],
        'actions' => $this->getEscalationActions(),
      ]);
    }

    // Route the intent.
    $intent = $this->intentRouter->route($user_message, $context);

    // If rule-based routing returns unknown, try LLM classification.
    if ($intent['type'] === 'unknown' && $this->llmEnhancer->isEnabled()) {
      $llm_intent = $this->llmEnhancer->classifyIntent($user_message, 'unknown');
      if ($llm_intent !== 'unknown') {
        $intent = ['type' => $llm_intent, 'source' => 'llm'];
      }
    }

    // Process based on intent.
    $response = $this->processIntent($intent, $user_message, $context);

    // Enhance response with LLM if enabled.
    $response = $this->llmEnhancer->enhanceResponse($response, $user_message);

    // Log the interaction.
    $this->analyticsLogger->log($intent['type'], $intent['value'] ?? '');

    // Check if we found any results.
    if (empty($response['results']) && $response['type'] !== 'navigation') {
      $this->analyticsLogger->logNoAnswer($user_message);
    }

    return new JsonResponse($response);
  }

  /**
   * Returns quick suggestions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with suggestions.
   */
  public function suggest(Request $request) {
    $query = $request->query->get('q', '');
    $type = $request->query->get('type', 'all');

    $suggestions = [];

    if (strlen($query) >= 2) {
      $query = $this->sanitizeInput($query);

      if ($type === 'all' || $type === 'topics') {
        $topics = $this->intentRouter->suggestTopics($query);
        foreach ($topics as $topic) {
          $suggestions[] = [
            'type' => 'topic',
            'label' => $topic['name'],
            'id' => $topic['id'],
          ];
        }
      }

      if ($type === 'all' || $type === 'faq') {
        $faqs = $this->faqIndex->search($query, 3);
        foreach ($faqs as $faq) {
          $suggestions[] = [
            'type' => 'faq',
            'label' => $faq['question'],
            'id' => $faq['id'],
          ];
        }
      }
    }

    return new JsonResponse([
      'suggestions' => array_slice($suggestions, 0, 6),
    ]);
  }

  /**
   * Returns FAQ data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with FAQ data.
   */
  public function faq(Request $request) {
    $query = $request->query->get('q', '');
    $id = $request->query->get('id');

    if ($id) {
      // Get specific FAQ item.
      $faq = $this->faqIndex->getById($id);
      if ($faq) {
        return new JsonResponse(['faq' => $faq]);
      }
      return new JsonResponse(['error' => 'FAQ not found'], 404);
    }

    // Search FAQs.
    if (strlen($query) >= 2) {
      $query = $this->sanitizeInput($query);
      $results = $this->faqIndex->search($query, 5);
      return new JsonResponse([
        'results' => $results,
        'count' => count($results),
      ]);
    }

    // Return all FAQ categories.
    $categories = $this->faqIndex->getCategories();
    return new JsonResponse(['categories' => $categories]);
  }

  /**
   * Processes an intent and returns a response.
   *
   * @param array $intent
   *   The detected intent.
   * @param string $message
   *   The user's message.
   * @param array $context
   *   Conversation context.
   *
   * @return array
   *   Response data.
   */
  protected function processIntent(array $intent, string $message, array $context) {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $canonical_urls = ilas_site_assistant_get_canonical_urls();

    switch ($intent['type']) {
      case 'faq':
        if (!$config->get('enable_faq')) {
          return [
            'type' => 'navigation',
            'message' => $this->t('You can find frequently asked questions on our FAQ page.'),
            'url' => $canonical_urls['faq'],
          ];
        }
        $results = $this->faqIndex->search($message, 3);
        return [
          'type' => 'faq',
          'message' => count($results) > 0
            ? $this->t('I found some FAQs that might help:')
            : $this->t('I couldn\'t find a matching FAQ. Try our FAQ page or contact us for help.'),
          'results' => $results,
          'fallback_url' => $canonical_urls['faq'],
        ];

      case 'forms':
        if (!$config->get('enable_resources')) {
          return [
            'type' => 'navigation',
            'message' => $this->t('You can find forms on our Forms page.'),
            'url' => $canonical_urls['forms'],
          ];
        }
        $results = $this->resourceFinder->findForms($intent['topic'] ?? $message, 3);
        return [
          'type' => 'resources',
          'message' => count($results) > 0
            ? $this->t('Here are some forms that might help:')
            : $this->t('I couldn\'t find matching forms. Browse our Forms page or contact us.'),
          'results' => $results,
          'fallback_url' => $canonical_urls['forms'],
        ];

      case 'guides':
        if (!$config->get('enable_resources')) {
          return [
            'type' => 'navigation',
            'message' => $this->t('You can find guides on our Guides page.'),
            'url' => $canonical_urls['guides'],
          ];
        }
        $results = $this->resourceFinder->findGuides($intent['topic'] ?? $message, 3);
        return [
          'type' => 'resources',
          'message' => count($results) > 0
            ? $this->t('Here are some guides that might help:')
            : $this->t('I couldn\'t find matching guides. Browse our Guides page or contact us.'),
          'results' => $results,
          'fallback_url' => $canonical_urls['guides'],
        ];

      case 'resources':
        if (!$config->get('enable_resources')) {
          return [
            'type' => 'navigation',
            'message' => $this->t('You can browse resources on our Resources page.'),
            'url' => $canonical_urls['resources'],
          ];
        }
        $results = $this->resourceFinder->findResources($intent['topic'] ?? $message, 3);
        return [
          'type' => 'resources',
          'message' => count($results) > 0
            ? $this->t('Here are some resources that might help:')
            : $this->t('I couldn\'t find matching resources. Browse our Resources page or contact us.'),
          'results' => $results,
          'fallback_url' => $canonical_urls['resources'],
        ];

      case 'topic':
        $topic_info = $this->intentRouter->getTopicInfo($intent['topic_id']);
        if ($topic_info) {
          $service_area_url = $topic_info['service_area_url'] ?? NULL;
          return [
            'type' => 'topic',
            'message' => $this->t('Here\'s information about @topic:', ['@topic' => $topic_info['name']]),
            'topic' => $topic_info,
            'service_area_url' => $service_area_url,
          ];
        }
        return [
          'type' => 'navigation',
          'message' => $this->t('Browse our service areas to find information on your topic.'),
          'url' => $canonical_urls['services'],
        ];

      case 'service_area':
        $area = $intent['area'];
        $url = $canonical_urls['service_areas'][$area] ?? $canonical_urls['services'];
        return [
          'type' => 'navigation',
          'message' => $this->t('Here\'s our @area legal help page:', ['@area' => ucfirst(str_replace('_', ' ', $area))]),
          'url' => $url,
        ];

      case 'eligibility':
        return [
          'type' => 'eligibility',
          'message' => $this->t('ILAS provides free legal help to low-income Idahoans. Eligibility is generally based on income and the type of legal issue. To find out if you qualify, you can apply online or call our Legal Advice Line.'),
          'caveat' => $this->t('Note: Eligibility depends on your specific situation. Applying is the best way to find out if we can help.'),
          'links' => [
            ['label' => $this->t('Apply for Help'), 'url' => $canonical_urls['apply'], 'type' => 'apply'],
            ['label' => $this->t('Legal Advice Line'), 'url' => $canonical_urls['hotline'], 'type' => 'hotline'],
            ['label' => $this->t('Our Services'), 'url' => $canonical_urls['services'], 'type' => 'services'],
          ],
        ];

      case 'apply':
        return [
          'type' => 'navigation',
          'message' => $this->t('Ready to apply for legal help? Here\'s how:'),
          'url' => $canonical_urls['apply'],
          'cta' => $this->t('Apply for Help'),
        ];

      case 'services':
        return [
          'type' => 'services_overview',
          'message' => $this->t('Idaho Legal Aid Services provides free civil legal help in areas including housing, family law, consumer issues, public benefits, and more. Here\'s an overview of our services:'),
          'url' => $canonical_urls['services'],
          'service_areas' => [
            ['label' => $this->t('Housing'), 'url' => $canonical_urls['service_areas']['housing']],
            ['label' => $this->t('Family'), 'url' => $canonical_urls['service_areas']['family']],
            ['label' => $this->t('Seniors'), 'url' => $canonical_urls['service_areas']['seniors']],
            ['label' => $this->t('Health & Benefits'), 'url' => $canonical_urls['service_areas']['health']],
            ['label' => $this->t('Consumer'), 'url' => $canonical_urls['service_areas']['consumer']],
            ['label' => $this->t('Civil Rights'), 'url' => $canonical_urls['service_areas']['civil_rights']],
          ],
        ];

      case 'hotline':
        return [
          'type' => 'navigation',
          'message' => $this->t('Our Legal Advice Line can help. Here\'s the information:'),
          'url' => $canonical_urls['hotline'],
          'cta' => $this->t('Contact Hotline'),
        ];

      case 'donate':
        return [
          'type' => 'navigation',
          'message' => $this->t('Thank you for considering a donation! Here\'s how you can help:'),
          'url' => $canonical_urls['donate'],
          'cta' => $this->t('Donate'),
        ];

      case 'offices':
        return [
          'type' => 'navigation',
          'message' => $this->t('Find an office near you:'),
          'url' => $canonical_urls['offices'],
          'cta' => $this->t('Find Offices'),
        ];

      case 'feedback':
        return [
          'type' => 'navigation',
          'message' => $this->t('We value your feedback:'),
          'url' => $canonical_urls['feedback'],
          'cta' => $this->t('Give Feedback'),
        ];

      case 'risk_detector':
        return [
          'type' => 'navigation',
          'message' => $this->t('Our Legal Risk Detector can help identify potential legal issues you may be facing:'),
          'url' => $canonical_urls['senior_risk_detector'],
          'cta' => $this->t('Take the Assessment'),
        ];

      case 'greeting':
        return [
          'type' => 'greeting',
          'message' => $config->get('welcome_message'),
          'suggestions' => $this->getQuickSuggestions(),
        ];

      default:
        // Before returning fallback, try searching FAQs as a last resort.
        // This catches questions that don't match patterns but may have answers.
        if ($config->get('enable_faq')) {
          $faq_results = $this->faqIndex->search($message, 3);
          if (!empty($faq_results)) {
            return [
              'type' => 'faq',
              'message' => $this->t('I found some information that might help:'),
              'results' => $faq_results,
              'fallback_url' => $canonical_urls['faq'],
            ];
          }
        }

        // Also try resource search as fallback.
        if ($config->get('enable_resources')) {
          $resource_results = $this->resourceFinder->findResources($message, 3);
          if (!empty($resource_results)) {
            return [
              'type' => 'resources',
              'message' => $this->t('I found some resources that might help:'),
              'results' => $resource_results,
              'fallback_url' => $canonical_urls['resources'],
            ];
          }
        }

        // Unknown intent - provide helpful fallback per spec.
        return [
          'type' => 'fallback',
          'message' => $this->t('I\'m not sure I understood. Are you looking for help with one of these areas?'),
          'topic_suggestions' => [
            ['label' => $this->t('Housing'), 'action' => 'topic_housing', 'url' => $canonical_urls['service_areas']['housing']],
            ['label' => $this->t('Family'), 'action' => 'topic_family', 'url' => $canonical_urls['service_areas']['family']],
            ['label' => $this->t('Seniors'), 'action' => 'topic_seniors', 'url' => $canonical_urls['service_areas']['seniors']],
            ['label' => $this->t('Benefits'), 'action' => 'topic_benefits', 'url' => $canonical_urls['service_areas']['health']],
            ['label' => $this->t('Consumer'), 'action' => 'topic_consumer', 'url' => $canonical_urls['service_areas']['consumer']],
            ['label' => $this->t('Civil Rights'), 'action' => 'topic_civil_rights', 'url' => $canonical_urls['service_areas']['civil_rights']],
          ],
          'suggestions' => $this->getQuickSuggestions(),
          'actions' => $this->getEscalationActions(),
        ];
    }
  }

  /**
   * Returns quick suggestion buttons.
   *
   * @return array
   *   Array of suggestions.
   */
  protected function getQuickSuggestions() {
    return [
      ['label' => $this->t('Find a form'), 'action' => 'forms'],
      ['label' => $this->t('Find a guide'), 'action' => 'guides'],
      ['label' => $this->t('Search FAQs'), 'action' => 'faq'],
      ['label' => $this->t('Apply for help'), 'action' => 'apply'],
    ];
  }

  /**
   * Returns escalation action buttons.
   *
   * @return array
   *   Array of escalation actions.
   */
  protected function getEscalationActions() {
    $canonical_urls = ilas_site_assistant_get_canonical_urls();
    return [
      [
        'label' => $this->t('Call Hotline'),
        'url' => $canonical_urls['hotline'],
        'type' => 'hotline',
      ],
      [
        'label' => $this->t('Apply for Help'),
        'url' => $canonical_urls['apply'],
        'type' => 'apply',
      ],
      [
        'label' => $this->t('Give Feedback'),
        'url' => $canonical_urls['feedback'],
        'type' => 'feedback',
      ],
    ];
  }

  /**
   * Sanitizes user input.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   Sanitized string.
   */
  protected function sanitizeInput(string $input) {
    // Remove HTML tags.
    $input = strip_tags($input);
    // Limit length.
    $input = mb_substr($input, 0, 500);
    // Remove control characters.
    $input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
    // Normalize whitespace.
    $input = preg_replace('/\s+/', ' ', $input);
    return trim($input);
  }

}
