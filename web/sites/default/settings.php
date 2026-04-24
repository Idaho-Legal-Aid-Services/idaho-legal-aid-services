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
 * Helper: validates a proxy IP or CIDR entry.
 *
 * @param string $candidate
 *   The IP/CIDR candidate.
 *
 * @return bool
 *   TRUE when the candidate is a valid IP or CIDR string.
 */
function _ilas_is_valid_proxy_address(string $candidate): bool {
  if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
    return TRUE;
  }

  if (!str_contains($candidate, '/')) {
    return FALSE;
  }

  [$ip, $prefix] = explode('/', $candidate, 2);
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
    return FALSE;
  }
  if (!ctype_digit($prefix)) {
    return FALSE;
  }

  $prefix = (int) $prefix;
  $max_prefix = str_contains($ip, ':') ? 128 : 32;
  return $prefix >= 0 && $prefix <= $max_prefix;
}

/**
 * Helper: parses the trusted-proxy environment contract.
 *
 * @param string|false $raw
 *   Raw `ILAS_TRUSTED_PROXY_ADDRESSES` value.
 *
 * @return array{valid: string[], invalid: string[]}
 *   Valid and invalid proxy-address entries.
 */
function _ilas_parse_trusted_proxy_addresses(string|false $raw): array {
  if ($raw === FALSE || trim($raw) === '') {
    return [
      'valid' => [],
      'invalid' => [],
    ];
  }

  $valid = [];
  $invalid = [];
  $entries = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

  foreach ($entries as $entry) {
    if (_ilas_is_valid_proxy_address($entry)) {
      $valid[] = $entry;
    }
    else {
      $invalid[] = $entry;
    }
  }

  return [
    'valid' => array_values(array_unique($valid)),
    'invalid' => array_values(array_unique($invalid)),
  ];
}

/**
 * Helper: parses a runtime boolean toggle.
 *
 * @param string|false|null $raw
 *   Raw environment value.
 * @param bool $fallback
 *   Fallback when the value is absent or invalid.
 *
 * @return bool
 *   Parsed boolean value.
 */
function _ilas_read_boolean(string|false|null $raw, bool $fallback = FALSE): bool {
  if ($raw === FALSE || $raw === NULL) {
    return $fallback;
  }

  $normalized = mb_strtolower(trim($raw));
  if ($normalized === '') {
    return $fallback;
  }

  return match ($normalized) {
    '1', 'true', 'yes', 'on' => TRUE,
    '0', 'false', 'no', 'off' => FALSE,
    default => $fallback,
  };
}

/**
 * Helper: parses a runtime boolean toggle from a plain-text flag file.
 *
 * @param string $path
 *   Absolute or relative path to the flag file.
 * @param bool $fallback
 *   Fallback when the file is absent, unreadable, or invalid.
 *
 * @return bool
 *   Parsed boolean value.
 */
function _ilas_read_boolean_file(string $path, bool $fallback = FALSE): bool {
  $path = trim($path);
  if ($path === '' || !is_readable($path)) {
    return $fallback;
  }

  $contents = file_get_contents($path);
  if ($contents === FALSE) {
    return $fallback;
  }

  return _ilas_read_boolean($contents, $fallback);
}

/**
 * Returns the raw Pantheon environment name when available.
 */
function _ilas_raw_pantheon_environment(): string|false {
  if (defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT !== '') {
    return PANTHEON_ENVIRONMENT;
  }

  return getenv('PANTHEON_ENVIRONMENT');
}

/**
 * Returns the normalized observability environment name.
 */
function _ilas_observability_environment_name(): string {
  $pantheon_env = _ilas_raw_pantheon_environment();
  if ($pantheon_env === FALSE || trim($pantheon_env) === '') {
    return 'local';
  }

  $normalized = mb_strtolower(trim($pantheon_env));
  return match ($normalized) {
    'dev' => 'pantheon-dev',
    'test' => 'pantheon-test',
    'live' => 'pantheon-live',
    default => 'pantheon-multidev-' . trim((string) preg_replace('/[^a-z0-9-]+/', '-', $normalized), '-'),
  };
}

/**
 * Returns the Pantheon multidev name when applicable.
 */
