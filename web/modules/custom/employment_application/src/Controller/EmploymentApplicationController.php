<?php

namespace Drupal\employment_application\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Flood\FloodInterface;
use Drupal\employment_application\Service\ApplicationValidator;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Employment Application Controller.
 *
 * Handles secure form submission with enterprise-grade validation,
 * file upload security, and email notifications.
 */
class EmploymentApplicationController extends ControllerBase {

  /**
   * The file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The mail manager service.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The CSRF token generator.
   */
  protected CsrfTokenGenerator $csrfToken;

  /**
   * The Symfony mailer service.
   */
  protected MailerInterface $mailer;

  /**
   * The application validator service.
   */
  protected ApplicationValidator $validator;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The flood service.
   */
  protected FloodInterface $flood;

  /**
   * The logger.
   */
  protected LoggerInterface $appLogger;


  /**
   * File upload directory.
   */
  private const UPLOAD_DIRECTORY = 'private://employment-applications';

  /**
   * Draft expiry in days.
   */
  private const DRAFT_EXPIRY_DAYS = 7;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->fileSystem = $container->get('file_system');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->csrfToken = $container->get('csrf_token');
    $instance->mailer = $container->get('symfony_mailer_lite.mailer');
    $instance->validator = $container->get('employment_application.validator');
    $instance->database = $container->get('database');
    $instance->flood = $container->get('flood');
    $instance->appLogger = $container->get('logger.factory')->get('employment_application');
    $instance->configFactory = $container->get('config.factory');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Returns list of currently posted jobs.
   */
  public function getPostedJobs(): CacheableJsonResponse {
    $jobs = $this->loadPostedJobs();

    $response = new CacheableJsonResponse([
      'jobs' => $jobs,
      'count' => count($jobs),
    ]);

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->setCacheTags(['node_list', 'paragraph_list', 'employment_jobs']);
    $cacheMetadata->setCacheContexts(['url.query_args']);
    $cacheMetadata->setCacheMaxAge(300);
    $response->addCacheableDependency($cacheMetadata);

    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    return $response;
  }

  /**
   * Returns a single job by UUID.
   */
  public function getJobByUuid(string $job_uuid): CacheableJsonResponse {
    $result = $this->loadJobByUuidWithReason($job_uuid);

    if ($result['job']) {
      $response = new CacheableJsonResponse([
        'job' => $result['job'],
        'valid' => TRUE,
      ]);
    }
    else {
      $errorData = [
        'valid' => FALSE,
        'reason' => $result['reason'],
      ];

      switch ($result['reason']) {
        case 'closed':
          $jobLabel = $result['job_location']
            ? "{$result['job_title']} — {$result['job_location']}"
            : $result['job_title'];
          $errorData['error'] = "The position \"{$jobLabel}\" is no longer accepting applications.";
          $errorData['job_title'] = $result['job_title'];
          $errorData['job_location'] = $result['job_location'] ?? '';
          $errorData['closed_date'] = $result['closed_date'];
          $errorData['message_type'] = 'closed';
          break;

        case 'not_job_posting':
          $errorData['error'] = 'This item is not a job posting.';
          $errorData['message_type'] = 'invalid';
          break;

        case 'not_found':
        default:
          $errorData['error'] = 'Job not found. It may have been removed.';
          $errorData['message_type'] = 'not_found';
          break;
      }

      $response = new CacheableJsonResponse($errorData, Response::HTTP_NOT_FOUND);
    }

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->setCacheTags(['paragraph_list', 'employment_jobs']);
    $cacheMetadata->setCacheMaxAge(300);
    $response->addCacheableDependency($cacheMetadata);

    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    return $response;
  }

  /**
   * Loads all currently posted jobs from the Employment node.
   */
  private function loadPostedJobs(): array {
    $jobs = [];

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'employment')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->range(0, 1);

    $nids = $query->execute();

    if (empty($nids)) {
      return $jobs;
    }

    $node = Node::load(reset($nids));

    if (!$node || !$node->hasField('field_job_listings')) {
      return $jobs;
    }

    $now = time();

    foreach ($node->get('field_job_listings') as $categoryRef) {
      $category = $categoryRef->entity;

      if (!$category || !$category->hasField('field_category_jobs')) {
        continue;
      }

      $categoryName = $category->get('field_category_name')->value ?? '';

      foreach ($category->get('field_category_jobs') as $jobRef) {
        $jobParagraph = $jobRef->entity;

        if (!$jobParagraph) {
          continue;
        }

        $datePosted = $jobParagraph->get('field_job_date_posted')->value ?? NULL;
        if (empty($datePosted)) {
          continue;
        }

        $validThrough = $jobParagraph->get('field_job_valid_through')->value ?? NULL;
        $openUntilFilled = (bool) ($jobParagraph->get('field_job_open_until_filled')->value ?? FALSE);

        $isActive = $this->isJobActive($validThrough, $openUntilFilled, $now);

        if (!$isActive) {
          continue;
        }

        $jobTitle = $jobParagraph->get('field_accordion_title')->value ?? '';
        $jobLocation = $jobParagraph->get('field_job_location')->value ?? '';
        $positionFamily = $jobParagraph->get('field_position_family')->value ?? '';
        $employmentType = $jobParagraph->get('field_job_employment_type')->value ?? '';
        $salaryRange = $jobParagraph->get('field_job_salary_range')->value ?? '';
        $workArrangement = $jobParagraph->get('field_job_work_arrangement')->value ?? '';

        $jobs[] = [
          'uuid' => $jobParagraph->uuid(),
          'id' => $jobParagraph->id(),
          'title' => $jobTitle,
          'location' => $jobLocation,
          'category' => $categoryName,
          'position_family' => $positionFamily,
          'employment_type' => $employmentType,
          'salary_range' => $salaryRange,
          'work_arrangement' => $workArrangement,
          'date_posted' => $datePosted,
          'valid_through' => $validThrough,
          'open_until_filled' => $openUntilFilled,
          'label' => $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle,
        ];
      }
    }

    usort($jobs, function ($a, $b) {
      $catCompare = strcmp($a['category'], $b['category']);
      if ($catCompare !== 0) {
        return $catCompare;
      }
      return strcmp($a['title'], $b['title']);
    });

