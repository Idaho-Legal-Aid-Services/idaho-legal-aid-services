<?php

namespace Drupal\employment_application\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    $instance->mailer = $container->get('symfony_mailer_lite.mailer');
    return $instance;
  }

  /**
   * Generates and returns CSRF token.
   */
  public function getToken(Request $request): JsonResponse {
    $token = $this->csrfToken->get('employment_application_form');
    
    $response = new JsonResponse([
      'token' => $token,
      'build_id' => 'form-' . bin2hex(random_bytes(8)),
    ]);
    
    // Prevent search indexing of this response
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    
    return $response;
  }

  /**
   * Handles employment application form submission.
   */
  public function submitApplication(Request $request): JsonResponse {
    try {
      // Log the request for debugging
      $contentType = $request->headers->get('Content-Type');
      \Drupal::logger('employment_application')->info('Request received - Content type: @type', [
        '@type' => $contentType,
      ]);

      // Determine if this is a file upload (FormData) or JSON submission
      $isFileUpload = strpos($contentType, 'multipart/form-data') !== false;
      
      if ($isFileUpload) {
        // Handle FormData submission with files
        \Drupal::logger('employment_application')->info('Processing FormData submission with files');
        
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
        
      } else {
        // Handle JSON submission (no files)
        \Drupal::logger('employment_application')->info('Processing JSON submission');
        
        // Get JSON content from request
        $content = $request->getContent();
        \Drupal::logger('employment_application')->info('Request content length: @length', [
          '@length' => strlen($content),
        ]);
        
        if (empty($content)) {
          \Drupal::logger('employment_application')->error('No content received in request');
          return $this->errorResponse('No data received.', Response::HTTP_BAD_REQUEST);
        }

        $jsonData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
          \Drupal::logger('employment_application')->error('JSON decode error: @error', [
            '@error' => json_last_error_msg(),
          ]);
          return $this->errorResponse('Invalid JSON data: ' . json_last_error_msg(), Response::HTTP_BAD_REQUEST);
        }

        \Drupal::logger('employment_application')->info('JSON data parsed successfully, keys: @keys', [
          '@keys' => implode(', ', array_keys($jsonData)),
        ]);

        // Validate and sanitize JSON form data
        $formData = $this->validateAndSanitizeJsonData($jsonData);
        if (!$formData['valid']) {
          return $this->errorResponse($formData['errors'], Response::HTTP_BAD_REQUEST);
        }

        // No files for JSON submissions
        $fileData = ['valid' => true, 'files' => []];
      }

      // Save application data
      $applicationId = $this->saveApplication($formData['data'], $fileData['files']);

      \Drupal::logger('employment_application')->info('Application saved with ID: @id', [
        '@id' => $applicationId,
      ]);

      // Generate PDF
      $pdfContent = $this->generateApplicationPDF($formData['data'], $fileData['files'], $applicationId);
      
      // Send notifications with PDF attachment
      $this->sendNotifications($formData['data'], $fileData['files'], $applicationId, $pdfContent);

      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Application submitted successfully.',
        'application_id' => $applicationId,
      ]);
      
      // Prevent search indexing of this response
      $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
      
      return $response;

    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('Submission error: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return $this->errorResponse('An error occurred while processing your application. Please try again.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Validates CSRF token from JSON data.
   */
  private function validateCsrfTokenFromJson(array $jsonData): bool {
    $token = $jsonData['form_token'] ?? '';
    return $token && $this->csrfToken->validate($token, 'employment_application_form');
  }

  /**
   * Validates CSRF token (legacy form data - kept for backwards compatibility).
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

    // Handle address data - check if it's JSON string from FormData
    $address = $request->request->get('address');
    if (!empty($address)) {
      if (is_string($address)) {
        // Decode JSON string from FormData
        $addressArray = json_decode($address, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($addressArray)) {
          $data['address'] = $this->sanitizeInput($addressArray);
        }
      } elseif (is_array($address)) {
        $data['address'] = $this->sanitizeInput($address);
      }
    }

    // Handle attorney-specific fields if position is attorney
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
        $data[$field] = $this->sanitizeInput($value);
      }
    }

    // Other optional scalar fields
    $optionalScalarFields = [
      'position_other', 'salary_requirements', 'referral_source',
      'referral_details', 'additional_comments'
    ];

    foreach ($optionalScalarFields as $field) {
      $value = $request->request->get($field);
      if (!empty($value) && is_scalar($value)) {
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
   * Validates and sanitizes JSON form data.
   */
  private function validateAndSanitizeJsonData(array $jsonData): array {
    $errors = [];
    $data = [];

    // Required fields validation
    $requiredFields = [
      'full_name' => 'Full name',
      'email' => 'Email address', 
      'phone' => 'Phone number',
      'position_applied' => 'Position applied for',
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

    // Handle address data (can now be nested)
    if (!empty($jsonData['address']) && is_array($jsonData['address'])) {
      $data['address'] = $this->sanitizeInput($jsonData['address']);
    }

    // Handle attorney-specific fields if position is attorney
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
        $data[$field] = $this->sanitizeInput($value);
      }
    }

    // Other optional scalar fields
    $optionalScalarFields = [
      'position_other', 'salary_requirements', 'referral_source',
      'referral_details', 'additional_comments'
    ];

    foreach ($optionalScalarFields as $field) {
      $value = $jsonData[$field] ?? '';
      if (!empty($value) && is_scalar($value)) {
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
   * Handles file uploads from JSON data.
   */
  private function handleJsonFileUploads(array $jsonData): array {
    // For Phase 2 implementation: temporarily skip file handling
    // Files will be handled in Phase 3
    $files = [];
    $errors = [];

    // Check if resume is required (for now, we'll make it optional)
    // TODO: Implement base64 file decoding in Phase 3
    
    return [
      'valid' => true, // Always valid for now
      'errors' => '',
      'files' => $files,
    ];
  }

  /**
   * Handles secure file uploads (legacy FormData method).
   */
  private function handleFileUploads(Request $request): array {
    $errors = [];
    $files = [];

    // Ensure upload directory exists
    $uploadDirectory = self::UPLOAD_DIRECTORY;
    $this->fileSystem->prepareDirectory($uploadDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

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

    // Resume is required for applications with file uploads
    if (empty($files['resume']) && !empty($files)) {
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
    // Log file details for debugging
    $extension = strtolower($file->getClientOriginalExtension());
    $mimeType = $file->getMimeType();
    $originalName = $file->getClientOriginalName();
    
    \Drupal::logger('employment_application')->info('File validation - Name: @name, Extension: @ext, MIME: @mime, Size: @size', [
      '@name' => $originalName,
      '@ext' => $extension,
      '@mime' => $mimeType,
      '@size' => $file->getSize(),
    ]);

    // Check file size
    if ($file->getSize() > self::MAX_FILE_SIZE) {
      return [
        'valid' => FALSE,
        'error' => 'File size must be less than 5MB.',
      ];
    }

    // Check file extension
    if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
      \Drupal::logger('employment_application')->error('Invalid extension: @ext, allowed: @allowed', [
        '@ext' => $extension,
        '@allowed' => implode(', ', self::ALLOWED_EXTENSIONS),
      ]);
      return [
        'valid' => FALSE,
        'error' => 'Only PDF, DOC, and DOCX files are allowed.',
      ];
    }

    // Check MIME type - be flexible with PDF detection
    $allowedMimeTypes = [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    
    // Some PDFs are detected as application/octet-stream, so check extension too
    $isValidPdf = ($extension === 'pdf' && ($mimeType === 'application/pdf' || $mimeType === 'application/octet-stream'));
    $isValidDoc = ($extension === 'doc' && $mimeType === 'application/msword');
    $isValidDocx = ($extension === 'docx' && $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    
    if (!($isValidPdf || $isValidDoc || $isValidDocx)) {
      \Drupal::logger('employment_application')->error('Invalid MIME type: @mime for extension @ext', [
        '@mime' => $mimeType,
        '@ext' => $extension,
      ]);
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
      $yearMonth = date('Y-m');
      $directoryPath = self::UPLOAD_DIRECTORY . '/' . $yearMonth;
      $destination = $directoryPath . '/' . $filename;

      // Ensure the specific year-month directory exists
      $this->fileSystem->prepareDirectory($directoryPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      // Move file using copy method which returns the URI
      $uri = $this->fileSystem->copy($uploadedFile->getPathname(), $destination, FileSystemInterface::EXISTS_RENAME);

      // Create file entity with the returned URI
      $file = File::create([
        'uid' => \Drupal::currentUser()->id(),
        'filename' => basename($uri), // Use the actual filename from the URI
        'uri' => $uri,
        'status' => 1, // FILE_STATUS_PERMANENT value is 1 in Drupal 11
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
   * Generates human-readable application ID.
   */
  private function generateHumanReadableApplicationId(array $data): string {
    // Parse the full name to extract first and last names
    $fullName = trim($data['full_name'] ?? 'Unknown');
    $nameParts = explode(' ', $fullName);
    
    $firstName = $nameParts[0] ?? 'Unknown';
    $lastName = count($nameParts) > 1 ? end($nameParts) : 'Unknown';
    
    // Format position by removing underscores and capitalizing
    $position = $data['position_applied'] ?? 'unknown';
    $formattedPosition = $this->formatPositionTitle($position);
    
    // Format date as MM/DD/YYYY with time to ensure uniqueness
    $dateSubmitted = date('m/d/Y H:i:s');
    
    // Create ID: LastName, FirstName - Position (DateSubmitted)
    $applicationId = "{$lastName}, {$firstName} - {$formattedPosition} ({$dateSubmitted})";
    
    return $applicationId;
  }

  /**
   * Formats position title by removing underscores and proper capitalization.
   */
  private function formatPositionTitle(string $position): string {
    // Remove underscores and replace with spaces
    $formatted = str_replace('_', ' ', $position);
    
    // Capitalize each word properly
    $formatted = ucwords(strtolower($formatted));
    
    return $formatted;
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
    
    // Generate human-readable application ID: LastName, FirstName - Position (DateSubmitted)
    $applicationId = $this->generateHumanReadableApplicationId($data);
    
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
    
    // Ensure all data is properly serializable
    $cleanData = [];
    foreach ($data as $key => $value) {
      if (is_scalar($value) || is_array($value)) {
        $cleanData[$key] = $value;
      }
    }

    $database->insert('employment_applications')
      ->fields([
        'application_id' => $applicationId,
        'submitted' => time(),
        'form_data' => json_encode($cleanData, JSON_UNESCAPED_UNICODE),
        'file_data' => json_encode($fileData, JSON_UNESCAPED_UNICODE),
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
   * Generates a PDF version of the application.
   */
  private function generateApplicationPDF(array $data, array $files, string $applicationId): string {
    try {
      // Use DomPDF directly since Entity Print installs it
      $options = new \Dompdf\Options();
      $options->set('defaultFont', 'DejaVu Sans');
      $options->set('isRemoteEnabled', false); // Security
      $options->set('isHtml5ParserEnabled', true);
      
      $dompdf = new \Dompdf\Dompdf($options);
      
      // Generate HTML content
      $html = $this->buildApplicationHTML($data, $files, $applicationId);
      
      // Load HTML into DomPDF
      $dompdf->loadHtml($html);
      
      // Set paper size
      $dompdf->setPaper('A4', 'portrait');
      
      // Render PDF
      $dompdf->render();
      
      // Return PDF content as string
      return $dompdf->output();
      
    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('PDF generation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Generates the main application PDF (without file content).
   */
  private function generateMainApplicationPDF(array $data, array $files, string $applicationId): string {
    try {
      // Use DomPDF directly since Entity Print installs it
      $options = new \Dompdf\Options();
      $options->set('defaultFont', 'DejaVu Sans');
      $options->set('isRemoteEnabled', false); // Security
      $options->set('isHtml5ParserEnabled', true);
      
      $dompdf = new \Dompdf\Dompdf($options);
      
      // Generate HTML content
      $html = $this->buildApplicationHTML($data, $files, $applicationId);
      
      // Load HTML into DomPDF
      $dompdf->loadHtml($html);
      
      // Set paper size
      $dompdf->setPaper('A4', 'portrait');
      
      // Render PDF
      $dompdf->render();
      
      // Return PDF content as string
      return $dompdf->output();
      
    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('Main PDF generation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Merges the main application PDF with uploaded PDF files.
   */
  private function mergePDFs(string $mainPdfContent, array $pdfFiles): string {
    try {
      \Drupal::logger('employment_application')->info('Starting PDF merge process with @count files', [
        '@count' => count($pdfFiles),
      ]);
      
      // Create temporary files for processing
      $tempMainFile = tempnam(sys_get_temp_dir(), 'app_main_') . '.pdf';
      file_put_contents($tempMainFile, $mainPdfContent);
      
      \Drupal::logger('employment_application')->info('Created temp main file: @file', [
        '@file' => $tempMainFile,
      ]);
      
      // Initialize FPDI
      $pdf = new \setasign\Fpdi\Fpdi();
      
      // Add pages from main application PDF
      $pageCount = $pdf->setSourceFile($tempMainFile);
      for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $pdf->addPage();
        $pdf->useTemplate($templateId);
      }
      
      // Add pages from uploaded PDF files
      foreach ($pdfFiles as $fileInfo) {
        try {
          // Add a separator page
          $pdf->addPage();
          $pdf->setFont('Arial', 'B', 16);
          $pdf->setTextColor(44, 90, 160); // ILAS blue color
          $pdf->text(50, 50, $fileInfo['label'] . ':');
          
          // Import and add pages from uploaded PDF
          $uploadedPageCount = $pdf->setSourceFile($fileInfo['path']);
          for ($i = 1; $i <= $uploadedPageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $pdf->addPage();
            $pdf->useTemplate($templateId);
          }
        } catch (\Exception $e) {
          \Drupal::logger('employment_application')->warning('Could not merge PDF file @file: @error', [
            '@file' => $fileInfo['path'],
            '@error' => $e->getMessage(),
          ]);
          // Continue with other files if one fails
        }
      }
      
      // Clean up temporary file
      unlink($tempMainFile);
      
      // Return merged PDF content
      return $pdf->output('S'); // 'S' returns string instead of outputting to browser
      
    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('PDF merging failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      // Return main PDF if merging fails
      return $mainPdfContent;
    }
  }

  /**
   * Builds HTML content for PDF generation.
   */
  private function buildApplicationHTML(array $data, array $files, string $applicationId): string {
    $siteName = \Drupal::config('system.site')->get('name') ?: 'Idaho Legal Aid Services';
    $currentDate = date('F j, Y \a\t g:i A');
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Employment Application - ' . htmlspecialchars($applicationId) . '</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; margin: 0; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #2c5aa0; padding-bottom: 15px; }
            .header h1 { color: #2c5aa0; margin: 0 0 5px 0; font-size: 18px; }
            .header h2 { color: #666; margin: 0; font-size: 14px; font-weight: normal; }
            .meta-info { background: #f8f9fa; padding: 10px; margin-bottom: 20px; border-left: 4px solid #2c5aa0; }
            .section { margin-bottom: 25px; }
            .section-title { color: #2c5aa0; font-size: 14px; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
            .field-group { margin-bottom: 15px; }
            .field-label { font-weight: bold; color: #555; margin-bottom: 3px; }
            .field-value { margin-bottom: 8px; padding: 4px 8px; background: #f8f9fa; border-radius: 3px; }
            .work-experience, .education { border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 10px; border-radius: 4px; }
            .experience-title { color: #2c5aa0; font-weight: bold; margin-bottom: 8px; }
            .file-list { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; }
            .file-item { margin-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            td { padding: 5px 8px; border: 1px solid #ddd; vertical-align: top; }
            .label-col { background: #f8f9fa; width: 30%; font-weight: bold; }
            .value-col { width: 70%; }
        </style>
    </head>
    <body>';
    
    // Header
    $html .= '
        <div class="header">
            <h1>' . htmlspecialchars($siteName) . '</h1>
            <h2>Employment Application</h2>
        </div>';
    
    // Meta information
    $html .= '
        <div class="meta-info">
            <strong>Application ID:</strong> ' . htmlspecialchars($applicationId) . '<br>
            <strong>Submitted:</strong> ' . $currentDate . '<br>
            <strong>Status:</strong> New Application
        </div>';
    
    // Personal Information
    $html .= '<div class="section">
        <div class="section-title">Personal Information</div>
        <table>
            <tr><td class="label-col">Full Name</td><td class="value-col">' . htmlspecialchars($data['full_name'] ?? '') . '</td></tr>
            <tr><td class="label-col">Email</td><td class="value-col">' . htmlspecialchars($data['email'] ?? '') . '</td></tr>
            <tr><td class="label-col">Phone</td><td class="value-col">' . htmlspecialchars($data['phone'] ?? '') . '</td></tr>';
    
    // Address
    if (!empty($data['address'])) {
        $address = $data['address'];
        $fullAddress = implode(', ', array_filter([
            $address['address'] ?? '',
            $address['city'] ?? '',
            $address['state_province'] ?? '',
            $address['postal_code'] ?? ''
        ]));
        $html .= '<tr><td class="label-col">Address</td><td class="value-col">' . htmlspecialchars($fullAddress) . '</td></tr>';
    }
    
    $html .= '</table></div>';
    
    // Position Information
    $position = $this->formatPositionTitle($data['position_applied'] ?? '');
    $html .= '<div class="section">
        <div class="section-title">Position Information</div>
        <table>
            <tr><td class="label-col">Position Applied For</td><td class="value-col">' . htmlspecialchars($position) . '</td></tr>';
    
    if (!empty($data['position_other'])) {
        $html .= '<tr><td class="label-col">Other Position Details</td><td class="value-col">' . htmlspecialchars($data['position_other']) . '</td></tr>';
    }
    
    $html .= '<tr><td class="label-col">Available Start Date</td><td class="value-col">' . htmlspecialchars($data['available_start_date'] ?? '') . '</td></tr>';
    
    if (!empty($data['salary_requirements'])) {
        $html .= '<tr><td class="label-col">Salary Requirements</td><td class="value-col">' . htmlspecialchars($data['salary_requirements']) . '</td></tr>';
    }
    
    $html .= '</table></div>';
    
    // Qualifications & Background
    $html .= '<div class="section">
        <div class="section-title">Qualifications & Background</div>
        <table>';
    
    // Attorney-specific questions
    $positionApplied = $data['position_applied'] ?? '';
    if ($positionApplied === 'managing_attorney' || $positionApplied === 'staff_attorney') {
        $html .= '<tr><td colspan="2" style="background: #e3f2fd; font-weight: bold; padding: 8px;">Attorney Qualifications</td></tr>';
        
        if (!empty($data['idaho_bar_licensed'])) {
            $barStatus = $this->formatYesNoOption($data['idaho_bar_licensed']);
            $html .= '<tr><td class="label-col">Licensed to practice law in Idaho?</td><td class="value-col">' . htmlspecialchars($barStatus) . '</td></tr>';
        }
        
        if (!empty($data['aba_law_school'])) {
            $abaGrad = $this->formatYesNoOption($data['aba_law_school']);
            $html .= '<tr><td class="label-col">Graduated from ABA-accredited law school?</td><td class="value-col">' . htmlspecialchars($abaGrad) . '</td></tr>';
        }
        
        if (!empty($data['bar_discipline'])) {
            $discipline = $this->formatYesNoOption($data['bar_discipline']);
            $html .= '<tr><td class="label-col">Ever subject to bar discipline?</td><td class="value-col">' . htmlspecialchars($discipline) . '</td></tr>';
        }
        
        $html .= '<tr><td colspan="2" style="height: 10px;"></td></tr>';
    }
    
    
    // Sensitive background questions
    $html .= '<tr><td colspan="2" style="background: #fff3cd; font-weight: bold; padding: 8px;">Background Screening</td></tr>';
    
    if (!empty($data['criminal_conviction'])) {
        $criminal = $this->formatYesNoOption($data['criminal_conviction']);
        $html .= '<tr><td class="label-col">Ever charged/convicted of domestic violence or violent crime?</td><td class="value-col">' . htmlspecialchars($criminal) . '</td></tr>';
    }
    
    if (!empty($data['protection_order'])) {
        $protection = $this->formatYesNoOption($data['protection_order']);
        $html .= '<tr><td class="label-col">Ever subject to protection order/restraining order?</td><td class="value-col">' . htmlspecialchars($protection) . '</td></tr>';
    }
    
    if (!empty($data['sexual_harassment'])) {
        $harassment = $this->formatYesNoOption($data['sexual_harassment']);
        $html .= '<tr><td class="label-col">Ever found to have engaged in sexual harassment?</td><td class="value-col">' . htmlspecialchars($harassment) . '</td></tr>';
    }
    
    $html .= '</table></div>';
    
    // Additional Information
    $html .= '<div class="section">
        <div class="section-title">Additional Information</div>
        <table>';
    
    if (!empty($data['referral_source'])) {
        $referralText = ucwords(str_replace('_', ' ', $data['referral_source']));
        $html .= '<tr><td class="label-col">How did you hear about us?</td><td class="value-col">' . htmlspecialchars($referralText) . '</td></tr>';
    }
    
    if (!empty($data['referral_details'])) {
        $html .= '<tr><td class="label-col">Referral Details</td><td class="value-col">' . htmlspecialchars($data['referral_details']) . '</td></tr>';
    }
    
    if (!empty($data['additional_comments'])) {
        $html .= '<tr><td class="label-col">Additional Comments</td><td class="value-col">' . nl2br(htmlspecialchars($data['additional_comments'])) . '</td></tr>';
    }
    
    $html .= '</table></div>';
    
    // File Attachments
    if (!empty($files)) {
        $html .= '<div class="section">
            <div class="section-title">Submitted Documents</div>
            <div class="file-list">';
        
        foreach ($files as $fieldName => $fieldFiles) {
            $fieldLabel = ucwords(str_replace('_', ' ', $fieldName));
            foreach ($fieldFiles as $file) {
                // $file is a File object, so use object methods
                $filename = $file->getFilename();
                $html .= '<div class="file-item">📄 <strong>' . htmlspecialchars($fieldLabel) . ':</strong> ' . htmlspecialchars($filename) . '</div>';
            }
        }
        
        $html .= '</div></div>';
    }
    
    // Footer
    $html .= '<div style="margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9px; color: #666; text-align: center;">
        Generated on ' . $currentDate . ' | ' . htmlspecialchars($siteName) . ' Employment Application System
    </div>';
    
    $html .= '</body></html>';
    
    return $html;
  }

  /**
   * Sends email notifications using Symfony Mailer.
   */
  private function sendNotifications(array $data, array $files, string $applicationId, string $pdfContent = ''): void {
    try {
      $config = \Drupal::config('system.site');
      $siteName = $config->get('name') ?: 'Idaho Legal Aid Services';
      $adminEmailAddress = 'evancurry@idaholegalaid.org';
      
      // Create admin email with PDF attachment
      $adminEmail = (new Email())
        ->from('noreply@idaholegalaid.org')
        ->to($adminEmailAddress)
        ->subject("New Employment Application - $siteName")
        ->text($this->formatAdminEmail($data, $files, $applicationId))
        ->html($this->formatAdminEmailHTML($data, $files, $applicationId));
      
      // Add PDF summary attachment if generated
      if (!empty($pdfContent)) {
        $filename = 'employment-application-summary-' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $applicationId) . '.pdf';
        $adminEmail->addPart(new DataPart($pdfContent, $filename, 'application/pdf'));
      }
      
      // Add original uploaded files as separate attachments
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          $filepath = \Drupal::service('file_system')->realpath($file->getFileUri());
          if ($filepath && file_exists($filepath)) {
            $fileContent = file_get_contents($filepath);
            $filename = $file->getFilename();
            $adminEmail->addPart(new DataPart($fileContent, $filename, $file->getMimeType()));
          }
        }
      }
      
      // Send admin email
      $this->mailer->send($adminEmail);
      
      \Drupal::logger('employment_application')->info('Admin email sent successfully');

      // Send confirmation email to applicant (no PDF needed)
      if (!empty($data['email'])) {
        $confirmEmail = (new Email())
          ->from('noreply@idaholegalaid.org')
          ->to($data['email'])
          ->subject("Application Received - $siteName")
          ->text($this->formatConfirmationEmail($data, $applicationId))
          ->html($this->formatConfirmationEmailHTML($data, $applicationId));
        
        $this->mailer->send($confirmEmail);
        
        \Drupal::logger('employment_application')->info('Confirmation email sent successfully');
      }
      
    } catch (\Exception $e) {
      \Drupal::logger('employment_application')->error('Email sending failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      // Don't fail the whole process if email fails
    }
  }

  /**
   * Formats Yes/No/Other options for display.
   */
  private function formatYesNoOption(string $value): string {
    switch (strtolower($value)) {
      case 'yes':
        return 'Yes';
      case 'no':
        return 'No';
      case 'licensed_other_state':
        return 'Licensed in Another State';
      default:
        return ucfirst(str_replace('_', ' ', $value));
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
    $message .= "Position: " . $this->formatPositionTitle($data['position_applied'] ?? 'N/A') . "\n";
    $message .= "Start Date: " . ($data['available_start_date'] ?? 'N/A') . "\n\n";
    
    if (!empty($files)) {
      $message .= "UPLOADED FILES:\n";
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          // $file is a File object, so use object methods
          $filename = $file->getFilename();
          $message .= "- " . ucfirst(str_replace('_', ' ', $fieldName)) . ": " . $filename . "\n";
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
           "Position: " . $this->formatPositionTitle($data['position_applied'] ?? 'N/A') . "\n\n" .
           "What happens next?\n" .
           "• We'll acknowledge receipt of your application within 24 hours\n" .
           "• Our team will review your qualifications\n" .
           "• If you're a good fit, we'll contact you within 1-2 weeks for next steps\n\n" .
           "Thank you for your interest in our mission.\n\n" .
           "Best regards,\n$siteName Team";
  }

  /**
   * Formats admin notification email (HTML version).
   */
  private function formatAdminEmailHTML(array $data, array $files, string $applicationId): string {
    $message = "<h2>New Employment Application Received</h2>";
    $message .= "<p><strong>Application ID:</strong> " . htmlspecialchars($applicationId) . "<br>";
    $message .= "<strong>Submitted:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
    $message .= "<h3>Applicant Information</h3>";
    $message .= "<ul>";
    $message .= "<li><strong>Name:</strong> " . htmlspecialchars($data['full_name'] ?? 'N/A') . "</li>";
    $message .= "<li><strong>Email:</strong> " . htmlspecialchars($data['email'] ?? 'N/A') . "</li>";
    $message .= "<li><strong>Phone:</strong> " . htmlspecialchars($data['phone'] ?? 'N/A') . "</li>";
    $message .= "<li><strong>Position:</strong> " . htmlspecialchars($this->formatPositionTitle($data['position_applied'] ?? 'N/A')) . "</li>";
    $message .= "<li><strong>Start Date:</strong> " . htmlspecialchars($data['available_start_date'] ?? 'N/A') . "</li>";
    $message .= "</ul>";
    
    if (!empty($files)) {
      $message .= "<h3>Uploaded Files</h3><ul>";
      foreach ($files as $fieldName => $fieldFiles) {
        foreach ($fieldFiles as $file) {
          // $file is a File object, so use object methods  
          $filename = $file->getFilename();
          $message .= "<li>" . ucfirst(str_replace('_', ' ', $fieldName)) . ": " . htmlspecialchars($filename) . "</li>";
        }
      }
      $message .= "</ul>";
    }
    
    $message .= "<p><strong>Complete application details are attached as PDF.</strong></p>";
    
    return $message;
  }

  /**
   * Formats confirmation email to applicant (HTML version).
   */
  private function formatConfirmationEmailHTML(array $data, string $applicationId): string {
    $siteName = \Drupal::config('system.site')->get('name');
    
    return "<h2>Application Received</h2>" .
           "<p>Dear " . htmlspecialchars($data['full_name'] ?? 'Applicant') . ",</p>" .
           "<p>Thank you for your interest in joining $siteName. We have successfully received your application.</p>" .
           "<p><strong>Application ID:</strong> " . htmlspecialchars($applicationId) . "<br>" .
           "<strong>Position:</strong> " . htmlspecialchars($this->formatPositionTitle($data['position_applied'] ?? 'N/A')) . "</p>" .
           "<h3>What happens next?</h3>" .
           "<ul>" .
           "<li>We'll acknowledge receipt of your application within 24 hours</li>" .
           "<li>Our team will review your qualifications</li>" .
           "<li>If you're a good fit, we'll contact you within 1-2 weeks for next steps</li>" .
           "</ul>" .
           "<p>Thank you for your interest in our mission.</p>" .
           "<p>Best regards,<br>$siteName Team</p>";
  }

  /**
   * Sanitizes input data.
   */
  private function sanitizeInput($value): string|array {
    if (is_array($value)) {
      $sanitized = [];
      foreach ($value as $key => $val) {
        if (is_array($val) || is_scalar($val)) {
          $sanitized[$key] = $this->sanitizeInput($val);
        }
      }
      return $sanitized;
    }
    
    if (is_scalar($value)) {
      return strip_tags(trim((string) $value));
    }
    
    return '';
  }

  /**
   * Returns error response.
   */
  private function errorResponse(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    $response = new JsonResponse([
      'success' => FALSE,
      'message' => $message,
    ], $status);
    
    // Prevent search indexing of error responses
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    
    return $response;
  }

  /**
   * Admin list of employment applications.
   */
  public function adminList(): array {
    $database = \Drupal::database();
    
    $query = $database->select('employment_applications', 'ea')
      ->fields('ea')
      ->orderBy('submitted', 'DESC')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(20);
    
    $applications = $query->execute()->fetchAll();
    
    $header = [
      'Application ID',
      'Submitted',
      'Applicant',
      'Position',
      'Files',
      'Actions'
    ];
    
    $rows = [];
    foreach ($applications as $app) {
      $formData = json_decode($app->form_data, TRUE);
      $fileData = json_decode($app->file_data, TRUE);
      
      $filesList = '';
      if (!empty($fileData)) {
        foreach ($fileData as $fieldName => $fieldFiles) {
          foreach ($fieldFiles as $file) {
            $downloadUrl = '/admin/employment-applications/download/' . $file['fid'];
            $filesList .= '<a href="' . $downloadUrl . '">' . $file['filename'] . '</a><br>';
          }
        }
      }
      
      $rows[] = [
        $app->application_id,
        date('Y-m-d H:i:s', $app->submitted),
        $formData['full_name'] ?? '',
        $formData['position_applied'] ?? '',
        ['data' => ['#markup' => $filesList]],
        ['data' => ['#markup' => '<a href="mailto:' . ($formData['email'] ?? '') . '">Contact</a>']],
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
    ];
  }

  /**
   * Download uploaded file.
   */
  public function downloadFile(int $fid): BinaryFileResponse {
    $file = File::load($fid);
    
    if (!$file) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('File not found.');
    }
    
    $filepath = \Drupal::service('file_system')->realpath($file->getFileUri());
    
    if (!file_exists($filepath)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Physical file not found.');
    }
    
    return new BinaryFileResponse(
      $filepath,
      200,
      [
        'Content-Type' => $file->getMimeType(),
        'Content-Disposition' => 'attachment; filename="' . $file->getFilename() . '"',
      ]
    );
  }
}