function _ilas_observability_multidev_name(): ?string {
  $pantheon_env = _ilas_raw_pantheon_environment();
  if ($pantheon_env === FALSE) {
    return NULL;
  }

  $normalized = mb_strtolower(trim($pantheon_env));
  if ($normalized === '' || in_array($normalized, ['dev', 'test', 'live'], TRUE)) {
    return NULL;
  }

  return $normalized;
}

/**
 * Returns the primary release identifier for observability.
 */
function _ilas_observability_release(): ?string {
  $pantheon_deploy = getenv('PANTHEON_DEPLOYMENT_IDENTIFIER');
  if ($pantheon_deploy !== FALSE && $pantheon_deploy !== '') {
    return $pantheon_deploy;
  }

  foreach (['ILAS_OBSERVABILITY_RELEASE', 'GITHUB_SHA', 'SOURCE_VERSION', 'GIT_COMMIT'] as $candidate) {
    $value = getenv($candidate);
    if ($value !== FALSE && $value !== '') {
      return $value;
    }
  }

  return NULL;
}

/**
 * Returns a git SHA, when one is explicitly available.
 */
function _ilas_observability_git_sha(): ?string {
  foreach (['GITHUB_SHA', 'SOURCE_VERSION', 'GIT_COMMIT'] as $candidate) {
    $value = getenv($candidate);
    if ($value !== FALSE && trim($value) !== '') {
      return mb_substr(trim($value), 0, 40);
    }
  }

  return NULL;
}

/**
 * Returns the normalized Sentry sample rate for the current environment.
 */
function _ilas_observability_sentry_sample_rate(string $kind): float {
  $environment = _ilas_observability_environment_name();

  return match ($kind) {
    'php_traces' => match ($environment) {
      'local' => 1.0,
      'pantheon-dev' => 0.5,
      'pantheon-test' => 0.25,
      'pantheon-live' => 0.10,
      default => 0.25,
    },
    'browser_traces' => match ($environment) {
      'local' => 1.0,
      'pantheon-dev' => 0.25,
      'pantheon-test' => 0.10,
      'pantheon-live' => 0.02,
      default => 0.05,
    },
    'replay_session' => match ($environment) {
      'local' => 0.0,
      'pantheon-dev', 'pantheon-test' => 0.05,
      'pantheon-live' => 0.01,
      default => 0.02,
    },
    'replay_error' => match ($environment) {
      'pantheon-live' => 0.25,
      'local' => 0.0,
      default => 1.0,
    },
    default => 0.0,
  };
}

/**
 * Returns the canonical site name tag for observability.
 */
function _ilas_observability_site_name(): string {
  foreach (['PANTHEON_SITE_NAME', 'DDEV_SITENAME'] as $candidate) {
    $value = getenv($candidate);
    if ($value !== FALSE && trim($value) !== '') {
      return trim($value);
    }
  }

  return 'local';
}

/**
 * Returns the canonical site ID tag for observability.
 */
function _ilas_observability_site_id(): ?string {
  $value = getenv('PANTHEON_SITE_ID');
  return ($value !== FALSE && trim($value) !== '') ? trim($value) : NULL;
}

/**
 * Returns shared observability settings for runtime consumers.
 */
function _ilas_observability_settings(): array {
  return [
    'environment' => _ilas_observability_environment_name(),
    'pantheon_env' => _ilas_raw_pantheon_environment() ?: '',
    'multidev_name' => _ilas_observability_multidev_name() ?? '',
    'release' => _ilas_observability_release() ?? '',
    'git_sha' => _ilas_observability_git_sha() ?? '',
    'site_name' => _ilas_observability_site_name(),
    'site_id' => _ilas_observability_site_id() ?? '',
    'sentry' => [
      'browser' => [
        'replay_session_sample_rate' => _ilas_observability_sentry_sample_rate('replay_session'),
        'replay_on_error_sample_rate' => _ilas_observability_sentry_sample_rate('replay_error'),
      ],
    ],
  ];
}

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
 * Reverse-proxy trust contract for request identity and flood controls.
 *
 * This stays fail-closed by default: forwarded headers are only trusted when
 * operators explicitly provide a proxy allowlist through the
 * `ILAS_TRUSTED_PROXY_ADDRESSES` runtime environment variable.
 */
