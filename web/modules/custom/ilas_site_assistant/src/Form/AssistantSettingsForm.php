<?php

namespace Drupal\ilas_site_assistant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for ILAS Site Assistant.
 */
class AssistantSettingsForm extends ConfigFormBase {

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
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ilas_site_assistant.settings');

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
      '#description' => $this->t('Paths where the widget should not appear. One per line. Use path prefixes (e.g., /admin).'),
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
      '#default_value' => $config->get('log_retention_days') ?? 90,
      '#min' => 1,
      '#max' => 365,
    ];

    $form['urls'] = [
      '#type' => 'details',
      '#title' => $this->t('Canonical URLs'),
      '#description' => $this->t('Configure the primary URLs the assistant directs users to.'),
      '#open' => FALSE,
    ];

    $canonical_urls = $config->get('canonical_urls') ?? [];

    $form['urls']['url_apply'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Apply for Help'),
      '#default_value' => $canonical_urls['apply'] ?? '/apply-for-help',
    ];

    $form['urls']['url_hotline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Legal Advice Line'),
      '#default_value' => $canonical_urls['hotline'] ?? '/Legal-Advice-Line',
    ];

    $form['urls']['url_offices'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Offices'),
      '#default_value' => $canonical_urls['offices'] ?? '/contact/offices',
    ];

    $form['urls']['url_donate'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donate'),
      '#default_value' => $canonical_urls['donate'] ?? '/donate',
    ];

    $form['urls']['url_feedback'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feedback'),
      '#default_value' => $canonical_urls['feedback'] ?? '/get-involved/feedback',
    ];

    $form['urls']['url_resources'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resources'),
      '#default_value' => $canonical_urls['resources'] ?? '/what-we-do/resources',
    ];

    $form['urls']['url_forms'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Forms'),
      '#default_value' => $canonical_urls['forms'] ?? '/forms',
    ];

    $form['urls']['url_guides'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Guides'),
      '#default_value' => $canonical_urls['guides'] ?? '/guides',
    ];

    $form['urls']['url_faq'] = [
      '#type' => 'textfield',
      '#title' => $this->t('FAQ'),
      '#default_value' => $canonical_urls['faq'] ?? '/faq',
    ];

    $form['urls']['url_services'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Services'),
      '#default_value' => $canonical_urls['services'] ?? '/services',
    ];

    $form['urls']['url_senior_risk'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Senior Risk Detector'),
      '#default_value' => $canonical_urls['senior_risk_detector'] ?? '/resources/legal-risk-detector',
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

    // LLM Enhancement Settings.
    $form['llm'] = [
      '#type' => 'details',
      '#title' => $this->t('LLM Enhancement (Gemini AI)'),
      '#description' => $this->t('Optional: Use Google Gemini to generate more natural, conversational responses. The LLM only summarizes content from your site - it does not search the web or provide legal advice.'),
      '#open' => FALSE,
    ];

    $llm_config = $config->get('llm') ?? [];

    $form['llm']['llm_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable LLM Enhancement'),
      '#description' => $this->t('When enabled, the assistant will use Gemini AI to generate more natural responses. Requires API credentials below.'),
      '#default_value' => $llm_config['enabled'] ?? FALSE,
    ];

    $form['llm']['llm_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => [
        'gemini_api' => $this->t('Gemini API (API Key)'),
        'vertex_ai' => $this->t('Vertex AI (Google Cloud)'),
      ],
      '#default_value' => $llm_config['provider'] ?? 'gemini_api',
      '#description' => $this->t('Gemini API is simpler to set up. Vertex AI is recommended for production.'),
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['llm']['llm_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'gemini-1.5-flash' => $this->t('Gemini 1.5 Flash (Fast, cheapest)'),
        'gemini-1.5-pro' => $this->t('Gemini 1.5 Pro (Best quality)'),
        'gemini-1.0-pro' => $this->t('Gemini 1.0 Pro (Legacy)'),
      ],
      '#default_value' => $llm_config['model'] ?? 'gemini-1.5-flash',
      '#description' => $this->t('Flash is recommended for most use cases. Pro provides better quality at higher cost.'),
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['llm']['gemini_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Gemini API Settings'),
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
          ':input[name="llm_provider"]' => ['value' => 'gemini_api'],
        ],
      ],
    ];

    $form['llm']['gemini_settings']['llm_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gemini API Key'),
      '#default_value' => $llm_config['api_key'] ?? '',
      '#description' => $this->t('Get your API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>. Keep this secret!'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['llm']['vertex_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vertex AI Settings'),
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
          ':input[name="llm_provider"]' => ['value' => 'vertex_ai'],
        ],
      ],
    ];

    $form['llm']['vertex_settings']['llm_project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Cloud Project ID'),
      '#default_value' => $llm_config['project_id'] ?? '',
      '#description' => $this->t('Your Google Cloud project ID (e.g., "my-project-123456").'),
    ];

    $form['llm']['vertex_settings']['llm_location'] = [
      '#type' => 'select',
      '#title' => $this->t('Location'),
      '#options' => [
        'us-central1' => 'us-central1 (Iowa)',
        'us-east1' => 'us-east1 (South Carolina)',
        'us-west1' => 'us-west1 (Oregon)',
        'europe-west1' => 'europe-west1 (Belgium)',
      ],
      '#default_value' => $llm_config['location'] ?? 'us-central1',
    ];

    $form['llm']['vertex_settings']['llm_service_account'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service Account JSON'),
      '#default_value' => $llm_config['service_account_json'] ?? '',
      '#description' => $this->t('Paste the entire service account JSON key here. Leave empty to use default credentials (when running on GCP).'),
      '#rows' => 4,
    ];

    $form['llm']['llm_options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Enhancement Options'),
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['llm']['llm_options']['llm_enhance_faq'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enhance FAQ Responses'),
      '#description' => $this->t('Summarize FAQ answers in a more conversational tone.'),
      '#default_value' => $llm_config['enhance_faq'] ?? TRUE,
    ];

    $form['llm']['llm_options']['llm_enhance_resources'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enhance Resource Responses'),
      '#description' => $this->t('Generate friendly introductions for form/guide/resource results.'),
      '#default_value' => $llm_config['enhance_resources'] ?? TRUE,
    ];

    $form['llm']['llm_options']['llm_enhance_greetings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enhance Greetings'),
      '#description' => $this->t('Generate varied, personalized greeting responses.'),
      '#default_value' => $llm_config['enhance_greetings'] ?? FALSE,
    ];

    $form['llm']['llm_advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="llm_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['llm']['llm_advanced']['llm_max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Output Tokens'),
      '#default_value' => $llm_config['max_tokens'] ?? 150,
      '#min' => 50,
      '#max' => 500,
      '#description' => $this->t('Maximum length of generated responses. Lower = faster/cheaper.'),
    ];

    $form['llm']['llm_advanced']['llm_temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#default_value' => $llm_config['temperature'] ?? 0.3,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Creativity level (0 = deterministic, 1 = creative). Lower is safer for factual content.'),
    ];

    $form['llm']['llm_advanced']['llm_fallback_on_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fallback on Error'),
      '#description' => $this->t('If LLM fails, use the rule-based response instead of showing an error.'),
      '#default_value' => $llm_config['fallback_on_error'] ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ilas_site_assistant.settings');

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
      'service_areas' => $config->get('canonical_urls.service_areas') ?? [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];

    // Build LLM config array.
    $llm_config = [
      'enabled' => (bool) $form_state->getValue('llm_enabled'),
      'provider' => $form_state->getValue('llm_provider'),
      'model' => $form_state->getValue('llm_model'),
      'api_key' => $form_state->getValue('llm_api_key'),
      'project_id' => $form_state->getValue('llm_project_id'),
      'location' => $form_state->getValue('llm_location'),
      'service_account_json' => $form_state->getValue('llm_service_account'),
      'max_tokens' => (int) $form_state->getValue('llm_max_tokens'),
      'temperature' => (float) $form_state->getValue('llm_temperature'),
      'enhance_faq' => (bool) $form_state->getValue('llm_enhance_faq'),
      'enhance_resources' => (bool) $form_state->getValue('llm_enhance_resources'),
      'enhance_greetings' => (bool) $form_state->getValue('llm_enhance_greetings'),
      'fallback_on_error' => (bool) $form_state->getValue('llm_fallback_on_error'),
    ];

    $config
      ->set('disclaimer_text', $form_state->getValue('disclaimer_text'))
      ->set('welcome_message', $form_state->getValue('welcome_message'))
      ->set('escalation_message', $form_state->getValue('escalation_message'))
      ->set('enable_global_widget', $form_state->getValue('enable_global_widget'))
      ->set('enable_faq', $form_state->getValue('enable_faq'))
      ->set('enable_resources', $form_state->getValue('enable_resources'))
      ->set('excluded_paths', $excluded_paths)
      ->set('enable_logging', $form_state->getValue('enable_logging'))
      ->set('log_retention_days', $form_state->getValue('log_retention_days'))
      ->set('canonical_urls', $canonical_urls)
      ->set('faq_node_path', $form_state->getValue('faq_node_path'))
      ->set('llm', $llm_config)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
