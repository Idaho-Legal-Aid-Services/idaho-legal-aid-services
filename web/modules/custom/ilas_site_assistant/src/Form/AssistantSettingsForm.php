<?php

namespace Drupal\ilas_site_assistant\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\LexiconOverrides;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Configuration form for ILAS Site Assistant.
 */
class AssistantSettingsForm extends ConfigFormBase {

  /**
   * The shared environment detector.
   *
   * @var \Drupal\ilas_site_assistant\Service\EnvironmentDetector
   */
  protected EnvironmentDetector $environmentDetector;

  /**
   * Runtime retrieval/canonical URL resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\RetrievalConfigurationService|null
   */
  protected ?RetrievalConfigurationService $retrievalConfiguration;

  /**
   * The module logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the assistant settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    ?EnvironmentDetector $environment_detector = NULL,
    ?RetrievalConfigurationService $retrieval_configuration = NULL,
    ?LoggerInterface $logger = NULL,
    ?AccountProxyInterface $current_user = NULL,
    ?LexiconOverrides $lexicon_overrides = NULL,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->environmentDetector = $environment_detector ?? new EnvironmentDetector();
    $this->retrievalConfiguration = $retrieval_configuration;
    $this->logger = $logger ?? new NullLogger();
    $this->currentUser = $current_user ?? new AccountProxy(new EventDispatcher());
    $this->lexiconOverrides = $lexicon_overrides;
  }

  /**
   * Admin lexicon overrides service (typos / topic aliases).
   *
   * @var \Drupal\ilas_site_assistant\Service\LexiconOverrides|null
   */
  protected ?LexiconOverrides $lexiconOverrides = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ilas_site_assistant.environment_detector'),
      $container->has('ilas_site_assistant.retrieval_configuration') ? $container->get('ilas_site_assistant.retrieval_configuration') : NULL,
      $container->get('logger.factory')->get('ilas_site_assistant'),
      $container->get('current_user'),
      $container->has('ilas_site_assistant.lexicon_overrides') ? $container->get('ilas_site_assistant.lexicon_overrides') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ilas_site_assistant_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ilas_site_assistant.settings'];
  }

  /**
   * Returns TRUE when running in Pantheon live environment.
   */
  protected function isLiveEnvironment(): bool {
    return $this->environmentDetector->isLiveEnvironment();
  }

  /**
   * Builds the non-secret LegalServer runtime notice.
   */
  protected function buildLegalServerRuntimeNotice(bool $runtimeConfigured, ?array $check): string {
    $summary = $this->formatLegalServerRuntimeSummary($check);

    if ($runtimeConfigured) {
      return (string) $this->t('Configured via runtime-only setting <code>ILAS_LEGALSERVER_ONLINE_APPLICATION_URL</code>. Drupal will not store or export the LegalServer intake URL. Validation: <strong>@summary</strong>.', [
        '@summary' => $summary,
      ]);
    }

    return (string) $this->t('Runtime-only. Set <code>ILAS_LEGALSERVER_ONLINE_APPLICATION_URL</code> in Pantheon runtime secrets or local DDEV environment settings. Drupal will not accept or export the LegalServer intake URL. Current validation: <strong>@summary</strong>.', [
      '@summary' => $summary,
    ]);
  }

  /**
   * Summarizes LegalServer runtime validation without exposing the URL.
   */
  protected function formatLegalServerRuntimeSummary(?array $check): string {
    if (!is_array($check)) {
      return 'unavailable';
    }

    if (empty($check['configured'])) {
      return 'missing';
    }

    $issues = [];
    if (empty($check['https'])) {
      $issues[] = 'non_https';
    }

    $requiredQueryKeys = is_array($check['required_query_keys'] ?? NULL)
      ? $check['required_query_keys']
      : [];
    if (empty($requiredQueryKeys['pid'])) {
      $issues[] = 'missing_pid';
    }
    if (empty($requiredQueryKeys['h'])) {
      $issues[] = 'missing_h';
    }

    return $issues === [] ? 'healthy' : implode(', ', $issues);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ilas_site_assistant.settings');
    $is_live_environment = $this->isLiveEnvironment();
    $canonical_urls = $this->retrievalConfiguration
      ? $this->retrievalConfiguration->getCanonicalUrls()
      : (is_array($config->get('canonical_urls')) ? $config->get('canonical_urls') : []);
    $retrieval_config = $this->retrievalConfiguration
      ? $this->retrievalConfiguration->getRetrievalConfig()
      : (is_array($config->get('retrieval')) ? $config->get('retrieval') : []);
    $legalserver_runtime_url = $this->retrievalConfiguration
      ? $this->retrievalConfiguration->getLegalServerOnlineApplicationUrl()
      : NULL;
    $legalserver_runtime_check = $this->retrievalConfiguration
      ? ($this->retrievalConfiguration->getHealthSnapshot()['canonical_urls']['legalserver_intake_url'] ?? NULL)
      : NULL;

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['disclaimer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Disclaimer Text'),
      '#description' => $this->t('This text is shown to users at the start of the chat.'),
      '#default_value' => $config->get('disclaimer_text'),
      '#rows' => 3,
      '#required' => TRUE,
    ];

    $form['general']['welcome_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Welcome Message'),
      '#description' => $this->t('The initial greeting message shown to users.'),
      '#default_value' => $config->get('welcome_message'),
      '#rows' => 2,
      '#required' => TRUE,
    ];

    $form['general']['escalation_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Escalation Message'),
      '#description' => $this->t('Message shown when directing users to human assistance.'),
      '#default_value' => $config->get('escalation_message'),
      '#rows' => 2,
    ];

    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Features'),
      '#open' => TRUE,
    ];

    $form['features']['enable_global_widget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Global Widget'),
      '#description' => $this->t('Show the floating chat widget on all pages (except excluded paths).'),
      '#default_value' => $config->get('enable_global_widget'),
    ];

    $form['features']['enable_assistant_page'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Assistant Page'),
      '#description' => $this->t('Allow visitors to access the dedicated /assistant page.'),
      '#default_value' => $config->get('enable_assistant_page'),
    ];

    $form['features']['enable_faq'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable FAQ Answers'),
      '#description' => $this->t('Allow the assistant to search and display FAQ content.'),
      '#default_value' => $config->get('enable_faq'),
    ];

    $form['features']['enable_resources'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Forms/Guides/Resources Search'),
      '#description' => $this->t('Allow the assistant to search and display forms, guides, and resources.'),
      '#default_value' => $config->get('enable_resources'),
    ];

    $form['features']['excluded_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded Paths'),
      '#description' => $this->t('Paths where the floating widget should not appear. One per line. Use path prefixes (e.g., /admin).'),
      '#default_value' => implode("\n", $config->get('excluded_paths') ?? []),
      '#rows' => 4,
    ];

    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('Analytics & Logging'),
      '#open' => TRUE,
    ];

    $form['logging']['enable_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Analytics Logging'),
      '#description' => $this->t('Log aggregated, non-PII analytics data for reporting.'),
      '#default_value' => $config->get('enable_logging'),
    ];

    $form['logging']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Log Retention (Days)'),
      '#description' => $this->t('Number of days to keep analytics data.'),
      '#default_value' => $config->get('log_retention_days') ?? 730,
      '#min' => 1,
      '#max' => 730,
    ];

    // Conversation logging (detailed per-exchange logs for QA/debugging).
    $conversation_logging = $config->get('conversation_logging') ?? [];

    $form['logging']['conversation_logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Conversation Logging'),
      '#description' => $this->t('Log individual chat exchanges to the legacy QA/debug table. The <em>Conversation Logs</em> report uses the canonical governance conversation store even when this legacy option is disabled.'),
      '#default_value' => $conversation_logging['enabled'] ?? FALSE,
    ];

    $form['logging']['conversation_logging_retention_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Conversation Retention (Hours)'),
      '#description' => $this->t('How long to keep individual conversation logs before automatic cleanup.'),
      '#default_value' => $conversation_logging['retention_hours'] ?? 72,
      '#min' => 1,
      '#max' => 720,
      '#states' => [
        'visible' => [
          ':input[name="conversation_logging_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['logging']['conversation_logging_redact_pii'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redact PII'),
      '#description' => $this->t('Automatically redact personally identifiable information (emails, phone numbers, SSNs) before storing conversation logs.'),
      '#default_value' => $conversation_logging['redact_pii'] ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="conversation_logging_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['logging']['conversation_logging_show_user_notice'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show User Notice'),
      '#description' => $this->t('Display a notice to users that their conversation may be logged for quality purposes.'),
      '#default_value' => $conversation_logging['show_user_notice'] ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="conversation_logging_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['urls'] = [
      '#type' => 'details',
      '#title' => $this->t('Canonical URLs'),
      '#description' => $this->t('Configure the primary URLs the assistant directs users to.'),
      '#open' => FALSE,
    ];

    $form['urls']['url_apply'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Apply for Help'),
      '#default_value' => $canonical_urls['apply'] ?? '',
    ];

    $form['urls']['url_hotline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Legal Advice Line'),
      '#default_value' => $canonical_urls['hotline'] ?? '',
    ];

    $form['urls']['url_offices'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Offices'),
      '#default_value' => $canonical_urls['offices'] ?? '',
    ];

    $form['urls']['url_donate'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donate'),
      '#default_value' => $canonical_urls['donate'] ?? '',
    ];

    $form['urls']['url_feedback'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feedback'),
      '#default_value' => $canonical_urls['feedback'] ?? '',
    ];

    $form['urls']['url_resources'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resources'),
      '#default_value' => $canonical_urls['resources'] ?? '',
    ];

    $form['urls']['url_forms'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Forms'),
      '#default_value' => $canonical_urls['forms'] ?? '',
    ];

    $form['urls']['url_guides'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Guides'),
      '#default_value' => $canonical_urls['guides'] ?? '',
    ];

    $form['urls']['url_faq'] = [
      '#type' => 'textfield',
      '#title' => $this->t('FAQ'),
      '#default_value' => $canonical_urls['faq'] ?? '',
    ];

    $form['urls']['url_services'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Services'),
      '#default_value' => $canonical_urls['services'] ?? '',
    ];

    $form['urls']['url_senior_risk'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Senior Risk Detector'),
      '#default_value' => $canonical_urls['senior_risk_detector'] ?? '',
    ];

    $form['urls']['legalserver_online_application_runtime_notice'] = [
      '#type' => 'item',
      '#title' => $this->t('LegalServer Online Application URL'),
      '#markup' => $this->buildLegalServerRuntimeNotice($legalserver_runtime_url !== NULL, $legalserver_runtime_check),
    ];

    $form['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Settings'),
      '#open' => FALSE,
    ];

    $form['content']['faq_node_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('FAQ Page Path'),
      '#description' => $this->t('Path to the FAQ page for extracting FAQ content.'),
      '#default_value' => $config->get('faq_node_path') ?? '/faq',
    ];

    $form['lexicon'] = [
      '#type' => 'details',
      '#title' => $this->t('Lexicon overrides (no deploy needed)'),
      '#open' => FALSE,
      '#description' => $this->t('Teach the assistant new spellings and wording without a code change. Entries here are merged on top of the shipped lexicon (config/routing/typos.yml, topic_map.yml). Promote entries that prove useful into those files with fixtures during the weekly gap review so CI covers them.'),
    ];
    $form['lexicon']['lexicon_typos'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Typo corrections'),
      '#description' => $this->t('One per line: <code>misspelling =&gt; canonical</code> (e.g. <code>tenet =&gt; tenant</code>). The canonical word must already be a vocabulary word.'),
      '#default_value' => LexiconOverrides::formatTypoLines(is_array($config->get('lexicon_overrides.typos')) ? $config->get('lexicon_overrides.typos') : []),
      '#rows' => 6,
    ];
    $form['lexicon']['lexicon_topic_aliases'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Topic aliases'),
      '#description' => $this->t('One per line: <code>phrase =&gt; topic_intent</code> (e.g. <code>entering a residence =&gt; topic_housing_landlord_tenant</code>). The target must be a Top Intents pack key; a message containing the phrase gets that answer.'),
      '#default_value' => LexiconOverrides::formatAliasLines(is_array($config->get('lexicon_overrides.topic_aliases')) ? $config->get('lexicon_overrides.topic_aliases') : []),
      '#rows' => 6,
    ];

    $form['retrieval'] = [
      '#type' => 'details',
      '#title' => $this->t('Retrieval Index Identifiers'),
      '#description' => $this->t('Govern the Search API index IDs used for lexical retrieval, lexical fallback, and vector supplementation.'),
      '#open' => FALSE,
    ];

    $form['retrieval']['retrieval_faq_index_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('FAQ Lexical Index ID'),
      '#description' => $this->t('Search API index ID for FAQ accordion lexical retrieval.'),
      '#default_value' => $retrieval_config['faq_index_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['retrieval']['retrieval_resource_index_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource Lexical Index ID'),
      '#description' => $this->t('Search API index ID for primary resource lexical retrieval.'),
      '#default_value' => $retrieval_config['resource_index_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['retrieval']['retrieval_resource_fallback_index_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource Fallback Lexical Index ID'),
      '#description' => $this->t('Search API index ID used when the dedicated resource index is unavailable.'),
      '#default_value' => $retrieval_config['resource_fallback_index_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['retrieval']['retrieval_faq_vector_index_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('FAQ Vector Index ID'),
      '#description' => $this->t('Search API index ID for FAQ semantic/vector supplementation.'),
      '#default_value' => $retrieval_config['faq_vector_index_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['retrieval']['retrieval_resource_vector_index_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource Vector Index ID'),
      '#description' => $this->t('Search API index ID for resource semantic/vector supplementation.'),
      '#default_value' => $retrieval_config['resource_vector_index_id'] ?? '',
      '#required' => TRUE,
    ];

    // Vector Search Enhancement Settings.
    $form['vector_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Search Enhancement'),
      '#description' => $this->t('Supplements lexical search with semantic vector search via Pinecone when lexical results are sparse. Requires the dedicated <code>pinecone_vector_faq</code> and <code>pinecone_vector_resources</code> Search API servers plus the vector indexes to be configured and indexed.'),
      '#open' => FALSE,
    ];

    $vector_config = $config->get('vector_search') ?? [];

    $form['vector_search']['vector_search_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Vector Search Fallback'),
      '#description' => $is_live_environment
        ? $this->t('Live vector enablement is runtime-only. Set <code>ILAS_VECTOR_SEARCH_ENABLED</code> and clear caches; this form keeps stored Drupal config false on live.')
        : $this->t('When enabled, sparse lexical results will be supplemented with semantic vector search results from Pinecone.'),
      '#default_value' => $is_live_environment ? FALSE : ($vector_config['enabled'] ?? FALSE),
      '#disabled' => $is_live_environment,
    ];

    $form['vector_search']['vector_search_fallback_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Fallback Threshold'),
      '#description' => $this->t('Minimum number of lexical results before vector search fires. If lexical returns fewer results than this, vector search supplements them.'),
      '#default_value' => $vector_config['fallback_threshold'] ?? 2,
      '#min' => 0,
      '#max' => 10,
      '#states' => [
        'visible' => [
          ':input[name="vector_search_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['vector_search_min_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Vector Score'),
      '#description' => $this->t('Minimum cosine similarity score (0-1) for a vector result to be included. Higher values are more selective.'),
      '#default_value' => $vector_config['min_vector_score'] ?? 0.70,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#states' => [
        'visible' => [
          ':input[name="vector_search_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['vector_search_normalization_factor'] = [
      '#type' => 'number',
      '#title' => $this->t('Score Normalization Factor'),
      '#description' => $this->t('Multiply cosine similarity scores by this value to bring them into the same range as lexical scores for ranking comparison.'),
      '#default_value' => $vector_config['score_normalization_factor'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="vector_search_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['vector_search_min_lexical_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Lexical Score'),
      '#description' => $this->t('If set above 0 and all lexical results score below this, vector search fires even when the result count meets the fallback threshold. Set to 0 to disable.'),
      '#default_value' => $vector_config['min_lexical_score'] ?? 0,
      '#min' => 0,
      '#max' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="vector_search_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $llm_config = $config->get('llm');
    $llm_config = is_array($llm_config) ? $llm_config : [];
    $form['llm'] = [
      '#type' => 'details',
      '#title' => $this->t('Request-time LLM'),
      '#description' => $this->t('Use Cohere for bounded ambiguous-intent classification and, when grounded generation is enabled, for short cited answers written strictly from retrieved site content. Assistant responses remain safety-gated and fall back to deterministic templates.'),
      '#open' => FALSE,
    ];

    $form['llm']['llm_runtime_notice'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Runtime secret: <code>ILAS_COHERE_API_KEY</code>. Live rollout remains runtime-toggle controlled with <code>ILAS_LLM_ENABLED</code>. Provider/model are non-secret config values; credentials are never stored in Drupal config. Greeting variation stays retired.') . '</p>',
    ];

    $form['llm']['llm_provider_model'] = [
      '#type' => 'item',
      '#title' => $this->t('Provider / model'),
      '#markup' => '<code>' . htmlspecialchars((string) ($llm_config['provider'] ?? 'cohere'), ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars((string) ($llm_config['model'] ?? 'command-a-03-2025'), ENT_QUOTES, 'UTF-8') . '</code>',
    ];

    $form['llm']['llm_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable request-time LLM classification'),
      '#description' => $is_live_environment
        ? $this->t('Live enablement is runtime-only. Set <code>ILAS_LLM_ENABLED</code> and clear caches instead of saving this checkbox.')
        : $this->t('Allow Cohere-backed request-time classification only when deterministic routing returns an ambiguous unknown intent.'),
      '#default_value' => (bool) ($llm_config['enabled'] ?? FALSE),
      '#disabled' => $is_live_environment,
    ];

    $form['llm']['llm_max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tokens'),
      '#default_value' => (int) ($llm_config['max_tokens'] ?? 150),
      '#min' => 32,
      '#max' => 256,
    ];

    $form['llm']['llm_temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#default_value' => (float) ($llm_config['temperature'] ?? 0.3),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
    ];

    $form['llm']['llm_fallback_on_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fallback To Clarify On Error'),
      '#default_value' => (bool) ($llm_config['fallback_on_error'] ?? TRUE),
    ];

    $form['llm']['llm_safety_threshold'] = [
      '#type' => 'select',
      '#title' => $this->t('Safety Threshold'),
      '#default_value' => (string) ($llm_config['safety_threshold'] ?? 'BLOCK_MEDIUM_AND_ABOVE'),
      '#options' => [
        'BLOCK_LOW_AND_ABOVE' => $this->t('Block low and above'),
        'BLOCK_MEDIUM_AND_ABOVE' => $this->t('Block medium and above'),
        'BLOCK_ONLY_HIGH' => $this->t('Block only high'),
      ],
    ];

    $form['llm']['llm_cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => (int) ($llm_config['cache_ttl'] ?? 3600),
      '#min' => 0,
      '#max' => 86400,
    ];

    $form['llm']['llm_max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => (int) ($llm_config['max_retries'] ?? 1),
      '#min' => 0,
      '#max' => 5,
    ];

    $generation_config = is_array($llm_config['generation'] ?? NULL) ? $llm_config['generation'] : [];
    $form['llm']['generation'] = [
      '#type' => 'details',
      '#title' => $this->t('Grounded answer generation'),
      '#description' => $this->t('When enabled, Cohere writes a short answer strictly from the retrieved FAQ/resource documents with citations. Any guard failure (no citations, legal-advice phrasing, invented contact details, timeout, budget) falls back to the deterministic template.'),
      '#open' => FALSE,
    ];

    $form['llm']['generation']['llm_generation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable grounded answer generation'),
      '#description' => $is_live_environment
        ? $this->t('Live enablement is runtime-only. Set <code>ILAS_LLM_GENERATION_ENABLED</code> and clear caches instead of saving this checkbox.')
        : $this->t('Requires request-time LLM classification to be enabled and the Cohere runtime key to be present.'),
      '#default_value' => (bool) ($generation_config['enabled'] ?? FALSE),
      '#disabled' => $is_live_environment,
    ];

    $form['llm']['generation']['llm_generation_max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Generation Maximum Tokens'),
      '#default_value' => (int) ($generation_config['max_tokens'] ?? 220),
      '#min' => 64,
      '#max' => 400,
    ];

    $form['llm']['generation']['llm_generation_max_documents'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Documents Sent'),
      '#default_value' => (int) ($generation_config['max_documents'] ?? 4),
      '#min' => 1,
      '#max' => 6,
    ];

    $form['llm']['generation']['llm_generation_max_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Answer Words'),
      '#default_value' => (int) ($generation_config['max_words'] ?? 120),
      '#min' => 40,
      '#max' => 250,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $typos = LexiconOverrides::parseTypoLines((string) $form_state->getValue('lexicon_typos'));
    $aliases = LexiconOverrides::parseAliasLines((string) $form_state->getValue('lexicon_topic_aliases'));
    $typo_errors = array_values($typos['errors']);
    $alias_errors = array_values($aliases['errors']);
    if ($this->lexiconOverrides !== NULL) {
      $typo_errors = array_merge($typo_errors, $this->lexiconOverrides->validateTypos($typos['entries']));
      $alias_errors = array_merge($alias_errors, $this->lexiconOverrides->validateAliases($aliases['entries']));
    }
    if ($typo_errors !== []) {
      $form_state->setErrorByName('lexicon_typos', $this->t('Typo corrections: @errors', ['@errors' => implode(' ', $typo_errors)]));
    }
    if ($alias_errors !== []) {
      $form_state->setErrorByName('lexicon_topic_aliases', $this->t('Topic aliases: @errors', ['@errors' => implode(' ', $alias_errors)]));
    }
    $form_state->set('lexicon_overrides_parsed', [
      'typos' => $typos['entries'],
      'topic_aliases' => $aliases['entries'],
    ]);

    if ($this->isLiveEnvironment() && (bool) $form_state->getValue('llm_enabled')) {
      $form_state->setErrorByName(
        'llm_enabled',
        $this->t('Live request-time LLM rollout is runtime-only. Set <code>ILAS_LLM_ENABLED</code> and clear caches instead of saving this checkbox.'),
      );
    }

    if ($this->isLiveEnvironment() && (bool) $form_state->getValue('llm_generation_enabled')) {
      $form_state->setErrorByName(
        'llm_generation_enabled',
        $this->t('Live grounded generation rollout is runtime-only. Set <code>ILAS_LLM_GENERATION_ENABLED</code> and clear caches instead of saving this checkbox.'),
      );
    }

    if ($this->isLiveEnvironment() && (bool) $form_state->getValue('vector_search_enabled')) {
      $form_state->setErrorByName(
        'vector_search_enabled',
        $this->t('Live vector search rollout is runtime-only. Set <code>ILAS_VECTOR_SEARCH_ENABLED</code> and clear caches instead of saving this checkbox.'),
      );
    }

    // Privacy coupling: if conversation logging is enabled,
    // PII redaction and user notice must remain on.
    if ((bool) $form_state->getValue('conversation_logging_enabled')) {
      if (!(bool) $form_state->getValue('conversation_logging_redact_pii')) {
        $form_state->setErrorByName(
          'conversation_logging_redact_pii',
          $this->t('PII redaction cannot be disabled while conversation logging is active.'),
        );
      }
      if (!(bool) $form_state->getValue('conversation_logging_show_user_notice')) {
        $form_state->setErrorByName(
          'conversation_logging_show_user_notice',
          $this->t('User notice cannot be hidden while conversation logging is active. Users must be informed.'),
        );
      }
    }

    $machine_name_fields = [
      'retrieval_faq_index_id' => $this->t('FAQ Lexical Index ID'),
      'retrieval_resource_index_id' => $this->t('Resource Lexical Index ID'),
      'retrieval_resource_fallback_index_id' => $this->t('Resource Fallback Lexical Index ID'),
      'retrieval_faq_vector_index_id' => $this->t('FAQ Vector Index ID'),
      'retrieval_resource_vector_index_id' => $this->t('Resource Vector Index ID'),
    ];

    foreach ($machine_name_fields as $field_name => $label) {
      $value = trim((string) $form_state->getValue($field_name));
      if ($value === '') {
        $form_state->setErrorByName($field_name, $this->t('@label is required.', ['@label' => $label]));
        continue;
      }
      if (!preg_match('/^[a-z0-9_]+$/', $value)) {
        $form_state->setErrorByName(
          $field_name,
          $this->t('@label must contain only lowercase letters, numbers, and underscores.', ['@label' => $label]),
        );
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  /**
   * Security-sensitive config keys to audit on change.
   */
  private const AUDITED_KEYS = [
    'rate_limit_per_minute',
    'rate_limit_per_hour',
    'llm.enabled',
    'vector_search.enabled',
    'conversation_logging.enabled',
    'conversation_logging.retention_hours',
    'conversation_logging.redact_pii',
    'enable_global_widget',
    'enable_assistant_page',
    'enable_faq',
    'enable_resources',
  ];

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Snapshot security-sensitive values BEFORE save for audit diff.
    $config = $this->config('ilas_site_assistant.settings');
    $before = [];
    foreach (self::AUDITED_KEYS as $key) {
      $before[$key] = $config->get($key);
    }

    // Process excluded paths.
    $excluded_paths = array_filter(
      array_map('trim', explode("\n", $form_state->getValue('excluded_paths')))
    );

    // Build canonical URLs array.
    $canonical_urls = [
      'apply' => $form_state->getValue('url_apply'),
      'hotline' => $form_state->getValue('url_hotline'),
      'offices' => $form_state->getValue('url_offices'),
      'donate' => $form_state->getValue('url_donate'),
      'feedback' => $form_state->getValue('url_feedback'),
      'resources' => $form_state->getValue('url_resources'),
      'forms' => $form_state->getValue('url_forms'),
      'guides' => $form_state->getValue('url_guides'),
      'faq' => $form_state->getValue('url_faq'),
      'services' => $form_state->getValue('url_services'),
      'senior_risk_detector' => $form_state->getValue('url_senior_risk'),
      'service_areas' => is_array($config->get('canonical_urls.service_areas')) ? $config->get('canonical_urls.service_areas') : [],
    ];

    // Build vector search config array.
    $vector_search_enabled = (bool) $form_state->getValue('vector_search_enabled');
    if ($this->isLiveEnvironment()) {
      // Live rollout stays runtime-only so synced Drupal config remains false.
      $vector_search_enabled = FALSE;
    }

    $vector_search_config = [
      'enabled' => $vector_search_enabled,
      'fallback_threshold' => (int) $form_state->getValue('vector_search_fallback_threshold'),
      'min_vector_score' => (float) $form_state->getValue('vector_search_min_score'),
      'score_normalization_factor' => (int) $form_state->getValue('vector_search_normalization_factor'),
      'min_lexical_score' => (int) $form_state->getValue('vector_search_min_lexical_score'),
    ];

    $retrieval_config = [
      'faq_index_id' => trim((string) $form_state->getValue('retrieval_faq_index_id')),
      'resource_index_id' => trim((string) $form_state->getValue('retrieval_resource_index_id')),
      'resource_fallback_index_id' => trim((string) $form_state->getValue('retrieval_resource_fallback_index_id')),
      'faq_vector_index_id' => trim((string) $form_state->getValue('retrieval_faq_vector_index_id')),
      'resource_vector_index_id' => trim((string) $form_state->getValue('retrieval_resource_vector_index_id')),
    ];

    $llm_enabled = (bool) $form_state->getValue('llm_enabled');
    if ($this->isLiveEnvironment()) {
      $llm_enabled = FALSE;
    }

    $llm_config = $config->get('llm');
    $llm_config = is_array($llm_config) ? $llm_config : [];
    unset(
      $llm_config['api_key'],
      $llm_config['project_id'],
      $llm_config['location'],
      $llm_config['service_account_json'],
    );
    $llm_config['provider'] = trim((string) ($llm_config['provider'] ?? '')) !== ''
      ? trim((string) $llm_config['provider'])
      : 'cohere';
    $llm_config['model'] = trim((string) ($llm_config['model'] ?? '')) !== ''
      ? trim((string) $llm_config['model'])
      : 'command-a-03-2025';
    $llm_config['enabled'] = $llm_enabled;
    $llm_config['max_tokens'] = (int) $form_state->getValue('llm_max_tokens');
    $llm_config['temperature'] = (float) $form_state->getValue('llm_temperature');
    $llm_config['fallback_on_error'] = (bool) $form_state->getValue('llm_fallback_on_error');
    $llm_config['safety_threshold'] = (string) $form_state->getValue('llm_safety_threshold');
    $llm_config['cache_ttl'] = (int) $form_state->getValue('llm_cache_ttl');
    $llm_config['max_retries'] = (int) $form_state->getValue('llm_max_retries');

    $llm_generation_enabled = (bool) $form_state->getValue('llm_generation_enabled');
    if ($this->isLiveEnvironment()) {
      $llm_generation_enabled = FALSE;
    }
    $generation_config = is_array($llm_config['generation'] ?? NULL) ? $llm_config['generation'] : [];
    $generation_config['enabled'] = $llm_generation_enabled;
    $generation_config['max_tokens'] = (int) $form_state->getValue('llm_generation_max_tokens');
    $generation_config['max_documents'] = (int) $form_state->getValue('llm_generation_max_documents');
    $generation_config['max_words'] = (int) $form_state->getValue('llm_generation_max_words');
    $llm_config['generation'] = $generation_config;

    // Build conversation logging config array.
    $conversation_logging_config = [
      'enabled' => (bool) $form_state->getValue('conversation_logging_enabled'),
      'retention_hours' => (int) $form_state->getValue('conversation_logging_retention_hours'),
      'redact_pii' => (bool) $form_state->getValue('conversation_logging_redact_pii'),
      'show_user_notice' => (bool) $form_state->getValue('conversation_logging_show_user_notice'),
    ];

    $config
      ->set('disclaimer_text', $form_state->getValue('disclaimer_text'))
      ->set('welcome_message', $form_state->getValue('welcome_message'))
      ->set('escalation_message', $form_state->getValue('escalation_message'))
      ->set('enable_global_widget', $form_state->getValue('enable_global_widget'))
      ->set('enable_assistant_page', $form_state->getValue('enable_assistant_page'))
      ->set('enable_faq', $form_state->getValue('enable_faq'))
      ->set('enable_resources', $form_state->getValue('enable_resources'))
      ->set('excluded_paths', $excluded_paths)
      ->set('enable_logging', $form_state->getValue('enable_logging'))
      ->set('log_retention_days', $form_state->getValue('log_retention_days'))
      ->set('canonical_urls', $canonical_urls)
      ->set('faq_node_path', $form_state->getValue('faq_node_path'))
      ->set('retrieval', $retrieval_config)
      ->set('vector_search', $vector_search_config)
      ->set('llm', $llm_config)
      ->set('conversation_logging', $conversation_logging_config)
      ->set('lexicon_overrides', $form_state->get('lexicon_overrides_parsed') ?? ['typos' => [], 'topic_aliases' => []])
      ->save();

    // Routing services cache the vocabulary and topic map for an hour.
    $this->lexiconOverrides?->invalidateCaches();

    // Audit log: record which security-sensitive settings changed.
    $changes = [];
    foreach (self::AUDITED_KEYS as $key) {
      $after = $config->get($key);
      if ($before[$key] !== $after) {
        $changes[$key] = [
          'from' => is_bool($before[$key]) ? ($before[$key] ? 'true' : 'false') : (string) ($before[$key] ?? '(null)'),
          'to' => is_bool($after) ? ($after ? 'true' : 'false') : (string) ($after ?? '(null)'),
        ];
      }
    }
    if ($changes !== []) {
      $change_summary = [];
      foreach ($changes as $key => $diff) {
        $change_summary[] = $key . ': ' . $diff['from'] . ' → ' . $diff['to'];
      }
      $this->logger->notice(
        'Assistant settings changed by @user (uid @uid): @changes',
        [
          '@user' => $this->currentUser->getAccountName(),
          '@uid' => $this->currentUser->id(),
          '@changes' => implode('; ', $change_summary),
        ]
      );
    }

    parent::submitForm($form, $form_state);
  }

}
