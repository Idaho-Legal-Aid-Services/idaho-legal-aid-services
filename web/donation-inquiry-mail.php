<?php

// Simple PHP script to handle donation inquiry emails
// This bypasses Drupal complexity and uses your SMTP directly

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Bootstrap Drupal
$autoloader = require_once 'autoload.php';
$kernel = new DrupalKernel('prod', $autoloader);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$kernel->boot();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// Get POST data
$data = $_POST;

// Validate required fields
if (empty($data['subject']) || empty($data['message']) || empty($data['sender_email'])) {
    http_response_code(400);
    echo 'Missing required fields';
    exit;
}

// Use Drupal's mail manager
$mailManager = \Drupal::service('plugin.manager.mail');

$module = 'system';
$key = 'donation_inquiry';
$to = 'development@idaholegalaid.org';
$params = array(
    'subject' => $data['subject'],
    'body' => $data['message'],
);
$langcode = \Drupal::currentUser()->getPreferredLangcode();
$send = TRUE;

// Set from address and reply-to
$from = $data['sender_email'];
$headers = array(
    'From' => $from,
    'Reply-To' => $from,
    'Cc' => 'evancurry@idaholegalaid.org'
);

try {
    $result = $mailManager->mail($module, $key, $to, $langcode, $params, $from, $send);
    
    if ($result['result']) {
        echo 'Email sent successfully';
    } else {
        http_response_code(500);
        echo 'Failed to send email';
    }
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}

$kernel->terminate($request, $response);
?>