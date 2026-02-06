<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for enhancing responses with LLM (Gemini/Vertex AI).
 *
 * This service provides optional LLM-powered enhancements:
 * - Summarizing search results into conversational responses
 * - Better intent classification for ambiguous queries
 * - Natural language response generation
 *
 * IMPORTANT: The LLM only sees retrieved content from Drupal.
 * It does NOT search the web or provide legal advice.
 */
class LlmEnhancer {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The policy filter service.
   *
   * @var \Drupal\ilas_site_assistant\Service\PolicyFilter
   */
  protected $policyFilter;

  /**
   * Gemini API endpoints.
   */
  const GEMINI_API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';
  const VERTEX_AI_ENDPOINT = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';

  /**
   * System prompts for different contexts.
   *
   * Based on ILAS Conversation Policy v4.1-4.4.
   */
  const SYSTEM_PROMPTS = [
    'default' => <<<'PROMPT'
You are the ILAS Site Assistant for Idaho Legal Aid Services (ILAS), a nonprofit that provides free civil legal help to low-income Idahoans.

=== CRITICAL DISALLOWED CONTENT RULES (MUST FOLLOW) ===

You MUST NOT:
1. Interpret laws or cite statutes — e.g., "Idaho Code § 6-303 says…"
2. Predict legal outcomes — e.g., "You will likely win your case."
3. Advise on legal strategy — e.g., "You should file a motion to dismiss."
4. Draft or complete legal documents — e.g., filling in form fields based on user facts
5. Collect PII — names, addresses, DOB, SSN, immigration status, detailed case narratives
6. Recommend specific actions with legal consequence — e.g., "You should stop paying rent."
7. Diagnose eligibility definitively — you may explain general guidelines but MUST caveat with "eligibility depends on your specific situation"

=== YOUR ROLE ===

You CAN and SHOULD:
- Help users find information on the ILAS website (idaholegalaid.org)
- Summarize FAQ answers and resources in a friendly, accessible way
- Direct users to appropriate pages, forms, guides, and services
- Explain what ILAS does and general eligibility guidelines (with caveats)
- Encourage users to contact the Legal Advice Line or apply for personalized help

=== RESPONSE STYLE ===

- Keep responses concise (2-3 sentences max)
- Be warm, helpful, and professional
- Always end with a helpful next step (link, action, or question)
- If uncertain, offer: "I'm not sure I have the right answer. You can contact our Legal Advice Line for help."

=== IF USER ASKS FOR LEGAL ADVICE ===

Respond with: "I can't give legal advice, but I can help you find resources. To speak with someone who can give legal advice, call the ILAS Hotline or apply for help."

=== SITE SCOPE ===

You can ONLY help with information on idaholegalaid.org. For external sites (courts, government agencies), respond: "I can only help with information on the ILAS website. For [external site], you may need to visit their website directly."
PROMPT,

    'faq_summary' => <<<'PROMPT'
Summarize the following FAQ answer in 1-2 friendly sentences for someone who needs quick, accessible information.

RULES:
- Do NOT add any information not present in the original answer
- Do NOT provide legal advice or say what the user "should" do
- Do NOT cite statutes or legal codes
- Keep it conversational and helpful
- If the answer involves specific steps, briefly mention them
PROMPT,

    'resource_summary' => <<<'PROMPT'
Based on the user's question, briefly introduce these resources (1-2 sentences).

RULES:
- Mention that these resources may help with their situation
- Encourage contacting ILAS for personalized assistance
- Do NOT provide legal advice
- Do NOT say which resource is "best" — let the user choose
- Keep it friendly and accessible
PROMPT,

    'intent_classification' => <<<'PROMPT'
Classify the user's intent into ONE of these categories:

PRIMARY INTENTS:
- eligibility: User asks "Do I qualify?" "Who can get help?" "Am I eligible?"
- apply: User wants to apply for legal help
- hotline: User wants to call/contact someone
- offices: User asks about office locations
- services: User asks "What do you do?" "What services?"
- forms: User needs a form or document
- guides: User needs instructions or a how-to guide
- resources: User needs general resources
- faq: User is asking a general question
- risk_detector: User asks about risk assessment/quiz
- donate: User wants to donate
- feedback: User wants to give feedback/complaint

TOPIC INTENTS:
- housing: Housing/eviction/landlord/tenant issues
- family: Family/divorce/custody/protection order issues
- consumer: Debt/credit/scam/bankruptcy issues
- benefits: Medicaid/Medicare/SNAP/SSI/benefits issues
- health: Health/medical/disability issues
- seniors: Elder law/senior/guardianship issues
- civil_rights: Discrimination/rights/workplace issues

OTHER:
- greeting: User is saying hello
- unknown: Cannot determine intent

Respond with ONLY the category name, nothing else.
PROMPT,

    'eligibility_response' => <<<'PROMPT'
The user is asking about eligibility for ILAS services. Provide a brief, general response that:
1. Explains ILAS helps low-income Idahoans with civil legal matters
2. Mentions eligibility depends on income AND the type of legal issue
3. CAVEATS that eligibility depends on their specific situation
4. Encourages them to apply or call the hotline to find out

Do NOT definitively say they do or don't qualify. Always caveat.
PROMPT,
  ];

