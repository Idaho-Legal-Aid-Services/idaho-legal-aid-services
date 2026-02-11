<?php

/**
 * @file
 * Drush-runnable test for Full HTML text format security (Finding H-1).
 *
 * Usage:
 *   ddev drush php:script modules/custom/ilas_seo/tests/scripts/test-full-html-filter.php
 *
 * Tests that the full_html format strips <script> tags, restricts <iframe>
 * attributes, and preserves legitimate allowed tags.
 */

$pass = 0;
$fail = 0;
$output = [];

echo "=== Full HTML Text Format Security Tests ===\n\n";

// Verify the format exists.
$format = \Drupal\filter\Entity\FilterFormat::load('full_html');
if (!$format) {
  echo "FATAL: full_html text format not found. Run 'drush cim' first.\n";
  // Use a Drush-safe non-zero exit.
  throw new \RuntimeException('full_html text format not found');
}

echo "Testing format: full_html (status: " . ($format->status() ? 'enabled' : 'disabled') . ")\n\n";

// --- Test 1: <script> tag stripped ---
$input = '<p>Safe</p><script>alert("XSS")</script><p>More</p>';
$result = (string) check_markup($input, 'full_html');

if (str_contains($result, '<p>Safe</p>') && !str_contains($result, '<script')) {
  $pass++;
  $output[] = '  PASS: script tag stripped';
}
else {
  $fail++;
  $output[] = "  FAIL: script tag stripped — Got: $result";
}

// --- Test 2: <script> with attributes stripped ---
$input = '<script type="text/javascript" src="https://evil.com/xss.js"></script>';
$result = (string) check_markup($input, 'full_html');

if (!str_contains($result, '<script') && !str_contains($result, 'evil.com')) {
  $pass++;
  $output[] = '  PASS: script with attributes stripped';
}
else {
  $fail++;
  $output[] = "  FAIL: script with attributes stripped — Got: $result";
}

// --- Test 3: <iframe srcdoc> stripped ---
$input = '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;" width="100"></iframe>';
$result = (string) check_markup($input, 'full_html');

if (!str_contains($result, 'srcdoc') && str_contains($result, '<iframe')) {
  $pass++;
  $output[] = '  PASS: iframe srcdoc attribute stripped';
}
else {
  $fail++;
  $output[] = "  FAIL: iframe srcdoc attribute stripped — Got: $result";
}

// --- Test 4: <iframe> event handlers stripped ---
$input = '<iframe src="https://example.com" onload="alert(1)" onerror="alert(2)"></iframe>';
$result = (string) check_markup($input, 'full_html');

if (!str_contains($result, 'onload') && !str_contains($result, 'onerror')) {
  $pass++;
  $output[] = '  PASS: iframe event handlers stripped';
}
else {
  $fail++;
  $output[] = "  FAIL: iframe event handlers stripped — Got: $result";
}

// --- Test 5: <iframe> allowed attributes preserved ---
$input = '<iframe src="https://www.youtube.com/embed/test" width="560" height="315" title="Video" allowfullscreen loading="lazy"></iframe>';
$result = (string) check_markup($input, 'full_html');

if (str_contains($result, 'src="https://www.youtube.com/embed/test"')
    && str_contains($result, 'width="560"')
    && str_contains($result, 'height="315"')
    && str_contains($result, 'title="Video"')
    && str_contains($result, 'allowfullscreen')) {
  $pass++;
  $output[] = '  PASS: iframe allowed attributes preserved';
}
else {
  $fail++;
  $output[] = "  FAIL: iframe allowed attributes preserved — Got: $result";
}

// --- Test 6: <iframe> embed-reality attributes preserved ---
$input = '<iframe src="https://donorbox.org/embed" allow="payment" referrerpolicy="no-referrer" sandbox="allow-scripts" scrolling="no" class="donorbox" id="embed-1"></iframe>';
$result = (string) check_markup($input, 'full_html');

if (str_contains($result, 'allow="payment"')
    && str_contains($result, 'referrerpolicy="no-referrer"')
    && str_contains($result, 'sandbox="allow-scripts"')
    && str_contains($result, 'scrolling="no"')
    && str_contains($result, 'class="donorbox"')
    && str_contains($result, 'id="embed-1"')) {
  $pass++;
  $output[] = '  PASS: iframe embed-reality attributes preserved';
}
else {
  $fail++;
  $output[] = "  FAIL: iframe embed-reality attributes preserved — Got: $result";
}

// --- Test 7: <svg> stripped ---
$input = '<svg onload="alert(1)"><circle r="50"/></svg>';
$result = (string) check_markup($input, 'full_html');

if (!str_contains($result, '<svg') && !str_contains($result, 'onload')) {
  $pass++;
  $output[] = '  PASS: svg tag stripped';
}
else {
  $fail++;
  $output[] = "  FAIL: svg tag stripped — Got: $result";
}

// --- Test 8: Allowed tags survive ---
$input = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em><a href="https://example.com" title="Link">Link text</a>';
$result = (string) check_markup($input, 'full_html');

if (str_contains($result, '<p>Paragraph</p>')
    && str_contains($result, '<strong>Bold</strong>')
    && str_contains($result, '<em>Italic</em>')
    && str_contains($result, 'href="https://example.com"')) {
  $pass++;
  $output[] = '  PASS: allowed tags survive (p, strong, em, a)';
}
else {
  $fail++;
  $output[] = "  FAIL: allowed tags survive — Got: $result";
}

// --- Output ---
echo implode("\n", $output) . "\n\n";
echo "Results: $pass passed, $fail failed out of " . ($pass + $fail) . " tests.\n";

if ($fail > 0) {
  echo "\nSECURITY TEST FAILED.\n";
  throw new \RuntimeException("$fail security test(s) failed");
}
else {
  echo "\nAll security assertions passed.\n";
}