$ilas_trusted_proxy_contract = _ilas_parse_trusted_proxy_addresses(getenv('ILAS_TRUSTED_PROXY_ADDRESSES'));
$settings['ilas_trusted_proxy_addresses'] = $ilas_trusted_proxy_contract['valid'];
$settings['ilas_trusted_proxy_addresses_invalid'] = $ilas_trusted_proxy_contract['invalid'];
if ($ilas_trusted_proxy_contract['valid'] !== []) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = $ilas_trusted_proxy_contract['valid'];
  $settings['reverse_proxy_trusted_headers'] =
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO |
    \Symfony\Component\HttpFoundation\Request::HEADER_FORWARDED;
}

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

  // Hard live guard: never allow assistant response debug metadata on live.
  $settings['ilas_site_assistant_debug_metadata_force_disable'] = TRUE;
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
 * ILAS Site Assistant Cohere API key override.
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_COHERE_API_KEY".
 * Locally (DDEV): add ILAS_COHERE_API_KEY=<value> to .ddev/.env, then
 * ddev restart.
 */
$ilas_cohere_key = _ilas_get_secret('ILAS_COHERE_API_KEY');
if ($ilas_cohere_key) {
  $settings['ilas_cohere_api_key'] = $ilas_cohere_key;
}

/**
 * Residual Gemini API key override for Search API / vector integrations that
 * still resolve the exported runtime key entity.
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_GEMINI_API_KEY".
 * Locally (DDEV): add ILAS_GEMINI_API_KEY=<value> to .ddev/.env, then ddev restart.
 */
$ilas_gemini_key = _ilas_get_secret('ILAS_GEMINI_API_KEY');
if ($ilas_gemini_key) {
  $settings['ilas_gemini_api_key'] = $ilas_gemini_key;
}

/**
 * ILAS Site Assistant LegalServer intake URL override.
 *
 * On Pantheon: type "runtime", scope "web", key
 * "ILAS_LEGALSERVER_ONLINE_APPLICATION_URL".
 * Locally (DDEV): add ILAS_LEGALSERVER_ONLINE_APPLICATION_URL=<value> to
 * .ddev/.env, then ddev restart.
 */
$ilas_legalserver_online_application_url = _ilas_get_secret('ILAS_LEGALSERVER_ONLINE_APPLICATION_URL');
if ($ilas_legalserver_online_application_url) {
  $settings['ilas_site_assistant_legalserver_online_application_url'] = $ilas_legalserver_online_application_url;
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
  $config['ilas_site_assistant.settings']['langfuse']['enabled'] = TRUE;
  $config['ilas_site_assistant.settings']['langfuse']['public_key'] = $langfuse_pk;
  $config['ilas_site_assistant.settings']['langfuse']['secret_key'] = $langfuse_sk;
}
$config['ilas_site_assistant.settings']['langfuse']['environment'] = _ilas_observability_environment_name();

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
 * ILAS Site Assistant vector-search rollout toggle.
 *
 * Runtime-only toggle. Sync config remains disabled-by-default so enablement
 * can be staged per environment without changing exported config. Pantheon
 * environments use the runtime secret; dev/test may also use a private flag
 * file when secrets do not propagate to the PHP runtime.
 */
$ilas_vector_search_raw = _ilas_get_secret('ILAS_VECTOR_SEARCH_ENABLED');
$ilas_vector_search_enabled = _ilas_read_boolean($ilas_vector_search_raw);
$ilas_vector_search_override_channel = NULL;
$ilas_vector_search_environment = _ilas_raw_pantheon_environment();
$ilas_vector_search_environment = $ilas_vector_search_environment !== FALSE
  ? mb_strtolower(trim($ilas_vector_search_environment))
  : 'local';

/**
 * ILAS Site Assistant request-time LLM rollout toggle.
 *
 * Runtime-only toggle. Exported config remains conservative. Hosted
 * environments opt in by providing ILAS_LLM_ENABLED together with the Cohere
 * runtime secret.
 */
