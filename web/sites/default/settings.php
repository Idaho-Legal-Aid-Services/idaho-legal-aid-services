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
 * SECURITY: Block direct access to core utility scripts (Added Feb 11, 2026).
 *
 * Prevents information disclosure from install.php / rebuild.php stack traces
 * and settings.php 200-OK responses. Must run before settings.pantheon.php.
 * Skips CLI so drush/terminus remain unaffected.
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_NAME'])) {
  $blocked_scripts = [
    '/core/install.php',
    '/core/rebuild.php',
    '/sites/default/settings.php',
  ];
  foreach ($blocked_scripts as $script) {
    if (strpos($_SERVER['SCRIPT_NAME'], $script) === 0) {
      header('HTTP/1.1 403 Forbidden');
      die('Access denied.');
    }
  }
}

/**
 * SECURITY: Block core documentation files that disclose Drupal version (L-2).
 *
 * These text files are served as static assets by nginx/Apache before Drupal
 * bootstraps, so they must be blocked here in settings.php (early enough for
 * Pantheon's infrastructure). REQUEST_URI is used because SCRIPT_NAME always
 * points to index.php for routed requests.
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI'])) {
  $request_path = strtok($_SERVER['REQUEST_URI'], '?');
  if (preg_match('#^/core/(CHANGELOG|COPYRIGHT|INSTALL|LICENSE|MAINTAINERS|UPDATE)\.txt$#i', $request_path)) {
    header('HTTP/1.1 404 Not Found');
    die('Not found.');
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
 * Permissions-Policy header.
 *
 * Restricts browser features not needed by this site. Uses the modern
 * Permissions-Policy format (not the deprecated Feature-Policy).
 * SecKit's feature_policy setting is intentionally left disabled because
 * it sends the deprecated Feature-Policy header name.
 *
 * See: Finding M-13 in security audit (Feb 2026).
 */
if (PHP_SAPI !== 'cli') {
  header('Permissions-Policy: attribution-reporting=(self "https://www.google-analytics.com" "https://analytics.google.com" "https://www.googletagmanager.com" "https://www.google.com" "https://www.googleadservices.com" "https://googleads.g.doubleclick.net"), camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=(), accelerometer=(), gyroscope=(), magnetometer=()');
}

/**
 * Google Analytics (GA4) tracking ID for Google tag (gtag.js).
 * Only enable this on the live Pantheon environment.
 */
if (isset($_ENV['PANTHEON_ENVIRONMENT']) && $_ENV['PANTHEON_ENVIRONMENT'] === 'live') {
  $settings['google_tag_id'] = 'G-QYT2ZNY442';

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
 * Langfuse observability API keys.
 *
 * On Pantheon: type "runtime", scope "web", keys "LANGFUSE_PUBLIC_KEY" and "LANGFUSE_SECRET_KEY".
 * Locally (DDEV): add to .ddev/.env, then ddev restart.
 */
$langfuse_pk = _ilas_get_secret('LANGFUSE_PUBLIC_KEY');
$langfuse_sk = _ilas_get_secret('LANGFUSE_SECRET_KEY');
if ($langfuse_pk && $langfuse_sk) {
  $config['ilas_site_assistant.settings']['langfuse']['public_key'] = $langfuse_pk;
  $config['ilas_site_assistant.settings']['langfuse']['secret_key'] = $langfuse_sk;
}
// Set environment label from Pantheon environment.
if (defined('PANTHEON_ENVIRONMENT')) {
  $config['ilas_site_assistant.settings']['langfuse']['environment'] = PANTHEON_ENVIRONMENT;
}

/**
 * Gemini API key for Drupal AI module (Google AI Studio).
 * Key entity "gemini_api_key" (config provider, empty default).
 * Reuses the same secret as ilas_site_assistant above.
 */
$gemini_for_ai = _ilas_get_secret('ILAS_GEMINI_API_KEY');
if ($gemini_for_ai) {
  $config['key.key.gemini_api_key']['key_provider_settings']['key_value'] = $gemini_for_ai;
}

/**
 * Vertex AI credentials for Drupal AI module (fallback provider).
 * Key entity "vertex_sa_credentials" (config provider, empty default).
 * Reuses the same SA JSON secret as ilas_site_assistant above.
 */
$vertex_sa_for_ai = _ilas_get_secret('ILAS_VERTEX_SA_JSON');
if ($vertex_sa_for_ai) {
  $config['key.key.vertex_sa_credentials']['key_provider_settings']['key_value'] = $vertex_sa_for_ai;
}

/**
 * Pinecone API key for AI Search vector database.
 * Key entity "pinecone_api_key" (config provider, empty default).
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_PINECONE_API_KEY".
 * Locally (DDEV): add ILAS_PINECONE_API_KEY=<value> to .ddev/.env, then ddev restart.
 */
$pinecone_key = _ilas_get_secret('ILAS_PINECONE_API_KEY');
if ($pinecone_key) {
  $config['key.key.pinecone_api_key']['key_provider_settings']['key_value'] = $pinecone_key;
}

/**
 * Sentry error tracking via drupal/raven.
 *
 * On Pantheon: type "runtime", scope "web", key "SENTRY_DSN".
 * Locally (DDEV): add SENTRY_DSN=<value> to .ddev/.env, then ddev restart.
 */
$sentry_dsn = _ilas_get_secret('SENTRY_DSN');
if ($sentry_dsn) {
  $config['raven.settings']['client_key'] = $sentry_dsn;
  $config['raven.settings']['environment'] = getenv('PANTHEON_ENVIRONMENT') ?: 'local';
  $config['raven.settings']['release'] = getenv('PANTHEON_DEPLOYMENT_IDENTIFIER') ?: NULL;
  $config['raven.settings']['log_levels'] = [
    'emergency' => TRUE,
    'alert' => TRUE,
    'critical' => TRUE,
    'error' => TRUE,
    'warning' => FALSE,
    'notice' => FALSE,
    'info' => FALSE,
    'debug' => FALSE,
  ];
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
