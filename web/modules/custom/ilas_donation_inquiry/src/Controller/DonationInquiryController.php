<?php

namespace Drupal\ilas_donation_inquiry\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles AJAX submissions for the donation inquiry form.
 */
class DonationInquiryController extends ControllerBase {

  use StringTranslationTrait;

  /**
   * Map of interest machine names to human-friendly labels.
   */
  protected const INTEREST_LABELS = [
    'making-donation' => 'Making a Donation',
    'existing-donation' => 'An Existing Donation',
    'program-info' => 'Financial Information',
    'other-ways' => 'Other Ways to Donate',
  ];

  /**
   * Detailed label maps for conditional checkboxes.
   */
  protected const ISSUE_LABELS = [
    'making_donation_issues' => [
      'website-trouble' => 'Having trouble donating on the website',
      'check-info' => 'Needs information about addressing a check',
      'invoice-needed' => 'Requires an invoice prior to donating',
    ],
    'existing_donation_issues' => [
      'no-receipt' => 'Has not received an acknowledgement or tax receipt',
      'update-card' => 'Needs to update recurring donation payment information',
      'update-contact' => 'Needs to update donor contact information',
    ],
    'other_ways_options' => [
      'estate-planning' => 'Including ILAS in estate planning',
      'electronic-transfer' => 'Electronic transfer of funds',
      'stock-transfer' => 'Transfer of appreciated stock',
      'planned-giving' => 'Other planned giving',
      'workplace-giving' => 'Workplace giving',
      'in-kind' => 'In-kind donations',
    ],
  ];

  /**
   * The mail manager.
   */
  protected MailManagerInterface $donationMailManager;

  /**
   * Email validator service.
   */
  protected EmailValidatorInterface $donationEmailValidator;

  /**
   * CSRF token generator.
   */
  protected CsrfTokenGenerator $donationCsrfToken;

  /**
   * HTTP client for reCAPTCHA verification.
   */
  protected ClientInterface $donationHttpClient;

  /**
   * Logger channel factory.
   */
  protected LoggerChannelFactoryInterface $donationLoggerFactory;

