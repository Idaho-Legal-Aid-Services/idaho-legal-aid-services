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

  // Disable ILAS Site Assistant (Aila) chatbot on production.
  // Module code stays deployed; this prevents the floating widget from loading
  // and gates the /assistant page. Survives drush config:import.
  $config['ilas_site_assistant.settings']['enable_global_widget'] = FALSE;

  // Production rate limits for the chatbot API (per IP).
  $config['ilas_site_assistant.settings']['rate_limit_per_minute'] = 15;
  $config['ilas_site_assistant.settings']['rate_limit_per_hour'] = 120;
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
 * Helper: retrieve a secret value.
 *
 * On Pantheon: uses pantheon_get_secret() (natively available on the platform).
 *   Secrets MUST be type "runtime", scope "web".
 * Locally (DDEV): falls back to getenv(), so you can set values in .ddev/.env.
 *
 * @param string $name
 *   The secret name (same key used in Pantheon Dashboard and in .ddev/.env).
 *
 * @return string|false
 *   The secret value, or FALSE if not set.
 */
function _ilas_get_secret(string $name) {
  // Pantheon runtime secrets (type: runtime, scope: web).
  if (function_exists('pantheon_get_secret')) {
    $val = pantheon_get_secret($name);
    if ($val !== FALSE && $val !== '') {
      return $val;
    }
  }
  // Local / DDEV fallback: read from environment variable.
  return getenv($name);
}

/**
 * SMTP password override.
 *
 * On Pantheon: type "runtime", scope "web", key "SMTP_PASSWORD".
 * Locally (DDEV): add SMTP_PASSWORD=<value> to .ddev/.env, then ddev restart.
 */
$smtp_pass = _ilas_get_secret('SMTP_PASSWORD');
if ($smtp_pass) {
  $config['symfony_mailer_lite.symfony_mailer_lite_transport.smtp']['configuration']['pass'] = $smtp_pass;
}

/**
 * Cloudflare Turnstile keys override.
 *
 * The Turnstile module stores its site key and secret key inside the Key
 * module's config entity "cloudflare_turnstile_keys". The config provider is
 * "config", which reads from key_provider_settings.key_value (a JSON string).
 * By overriding that JSON at runtime, `drush cim` cannot wipe the keys.
 *
 * On Pantheon: type "runtime", scope "web":
 *   TURNSTILE_SITE_KEY
 *   TURNSTILE_SECRET_KEY
 * Locally (DDEV): add to .ddev/.env, then ddev restart.
 */
$turnstile_site_key   = _ilas_get_secret('TURNSTILE_SITE_KEY');
$turnstile_secret_key = _ilas_get_secret('TURNSTILE_SECRET_KEY');
if ($turnstile_site_key || $turnstile_secret_key) {
  $config['key.key.cloudflare_turnstile_keys']['key_provider_settings']['key_value'] = json_encode([
    'site_key'   => $turnstile_site_key ?: '',
    'secret_key' => $turnstile_secret_key ?: '',
  ]);
}

/**
 * TMGMT Google Translate API key override.
 *
 * On Pantheon: type "runtime", scope "web", key "TMGMT_GOOGLE_API_KEY".
 * Locally (DDEV): add TMGMT_GOOGLE_API_KEY=<value> to .ddev/.env, then ddev restart.
 */
$tmgmt_google_key = _ilas_get_secret('TMGMT_GOOGLE_API_KEY');
if ($tmgmt_google_key) {
  $config['tmgmt.translator.google']['settings']['api_key'] = $tmgmt_google_key;
}

/**
 * ILAS Site Assistant Gemini API key override.
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_GEMINI_API_KEY".
 * Locally (DDEV): add ILAS_GEMINI_API_KEY=<value> to .ddev/.env, then ddev restart.
 */
$ilas_gemini_key = _ilas_get_secret('ILAS_GEMINI_API_KEY');
if ($ilas_gemini_key) {
  $config['ilas_site_assistant.settings']['llm.api_key'] = $ilas_gemini_key;
}

/**
 * ILAS Site Assistant Vertex AI service account JSON override.
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_VERTEX_SA_JSON".
 * Locally (DDEV): add ILAS_VERTEX_SA_JSON=<json> to .ddev/.env, then ddev restart.
 */
$ilas_vertex_sa = _ilas_get_secret('ILAS_VERTEX_SA_JSON');
if ($ilas_vertex_sa) {
  $config['ilas_site_assistant.settings']['llm.service_account_json'] = $ilas_vertex_sa;
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