$ilas_llm_enabled_raw = _ilas_get_secret('ILAS_LLM_ENABLED');
if (
  _ilas_read_boolean($ilas_llm_enabled_raw)
  && in_array($ilas_vector_search_environment, ['local', 'dev', 'test', 'live'], TRUE)
) {
  $config['ilas_site_assistant.settings']['llm']['enabled'] = TRUE;
  $settings['ilas_llm_override_channel'] = 'settings.php runtime toggle ILAS_LLM_ENABLED -> getenv/pantheon_get_secret';
}

if (
  !$ilas_vector_search_enabled &&
  in_array($ilas_vector_search_environment, ['dev', 'test'], TRUE) &&
  !empty($settings['file_private_path']) &&
  is_string($settings['file_private_path'])
) {
  $ilas_vector_search_flag_path = rtrim($settings['file_private_path'], '/')
    . '/ilas-vector-search-enabled.txt';
  if (_ilas_read_boolean_file($ilas_vector_search_flag_path)) {
    $ilas_vector_search_enabled = TRUE;
    $ilas_vector_search_override_channel = 'settings.php runtime toggle -> private flag file';
  }
}

if ($ilas_vector_search_enabled && $ilas_vector_search_override_channel === NULL) {
  $ilas_vector_search_override_channel = 'settings.php runtime toggle -> getenv/pantheon_get_secret';
}

if (
  $ilas_vector_search_enabled
  && in_array($ilas_vector_search_environment, ['local', 'dev', 'test', 'live'], TRUE)
) {
  $config['ilas_site_assistant.settings']['vector_search']['enabled'] = TRUE;
  $settings['ilas_vector_search_override_channel'] = $ilas_vector_search_override_channel;
}

/**
 * Voyage AI reranking API key.
 *
 * On Pantheon: type "runtime", scope "web", key "ILAS_VOYAGE_API_KEY".
 * Locally (DDEV): add ILAS_VOYAGE_API_KEY=<value> to .ddev/.env, then ddev restart.
 */
$voyage_key = _ilas_get_secret('ILAS_VOYAGE_API_KEY');
if ($voyage_key) {
  $settings['ilas_voyage_api_key'] = $voyage_key;
}

/**
 * Voyage AI reranking rollout toggle.
 *
 * Runtime-only toggle. Disabled by default in config and enabled only when
 * ILAS_VOYAGE_ENABLED resolves truthy for an approved environment.
 */
$voyage_enabled_raw = _ilas_get_secret('ILAS_VOYAGE_ENABLED');
if (
  _ilas_read_boolean($voyage_enabled_raw)
  && in_array($ilas_vector_search_environment, ['local', 'dev', 'test', 'live'], TRUE)
) {
  $config['ilas_site_assistant.settings']['voyage']['enabled'] = TRUE;
}

/**
 * Sentry error tracking via drupal/raven.
 *
 * On Pantheon: type "runtime", scope "web", key "SENTRY_DSN".
 * Locally (DDEV): add SENTRY_DSN=<value> to .ddev/.env, then ddev restart.
 */