    return $jobs;
  }

  /**
   * Determines if a job is currently active.
   */
  private function isJobActive(?string $validThrough, bool $openUntilFilled, int $now): bool {
    if ($openUntilFilled) {
      return TRUE;
    }

    if (empty($validThrough)) {
      return TRUE;
    }

    $dayAfterDeadline = strtotime($validThrough . ' +1 day');
    if ($dayAfterDeadline && $dayAfterDeadline > $now) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Loads a single job by UUID if it's currently posted.
   */
  private function loadJobByUuid(string $uuid): ?array {
    $result = $this->loadJobByUuidWithReason($uuid);
    return $result['job'];
  }

  /**
   * Loads a single job by UUID with closure reason.
   */
  private function loadJobByUuidWithReason(string $uuid): array {
    $paragraphs = $this->entityTypeManager
      ->getStorage('paragraph')
      ->loadByProperties(['uuid' => $uuid]);

    if (empty($paragraphs)) {
      return ['job' => NULL, 'reason' => 'not_found', 'job_title' => NULL];
    }

    $jobParagraph = reset($paragraphs);

    if ($jobParagraph->bundle() !== 'accordion_item') {
      return ['job' => NULL, 'reason' => 'not_found', 'job_title' => NULL];
    }

    $jobTitle = $jobParagraph->get('field_accordion_title')->value ?? '';
    $jobLocation = $jobParagraph->get('field_job_location')->value ?? '';

    $datePosted = $jobParagraph->get('field_job_date_posted')->value ?? NULL;

    if (empty($datePosted)) {
      return ['job' => NULL, 'reason' => 'not_job_posting', 'job_title' => $jobTitle];
    }

    $validThrough = $jobParagraph->get('field_job_valid_through')->value ?? NULL;
    $openUntilFilled = (bool) ($jobParagraph->get('field_job_open_until_filled')->value ?? FALSE);
    $now = time();

    $isActive = $this->isJobActive($validThrough, $openUntilFilled, $now);

    if (!$isActive) {
      return [
        'job' => NULL,
        'reason' => 'closed',
        'job_title' => $jobTitle,
        'job_location' => $jobLocation,
        'closed_date' => $validThrough,
      ];
    }

    $positionFamily = $jobParagraph->get('field_position_family')->value ?? '';
    $employmentType = $jobParagraph->get('field_job_employment_type')->value ?? '';
    $salaryRange = $jobParagraph->get('field_job_salary_range')->value ?? '';
    $workArrangement = $jobParagraph->get('field_job_work_arrangement')->value ?? '';

    $categoryName = $this->getJobCategoryName($jobParagraph);

    return [
      'job' => [
        'uuid' => $jobParagraph->uuid(),
        'id' => $jobParagraph->id(),
        'title' => $jobTitle,
        'location' => $jobLocation,
        'category' => $categoryName,
        'position_family' => $positionFamily,
        'employment_type' => $employmentType,
        'salary_range' => $salaryRange,
        'work_arrangement' => $workArrangement,
        'date_posted' => $datePosted,
        'valid_through' => $validThrough,
        'open_until_filled' => $openUntilFilled,
        'label' => $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle,
      ],
      'reason' => NULL,
    ];
  }

  /**
   * Gets the category name for a job paragraph.
   */
  private function getJobCategoryName(Paragraph $jobParagraph): string {
    $parentField = $jobParagraph->get('parent_field_name')->value;
    $parentType = $jobParagraph->get('parent_type')->value;
    $parentId = $jobParagraph->get('parent_id')->value;

    if ($parentType === 'paragraph' && $parentField === 'field_category_jobs') {
      $parentParagraph = Paragraph::load($parentId);
      if ($parentParagraph && $parentParagraph->hasField('field_category_name')) {
        return $parentParagraph->get('field_category_name')->value ?? '';
      }
    }

    return '';
  }

  /**
   * Validates job_uuid and returns job data if valid.
   */
  private function validateJobUuid(string $jobUuid): ?array {
    if (empty($jobUuid) || !$this->validator->validateUuid($jobUuid)) {
      return NULL;
    }

    return $this->loadJobByUuid($jobUuid);
  }

  /**
   * Generates and returns CSRF token + session-bound nonce.
   */
  public function getToken(Request $request): JsonResponse {
    \Drupal::service('page_cache_kill_switch')->trigger();

    $token = $this->csrfToken->get('employment_application_form');

    $nonce = bin2hex(random_bytes(16));
    $session = $request->getSession();
    $session->set('employment_app_nonce', $nonce);

    // Store token issuance time server-side for time-gate validation.
    // This replaces relying on Twig-rendered form_start_time which can be
    // cached by Varnish, making the time-gate unreliable.
    $session->set('employment_app_token_time', time());

    $response = new JsonResponse([
      'token' => $token,
      'nonce' => $nonce,
    ]);

    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $response->headers->set('Surrogate-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    $response->headers->set('Vary', 'Cookie');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    return $response;
  }

  /**
   * Handles employment application form submission.
   */
  public function submitApplication(Request $request): JsonResponse {
    // Per-request correlation ID for tracing across log entries.
    $correlationId = 'EA-' . bin2hex(random_bytes(4));

    try {
      $ip = $request->getClientIp();

      // =================================================================
      // LOAD CONFIGURABLE RATE-LIMIT THRESHOLDS
      // =================================================================
      $rateLimits = $this->configFactory->get('employment_application.settings')->get('rate_limits') ?? [];
      $burstLimit = (int) ($rateLimits['ip_burst_limit'] ?? 5);
      $burstWindow = (int) ($rateLimits['ip_burst_window'] ?? 60);
      $hourLimit = (int) ($rateLimits['ip_hour_limit'] ?? 20);
      $hourWindow = (int) ($rateLimits['ip_hour_window'] ?? 3600);
      $globalLimit = (int) ($rateLimits['global_limit'] ?? 30);
      $globalWindow = (int) ($rateLimits['global_window'] ?? 60);
      $unvalidatedLimit = (int) ($rateLimits['unvalidated_ip_limit'] ?? 10);
      $unvalidatedWindow = (int) ($rateLimits['unvalidated_ip_window'] ?? 3600);

      // Lightweight header classification for rate-limit log context.
      $headerClass = $this->classifyRequestHeaders($request);

      // =================================================================
      // RATE LIMITING — pre-validation (unvalidated request budget)
      // Only the unvalidated counter is checked + registered here.
      // Burst/hour/global are checked but NOT registered until after
      // security validation passes (see below).
      // =================================================================
      if (!$this->flood->isAllowed('employment_app_ip_unvalidated', $unvalidatedLimit, $unvalidatedWindow, $ip)) {
        $this->appLogger->warning('[@cid] Rate limit (unvalidated) exceeded for IP: @ip [@header_class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@header_class' => $headerClass,
        ]);
        return $this->errorResponse('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $unvalidatedWindow]);
      }

      if (!$this->flood->isAllowed('employment_app_ip_burst', $burstLimit, $burstWindow, $ip)) {
        $this->appLogger->warning('[@cid] Rate limit (burst) exceeded for IP: @ip [@header_class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@header_class' => $headerClass,
        ]);
        return $this->errorResponse('Too many requests. Please wait a moment.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $burstWindow]);
      }

      if (!$this->flood->isAllowed('employment_app_ip_hour', $hourLimit, $hourWindow, $ip)) {
        $this->appLogger->warning('[@cid] Rate limit (hourly) exceeded for IP: @ip [@header_class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@header_class' => $headerClass,
        ]);
        return $this->errorResponse('Submission limit reached. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $hourWindow]);
      }

      if (!$this->flood->isAllowed('employment_app_global', $globalLimit, $globalWindow, 'global')) {
        $this->appLogger->warning('[@cid] Global rate limit exceeded [@header_class]', [
          '@cid' => $correlationId,
          '@header_class' => $headerClass,
        ]);
        return $this->errorResponse('Service temporarily busy. Please try again shortly.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $globalWindow]);
      }

      // Register ONLY the unvalidated counter here — bots that fail
      // CSRF/nonce burn through this budget without touching the
      // validated counters that legitimate users need.
      $this->flood->register('employment_app_ip_unvalidated', $unvalidatedWindow, $ip);

      // =================================================================
      // CONTENT-TYPE VALIDATION
      // =================================================================
      $contentType = $request->headers->get('Content-Type', '');
      $isJson = strpos($contentType, 'application/json') !== FALSE;
      $isFormData = strpos($contentType, 'multipart/form-data') === 0;

      if (!$isJson && !$isFormData) {
        $this->appLogger->warning('[@cid] Invalid Content-Type from @ip: @type', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@type' => $contentType,
        ]);
        return $this->errorResponse('Invalid request format.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
      }

      // =================================================================
      // EXTRACT SECURITY FIELDS (unified for both content types)
      // =================================================================
      $honeypotValue = '';
      $formToken = NULL;
      $formNonce = NULL;
      $formStartTime = 0;
      $jsonData = NULL;

      if ($isFormData) {
        $honeypotValue = $request->request->get('fax_number', '');
        $formToken = $request->request->get('form_token');
        $formNonce = $request->request->get('form_nonce');
        $formStartTime = (int) $request->request->get('form_start_time', 0);
      }
      else {
        $content = $request->getContent();
        if (empty($content)) {
          return $this->errorResponse('No data received.', Response::HTTP_BAD_REQUEST);
        }
        $jsonData = json_decode($content, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          return $this->errorResponse('Invalid JSON data.', Response::HTTP_BAD_REQUEST);
        }
        $honeypotValue = $jsonData['fax_number'] ?? '';
        $formToken = $jsonData['form_token'] ?? NULL;
        $formNonce = $jsonData['form_nonce'] ?? NULL;
        $formStartTime = (int) ($jsonData['form_start_time'] ?? 0);
      }

      // =================================================================
      // HONEYPOT CHECK
      // =================================================================
      if (!empty($honeypotValue)) {
        $this->appLogger->notice('[@cid] Honeypot triggered from @ip', [
          '@cid' => $correlationId,
          '@ip' => $ip,
        ]);
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Application submitted successfully.',
        ]);
      }

      // =================================================================
      // CLASSIFY REQUEST (for log severity decisions)
      // =================================================================
      $ctx = $this->classifySubmissionRequest($request, $formToken, $formNonce, $formStartTime, $isFormData);

      // =================================================================
      // TIME-GATE: prefer server-side token issuance time over client-sent
      // form_start_time (which can be cached by Varnish).
      // =================================================================
      $session = $request->getSession();
      $tokenIssuedAt = (int) $session->get('employment_app_token_time', 0);

      // Use server-side token time if available, fall back to form_start_time.
      $effectiveStartTime = $tokenIssuedAt > 0 ? $tokenIssuedAt : $formStartTime;

      if ($effectiveStartTime <= 0) {
        $this->appLogger->notice('[@cid] Missing timing data from @ip [@class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@class' => $ctx['classification'],
        ] + $ctx);
        return $this->errorResponse('Invalid request.', Response::HTTP_BAD_REQUEST);
      }

      if ((time() - $effectiveStartTime) < ApplicationValidator::MIN_FORM_TIME_SECONDS) {
        $this->appLogger->notice('[@cid] Form submitted too quickly (@sec sec) from @ip', [
          '@cid' => $correlationId,
          '@sec' => time() - $effectiveStartTime,
          '@ip' => $ip,
        ]);
        return $this->errorResponse('Please take a moment to review your application.', Response::HTTP_BAD_REQUEST);
      }

      // =================================================================
      // SESSION NONCE VALIDATION
      // =================================================================
      $sessionNonce = $session->get('employment_app_nonce', '');
      if (empty($formNonce) || empty($sessionNonce) || !hash_equals($sessionNonce, $formNonce)) {
        $nonceType = empty($formNonce) ? 'missing_nonce' : 'invalid_nonce';
        $level = ($ctx['classification'] === 'likely_browser') ? 'warning' : 'notice';
        $this->appLogger->$level('[@cid] Nonce validation failed from @ip [@type] [@class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@type' => $nonceType,
          '@class' => $ctx['classification'],
        ] + $ctx);
        return $this->errorResponse('Session expired. Please reload the page and try again.', Response::HTTP_FORBIDDEN);
      }
      $session->remove('employment_app_nonce');
      $session->remove('employment_app_token_time');

      // =================================================================
      // CSRF TOKEN VALIDATION
      // =================================================================
      if (empty($formToken)) {
        $this->appLogger->notice('[@cid] Missing CSRF token from @ip [@class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@class' => $ctx['classification'],
        ] + $ctx);
        return $this->errorResponse('Invalid security token. Please reload the page.', Response::HTTP_FORBIDDEN);
      }
      if (!$this->csrfToken->validate($formToken, 'employment_application_form')) {
        $level = ($ctx['classification'] === 'likely_browser') ? 'warning' : 'notice';
        $this->appLogger->$level('[@cid] Invalid CSRF token from @ip [@class]', [
          '@cid' => $correlationId,
          '@ip' => $ip,
          '@class' => $ctx['classification'],
        ] + $ctx);
        return $this->errorResponse('Security token expired. Please reload the page and try again.', Response::HTTP_FORBIDDEN);
      }

      $this->appLogger->debug('[@cid] Security validation passed from @ip - @type', [
        '@cid' => $correlationId,
        '@ip' => $ip,
        '@type' => $isFormData ? 'FormData' : 'JSON',
      ]);

      // =================================================================
      // REGISTER VALIDATED FLOOD ENTRIES (post-security-validation)
      // Only requests that pass CSRF + nonce consume the main budget.
      // =================================================================
      $this->flood->register('employment_app_ip_burst', $burstWindow, $ip);
      $this->flood->register('employment_app_ip_hour', $hourWindow, $ip);
      $this->flood->register('employment_app_global', $globalWindow, 'global');

      // =================================================================
      // JOB + DATA + FILE VALIDATION (per content type)
      // =================================================================
      if ($isFormData) {
        $jobUuid = $request->request->get('job_uuid', '');
        $jobData = $this->validateJobUuid($jobUuid);
        if (!$jobData) {
          $this->appLogger->warning('[@cid] Invalid job_uuid from @ip: @uuid', [
            '@cid' => $correlationId,
            '@ip' => $ip,
            '@uuid' => $jobUuid,
          ]);
          return $this->errorResponse('Invalid job selection. Please select a currently posted position.', Response::HTTP_BAD_REQUEST);
        }

        $formData = $this->validateAndSanitizeData($request, $jobData);
        if (!$formData['valid']) {
          return $this->errorResponse($formData['errors'], Response::HTTP_BAD_REQUEST);
        }

        $fileData = $this->handleFileUploads($request, $correlationId);
        if (!$fileData['valid']) {
          return $this->errorResponse($fileData['errors'], Response::HTTP_BAD_REQUEST);
        }
      }
      else {
        $jobUuid = $jsonData['job_uuid'] ?? '';
        $jobData = $this->validateJobUuid($jobUuid);
        if (!$jobData) {
          $this->appLogger->warning('[@cid] Invalid job_uuid (JSON) from @ip: @uuid', [
            '@cid' => $correlationId,
            '@ip' => $ip,
            '@uuid' => $jobUuid,
          ]);
          return $this->errorResponse('Invalid job selection. Please select a currently posted position.', Response::HTTP_BAD_REQUEST);
        }

        $formData = $this->validateAndSanitizeJsonData($jsonData, $jobData);
        if (!$formData['valid']) {
          return $this->errorResponse($formData['errors'], Response::HTTP_BAD_REQUEST);
        }

        $fileData = ['valid' => TRUE, 'files' => []];
      }

      // =================================================================
      // SAVE (with transaction + file cleanup on failure)
      // =================================================================
      $createdFiles = $fileData['files'];
      $transaction = $this->database->startTransaction();

      try {
        $applicationId = $this->saveApplication(
          $formData['data'],
          $createdFiles,
          $ip,
          $correlationId
        );

        $pdfContent = $this->generateApplicationPDF(
          $formData['data'],
          $createdFiles,
          $applicationId
        );

        // Transaction commits when $transaction goes out of scope.
        unset($transaction);

      }
      catch (\Throwable $saveError) {
        // Rollback DB changes.
        $transaction->rollBack();

        // Explicitly clean up orphaned files (physical + entities).
        $this->cleanupCreatedFiles($createdFiles, $correlationId);

        throw $saveError;
      }

      // Email is outside the transaction — it's OK if it fails after DB commit.
      $this->sendNotifications($formData['data'], $createdFiles, $applicationId, $pdfContent);

      $this->appLogger->info('[@cid] Application saved: @id', [
        '@cid' => $correlationId,
        '@id' => $applicationId,
      ]);

      // Store application ID in session for server-rendered success page.
      $session->set('employment_app_submitted_id', $applicationId);
      $session->set('employment_app_submitted_position', $formData['data']['job_title'] ?? '');
      $session->set('employment_app_submitted_location', $formData['data']['job_location'] ?? '');

      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Application submitted successfully.',
        'application_id' => $applicationId,
      ]);

      $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

      return $response;

    }
    catch (\Throwable $e) {
      $errorClass = get_class($e);
      $this->appLogger->error(
        '[@cid] Submission failed: @class — @error in @file:@line',
        [
          '@cid' => $correlationId,
          '@class' => $errorClass,
          '@error' => $e->getMessage(),
          '@file' => basename($e->getFile()),
          '@line' => $e->getLine(),
        ]
      );

      return $this->errorResponse(
        'An error occurred while processing your application. Reference: ' . $correlationId,
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }
  }

  /**
   * Cleans up File entities and physical files created during a failed attempt.
   *
   * A DB transaction rollback reverts the employment_applications row and
   * file_managed rows, but does NOT undo physical file moves. This method
   * handles that cleanup explicitly.
   *
   * @param array $files
   *   Nested array of File entities keyed by field name.
   * @param string $correlationId
   *   Correlation ID for log tracing.
   */
  private function cleanupCreatedFiles(array $files, string $correlationId): void {
    foreach ($files as $fieldName => $fieldFiles) {
      foreach ($fieldFiles as $file) {
        if (!$file instanceof File) {
          continue;
        }
        try {
          $uri = $file->getFileUri();

          // Delete the physical file.
          if ($uri) {
            $realPath = $this->fileSystem->realpath($uri);
            if ($realPath && file_exists($realPath)) {
              $this->fileSystem->delete($uri);
            }
          }

          // Delete the file entity (may already be rolled back by transaction,
          // but delete() is safe to call regardless).
          $file->delete();
        }
        catch (\Throwable $cleanupError) {
          $this->appLogger->warning('[@cid] File cleanup failed for fid @fid: @error', [
            '@cid' => $correlationId,
            '@fid' => $file->id() ?? 'unknown',
            '@error' => $cleanupError->getMessage(),
          ]);
        }
      }
    }
  }

  /**
   * Classifies a submission request for structured logging.
   */
  private function classifySubmissionRequest(Request $request, $formToken, $formNonce, int $formStartTime, bool $isFormData): array {
    $ua = $request->headers->get('User-Agent', '');
    $referer = $request->headers->get('Referer', '');

    $hasSessionCookie = FALSE;
    foreach ($request->cookies->keys() as $name) {
      if (str_starts_with($name, 'SESS') || str_starts_with($name, 'SSESS')) {
        $hasSessionCookie = TRUE;
        break;
      }
    }

    $hasExpectedFields = FALSE;
    if ($isFormData) {
      $hasExpectedFields = $request->request->has('full_name') && $request->request->has('email');
    }

    $signals = 0;
    if (!empty($referer) && str_contains($referer, '/eapplication')) {
      $signals++;
    }
    if ($hasSessionCookie) {
      $signals++;
    }
    if (!empty($formToken)) {
      $signals++;
    }
    if (!empty($formNonce)) {
      $signals++;
    }
    if ($formStartTime > 0) {
      $signals++;
    }
    if ($hasExpectedFields) {
      $signals++;
    }
    if (!preg_match('/^$|curl|python|wget|scrapy|bot|crawl|spider/i', $ua)) {
      $signals++;
    }

    if ($signals >= 4) {
      $classification = 'likely_browser';
    }
    elseif ($signals >= 2) {
      $classification = 'uncertain';
    }
    else {
      $classification = 'bot_like';
    }

    return [
      'classification' => $classification,
      '@ua' => mb_substr($ua, 0, 200),
      '@referer' => mb_substr($referer, 0, 200),
      '@has_session' => $hasSessionCookie ? 'yes' : 'no',
      '@has_token' => !empty($formToken) ? 'yes' : 'no',
      '@has_nonce' => !empty($formNonce) ? 'yes' : 'no',
      '@has_start_time' => ($formStartTime > 0) ? 'yes' : 'no',
      '@has_fields' => $hasExpectedFields ? 'yes' : 'no',
    ];
  }

  /**
   * Lightweight header-only classification for rate-limit log entries.
   *
   * Unlike classifySubmissionRequest() which inspects form fields, this
   * runs before the request body is parsed so it can label early rate-limit
   * rejections (bot_like / uncertain / likely_browser).
   */
  private function classifyRequestHeaders(Request $request): string {
    $ua = $request->headers->get('User-Agent', '');
    $referer = $request->headers->get('Referer', '');

    $hasSessionCookie = FALSE;
    foreach ($request->cookies->keys() as $name) {
      if (str_starts_with($name, 'SESS') || str_starts_with($name, 'SSESS')) {
        $hasSessionCookie = TRUE;
        break;
      }
    }

    $signals = 0;
    if (!empty($referer) && str_contains($referer, '/eapplication')) {
      $signals++;
    }
    if ($hasSessionCookie) {
      $signals++;
    }
    if (!preg_match('/^$|curl|python|wget|scrapy|bot|crawl|spider/i', $ua)) {
      $signals++;
    }

    if ($signals >= 2) {
      return 'likely_browser';
    }
    if ($signals >= 1) {
      return 'uncertain';
    }
    return 'bot_like';
  }

  /**
   * Validates and sanitizes FormData form data.
   */
  private function validateAndSanitizeData(Request $request, array $jobData): array {
    $errors = [];
    $data = [];

    $data['job_uuid'] = $jobData['uuid'];
    $data['job_title'] = $jobData['title'];
    $data['job_location'] = $jobData['location'];
    $data['job_category'] = $jobData['category'];
    $data['position_applied'] = $jobData['position_family'];

    $requiredFields = [
      'full_name' => 'Full name',
      'email' => 'Email address',
      'phone' => 'Phone number',
      'available_start_date' => 'Available start date',
      'criminal_conviction' => 'Criminal conviction disclosure',
      'protection_order' => 'Protection order disclosure',
      'sexual_harassment' => 'Sexual harassment disclosure',
      'agreement' => 'Terms agreement',
    ];

    foreach ($requiredFields as $field => $label) {
      $value = $request->request->get($field);
      if (empty($value)) {
        $errors[] = "$label is required.";
        continue;
      }
      $data[$field] = $this->validator->sanitizeInput($value);
    }

    // Email validation.
    if (!empty($data['email']) && !$this->validator->validateEmail($data['email'])) {
      $errors[] = 'Please enter a valid email address.';
    }

    // Phone validation.
    if (!empty($data['phone']) && !$this->validator->validatePhone($data['phone'])) {
      $errors[] = 'Please enter a valid phone number.';
    }

    // Address data.
    $address = $request->request->get('address');
    if (!empty($address)) {
      if (is_string($address)) {
        $addressArray = json_decode($address, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($addressArray)) {
          $data['address'] = $this->validator->sanitizeInput($addressArray);
        }
      }
      elseif (is_array($address)) {
        $data['address'] = $this->validator->sanitizeInput($address);
      }
    }

    // Attorney-specific fields.
    $positionApplied = $data['position_applied'] ?? '';
    if ($positionApplied === 'managing_attorney' || $positionApplied === 'staff_attorney') {
      $attorneyFields = [
        'idaho_bar_licensed' => 'Idaho bar license status',
        'aba_law_school' => 'ABA law school graduation',
        'bar_discipline' => 'Bar discipline history',
      ];

      foreach ($attorneyFields as $field => $label) {
        $value = $request->request->get($field);
        if (empty($value)) {
          $errors[] = "$label is required for attorney positions.";
          continue;
        }
        $data[$field] = $this->validator->sanitizeInput($value);
      }
    }

    // Optional scalar fields.
    $optionalScalarFields = [
      'salary_requirements', 'referral_source',
      'referral_details', 'additional_comments',
      'accommodation_request',
    ];

    foreach ($optionalScalarFields as $field) {
      $value = $request->request->get($field);
      if (!empty($value) && is_scalar($value)) {
        $data[$field] = $this->validator->sanitizeInput($value);
      }
    }

    // ZIP code validation.
    if (!empty($data['address']['postal_code']) && !$this->validator->validateZipCode($data['address']['postal_code'])) {
      $errors[] = 'Please enter a valid US ZIP code (e.g. 83702 or 83702-1234).';
    }

    return [
      'valid' => empty($errors),
      'errors' => implode(' ', $errors),
      'data' => $data,
    ];
  }

  /**
   * Validates and sanitizes JSON form data.
   */
  private function validateAndSanitizeJsonData(array $jsonData, array $jobData): array {
    $errors = [];
    $data = [];

    $data['job_uuid'] = $jobData['uuid'];
    $data['job_title'] = $jobData['title'];
    $data['job_location'] = $jobData['location'];
    $data['job_category'] = $jobData['category'];
    $data['position_applied'] = $jobData['position_family'];

    $requiredFields = [
      'full_name' => 'Full name',
      'email' => 'Email address',
      'phone' => 'Phone number',
      'available_start_date' => 'Available start date',
      'criminal_conviction' => 'Criminal conviction disclosure',
      'protection_order' => 'Protection order disclosure',
      'sexual_harassment' => 'Sexual harassment disclosure',
      'agreement' => 'Terms agreement',
    ];

    foreach ($requiredFields as $field => $label) {
      $value = $jsonData[$field] ?? '';
      if (empty($value)) {
        $errors[] = "$label is required.";
        continue;
      }
      $data[$field] = $this->validator->sanitizeInput($value);
    }

    if (!empty($data['email']) && !$this->validator->validateEmail($data['email'])) {
      $errors[] = 'Please enter a valid email address.';
    }

    if (!empty($data['phone']) && !$this->validator->validatePhone($data['phone'])) {
      $errors[] = 'Please enter a valid phone number.';
    }

    if (!empty($jsonData['address']) && is_array($jsonData['address'])) {
      $data['address'] = $this->validator->sanitizeInput($jsonData['address']);
    }

    $positionApplied = $data['position_applied'] ?? '';
    if ($positionApplied === 'managing_attorney' || $positionApplied === 'staff_attorney') {
      $attorneyFields = [
        'idaho_bar_licensed' => 'Idaho bar license status',
        'aba_law_school' => 'ABA law school graduation',
        'bar_discipline' => 'Bar discipline history',
      ];

      foreach ($attorneyFields as $field => $label) {
        $value = $jsonData[$field] ?? '';
        if (empty($value)) {
          $errors[] = "$label is required for attorney positions.";
          continue;
        }
        $data[$field] = $this->validator->sanitizeInput($value);
      }
    }

    $optionalScalarFields = [
      'salary_requirements', 'referral_source',
      'referral_details', 'additional_comments',
      'accommodation_request',
    ];

    foreach ($optionalScalarFields as $field) {
      $value = $jsonData[$field] ?? '';
      if (!empty($value) && is_scalar($value)) {
        $data[$field] = $this->validator->sanitizeInput($value);
      }
    }

    // ZIP code validation.
    if (!empty($data['address']['postal_code']) && !$this->validator->validateZipCode($data['address']['postal_code'])) {
      $errors[] = 'Please enter a valid US ZIP code (e.g. 83702 or 83702-1234).';
    }

    return [
      'valid' => empty($errors),
      'errors' => implode(' ', $errors),
      'data' => $data,
    ];
  }

  /**
   * Handles secure file uploads.
   */
  private function handleFileUploads(Request $request, string $correlationId): array {
    $errors = [];
    $files = [];

    $uploadDirectory = self::UPLOAD_DIRECTORY;
    $this->fileSystem->prepareDirectory($uploadDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $fileFields = ['resume', 'cover_letter', 'additional_documents'];

    // Check total upload size across all files before processing.
    $totalSize = 0;
    foreach ($fileFields as $field) {
      $uploadedFiles = $request->files->get($field);
      if (!$uploadedFiles) {
        continue;
      }
      if (!is_array($uploadedFiles)) {
        $uploadedFiles = [$uploadedFiles];
      }
      foreach ($uploadedFiles as $uploadedFile) {
        if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
          $totalSize += $uploadedFile->getSize();
        }
      }
    }

    if ($totalSize > ApplicationValidator::MAX_TOTAL_UPLOAD_SIZE) {
      $maxMb = ApplicationValidator::MAX_TOTAL_UPLOAD_SIZE / (1024 * 1024);
      $this->appLogger->warning('[@cid] Total upload size @size exceeds @max limit', [
        '@cid' => $correlationId,
        '@size' => round($totalSize / (1024 * 1024), 1) . 'MB',
        '@max' => $maxMb . 'MB',
      ]);
      return [
        'valid' => FALSE,
        'errors' => "Total file size exceeds the {$maxMb}MB limit. Please reduce file sizes and try again.",
        'files' => [],
      ];
    }

    foreach ($fileFields as $field) {
      $uploadedFiles = $request->files->get($field);

      if (!$uploadedFiles) {
        continue;
      }

      if (!is_array($uploadedFiles)) {
        $uploadedFiles = [$uploadedFiles];
      }

      foreach ($uploadedFiles as $uploadedFile) {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
          continue;
        }

        $validation = $this->validateUploadedFile($uploadedFile, $correlationId);
        if (!$validation['valid']) {
          $errors[] = $validation['error'];
          continue;
        }

        $savedFile = $this->saveFile($uploadedFile, $field);
        if ($savedFile) {
          $files[$field][] = $savedFile;
        }
      }
    }

    // Resume is always required for submissions with file upload support.
    if (empty($files['resume'])) {
      $errors[] = 'Resume is required.';
    }

    return [
      'valid' => empty($errors),
      'errors' => implode(' ', $errors),
      'files' => $files,
    ];
  }

  /**
   * Validates an uploaded file using the ApplicationValidator service.
   */
  private function validateUploadedFile(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $correlationId): array {
    $extension = strtolower($file->getClientOriginalExtension());
    $mimeType = $file->getMimeType();
    $originalName = $file->getClientOriginalName();

    $this->appLogger->debug('[@cid] File validation - Name: @name, Ext: @ext, MIME: @mime, Size: @size', [
      '@cid' => $correlationId,
      '@name' => $originalName,
      '@ext' => $extension,
      '@mime' => $mimeType,
      '@size' => $file->getSize(),
    ]);

    $result = $this->validator->validateFile(
      $extension,
      $mimeType,
      $file->getSize(),
      $file->getPathname()
    );

    if (!$result['valid']) {
      $this->appLogger->warning('[@cid] File rejected: @name — @error', [
        '@cid' => $correlationId,
        '@name' => $originalName,
        '@error' => $result['error'],
      ]);
    }

    return $result;
  }

  /**
   * Saves uploaded file securely.
   */
  private function saveFile(\Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile, string $fieldName): ?File {
    try {
      $filename = $this->validator->generateSecureFilename($uploadedFile->getClientOriginalName());
      $yearMonth = date('Y-m');
      $directoryPath = self::UPLOAD_DIRECTORY . '/' . $yearMonth;
      $destination = $directoryPath . '/' . $filename;

      $this->fileSystem->prepareDirectory($directoryPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $uri = $this->fileSystem->copy($uploadedFile->getPathname(), $destination, FileSystemInterface::EXISTS_RENAME);

      $file = File::create([
        'uid' => \Drupal::currentUser()->id(),
        'filename' => basename($uri),
        'uri' => $uri,
        'status' => 1,
        'filemime' => $uploadedFile->getMimeType(),
      ]);
      $file->save();

      return $file;
    }
    catch (\Exception $e) {
      $this->appLogger->error('File upload error: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Saves application data to database.
   */
  private function saveApplication(array $data, array $files, string $ip, string $correlationId): string {
    $applicationId = $this->validator->generateApplicationId($data);

    // Prepare file references.
    $fileData = [];
    foreach ($files as $fieldName => $fieldFiles) {
      foreach ($fieldFiles as $file) {
        $fileData[$fieldName][] = [
          'fid' => $file->id(),
          'filename' => $file->getFilename(),
          'uri' => $file->getFileUri(),
        ];
      }
    }

    $cleanData = [];
    foreach ($data as $key => $value) {
      if (is_scalar($value) || is_array($value)) {
        $cleanData[$key] = $value;
      }
    }

    // Hash IP for privacy — don't store raw IPs.
    $hashSalt = \Drupal::service('settings')->get('hash_salt', '');
    $ipHash = $this->validator->hashIp($ip, $hashSalt);

    $this->database->insert('employment_applications')
      ->fields([
        'application_id' => $applicationId,
        'submitted' => time(),
        'form_data' => json_encode($cleanData, JSON_UNESCAPED_UNICODE),
        'file_data' => json_encode($fileData, JSON_UNESCAPED_UNICODE),
        'status' => 'submitted',
        'ip_hash' => $ipHash,
      ])
      ->execute();

    return $applicationId;
  }

  /**
   * Generates a PDF version of the application.
   */
  private function generateApplicationPDF(array $data, array $files, string $applicationId): string {
    try {
      if (!class_exists(\Dompdf\Options::class)) {
        $this->appLogger->error('Dompdf library is not installed. Run: composer require dompdf/dompdf');
        return '';
      }
      $options = new \Dompdf\Options();
      $options->set('defaultFont', 'DejaVu Sans');
      $options->set('isRemoteEnabled', FALSE);
      $options->set('isHtml5ParserEnabled', TRUE);

      $dompdf = new \Dompdf\Dompdf($options);

      $html = $this->buildApplicationHTML($data, $files, $applicationId);

      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'portrait');

      // Timeout protection: limit PDF rendering to 30 seconds to prevent
      // hangs on malformed or extremely large content.
      $previousLimit = (int) ini_get('max_execution_time');
      set_time_limit(30);
      $dompdf->render();
      set_time_limit($previousLimit);

      return $dompdf->output();

    }
    catch (\Throwable $e) {
      $this->appLogger->error('PDF generation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Merges the main application PDF with uploaded PDF files.
   */
  private function mergePDFs(string $mainPdfContent, array $pdfFiles): string {
    $tempMainFile = NULL;
    try {
      $tempMainFile = tempnam(sys_get_temp_dir(), 'app_main_') . '.pdf';
      file_put_contents($tempMainFile, $mainPdfContent);

      $pdf = new \setasign\Fpdi\Fpdi();

      $pageCount = $pdf->setSourceFile($tempMainFile);
      for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $pdf->addPage();
        $pdf->useTemplate($templateId);
      }

      foreach ($pdfFiles as $fileInfo) {
        try {
          $pdf->addPage();
          $pdf->setFont('Arial', 'B', 16);
          $pdf->setTextColor(44, 90, 160);
          $pdf->text(50, 50, $fileInfo['label'] . ':');

          $uploadedPageCount = $pdf->setSourceFile($fileInfo['path']);
          for ($i = 1; $i <= $uploadedPageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $pdf->addPage();
            $pdf->useTemplate($templateId);
          }
        }
        catch (\Exception $e) {
          $this->appLogger->warning('Could not merge PDF file @file: @error', [
            '@file' => $fileInfo['path'],
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return $pdf->output('S');

    }
    catch (\Exception $e) {
      $this->appLogger->error('PDF merging failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $mainPdfContent;
    }
    finally {
      // Always clean up temp file, even on exception.
      if ($tempMainFile !== NULL && file_exists($tempMainFile)) {
        @unlink($tempMainFile);
      }
    }
  }

  /**
   * Builds HTML content for PDF generation.
   */
  private function buildApplicationHTML(array $data, array $files, string $applicationId): string {
    $siteName = htmlspecialchars(
      $this->configFactory->get('system.site')->get('name') ?: 'Idaho Legal Aid Services',
      ENT_QUOTES,
      'UTF-8'
    );
    $currentDate = date('F j, Y \a\t g:i A');

    $jobTitle = $data['job_title'] ?? '';
    $jobLocation = $data['job_location'] ?? '';
    $jobCategory = $data['job_category'] ?? '';
    $positionFamily = $data['position_applied'] ?? '';
    $positionFamilyLabel = $this->validator->formatPositionTitle($positionFamily);

    $positionDisplay = $jobTitle;
    if ($jobLocation) {
      $positionDisplay .= " — {$jobLocation}";
    }

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Employment Application - ' . htmlspecialchars($applicationId, ENT_QUOTES, 'UTF-8') . '</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; margin: 0; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #2c5aa0; padding-bottom: 15px; }
            .header h1 { color: #2c5aa0; margin: 0 0 5px 0; font-size: 18px; }
            .header h2 { color: #666; margin: 0; font-size: 14px; font-weight: normal; }
            .meta-info { background: #f8f9fa; padding: 10px; margin-bottom: 20px; border-left: 4px solid #2c5aa0; }
            .position-highlight { background: #e8f4fd; padding: 12px; margin-bottom: 20px; border: 1px solid #2c5aa0; border-radius: 4px; }
            .position-highlight h3 { color: #2c5aa0; margin: 0 0 5px 0; font-size: 13px; }
            .position-highlight .position-name { font-size: 14px; font-weight: bold; color: #1a3d6e; }
            .position-highlight .position-category { font-size: 11px; color: #666; margin-top: 3px; }
            .section { margin-bottom: 25px; }
            .section-title { color: #2c5aa0; font-size: 14px; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            td { padding: 5px 8px; border: 1px solid #ddd; vertical-align: top; }
            .label-col { background: #f8f9fa; width: 30%; font-weight: bold; }
            .value-col { width: 70%; }
            .file-list { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; }
            .file-item { margin-bottom: 5px; }
        </style>
    </head>
    <body>';

    $html .= '
        <div class="header">
            <h1>' . $siteName . '</h1>
            <h2>Employment Application</h2>
        </div>';

    $html .= '
        <div class="meta-info">
            <strong>Application ID:</strong> ' . htmlspecialchars($applicationId, ENT_QUOTES, 'UTF-8') . '<br>
            <strong>Submitted:</strong> ' . $currentDate . '<br>
            <strong>Status:</strong> New Application
        </div>';

    $html .= '
        <div class="position-highlight">
            <h3>Position Applied For</h3>
            <div class="position-name">' . htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8') . '</div>';

    if ($jobCategory) {
      $html .= '<div class="position-category">Category: ' . htmlspecialchars($jobCategory, ENT_QUOTES, 'UTF-8') . ' | Family: ' . htmlspecialchars($positionFamilyLabel, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $html .= '</div>';

    $html .= '<div class="section">
        <div class="section-title">Personal Information</div>
        <table>
            <tr><td class="label-col">Full Name</td><td class="value-col">' . htmlspecialchars($data['full_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>
            <tr><td class="label-col">Email</td><td class="value-col">' . htmlspecialchars($data['email'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>
            <tr><td class="label-col">Phone</td><td class="value-col">' . htmlspecialchars($data['phone'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>';

    if (!empty($data['address'])) {
      $address = $data['address'];
      $fullAddress = implode(', ', array_filter([
        $address['address'] ?? '',
        $address['city'] ?? '',
        $address['state_province'] ?? '',
        $address['postal_code'] ?? '',
      ]));
      $html .= '<tr><td class="label-col">Address</td><td class="value-col">' . htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $html .= '</table></div>';

    $html .= '<div class="section">
        <div class="section-title">Position Information</div>
        <table>
            <tr><td class="label-col">Position Applied For</td><td class="value-col">' . htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8') . '</td></tr>
            <tr><td class="label-col">Position Category</td><td class="value-col">' . htmlspecialchars($jobCategory, ENT_QUOTES, 'UTF-8') . '</td></tr>
            <tr><td class="label-col">Position Family</td><td class="value-col">' . htmlspecialchars($positionFamilyLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>';

    $html .= '<tr><td class="label-col">Available Start Date</td><td class="value-col">' . htmlspecialchars($data['available_start_date'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>';

    if (!empty($data['salary_requirements'])) {
      $html .= '<tr><td class="label-col">Salary Requirements</td><td class="value-col">' . htmlspecialchars($data['salary_requirements'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $html .= '</table></div>';

    $html .= '<div class="section">
        <div class="section-title">Qualifications &amp; Background</div>
        <table>';

    $positionApplied = $data['position_applied'] ?? '';
    if ($positionApplied === 'managing_attorney' || $positionApplied === 'staff_attorney') {
      $html .= '<tr><td colspan="2" style="background: #e3f2fd; font-weight: bold; padding: 8px;">Attorney Qualifications</td></tr>';

      if (!empty($data['idaho_bar_licensed'])) {
        $barStatus = $this->validator->formatYesNoOption($data['idaho_bar_licensed']);
        $html .= '<tr><td class="label-col">Licensed to practice law in Idaho?</td><td class="value-col">' . htmlspecialchars($barStatus, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      }

      if (!empty($data['aba_law_school'])) {
        $abaGrad = $this->validator->formatYesNoOption($data['aba_law_school']);
        $html .= '<tr><td class="label-col">Graduated from ABA-accredited law school?</td><td class="value-col">' . htmlspecialchars($abaGrad, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      }

      if (!empty($data['bar_discipline'])) {
        $discipline = $this->validator->formatYesNoOption($data['bar_discipline']);
        $html .= '<tr><td class="label-col">Ever subject to bar discipline?</td><td class="value-col">' . htmlspecialchars($discipline, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      }

      $html .= '<tr><td colspan="2" style="height: 10px;"></td></tr>';
    }

    $html .= '<tr><td colspan="2" style="background: #fff3cd; font-weight: bold; padding: 8px;">Background Screening</td></tr>';

    if (!empty($data['criminal_conviction'])) {
      $criminal = $this->validator->formatYesNoOption($data['criminal_conviction']);
      $html .= '<tr><td class="label-col">Ever charged/convicted of domestic violence or violent crime?</td><td class="value-col">' . htmlspecialchars($criminal, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    if (!empty($data['protection_order'])) {
      $protection = $this->validator->formatYesNoOption($data['protection_order']);
      $html .= '<tr><td class="label-col">Ever subject to protection order/restraining order?</td><td class="value-col">' . htmlspecialchars($protection, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    if (!empty($data['sexual_harassment'])) {
      $harassment = $this->validator->formatYesNoOption($data['sexual_harassment']);
      $html .= '<tr><td class="label-col">Ever found to have engaged in sexual harassment?</td><td class="value-col">' . htmlspecialchars($harassment, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $html .= '</table></div>';

    $html .= '<div class="section">
        <div class="section-title">Additional Information</div>
        <table>';

    if (!empty($data['referral_source'])) {
      $referralText = ucwords(str_replace('_', ' ', $data['referral_source']));
      $html .= '<tr><td class="label-col">How did you hear about us?</td><td class="value-col">' . htmlspecialchars($referralText, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    if (!empty($data['referral_details'])) {
      $html .= '<tr><td class="label-col">Referral Details</td><td class="value-col">' . htmlspecialchars($data['referral_details'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    if (!empty($data['additional_comments'])) {
      $html .= '<tr><td class="label-col">Additional Comments</td><td class="value-col">' . nl2br(htmlspecialchars($data['additional_comments'], ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    }

    if (!empty($data['accommodation_request'])) {
      $html .= '<tr><td class="label-col">Accommodation Request</td><td class="value-col">' . nl2br(htmlspecialchars($data['accommodation_request'], ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    }

    $html .= '</table></div>';

    if (!empty($files)) {
      $html .= '<div class="section">
            <div class="section-title">Submitted Documents</div>
            <div class="file-list">';

      foreach ($files as $fieldName => $fieldFiles) {
        $fieldLabel = ucwords(str_replace('_', ' ', $fieldName));
        foreach ($fieldFiles as $file) {
          $filename = $file->getFilename();
          $html .= '<div class="file-item"><strong>' . htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</div>';
        }
      }

      $html .= '</div></div>';
    }

    $html .= '<div style="margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9px; color: #666; text-align: center;">
        Generated on ' . $currentDate . ' | ' . $siteName . ' Employment Application System
    </div>';

    $html .= '</body></html>';

    return $html;
  }

  /**
   * Sends email notifications using Symfony Mailer.
   */
  private function sendNotifications(array $data, array $files, string $applicationId, string $pdfContent = ''): void {
    try {
      $siteConfig = $this->configFactory->get('system.site');
      $appConfig = $this->configFactory->get('employment_application.settings');

      $siteName = $siteConfig->get('name') ?: 'Idaho Legal Aid Services';

      $adminEmailAddress = $appConfig->get('admin_email')
        ?: $siteConfig->get('mail')
        ?: 'admin@idaholegalaid.org';

      if ($appConfig->get('notification_enabled') === FALSE) {
        $this->appLogger->debug('Email notifications disabled by config');
        return;
      }

      $jobTitle = $data['job_title'] ?? '';
      $jobLocation = $data['job_location'] ?? '';
      $positionDisplay = $jobTitle;
      if ($jobLocation) {
        $positionDisplay .= " — {$jobLocation}";
      }

      $adminEmail = (new Email())
        ->from('noreply@idaholegalaid.org')
        ->to($adminEmailAddress)
        ->replyTo($data['email'] ?? 'noreply@idaholegalaid.org')
        ->subject("New Application: {$positionDisplay} - {$siteName}")
        ->text($this->formatAdminEmail($data, $files, $applicationId))
        ->html($this->formatAdminEmailHTML($data, $files, $applicationId));

      if (!empty($pdfContent)) {
        $filename = 'employment-application-summary-' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $applicationId) . '.pdf';
        $adminEmail->addPart(new DataPart($pdfContent, $filename, 'application/pdf'));
      }

      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          $filepath = $this->fileSystem->realpath($file->getFileUri());
          if ($filepath && file_exists($filepath)) {
            $fileContent = file_get_contents($filepath);
            $filename = $file->getFilename();
            $adminEmail->addPart(new DataPart($fileContent, $filename, $file->getMimeType()));
          }
        }
      }

      $this->mailer->send($adminEmail);

      $this->appLogger->debug('Admin email sent for application: @id', ['@id' => $applicationId]);

      if (!empty($data['email'])) {
        $confirmEmail = (new Email())
          ->from('noreply@idaholegalaid.org')
          ->to($data['email'])
          ->replyTo('noreply@idaholegalaid.org')
          ->subject("Application Received: {$positionDisplay} - {$siteName}")
          ->text($this->formatConfirmationEmail($data, $applicationId))
          ->html($this->formatConfirmationEmailHTML($data, $applicationId));

        $this->mailer->send($confirmEmail);

        $this->appLogger->debug('Confirmation email sent for application: @id', ['@id' => $applicationId]);
      }

    }
    catch (\Throwable $e) {
      $this->appLogger->error('Email sending failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Formats admin notification email (plain text).
   */
  private function formatAdminEmail(array $data, array $files, string $applicationId): string {
    $jobTitle = $data['job_title'] ?? '';
    $jobLocation = $data['job_location'] ?? '';
    $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

    $message = "New employment application received:\n\n";
    $message .= "Application ID: $applicationId\n";
    $message .= "Submitted: " . date('Y-m-d H:i:s') . "\n\n";

    $message .= "POSITION APPLIED FOR:\n";
    $message .= "Position: " . $positionDisplay . "\n";
    $message .= "Category: " . ($data['job_category'] ?? 'N/A') . "\n\n";

    $message .= "APPLICANT INFORMATION:\n";
    $message .= "Name: " . ($data['full_name'] ?? 'N/A') . "\n";
    $message .= "Email: " . ($data['email'] ?? 'N/A') . "\n";
    $message .= "Phone: " . ($data['phone'] ?? 'N/A') . "\n";
    $message .= "Start Date: " . ($data['available_start_date'] ?? 'N/A') . "\n\n";

    if (!empty($files)) {
      $message .= "UPLOADED FILES:\n";
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          $filename = $file->getFilename();
          $message .= "- " . ucfirst(str_replace('_', ' ', $fieldName)) . ": " . $filename . "\n";
        }
      }
    }

    return $message;
  }

  /**
   * Formats confirmation email to applicant (plain text).
   */
  private function formatConfirmationEmail(array $data, string $applicationId): string {
    $siteName = $this->configFactory->get('system.site')->get('name');
    $jobTitle = $data['job_title'] ?? '';
    $jobLocation = $data['job_location'] ?? '';
    $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

    return "Dear " . ($data['full_name'] ?? 'Applicant') . ",\n\n" .
           "Thank you for your interest in joining $siteName. We have successfully received your application.\n\n" .
           "Application ID: $applicationId\n" .
           "Position: $positionDisplay\n\n" .
           "What happens next?\n" .
           "Our team will review your qualifications.\n" .
           "If you're a good fit, we'll contact you within 1-2 weeks for next steps.\n\n" .
           "Thank you for your interest in our mission.\n\n" .
           "Best regards,\n$siteName Team";
  }

  /**
   * Formats admin notification email (HTML).
   */
  private function formatAdminEmailHTML(array $data, array $files, string $applicationId): string {
    $jobTitle = $data['job_title'] ?? '';
    $jobLocation = $data['job_location'] ?? '';
    $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

    $message = "<h2>New Employment Application Received</h2>";
    $message .= "<p><strong>Application ID:</strong> " . htmlspecialchars($applicationId, ENT_QUOTES, 'UTF-8') . "<br>";
    $message .= "<strong>Submitted:</strong> " . date('Y-m-d H:i:s') . "</p>";

    $message .= "<h3>Position Applied For</h3>";
    $message .= "<p style='font-size: 16px; color: #2c5aa0; font-weight: bold;'>" . htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8') . "</p>";
    $message .= "<p><strong>Category:</strong> " . htmlspecialchars($data['job_category'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";

    $message .= "<h3>Applicant Information</h3>";
    $message .= "<ul>";
    $message .= "<li><strong>Name:</strong> " . htmlspecialchars($data['full_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</li>";
    $message .= "<li><strong>Email:</strong> " . htmlspecialchars($data['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</li>";
    $message .= "<li><strong>Phone:</strong> " . htmlspecialchars($data['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</li>";
    $message .= "<li><strong>Start Date:</strong> " . htmlspecialchars($data['available_start_date'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</li>";
    $message .= "</ul>";

    if (!empty($files)) {
      $message .= "<h3>Uploaded Files</h3><ul>";
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          $filename = $file->getFilename();
          $message .= "<li>" . ucfirst(str_replace('_', ' ', $fieldName)) . ": " . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . "</li>";
        }
      }
      $message .= "</ul>";
    }

    $message .= "<p><strong>Complete application details are attached as PDF.</strong></p>";

    return $message;
  }

  /**
   * Formats confirmation email to applicant (HTML).
   */
  private function formatConfirmationEmailHTML(array $data, string $applicationId): string {
    $siteName = htmlspecialchars(
      $this->configFactory->get('system.site')->get('name') ?: 'Idaho Legal Aid Services',
      ENT_QUOTES,
      'UTF-8'
    );
    $jobTitle = $data['job_title'] ?? '';
    $jobLocation = $data['job_location'] ?? '';
    $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

    return "<h2>Application Received</h2>" .
           "<p>Dear " . htmlspecialchars($data['full_name'] ?? 'Applicant', ENT_QUOTES, 'UTF-8') . ",</p>" .
           "<p>Thank you for your interest in joining " . $siteName . ". We have successfully received your application.</p>" .
           "<p><strong>Application ID:</strong> " . htmlspecialchars($applicationId, ENT_QUOTES, 'UTF-8') . "<br>" .
           "<strong>Position:</strong> " . htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8') . "</p>" .
           "<h3>What happens next?</h3>" .
           "<ul>" .
           "<li>Our team will review your qualifications</li>" .
           "<li>If you're a good fit, we'll contact you within 1-2 weeks for next steps</li>" .
           "</ul>" .
           "<p>Thank you for your interest in our mission.</p>" .
           "<p>Best regards,<br>" . $siteName . " Team</p>";
  }

  /**
   * Returns error response.
   *
   * @param string $message
   *   Error message.
   * @param int $status
   *   HTTP status code.
   * @param array $headers
   *   Optional additional headers (e.g. Retry-After for 429 responses).
   */
  private function errorResponse(string $message, int $status = Response::HTTP_BAD_REQUEST, array $headers = []): JsonResponse {
    $response = new JsonResponse([
      'success' => FALSE,
      'message' => $message,
    ], $status);

    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    foreach ($headers as $name => $value) {
      $response->headers->set($name, $value);
    }

    return $response;
  }

  /**
   * Allowed application statuses.
   */
  private const ALLOWED_STATUSES = [
    'submitted' => 'Submitted',
    'reviewing' => 'Reviewing',
    'interviewed' => 'Interviewed',
    'offered' => 'Offered',
    'hired' => 'Hired',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn',
  ];

  /**
   * Admin list of employment applications.
   */
  public function adminList(): array {
    $query = $this->database->select('employment_applications', 'ea')
      ->fields('ea')
      ->orderBy('submitted', 'DESC')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(20);

    $applications = $query->execute()->fetchAll();

    $header = [
      'Submitted',
      'Applicant',
      'Position',
      'Status',
      'Files',
      'Actions',
    ];

    $rows = [];
    foreach ($applications as $app) {
      $formData = json_decode($app->form_data, TRUE);
      $fileData = json_decode($app->file_data, TRUE);

      $jobTitle = $formData['job_title'] ?? '';
      $jobLocation = $formData['job_location'] ?? '';
      $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

      $statusLabel = self::ALLOWED_STATUSES[$app->status] ?? ucfirst($app->status);

      $fileCount = 0;
      if (!empty($fileData) && is_array($fileData)) {
        foreach ($fileData as $fieldFiles) {
          if (is_array($fieldFiles)) {
            $fileCount += count($fieldFiles);
          }
        }
      }

      $detailUrl = '/admin/employment-applications/' . $app->id;
      $actions = '<a href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '">View</a>';
      $actions .= ' | <a href="mailto:' . htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8') . '">Email</a>';

      $rows[] = [
        date('M j, Y', $app->submitted),
        ['data' => ['#markup' => '<a href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($formData['full_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</a>']],
        htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8'),
        ['data' => ['#markup' => '<span class="application-status application-status--' . htmlspecialchars($app->status, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>']],
        $fileCount . ' file' . ($fileCount !== 1 ? 's' : ''),
        ['data' => ['#markup' => $actions]],
      ];
    }

    return [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => 'No applications found.',
      '#attached' => [
        'library' => ['system/admin'],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Application detail view.
   */
  public function adminDetail(int $id): array {
    $application = $this->database->select('employment_applications', 'ea')
      ->fields('ea')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$application) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Application not found.');
    }

    $formData = json_decode($application->form_data, TRUE) ?: [];
    $fileData = json_decode($application->file_data, TRUE) ?: [];

    $jobTitle = $formData['job_title'] ?? '';
    $jobLocation = $formData['job_location'] ?? '';
    $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;
    $positionFamily = $formData['position_applied'] ?? '';
    $positionFamilyLabel = $this->validator->formatPositionTitle($positionFamily);

    // Build personal info rows.
    $personalRows = [];
    $personalRows[] = ['Full Name', htmlspecialchars($formData['full_name'] ?? '', ENT_QUOTES, 'UTF-8')];
    $personalRows[] = ['Email', ['data' => ['#markup' => '<a href="mailto:' . htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8') . '</a>']]];
    $personalRows[] = ['Phone', htmlspecialchars($formData['phone'] ?? '', ENT_QUOTES, 'UTF-8')];

    if (!empty($formData['address'])) {
      $addr = $formData['address'];
      $fullAddress = implode(', ', array_filter([
        $addr['address'] ?? '',
        $addr['city'] ?? '',
        $addr['state_province'] ?? '',
        $addr['postal_code'] ?? '',
      ]));
      $personalRows[] = ['Address', htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8')];
    }

    // Build position info rows.
    $positionRows = [];
    $positionRows[] = ['Position', htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8')];
    $positionRows[] = ['Category', htmlspecialchars($formData['job_category'] ?? '', ENT_QUOTES, 'UTF-8')];
    $positionRows[] = ['Position Family', htmlspecialchars($positionFamilyLabel, ENT_QUOTES, 'UTF-8')];
    $positionRows[] = ['Available Start Date', htmlspecialchars($formData['available_start_date'] ?? '', ENT_QUOTES, 'UTF-8')];
    if (!empty($formData['salary_requirements'])) {
      $positionRows[] = ['Salary Requirements', htmlspecialchars($formData['salary_requirements'], ENT_QUOTES, 'UTF-8')];
    }

    // Build qualifications rows.
    $qualRows = [];
    if ($positionFamily === 'managing_attorney' || $positionFamily === 'staff_attorney') {
      if (!empty($formData['idaho_bar_licensed'])) {
        $qualRows[] = ['Idaho Bar Licensed', htmlspecialchars($this->validator->formatYesNoOption($formData['idaho_bar_licensed']), ENT_QUOTES, 'UTF-8')];
      }
      if (!empty($formData['aba_law_school'])) {
        $qualRows[] = ['ABA Law School', htmlspecialchars($this->validator->formatYesNoOption($formData['aba_law_school']), ENT_QUOTES, 'UTF-8')];
      }
      if (!empty($formData['bar_discipline'])) {
        $qualRows[] = ['Bar Discipline', htmlspecialchars($this->validator->formatYesNoOption($formData['bar_discipline']), ENT_QUOTES, 'UTF-8')];
      }
    }

    $screeningRows = [];
    if (!empty($formData['criminal_conviction'])) {
      $screeningRows[] = ['Criminal Conviction', htmlspecialchars($this->validator->formatYesNoOption($formData['criminal_conviction']), ENT_QUOTES, 'UTF-8')];
    }
    if (!empty($formData['protection_order'])) {
      $screeningRows[] = ['Protection Order', htmlspecialchars($this->validator->formatYesNoOption($formData['protection_order']), ENT_QUOTES, 'UTF-8')];
    }
    if (!empty($formData['sexual_harassment'])) {
      $screeningRows[] = ['Sexual Harassment', htmlspecialchars($this->validator->formatYesNoOption($formData['sexual_harassment']), ENT_QUOTES, 'UTF-8')];
    }

    // Build file download links.
    $fileLinks = [];
    foreach ($fileData as $fieldName => $fieldFiles) {
      if (!is_array($fieldFiles)) {
        continue;
      }
      $fieldLabel = ucwords(str_replace('_', ' ', $fieldName));
      foreach ($fieldFiles as $file) {
        $downloadUrl = '/admin/employment-applications/' . $id . '/download/' . $file['fid'];
        $fileLinks[] = [
          'label' => $fieldLabel,
          'url' => $downloadUrl,
          'filename' => $file['filename'],
        ];
      }
    }

    // Status update form.
    $currentStatus = $application->status;
    $statusOptions = '';
    foreach (self::ALLOWED_STATUSES as $key => $label) {
      $selected = ($key === $currentStatus) ? ' selected' : '';
      $statusOptions .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    $csrfToken = $this->csrfToken->get('employment_application_admin');

    // Build render array.
    $build = [];

    $build['back_link'] = [
      '#markup' => '<p><a href="/admin/employment-applications">&laquo; Back to Applications</a></p>',
    ];

    $build['meta'] = [
      '#markup' => Markup::create('<div style="background:#f8f9fa;padding:15px;border-left:4px solid #2c5aa0;margin-bottom:20px;">'
        . '<strong>Application ID:</strong> ' . htmlspecialchars($application->application_id, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Submitted:</strong> ' . date('F j, Y \a\t g:i A', $application->submitted) . '<br>'
        . '<strong>Status:</strong> '
        . '<form method="post" action="/admin/employment-applications/' . $id . '/status" style="display:inline;">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
        . '<select name="status" onchange="this.form.submit()">' . $statusOptions . '</select>'
        . '</form>'
        . '</div>'),
    ];

    $build['personal_heading'] = ['#markup' => '<h3>Personal Information</h3>'];
    $build['personal'] = [
      '#theme' => 'table',
      '#rows' => $personalRows,
    ];

    $build['position_heading'] = ['#markup' => '<h3>Position Information</h3>'];
    $build['position'] = [
      '#theme' => 'table',
      '#rows' => $positionRows,
    ];

    if (!empty($qualRows)) {
      $build['qual_heading'] = ['#markup' => '<h3>Attorney Qualifications</h3>'];
      $build['qual'] = [
        '#theme' => 'table',
        '#rows' => $qualRows,
      ];
    }

    if (!empty($screeningRows)) {
      $build['screening_heading'] = ['#markup' => '<h3>Background Screening</h3>'];
      $build['screening'] = [
        '#theme' => 'table',
        '#rows' => $screeningRows,
      ];
    }

    // Additional info.
    $additionalRows = [];
    if (!empty($formData['referral_source'])) {
      $additionalRows[] = ['Referral Source', htmlspecialchars(ucwords(str_replace('_', ' ', $formData['referral_source'])), ENT_QUOTES, 'UTF-8')];
    }
    if (!empty($formData['referral_details'])) {
      $additionalRows[] = ['Referral Details', htmlspecialchars($formData['referral_details'], ENT_QUOTES, 'UTF-8')];
    }
    if (!empty($formData['additional_comments'])) {
      $additionalRows[] = ['Additional Comments', htmlspecialchars($formData['additional_comments'], ENT_QUOTES, 'UTF-8')];
    }
    if (!empty($formData['accommodation_request'])) {
      $additionalRows[] = ['Accommodation Request', htmlspecialchars($formData['accommodation_request'], ENT_QUOTES, 'UTF-8')];
    }

    if (!empty($additionalRows)) {
      $build['additional_heading'] = ['#markup' => '<h3>Additional Information</h3>'];
      $build['additional'] = [
        '#theme' => 'table',
        '#rows' => $additionalRows,
      ];
    }

    // Files.
    if (!empty($fileLinks)) {
      $build['files_heading'] = ['#markup' => '<h3>Uploaded Documents</h3>'];
      $fileMarkup = '<ul>';
      foreach ($fileLinks as $fl) {
        $fileMarkup .= '<li><strong>' . htmlspecialchars($fl['label'], ENT_QUOTES, 'UTF-8') . ':</strong> '
          . '<a href="' . htmlspecialchars($fl['url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($fl['filename'], ENT_QUOTES, 'UTF-8') . '</a></li>';
      }
      $fileMarkup .= '</ul>';
      $build['files'] = ['#markup' => $fileMarkup];
    }

    // Delete action.
    $build['delete_separator'] = ['#markup' => '<hr>'];
    $build['delete'] = [
      '#type' => 'link',
      '#title' => $this->t('Delete Application'),
      '#url' => Url::fromRoute('employment_application.delete', ['id' => $id]),
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];

    $build['#attached'] = ['library' => ['system/admin']];

    return $build;
  }

  /**
   * Updates application status.
   */
  public function updateStatus(int $id, Request $request): Response {
    $csrfToken = $request->request->get('csrf_token', '');
    if (!$this->csrfToken->validate($csrfToken, 'employment_application_admin')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Invalid CSRF token.');
    }

    $newStatus = $request->request->get('status', '');
    if (!isset(self::ALLOWED_STATUSES[$newStatus])) {
      $this->messenger()->addError('Invalid status value.');
      return new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/employment-applications/' . $id);
    }

    $this->database->update('employment_applications')
      ->fields(['status' => $newStatus])
      ->condition('id', $id)
      ->execute();

    $this->messenger()->addStatus('Application status updated to "' . self::ALLOWED_STATUSES[$newStatus] . '".');

    return new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/employment-applications/' . $id);
  }

  /**
   * Saves a draft and emails a resume link to the applicant.
   */
  public function saveDraft(Request $request): JsonResponse {
    // Rate limit: 10 draft saves per hour per IP.
    $clientIp = $request->getClientIp() ?: 'unknown';
    if (!$this->flood->isAllowed('employment_draft_save', 10, 3600, $clientIp)) {
      return $this->errorResponse('Too many save requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '3600']);
    }
    $this->flood->register('employment_draft_save', 3600, $clientIp);

    $contentType = $request->headers->get('Content-Type', '');
    if (stripos($contentType, 'application/json') === FALSE) {
      return $this->errorResponse('Invalid content type.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (empty($body) || empty($body['email']) || empty($body['form_data'])) {
      return $this->errorResponse('Email and form data are required.');
    }

    // CSRF token validation (M-2).
    $formToken = $body['form_token'] ?? '';
    if (empty($formToken) || !$this->csrfToken->validate($formToken, 'employment_application_form')) {
      return $this->errorResponse('Invalid security token. Please reload the page and try again.', Response::HTTP_FORBIDDEN);
    }

    $email = trim($body['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->errorResponse('Please provide a valid email address.');
    }

    $formData = $body['form_data'];
    if (!is_array($formData)) {
      return $this->errorResponse('Invalid form data.');
    }

    $now = \Drupal::time()->getRequestTime();
    $ipHash = hash('sha256', $clientIp);

    // Check if a draft exists for this email (update instead of duplicate).
    $existing = $this->database->select('employment_application_drafts', 'd')
      ->fields('d', ['id', 'resume_token'])
      ->condition('email', $email)
      ->execute()
      ->fetchObject();

    if ($existing) {
      // Update existing draft.
      $this->database->update('employment_application_drafts')
        ->fields([
          'form_data' => json_encode($formData),
          'updated' => $now,
          'ip_hash' => $ipHash,
        ])
        ->condition('id', $existing->id)
        ->execute();
      $resumeToken = $existing->resume_token;
    }
    else {
      // Create new draft.
      $resumeToken = bin2hex(random_bytes(32));
      $this->database->insert('employment_application_drafts')
        ->fields([
          'resume_token' => $resumeToken,
          'email' => $email,
          'form_data' => json_encode($formData),
          'created' => $now,
          'updated' => $now,
          'ip_hash' => $ipHash,
        ])
        ->execute();
    }

    // Send resume email.
    $this->sendDraftResumeEmail($email, $resumeToken, $formData);

    $this->appLogger->info('Draft saved for email @email', ['@email' => $email]);

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Your progress has been saved. Check your email for a link to resume your application.',
    ]);
  }

  /**
   * Loads a draft by resume token (JSON API).
   */
  public function loadDraft(string $token): JsonResponse {
    $draft = $this->database->select('employment_application_drafts', 'd')
      ->fields('d')
      ->condition('resume_token', $token)
      ->execute()
      ->fetchObject();

    if (!$draft) {
      return $this->errorResponse('Draft not found or has expired.', Response::HTTP_NOT_FOUND);
    }

    // Check expiry.
    $expiry = $draft->created + (self::DRAFT_EXPIRY_DAYS * 86400);
    if (\Drupal::time()->getRequestTime() > $expiry) {
      // Clean up expired draft.
      $this->database->delete('employment_application_drafts')
        ->condition('id', $draft->id)
        ->execute();
      return $this->errorResponse('This draft link has expired. Please start a new application.', Response::HTTP_GONE);
    }

    $formData = json_decode($draft->form_data, TRUE);

    return new JsonResponse([
      'success' => TRUE,
      'form_data' => $formData,
      'email' => $draft->email,
      'saved_at' => date('F j, Y g:i A', $draft->updated),
    ]);
  }

  /**
   * Sends a draft resume link email to the applicant.
   */
  private function sendDraftResumeEmail(string $email, string $resumeToken, array $formData): void {
    try {
      $siteConfig = $this->configFactory->get('system.site');
      $siteName = $siteConfig->get('name') ?: 'Idaho Legal Aid Services';

      $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
      $resumeUrl = $baseUrl . '/eapplication?resume=' . $resumeToken;

      $jobTitle = $formData['job_title'] ?? $formData['position_applied'] ?? '';
      $applicantName = $formData['full_name'] ?? 'Applicant';
      $expiryDays = self::DRAFT_EXPIRY_DAYS;

      $textBody = "Dear {$applicantName},\n\n"
        . "Your employment application draft has been saved. Use the link below to resume your application from any device:\n\n"
        . $resumeUrl . "\n\n";
      if ($jobTitle) {
        $textBody .= "Position: {$jobTitle}\n\n";
      }
      $textBody .= "This link will expire in {$expiryDays} days.\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n"
        . "Best regards,\n"
        . $siteName . "\n";

      $htmlBody = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">'
        . '<div style="background: #1a3a5c; color: #ffffff; padding: 20px; text-align: center;">'
        . '<h1 style="margin: 0; font-size: 20px;">' . htmlspecialchars($siteName) . '</h1>'
        . '</div>'
        . '<div style="padding: 30px; background: #f8f9fa;">'
        . '<p>Dear ' . htmlspecialchars($applicantName) . ',</p>'
        . '<p>Your employment application draft has been saved. Click the button below to resume your application from any device:</p>';
      if ($jobTitle) {
        $htmlBody .= '<p style="background: #e9ecef; padding: 10px; border-radius: 4px;"><strong>Position:</strong> '
          . htmlspecialchars($jobTitle) . '</p>';
      }
      $htmlBody .= '<div style="text-align: center; margin: 30px 0;">'
        . '<a href="' . htmlspecialchars($resumeUrl) . '" style="display: inline-block; background: #1a3a5c; color: #ffffff; padding: 14px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Resume Application</a>'
        . '</div>'
        . '<p style="color: #6c757d; font-size: 13px;">This link will expire in ' . $expiryDays . ' days. If you did not request this, you can safely ignore this email.</p>'
        . '</div>'
        . '<div style="padding: 15px; text-align: center; color: #6c757d; font-size: 12px;">'
        . htmlspecialchars($siteName)
        . '</div></div>';

      $draftEmail = (new Email())
        ->from('noreply@idaholegalaid.org')
        ->to($email)
        ->replyTo('noreply@idaholegalaid.org')
        ->subject("Resume Your Application — {$siteName}")
        ->text($textBody)
        ->html($htmlBody);

      $this->mailer->send($draftEmail);

      $this->appLogger->debug('Draft resume email sent to @email', ['@email' => $email]);
    }
    catch (\Throwable $e) {
      $this->appLogger->error('Draft resume email failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Download uploaded file.
   */
  public function downloadFile(int $id, int $fid): BinaryFileResponse {
    $application = $this->database->select('employment_applications', 'ea')
      ->fields('ea', ['file_data'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$application) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Application not found.');
    }

    $fileData = json_decode($application->file_data, TRUE);
    $fidBelongsToApplication = FALSE;

    if (is_array($fileData)) {
      foreach ($fileData as $fieldFiles) {
        if (!is_array($fieldFiles)) {
          continue;
        }
        foreach ($fieldFiles as $file) {
          if (isset($file['fid']) && (int) $file['fid'] === $fid) {
            $fidBelongsToApplication = TRUE;
            break 2;
          }
        }
      }
    }

    if (!$fidBelongsToApplication) {
      $this->appLogger->warning('IDOR attempt: fid @fid not in application @app', [
        '@fid' => $fid,
        '@app' => $id,
      ]);
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('File does not belong to this application.');
    }

    $file = File::load($fid);
    if (!$file) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('File not found.');
    }

    $uri = $file->getFileUri();
    if (strpos($uri, 'private://') !== 0) {
      $this->appLogger->warning('File @fid not in private scheme: @uri', [
        '@fid' => $fid,
        '@uri' => $uri,
      ]);
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Invalid file location.');
    }

    $filepath = $this->fileSystem->realpath($uri);
    if (!$filepath || !file_exists($filepath)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Physical file not found.');
    }

    return new BinaryFileResponse(
      $filepath,
      200,
      [
        'Content-Type' => $file->getMimeType(),
        'Content-Disposition' => HeaderUtils::makeDisposition(
          'attachment',
          $file->getFilename(),
          'download'
        ),
      ]
    );
  }

}
