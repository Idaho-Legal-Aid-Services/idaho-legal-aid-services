<?php

namespace Drupal\b5subtheme\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\CsrfTokenGenerator;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
   * File upload configuration.
   */
  private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];
  private const MAX_FILE_SIZE = 5242880; // 5MB
  private const UPLOAD_DIRECTORY = 'private://employment-applications';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->fileSystem = $container->get('file_system');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->csrfToken = $container->get('csrf_token');
    return $instance;
  }

  /**
   * Generates and returns CSRF token.
   */
  public function getToken(Request $request): JsonResponse {
    $token = $this->csrfToken->get('employment_application_form');
    
    return new JsonResponse([
      'token' => $token,
      'build_id' => 'form-' . bin2hex(random_bytes(8)),
    ]);
  }

  /**
   * Handles employment application form submission.
   */
  public function submitApplication(Request $request): JsonResponse {
    try {
      // Validate CSRF token
      if (!$this->validateCsrfToken($request)) {
        return $this->errorResponse('Invalid security token.', Response::HTTP_FORBIDDEN);
      }

      // Validate and sanitize form data
      $formData = $this->validateAndSanitizeData($request);
      if (!$formData['valid']) {
        return $this->errorResponse($formData['errors'], Response::HTTP_BAD_REQUEST);
      }

      // Handle file uploads
      $fileData = $this->handleFileUploads($request);
      if (!$fileData['valid']) {
        return $this->errorResponse($fileData['errors'], Response::HTTP_BAD_REQUEST);
      }

      // Save application data
      $applicationId = $this->saveApplication($formData['data'], $fileData['files']);

      // Send notifications
      $this->sendNotifications($formData['data'], $fileData['files'], $applicationId);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Application submitted successfully.',
        'application_id' => $applicationId,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('Submission error: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return $this->errorResponse('An error occurred while processing your application. Please try again.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Validates CSRF token.
   */
  private function validateCsrfToken(Request $request): bool {
    $token = $request->request->get('form_token');
    return $token && $this->csrfToken->validate($token, 'employment_application_form');
  }

  /**
   * Validates and sanitizes form data.
   */
  private function validateAndSanitizeData(Request $request): array {
    $errors = [];
    $data = [];

    // Required fields validation
    $requiredFields = [
      'full_name' => 'Full name',
      'email' => 'Email address', 
      'phone' => 'Phone number',
      'position_applied' => 'Position applied for',
      'available_start_date' => 'Available start date',
      'agreement' => 'Terms agreement',
    ];

    foreach ($requiredFields as $field => $label) {
      $value = $request->request->get($field);
      if (empty($value)) {
        $errors[] = "$label is required.";
        continue;
      }
      $data[$field] = $this->sanitizeInput($value);
    }

    // Email validation
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid email address.';
    }

    // Phone validation
    if (!empty($data['phone']) && !preg_match('/^[\+]?[\s\-\(\)]?[\d\s\-\(\)]{10,}$/', $data['phone'])) {
      $errors[] = 'Please enter a valid phone number.';
    }

    // Optional fields
    $optionalFields = [
      'address', 'position_other', 'salary_requirements',
      'work_experience', 'education', 'referral_source',
      'referral_details', 'additional_comments'
    ];

    foreach ($optionalFields as $field) {
      $value = $request->request->get($field);
      if (!empty($value)) {
        $data[$field] = $this->sanitizeInput($value);
      }
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
  private function handleFileUploads(Request $request): array {
    $errors = [];
    $files = [];

    // Ensure upload directory exists
    $this->fileSystem->prepareDirectory(self::UPLOAD_DIRECTORY, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $fileFields = ['resume', 'cover_letter', 'additional_documents'];

    foreach ($fileFields as $field) {
      $uploadedFiles = $request->files->get($field);
      
      if (!$uploadedFiles) {
        continue;
      }

      // Handle multiple files for additional_documents
      if (!is_array($uploadedFiles)) {
        $uploadedFiles = [$uploadedFiles];
      }

      foreach ($uploadedFiles as $uploadedFile) {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
          continue;
        }

        // Validate file
        $validation = $this->validateFile($uploadedFile);
        if (!$validation['valid']) {
          $errors[] = $validation['error'];
          continue;
        }

        // Save file
        $savedFile = $this->saveFile($uploadedFile, $field);
        if ($savedFile) {
          $files[$field][] = $savedFile;
        }
      }
    }

    // Resume is required
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
   * Validates uploaded file.
   */
  private function validateFile(\Symfony\Component\HttpFoundation\File\UploadedFile $file): array {
    // Check file size
    if ($file->getSize() > self::MAX_FILE_SIZE) {
      return [
        'valid' => FALSE,
        'error' => 'File size must be less than 5MB.',
      ];
    }

    // Check file extension
    $extension = strtolower($file->getClientOriginalExtension());
    if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
      return [
        'valid' => FALSE,
        'error' => 'Only PDF, DOC, and DOCX files are allowed.',
      ];
    }

    // Check MIME type
    $mimeType = $file->getMimeType();
    $allowedMimeTypes = [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    if (!in_array($mimeType, $allowedMimeTypes)) {
      return [
        'valid' => FALSE,
        'error' => 'Invalid file type.',
      ];
    }

    return ['valid' => TRUE];
  }

  /**
   * Saves uploaded file securely.
   */
  private function saveFile(\Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile, string $fieldName): ?File {
    try {
      $filename = $this->generateSecureFilename($uploadedFile->getClientOriginalName());
      $destination = self::UPLOAD_DIRECTORY . '/' . date('Y-m') . '/' . $filename;

      // Move file
      $uri = $this->fileSystem->copy($uploadedFile->getPathname(), $destination, FileSystemInterface::EXISTS_RENAME);

      // Create file entity
      $file = File::create([
        'uid' => \Drupal::currentUser()->id(),
        'filename' => $filename,
        'uri' => $uri,
        'status' => FILE_STATUS_PERMANENT,
        'filemime' => $uploadedFile->getMimeType(),
      ]);
      $file->save();

      return $file;
    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('File upload error: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Generates secure filename.
   */
  private function generateSecureFilename(string $originalName): string {
    $pathinfo = pathinfo($originalName);
    $extension = strtolower($pathinfo['extension'] ?? '');
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $pathinfo['filename'] ?? 'file');
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    
    return "{$basename}_{$timestamp}_{$random}.{$extension}";
  }

  /**
   * Saves application data to database.
   */
  private function saveApplication(array $data, array $files): string {
    $database = \Drupal::database();
    
    // Create table if it doesn't exist
    $this->createApplicationTable();
    
    $applicationId = 'APP_' . date('Ymd') . '_' . strtoupper(bin2hex(random_bytes(4)));
    
    // Prepare file references
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
    
    $database->insert('employment_applications')
      ->fields([
        'application_id' => $applicationId,
        'submitted' => time(),
        'form_data' => json_encode($data),
        'file_data' => json_encode($fileData),
        'status' => 'submitted',
        'ip_address' => \Drupal::request()->getClientIp(),
      ])
      ->execute();

    return $applicationId;
  }

  /**
   * Creates application storage table.
   */
  private function createApplicationTable(): void {
    $database = \Drupal::database();
    
    if (!$database->schema()->tableExists('employment_applications')) {
      $schema = [
        'description' => 'Employment application submissions.',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE,
          ],
          'application_id' => [
            'type' => 'varchar',
            'length' => 50,
            'not null' => TRUE,
          ],
          'submitted' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
          ],
          'form_data' => [
            'type' => 'text',
            'size' => 'big',
            'not null' => TRUE,
          ],
          'file_data' => [
            'type' => 'text',
            'size' => 'big',
            'not null' => FALSE,
          ],
          'status' => [
            'type' => 'varchar',
            'length' => 20,
            'not null' => TRUE,
            'default' => 'submitted',
          ],
          'ip_address' => [
            'type' => 'varchar',
            'length' => 45,
            'not null' => FALSE,
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'application_id' => ['application_id'],
        ],
        'indexes' => [
          'submitted' => ['submitted'],
          'status' => ['status'],
        ],
      ];
      
      $database->schema()->createTable('employment_applications', $schema);
    }
  }

  /**
   * Sends email notifications.
   */
  private function sendNotifications(array $data, array $files, string $applicationId): void {
    $config = \Drupal::config('system.site');
    $siteName = $config->get('name');
    $adminEmail = $config->get('mail');
    
    // Email to admin
    $this->mailManager->mail(
      'system',
      'employment_application_admin',
      $adminEmail,
      'en',
      [
        'subject' => "New Employment Application - $siteName",
        'body' => $this->formatAdminEmail($data, $files, $applicationId),
      ]
    );

    // Confirmation email to applicant
    if (!empty($data['email'])) {
      $this->mailManager->mail(
        'system',
        'employment_application_confirmation',
        $data['email'],
        'en',
        [
          'subject' => "Application Received - $siteName",
          'body' => $this->formatConfirmationEmail($data, $applicationId),
        ]
      );
    }
  }

  /**
   * Formats admin notification email.
   */
  private function formatAdminEmail(array $data, array $files, string $applicationId): string {
    $message = "New employment application received:\n\n";
    $message .= "Application ID: $applicationId\n";
    $message .= "Submitted: " . date('Y-m-d H:i:s') . "\n\n";
    
    $message .= "APPLICANT INFORMATION:\n";
    $message .= "Name: " . ($data['full_name'] ?? 'N/A') . "\n";
    $message .= "Email: " . ($data['email'] ?? 'N/A') . "\n";
    $message .= "Phone: " . ($data['phone'] ?? 'N/A') . "\n";
    $message .= "Position: " . ($data['position_applied'] ?? 'N/A') . "\n";
    $message .= "Start Date: " . ($data['available_start_date'] ?? 'N/A') . "\n\n";
    
    if (!empty($files)) {
      $message .= "UPLOADED FILES:\n";
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          $message .= "- " . ucfirst(str_replace('_', ' ', $fieldName)) . ": " . $file['filename'] . "\n";
        }
      }
    }
    
    return $message;
  }

  /**
   * Formats confirmation email to applicant.
   */
  private function formatConfirmationEmail(array $data, string $applicationId): string {
    $siteName = \Drupal::config('system.site')->get('name');
    
    return "Dear " . ($data['full_name'] ?? 'Applicant') . ",\n\n" .
           "Thank you for your interest in joining $siteName. We have successfully received your application.\n\n" .
           "Application ID: $applicationId\n" .
           "Position: " . ($data['position_applied'] ?? 'N/A') . "\n\n" .
           "What happens next?\n" .
           "• We'll acknowledge receipt of your application within 24 hours\n" .
           "• Our team will review your qualifications\n" .
           "• If you're a good fit, we'll contact you within 1-2 weeks for next steps\n\n" .
           "Thank you for your interest in our mission.\n\n" .
           "Best regards,\n$siteName Team";
  }

  /**
   * Sanitizes input data.
   */
  private function sanitizeInput($value): string|array {
    if (is_array($value)) {
      return array_map([$this, 'sanitizeInput'], $value);
    }
    return strip_tags(trim($value));
  }

  /**
   * Returns error response.
   */
  private function errorResponse(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    return new JsonResponse([
      'success' => FALSE,
      'message' => $message,
    ], $status);
  }
}