  /**
   * Constructs an LlmEnhancer object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    PolicyFilter $policy_filter
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('ilas_site_assistant');
    $this->policyFilter = $policy_filter;
  }

  /**
   * Checks if LLM enhancement is enabled.
   *
   * @return bool
   *   TRUE if LLM is enabled and configured.
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('llm.enabled')) {
      return FALSE;
    }

    // Check for required configuration.
    $provider = $config->get('llm.provider');

    if ($provider === 'gemini_api') {
      return !empty($config->get('llm.api_key'));
    }

    if ($provider === 'vertex_ai') {
      return !empty($config->get('llm.project_id')) &&
             !empty($config->get('llm.location'));
    }

    return FALSE;
  }

  /**
   * Enhances a response with LLM-generated summary.
   *
   * @param array $response
   *   The original response from intent processing.
   * @param string $userQuery
   *   The user's original query.
   *
   * @return array
   *   Enhanced response with optional 'llm_summary' field.
   */
  public function enhanceResponse(array $response, string $userQuery): array {
    if (!$this->isEnabled()) {
      return $response;
    }

    // Don't enhance escalation or error responses.
    if (in_array($response['type'] ?? '', ['escalation', 'error'])) {
      return $response;
    }

    // Don't enhance if no results to summarize.
    if (empty($response['results']) && empty($response['message'])) {
      return $response;
    }

    try {
      $summary = $this->generateSummary($response, $userQuery);
      if ($summary) {
        $response['llm_summary'] = $summary;
        $response['llm_enhanced'] = TRUE;
      }
    }
    catch (\Exception $e) {
      // Log error but don't break the response.
      $this->logger->warning('LLM enhancement failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Return original response without enhancement.
    }

    return $response;
  }

  /**
   * Uses LLM to help classify ambiguous intents.
   *
   * @param string $userQuery
   *   The user's query.
   * @param string $currentIntent
   *   The intent detected by rule-based system.
   *
   * @return string
   *   The classified intent (may be same as current or improved).
   */
  public function classifyIntent(string $userQuery, string $currentIntent = 'unknown'): string {
    if (!$this->isEnabled()) {
      return $currentIntent;
    }

    // Only use LLM for ambiguous cases.
    if ($currentIntent !== 'unknown') {
      return $currentIntent;
    }

    try {
      $prompt = self::SYSTEM_PROMPTS['intent_classification'] . "\n\nUser query: " . $userQuery;

      $result = $this->callLlm($prompt, [
        'max_tokens' => 20,
        'temperature' => 0.1,
      ]);

      // Validate the response is a known intent.
      $validIntents = [
        'faq', 'forms', 'guides', 'resources', 'apply', 'hotline', 'donate',
        'housing', 'family', 'consumer', 'health', 'seniors', 'civil_rights',
        'greeting', 'unknown',
      ];

      $classified = strtolower(trim($result));

      if (in_array($classified, $validIntents)) {
        return $classified;
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('LLM intent classification failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $currentIntent;
  }

  /**
   * Generates a conversational summary of results.
   *
   * @param array $response
   *   The response data with results.
   * @param string $userQuery
   *   The user's original query.
   *
   * @return string|null
   *   The generated summary or NULL on failure.
   */
  protected function generateSummary(array $response, string $userQuery): ?string {
    $type = $response['type'] ?? 'unknown';
    $results = $response['results'] ?? [];

    // Build context from results.
    $context = $this->buildResultContext($type, $results);

    if (empty($context)) {
      return NULL;
    }

    // Select appropriate system prompt.
    $systemPrompt = match ($type) {
      'faq' => self::SYSTEM_PROMPTS['faq_summary'],
      'resources' => self::SYSTEM_PROMPTS['resource_summary'],
      default => self::SYSTEM_PROMPTS['default'],
    };

    // Build the full prompt.
    $prompt = $systemPrompt . "\n\n";
    $prompt .= "User's question: " . $userQuery . "\n\n";
    $prompt .= "Information to summarize:\n" . $context;

    // Call the LLM.
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    return $this->callLlm($prompt, [
      'max_tokens' => $config->get('llm.max_tokens') ?? 150,
      'temperature' => $config->get('llm.temperature') ?? 0.3,
    ]);
  }

  /**
   * Builds context string from results.
   *
   * @param string $type
   *   The response type.
   * @param array $results
   *   The results array.
   *
   * @return string
   *   Formatted context for the LLM.
   */
  protected function buildResultContext(string $type, array $results): string {
    if (empty($results)) {
      return '';
    }

    $context = '';

    switch ($type) {
      case 'faq':
        foreach (array_slice($results, 0, 3) as $i => $faq) {
          $context .= "FAQ " . ($i + 1) . ":\n";
          $context .= "Q: " . ($faq['question'] ?? '') . "\n";
          $context .= "A: " . ($faq['full_answer'] ?? $faq['answer'] ?? '') . "\n\n";
        }
        break;

      case 'resources':
        foreach (array_slice($results, 0, 3) as $i => $resource) {
          $context .= "Resource " . ($i + 1) . ": ";
          $context .= ($resource['title'] ?? '') . " (" . ($resource['type'] ?? 'resource') . ")\n";
          if (!empty($resource['description'])) {
            $context .= "Description: " . $resource['description'] . "\n";
          }
          $context .= "\n";
        }
        break;

      default:
        // Generic formatting.
        foreach (array_slice($results, 0, 3) as $result) {
          if (is_array($result)) {
            $context .= json_encode($result) . "\n";
          }
        }
    }

    // Truncate to prevent excessive token usage.
    return mb_substr($context, 0, 2000);
  }

  /**
   * Calls the LLM API.
   *
   * @param string $prompt
   *   The prompt to send.
   * @param array $options
   *   Options like max_tokens, temperature.
   *
   * @return string
   *   The LLM response text.
   *
   * @throws \Exception
   *   If the API call fails.
   */

  /**
   * Returns the configured Gemini safety settings.
   *
   * @return array
   *   Safety settings array for the Gemini/Vertex API payload.
   */
  protected function getSafetySettings(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $threshold = $config->get('llm.safety_threshold') ?? 'BLOCK_MEDIUM_AND_ABOVE';

    // Validate against allowed values — BLOCK_NONE is intentionally excluded.
    $allowed = [
      'BLOCK_LOW_AND_ABOVE',
      'BLOCK_MEDIUM_AND_ABOVE',
      'BLOCK_ONLY_HIGH',
    ];
    if (!in_array($threshold, $allowed, TRUE)) {
      $threshold = 'BLOCK_MEDIUM_AND_ABOVE';
    }

    $categories = [
      'HARM_CATEGORY_HARASSMENT',
      'HARM_CATEGORY_HATE_SPEECH',
      'HARM_CATEGORY_SEXUALLY_EXPLICIT',
      'HARM_CATEGORY_DANGEROUS_CONTENT',
    ];

    return array_map(fn($cat) => [
      'category' => $cat,
      'threshold' => $threshold,
    ], $categories);
  }

  protected function callLlm(string $prompt, array $options = []): string {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $provider = $config->get('llm.provider') ?? 'gemini_api';

    // Sanitize prompt to remove any PII that might have slipped through.
    $prompt = $this->policyFilter->sanitizeForStorage($prompt);

    if ($provider === 'vertex_ai') {
      return $this->callVertexAi($prompt, $options);
    }

    return $this->callGeminiApi($prompt, $options);
  }

  /**
   * Calls the Gemini API directly (using API key).
   *
   * @param string $prompt
   *   The prompt.
   * @param array $options
   *   Options.
   *
   * @return string
   *   The response text.
   */
  protected function callGeminiApi(string $prompt, array $options): string {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $apiKey = $config->get('llm.api_key');
    $model = $config->get('llm.model') ?? 'gemini-1.5-flash';

    if (empty($apiKey)) {
      throw new \Exception('Gemini API key not configured');
    }

    $url = self::GEMINI_API_ENDPOINT . '/' . $model . ':generateContent?key=' . $apiKey;

    $payload = [
      'contents' => [
        [
          'parts' => [
            ['text' => $prompt],
          ],
        ],
      ],
      'generationConfig' => [
        'maxOutputTokens' => $options['max_tokens'] ?? 150,
        'temperature' => $options['temperature'] ?? 0.3,
        'topP' => 0.8,
        'topK' => 40,
      ],
      'safetySettings' => $this->getSafetySettings(),
    ];

    return $this->makeApiRequest($url, $payload);
  }

  /**
   * Calls Vertex AI (using service account).
   *
   * @param string $prompt
   *   The prompt.
   * @param array $options
   *   Options.
   *
   * @return string
   *   The response text.
   */
  protected function callVertexAi(string $prompt, array $options): string {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    $projectId = $config->get('llm.project_id');
    $location = $config->get('llm.location') ?? 'us-central1';
    $model = $config->get('llm.model') ?? 'gemini-1.5-flash';

    if (empty($projectId)) {
      throw new \Exception('Vertex AI project ID not configured');
    }

    // Get access token.
    $accessToken = $this->getVertexAiAccessToken();

    $url = sprintf(
      self::VERTEX_AI_ENDPOINT,
      $location,
      $projectId,
      $location,
      $model
    );

    $payload = [
      'contents' => [
        [
          'role' => 'user',
          'parts' => [
            ['text' => $prompt],
          ],
        ],
      ],
      'generationConfig' => [
        'maxOutputTokens' => $options['max_tokens'] ?? 150,
        'temperature' => $options['temperature'] ?? 0.3,
        'topP' => 0.8,
        'topK' => 40,
      ],
      'safetySettings' => $this->getSafetySettings(),
    ];

    return $this->makeApiRequest($url, $payload, [
      'Authorization' => 'Bearer ' . $accessToken,
    ]);
  }

  /**
   * Makes an API request to Gemini/Vertex AI.
   *
   * @param string $url
   *   The API URL.
   * @param array $payload
   *   The request payload.
   * @param array $headers
   *   Additional headers.
   *
   * @return string
   *   The response text.
   */
  protected function makeApiRequest(string $url, array $payload, array $headers = []): string {
    $defaultHeaders = [
      'Content-Type' => 'application/json',
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $allHeaders,
        'json' => $payload,
        'timeout' => 10,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);

      // Extract text from response.
      if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        $text = $body['candidates'][0]['content']['parts'][0]['text'];

        // Final safety check - ensure no legal advice slipped through.
        if ($this->containsLegalAdvice($text)) {
          $this->logger->warning('LLM response contained potential legal advice, filtering.');
          return $this->t('I found some information that may help. Please contact our Legal Advice Line for guidance specific to your situation.');
        }

        return trim($text);
      }

      // Check for blocked content.
      if (isset($body['candidates'][0]['finishReason']) &&
          $body['candidates'][0]['finishReason'] === 'SAFETY') {
        $this->logger->warning('LLM response blocked by safety filters.');
        return '';
      }

      throw new \Exception('Unexpected API response format');
    }
    catch (GuzzleException $e) {
      throw new \Exception('API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Gets an access token for Vertex AI.
   *
   * This uses the default application credentials (service account).
   *
   * @return string
   *   The access token.
   */
  protected function getVertexAiAccessToken(): string {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Option 1: Service account JSON key stored in config.
    $serviceAccountJson = $config->get('llm.service_account_json');
    if (!empty($serviceAccountJson)) {
      return $this->getTokenFromServiceAccount($serviceAccountJson);
    }

    // Option 2: Use metadata server (when running on GCP).
    return $this->getTokenFromMetadataServer();
  }

  /**
   * Gets access token from service account JSON.
   *
   * @param string $json
   *   The service account JSON.
   *
   * @return string
   *   The access token.
   */
  protected function getTokenFromServiceAccount(string $json): string {
    $credentials = json_decode($json, TRUE);

    if (!$credentials || empty($credentials['private_key']) || empty($credentials['client_email'])) {
      throw new \Exception('Invalid service account JSON');
    }

    // Create JWT.
    $header = [
      'alg' => 'RS256',
      'typ' => 'JWT',
    ];

    $now = time();
    $payload = [
      'iss' => $credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/cloud-platform',
      'aud' => 'https://oauth2.googleapis.com/token',
      'iat' => $now,
      'exp' => $now + 3600,
    ];

    $headerEncoded = $this->base64UrlEncode(json_encode($header));
    $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

    $signatureInput = $headerEncoded . '.' . $payloadEncoded;

    openssl_sign($signatureInput, $signature, $credentials['private_key'], 'SHA256');
    $signatureEncoded = $this->base64UrlEncode($signature);

    $jwt = $signatureInput . '.' . $signatureEncoded;

    // Exchange JWT for access token.
    $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
      'form_params' => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
      ],
    ]);

    $body = json_decode($response->getBody()->getContents(), TRUE);

    if (empty($body['access_token'])) {
      throw new \Exception('Failed to get access token');
    }

    return $body['access_token'];
  }

  /**
   * Gets access token from GCP metadata server.
   *
   * @return string
   *   The access token.
   */
  protected function getTokenFromMetadataServer(): string {
    try {
      $response = $this->httpClient->request('GET',
        'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token',
        [
          'headers' => [
            'Metadata-Flavor' => 'Google',
          ],
          'timeout' => 5,
        ]
      );

      $body = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($body['access_token'])) {
        throw new \Exception('No access token in metadata response');
      }

      return $body['access_token'];
    }
    catch (\Exception $e) {
      throw new \Exception('Failed to get token from metadata server. Are you running on GCP? Error: ' . $e->getMessage());
    }
  }

  /**
   * Base64 URL encode.
   */
  protected function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  /**
   * Checks if text contains potential legal advice.
   *
   * Based on ILAS Conversation Policy v4.1 Disallowed Content Rules.
   *
   * @param string $text
   *   The text to check.
   *
   * @return bool
   *   TRUE if legal advice detected.
   */
  protected function containsLegalAdvice(string $text): bool {
    $patterns = [
      // Interpreting laws or citing statutes.
      '/idaho\s+code\s*(§|section)/i',
      '/i\.c\.\s*§/i',
      '/under\s+(the\s+)?(law|statute|code)/i',
      '/according\s+to\s+(the\s+)?(law|statute)/i',
      '/(statute|code)\s+(says|states|requires)/i',

      // Predicting legal outcomes.
      '/you\s+(will|would)\s+(likely|probably)\s+(win|lose|succeed|fail)/i',
      '/your\s+chances\s+(of|are)/i',
      '/the\s+court\s+will\s+(likely|probably)/i',
      '/you\s+(are|\'re)\s+(likely|probably)\s+to\s+(win|lose)/i',

      // Advising on legal strategy.
      '/you\s+should\s+(file|sue|appeal|claim|motion)/i',
      '/i\s+(would\s+)?(advise|recommend|suggest)\s+(you|that\s+you)/i',
      '/my\s+(legal\s+)?advice\s+is/i',
      '/the\s+best\s+(legal\s+)?(strategy|approach)\s+is/i',
      '/you\s+need\s+to\s+(file|submit|send)/i',

      // Recommending specific actions with legal consequence.
      '/you\s+should\s+(stop\s+paying|withhold|break\s+your)/i',
      '/don\'t\s+(pay|respond|go\s+to\s+court)/i',
      '/ignore\s+the\s+(notice|summons|order)/i',
      '/you\s+have\s+the\s+right\s+to/i',

      // Definitive eligibility statements.
      '/you\s+(definitely|clearly|certainly)\s+qualify/i',
      '/you\s+(do|don\'t)\s+qualify\s+for/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Generates a friendly greeting response.
   *
   * @param string $userQuery
   *   The user's greeting.
   *
   * @return string|null
   *   A friendly response or NULL to use default.
   */
  public function generateGreeting(string $userQuery): ?string {
    if (!$this->isEnabled()) {
      return NULL;
    }

    $config = $this->configFactory->get('ilas_site_assistant.settings');

    if (!$config->get('llm.enhance_greetings')) {
      return NULL;
    }

    try {
      $prompt = self::SYSTEM_PROMPTS['default'] . "\n\n";
      $prompt .= "The user just said: \"" . $userQuery . "\"\n\n";
      $prompt .= "Respond with a brief, friendly greeting (1 sentence) and ask how you can help them find information on the ILAS website today.";

      return $this->callLlm($prompt, [
        'max_tokens' => 50,
        'temperature' => 0.7,
      ]);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