$observability_environment = _ilas_observability_environment_name();
$observability_release = _ilas_observability_release();
$observability_git_sha = _ilas_observability_git_sha();
$observability_site_name = _ilas_observability_site_name();
$observability_site_id = _ilas_observability_site_id();
$observability_pantheon_env = _ilas_raw_pantheon_environment() ?: '';
$observability_multidev = _ilas_observability_multidev_name() ?? '';
$public_site_url = getenv('PUBLIC_SITE_URL') ?: '';
$sentry_browser_dsn = _ilas_get_secret('SENTRY_BROWSER_DSN');
$sentry_dsn = _ilas_get_secret('SENTRY_DSN');
$sentry_public_dsn = $sentry_browser_dsn ?: $sentry_dsn;
$sentry_browser_enabled = $sentry_public_dsn !== FALSE && $sentry_public_dsn !== '';
$trace_targets_frontend = [
  '^/assistant(?:/|$)',
  '^/assistant/api(?:/|$)',
];
$trace_targets_backend = [];
if ($public_site_url !== '') {
  $site_host = parse_url($public_site_url, PHP_URL_HOST);
  if (is_string($site_host) && $site_host !== '') {
    $escaped_host = preg_quote($site_host, '/');
    $trace_targets_frontend[] = '^https?://' . $escaped_host . '(?:/|$)';
    $trace_targets_backend[] = '^https?://' . $escaped_host . '(?:/|$)';
  }
}
if ($sentry_dsn) {
  $config['raven.settings']['client_key'] = $sentry_dsn;
  $config['raven.settings']['public_dsn'] = $sentry_public_dsn;
  $config['raven.settings']['environment'] = $observability_environment;
  $config['raven.settings']['release'] = $observability_release;
  $config['raven.settings']['request_tracing'] = TRUE;
  $config['raven.settings']['traces_sample_rate'] = _ilas_observability_sentry_sample_rate('php_traces');
  $config['raven.settings']['browser_traces_sample_rate'] = $sentry_browser_enabled ? _ilas_observability_sentry_sample_rate('browser_traces') : NULL;
  $config['raven.settings']['javascript_error_handler'] = $sentry_browser_enabled;
  $config['raven.settings']['auto_session_tracking'] = $sentry_browser_enabled;
  $config['raven.settings']['send_client_reports'] = $sentry_browser_enabled;
  $config['raven.settings']['show_report_dialog'] = $observability_environment !== 'pantheon-live';
  $config['raven.settings']['drush_error_handler'] = TRUE;
  $config['raven.settings']['drush_tracing'] = TRUE;
  $config['raven.settings']['cli_enable_logs'] = TRUE;
  $config['raven.settings']['trace_propagation_targets_frontend'] = array_values(array_unique($trace_targets_frontend));
  $config['raven.settings']['trace_propagation_targets_backend'] = array_values(array_unique($trace_targets_backend));
  $config['raven.settings']['log_levels'] = [
    'emergency' => TRUE,
    'alert' => TRUE,
    'critical' => TRUE,
    'error' => TRUE,
    'warning' => ($observability_environment !== 'local'),
    'notice' => FALSE,
    'info' => FALSE,
    'debug' => FALSE,
  ];
  $config['raven.settings']['logs_log_levels'] = $config['raven.settings']['log_levels'];
  // Route browser CSP violation reports directly to Sentry's native CSP
  // endpoint so violated directives and blocked URIs are properly structured
  // instead of lost in Drupal's @placeholder parameterization (PHP-12).
  $config['raven.settings']['seckit_set_report_uri'] = TRUE;
}

// Only monitor cron on production-like environments. Pantheon dev cron is
// traffic-dependent and runs sporadically — monitoring it generates constant
// missed check-in noise with no operational signal.
$sentry_cron_monitor_id = _ilas_get_secret('SENTRY_CRON_MONITOR_ID');
if ($sentry_cron_monitor_id && in_array($observability_environment ?? '', ['pantheon-live', 'pantheon-test'], TRUE)) {
  $config['raven.settings']['cron_monitor_id'] = $sentry_cron_monitor_id;
}

$settings['ilas_observability'] = _ilas_observability_settings();
$settings['ilas_observability']['pantheon_site_name'] = $observability_site_name;
$settings['ilas_observability']['pantheon_site_id'] = $observability_site_id ?? '';
$settings['ilas_observability']['pantheon_environment'] = $observability_pantheon_env;
$settings['ilas_observability']['multidev_name'] = $observability_multidev;
$settings['ilas_observability']['release'] = $observability_release ?? '';
$settings['ilas_observability']['git_sha'] = $observability_git_sha ?? '';
$settings['ilas_observability']['public_site_url'] = $public_site_url;

/**
 * Assistant diagnostics token for private machine-auth health/metrics access.
 *
 * On Pantheon: type "runtime", scope "web", key
 * "ILAS_ASSISTANT_DIAGNOSTICS_TOKEN".
 * Locally (DDEV): add ILAS_ASSISTANT_DIAGNOSTICS_TOKEN=<value> to .ddev/.env,
 * then ddev restart.
 */
$ilas_assistant_diagnostics_token = _ilas_get_secret('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN');
if ($ilas_assistant_diagnostics_token) {
  $settings['ilas_assistant_diagnostics_token'] = $ilas_assistant_diagnostics_token;
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