  /**
   * DonationInquiryController constructor.
   */
  public function __construct(
    MailManagerInterface $mailManager,
    EmailValidatorInterface $emailValidator,
    CsrfTokenGenerator $csrfToken,
    ConfigFactoryInterface $configFactory,
    LanguageManagerInterface $languageManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->donationMailManager = $mailManager;
    $this->donationEmailValidator = $emailValidator;
    $this->donationCsrfToken = $csrfToken;
    $this->configFactory = $configFactory;
    $this->languageManager = $languageManager;
    $this->donationHttpClient = $httpClient;
    $this->donationLoggerFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('email.validator'),
      $container->get('csrf_token'),
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('http_client'),
      $container->get('logger.factory')
    );
  }

  /**
   * Handles submission of the donation inquiry form.
   */
  public function submit(Request $request): JsonResponse {
    $data = $this->extractPayload($request);

    // CSRF validation.
    $submitted_token = $data['csrf_token'] ?? '';
    if (!$this->donationCsrfToken->validate($submitted_token, 'donation_inquiry_form')) {
      return $this->jsonError('Invalid submission token. Please refresh the page and try again.', 403);
    }

    // Honeypot check.
    if (!empty($data['website_url'])) {
      $this->donationLoggerFactory->get('ilas_donation_inquiry')->warning('Honeypot triggered for donation inquiry.');
      return $this->jsonError('Unable to submit the request at this time.', 400);
    }

    $errors = $this->validateRequiredFields($data);
    if (!empty($errors)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Please correct the highlighted fields.'),
        'errors' => $errors,
      ], 400);
    }

    $this->donationLoggerFactory->get('ilas_donation_inquiry')->notice('Donation inquiry submission received for @email with topics: @topics', [
      '@email' => $this->maskEmail($data['email'] ?? ''),
      '@topics' => implode(', ', $data['interests'] ?? []),
    ]);

    // Optional reCAPTCHA verification.
    $config = $this->configFactory->get('ilas_donation_inquiry.settings');
    $recaptcha_secret = $config->get('recaptcha_secret_key');
    if (!empty($recaptcha_secret)) {
      $verification = $this->verifyRecaptcha($recaptcha_secret, $data['recaptcha_token'] ?? '', $request->getClientIp());
      if ($verification !== TRUE) {
        return $this->jsonError($verification ?: 'reCAPTCHA validation failed.', 400);
      }
    }

    $mail_params = $this->buildMailParams($data, $request);
    $recipient = $config->get('recipient_email') ?: $this->config('system.site')->get('mail');
    if (empty($recipient)) {
      $this->donationLoggerFactory->get('ilas_donation_inquiry')->error('No donation inquiry recipient email configured.');
      return $this->jsonError('Unable to send your request at this time. Please try again later.', 500);
    }

    if ($cc = $config->get('cc_email')) {
      $mail_params['cc'] = $cc;
    }

    $language = $this->languageManager->getDefaultLanguage()->getId();

    $result = $this->donationMailManager->mail(
      'ilas_donation_inquiry',
      'submission',
      $recipient,
      $language,
      $mail_params,
      $mail_params['reply_to'],
      TRUE
    );

    if (empty($result['result'])) {
      $this->donationLoggerFactory->get('ilas_donation_inquiry')->error('Failed to send donation inquiry email.');
      return $this->jsonError('We were unable to send your request. Please try again later.', 500);
    }

    $this->donationLoggerFactory->get('ilas_donation_inquiry')->notice('Donation inquiry email successfully dispatched for @email', [
      '@email' => $this->maskEmail($data['email'] ?? ''),
    ]);

    return new JsonResponse([
      'status' => 'success',
      'message' => $this->t('Thank you! Your inquiry has been sent to our development team.'),
    ]);
  }

  /**
   * Extracts JSON or form payload from the request.
   */
  protected function extractPayload(Request $request): array {
    $content = trim((string) $request->getContent());
    if ($content) {
      try {
        $decoded = Json::decode($content, TRUE);
        if (is_array($decoded)) {
          return $decoded;
        }
      }
      catch (InvalidDataTypeException $exception) {
        $this->donationLoggerFactory->get('ilas_donation_inquiry')->warning('Invalid JSON payload: @message', ['@message' => $exception->getMessage()]);
      }
    }
    return $request->request->all();
  }

  /**
   * Validates required fields.
   */
  protected function validateRequiredFields(array $data): array {
    $errors = [];
    $required_fields = [
      'first_name' => $this->t('First name is required.'),
      'last_name' => $this->t('Last name is required.'),
      'email' => $this->t('A valid email address is required.'),
      'phone' => $this->t('A valid phone number is required.'),
      'interests' => $this->t('Select at least one topic.'),
    ];

    foreach ($required_fields as $field => $message) {
      if (empty($data[$field])) {
        $errors[$field] = $message;
      }
    }

    if (!empty($data['email']) && !$this->donationEmailValidator->isValid($data['email'])) {
      $errors['email'] = $this->t('Please enter a valid email address.');
    }

    if (!empty($data['phone'])) {
      $digits = preg_replace('/\D+/', '', $data['phone']);
      if (strlen($digits) < 10) {
        $errors['phone'] = $this->t('Please enter a valid phone number.');
      }
    }

    if (!empty($data['interests']) && !is_array($data['interests'])) {
      $errors['interests'] = $this->t('Invalid interest selection.');
    }

    return $errors;
  }

  /**
   * Verifies a reCAPTCHA token against Google.
   */
  protected function verifyRecaptcha(string $secret, string $token, ?string $ip): string|bool {
    if (empty($token)) {
      return $this->t('Please complete the reCAPTCHA challenge.');
    }

    try {
      $response = $this->donationHttpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
        'form_params' => [
          'secret' => $secret,
          'response' => $token,
          'remoteip' => $ip,
        ],
        'timeout' => 5,
      ]);
      try {
        $body = Json::decode($response->getBody()->getContents());
      }
      catch (InvalidDataTypeException $exception) {
        $this->donationLoggerFactory->get('ilas_donation_inquiry')->error('Unable to decode reCAPTCHA response: @message', ['@message' => $exception->getMessage()]);
        return $this->t('We could not verify the reCAPTCHA response. Please try again.');
      }
      if (!empty($body['success'])) {
        return TRUE;
      }
      if (!empty($body['error-codes'])) {
        $this->donationLoggerFactory->get('ilas_donation_inquiry')->warning('reCAPTCHA failed with codes: @codes', ['@codes' => implode(', ', $body['error-codes'])]);
      }
    }
    catch (GuzzleException $exception) {
      $this->donationLoggerFactory->get('ilas_donation_inquiry')->error('reCAPTCHA verification failed: @message', ['@message' => $exception->getMessage()]);
    }

    return $this->t('We could not verify the reCAPTCHA response. Please try again.');
  }

  /**
   * Builds the mail parameters array for hook_mail().
   */
  protected function buildMailParams(array $data, Request $request): array {
    $name = trim(sprintf('%s %s', $data['first_name'], $data['last_name']));
    $sections = [];

    $sections['Contact Information'] = implode(PHP_EOL, array_filter([
      'Name: ' . $name,
      'Email: ' . $data['email'],
      'Phone: ' . $data['phone'],
      !empty($data['address']) ? 'Address: ' . $data['address'] : '',
    ]));

    $sections['Topics Selected'] = implode(PHP_EOL, $this->mapSelections($data['interests'] ?? [], self::INTEREST_LABELS));

    foreach (['making_donation_issues', 'existing_donation_issues', 'other_ways_options'] as $key) {
      if (!empty($data[$key])) {
        $label = match ($key) {
          'making_donation_issues' => 'Making a Donation Details',
          'existing_donation_issues' => 'Existing Donation Details',
          default => 'Other Ways to Donate',
        };
        $sections[$label] = implode(PHP_EOL, $this->mapSelections($data[$key], self::ISSUE_LABELS[$key]));
      }
    }

    if (!empty($data['making_donation_other'])) {
      $sections['Making a Donation - Additional'] = $data['making_donation_other'];
    }

    if (!empty($data['existing_donation_other'])) {
      $sections['Existing Donation - Additional'] = $data['existing_donation_other'];
    }

    if (!empty($data['program_info_details'])) {
      $sections['Program / Finance Information'] = $data['program_info_details'];
    }

    if (!empty($data['other_ways_additional'])) {
      $sections['Other Ways - Additional'] = $data['other_ways_additional'];
    }

    $sourceUrl = $data['source_url'] ?? $request->headers->get('referer');
    if (empty($sourceUrl)) {
      $sourceUrl = $request->getUri();
    }

    return [
      'subject' => $this->t('Donation Inquiry from @name', ['@name' => $name]),
      'intro' => $this->t('A new donation inquiry was submitted via the Idaho Legal Aid Services website.'),
      'sections' => $sections,
      'reply_to' => $data['email'],
      'source_url' => $sourceUrl,
    ];
  }

  /**
   * Helper to map machine values to labels.
   */
  protected function mapSelections($values, array $label_map): array {
    if (!is_array($values)) {
      $values = array_filter([$values]);
    }
    $mapped = [];
    foreach ($values as $value) {
      $mapped[] = '- ' . ($label_map[$value] ?? $value);
    }
    return $mapped ?: ['- None provided'];
  }

  /**
   * Returns a JSON error response.
   */
  protected function jsonError(string $message, int $code): JsonResponse {
    return new JsonResponse([
      'status' => 'error',
      'message' => $message,
    ], $code);
  }

  /**
   * Masks an email for logging while retaining some context.
   */
  protected function maskEmail(?string $email): string {
    if (empty($email)) {
      return 'unknown';
    }
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
      return $email;
    }
    $local = $parts[0];
    $domain = $parts[1];
    $localMasked = strlen($local) > 3 ? substr($local, 0, 3) . '***' : '***';
    return $localMasked . '@' . $domain;
  }

}
