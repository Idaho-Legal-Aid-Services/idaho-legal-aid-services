<?php

/**
 * SECURITY: Early Exit for Spam & Scrapers (Added Nov 30, 2025)
 * Prevents database exhaustion from facet spam and known bot patterns.
 * Placed at the top to exit before bootstrapping Drupal.
 */
if (isset($_SERVER['REQUEST_URI']) &&
    strpos($_SERVER['REQUEST_URI'], '/admin/') === false &&
    (strpos($_SERVER['REQUEST_URI'], '/search') === 0 ||
     strpos($_SERVER['REQUEST_URI'], '/search?') === 0 ||
     preg_match('#^/[^/]+/search($|\?)#', $_SERVER['REQUEST_URI']))) {

  // 1. Block ALL Facet Parameters (The "Kill Switch")
  // Faceted search is not enabled on this site (Facets module disabled).
  // Any request with f[...] parameters is bot traffic.
  // Updated Dec 19, 2025: Changed from blocking f[5]+ to blocking ALL facet params
  // to address China-based botnet attack using 5-filter combinations.
  if (isset($_SERVER['QUERY_STRING']) && (strpos($_SERVER['QUERY_STRING'], 'f[') !== false || strpos($_SERVER['QUERY_STRING'], 'f%5B') !== false)) {
      header('HTTP/1.1 429 Too Many Requests');
      die('Error: Invalid search parameters.');
  }

}

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Include the Pantheon-specific settings file.
 *
 * n.b. The settings.pantheon.php file makes some changes
 * that affect all environments that this site
 * exists in.  Always include this file, even in
 * a local development environment, to ensure that
 * the site settings remain consistent.
 */
include __DIR__ . "/settings.pantheon.php";

/**
 * Google Privacy Sandbox Permissions Policy.
 * Fixes "fetch failed" console errors for GA4 attribution reporting.
 */
if (PHP_SAPI !== 'cli') {
  header('Permissions-Policy: attribution-reporting=(self "https://www.google-analytics.com" "https://analytics.google.com" "https://www.googletagmanager.com" "https://www.google.com" "https://www.googleadservices.com" "https://googleads.g.doubleclick.net")');
}

/**
 * Google Analytics (GA4) tracking ID for Google tag (gtag.js).
 * Only enable this on the live Pantheon environment.
 */
if (isset($_ENV['PANTHEON_ENVIRONMENT']) && $_ENV['PANTHEON_ENVIRONMENT'] === 'live') {
  $settings['google_tag_id'] = 'G-QYT2ZNY442';
}

/**
 * Skipping permissions hardening will make scaffolding
 * work better, but will also raise a warning when you
 * install Drupal.
 *
 * https://www.drupal.org/project/drupal/issues/3091285
 */
// $settings['skip_permissions_hardening'] = TRUE;


/**
 * SMTP password override via environment variable.
 *
 * The symfony_mailer_lite SMTP transport password is injected at runtime
 * from the SMTP_PASSWORD environment variable. This keeps the credential
 * out of exported config YAML and version control.
 *
 * On Pantheon, set it via:
 *   terminus secret:set <site>.<env> SMTP_PASSWORD <value> --scope=web
 * Locally (DDEV), add to .ddev/.env or export before `ddev start`.
 */
$smtp_pass = getenv('SMTP_PASSWORD');
if ($smtp_pass) {
  $config['symfony_mailer_lite.symfony_mailer_lite_transport.smtp']['configuration']['pass'] = $smtp_pass;
}

/**
 * Include DDEV settings if present.
 * Safe: this file doesn't exist on Pantheon.
 */
$ddev_settings = __DIR__ . '/settings.ddev.php';
if (is_readable($ddev_settings)) {
  require $ddev_settings;
}

/**
 * Include local settings LAST so it can override DDEV.
 */
$local_settings = __DIR__ . '/settings.local.php';
if (file_exists($local_settings)) {
  include $local_settings;
}
