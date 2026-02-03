<?php

use Drupal\file\Entity\File;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Resend employment application admin notifications.
 *
 * Usage:
 *   drush scr scripts/resend_employment_admin.php -- --days=10 --limit=50 --dry-run=0
 */

if (PHP_SAPI !== 'cli') {
  throw new \RuntimeException('This script must be run from CLI.');
}

$options = getopt('', ['days::', 'limit::', 'dry-run::']);
$days = isset($options['days']) ? (int) $options['days'] : 10;
$limit = isset($options['limit']) ? (int) $options['limit'] : 50;
$dryRun = isset($options['dry-run']) ? (bool) $options['dry-run'] : false;

if ($days <= 0) {
  $days = 10;
}
if ($limit <= 0) {
  $limit = 50;
}

$since = time() - ($days * 86400);

$database = \Drupal::database();
$query = $database->select('employment_applications', 'ea')
  ->fields('ea')
  ->condition('submitted', $since, '>=')
  ->orderBy('submitted', 'DESC')
  ->range(0, $limit);

$results = $query->execute()->fetchAll();

if (empty($results)) {
  print "No applications found in the last {$days} days.\n";
  return;
}

$siteConfig = \Drupal::config('system.site');
$appConfig = \Drupal::config('employment_application.settings');
$siteName = $siteConfig->get('name') ?: 'Idaho Legal Aid Services';
$adminEmailAddress = $appConfig->get('admin_email')
  ?: $siteConfig->get('mail')
  ?: 'admin@idaholegalaid.org';

$controller = \Drupal::service('employment_application.controller');
$ref = new \ReflectionClass($controller);
$formatText = $ref->getMethod('formatAdminEmail');
$formatText->setAccessible(true);
$formatHtml = $ref->getMethod('formatAdminEmailHTML');
$formatHtml->setAccessible(true);
$generatePdf = $ref->getMethod('generateApplicationPDF');
$generatePdf->setAccessible(true);

$mailer = \Drupal::service('symfony_mailer_lite.mailer');

print "Found " . count($results) . " applications in the last {$days} days.\n";
print $dryRun ? "DRY RUN: no emails will be sent.\n" : "Sending admin notifications...\n";

foreach ($results as $app) {
  $data = json_decode($app->form_data, true) ?: [];
  $fileData = json_decode($app->file_data, true) ?: [];

  // Load file entities into the same structure expected by PDF/email helpers.
  $files = [];
  foreach ($fileData as $fieldName => $fieldFiles) {
    if (!is_array($fieldFiles)) {
      continue;
    }
    foreach ($fieldFiles as $fileInfo) {
      if (!isset($fileInfo['fid'])) {
        continue;
      }
      $file = File::load((int) $fileInfo['fid']);
      if ($file) {
        $files[$fieldName][] = $file;
      }
    }
  }

  $applicationId = $app->application_id;
  $jobTitle = $data['job_title'] ?? '';
  $jobLocation = $data['job_location'] ?? '';
  $positionDisplay = $jobLocation ? "{$jobTitle} — {$jobLocation}" : $jobTitle;

  $textBody = $formatText->invoke($controller, $data, $files, $applicationId);
  $htmlBody = $formatHtml->invoke($controller, $data, $files, $applicationId);
  $pdfContent = $generatePdf->invoke($controller, $data, $files, $applicationId);

  if ($dryRun) {
    print "DRY RUN: {$applicationId} ({$positionDisplay})\n";
    continue;
  }

  $email = (new Email())
    ->from('noreply@idaholegalaid.org')
    ->to($adminEmailAddress)
    ->replyTo($data['email'] ?? 'noreply@idaholegalaid.org')
    ->subject("Resent Application: {$positionDisplay} - {$siteName}")
    ->text($textBody)
    ->html($htmlBody);

  if (!empty($pdfContent)) {
    $filename = 'employment-application-summary-' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $applicationId) . '.pdf';
    $email->addPart(new DataPart($pdfContent, $filename, 'application/pdf'));
  }

  $mailer->send($email);
  print "Sent: {$applicationId}\n";
}

print "Done.\n";
