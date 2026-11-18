<?php

/**
 * @file
 * Quicksilver: ask GitHub to smoke-test this site after code changes.
 *
 * Runs after sync_code (code reaching dev or a Multidev by any route: git
 * push, dashboard commit, upstream update) and after deploy (test, live).
 *
 * Deploy-time checks in scripts/deploy/pantheon-deploy.sh only run when the
 * person deploying uses that script. Code also reaches Pantheon from the
 * dashboard and from direct pushes by other parties. This hook fires for all
 * of them and starts .github/workflows/site-smoke.yml, which requests the
 * public pages of every environment and opens an issue if any are broken.
 *
 * Needs a GitHub token that can send repository_dispatch events, stored as a
 * Pantheon runtime secret readable by the web scope:
 *
 *   terminus secret:site:set idaho-legal-aid-services \
 *     GITHUB_SMOKE_DISPATCH_TOKEN <token> --type=runtime --scope=web
 *
 * Without the secret the hook does nothing and says so. It never fails the
 * Pantheon workflow: the daily scheduled smoke is the backstop.
 *
 * Web requests cannot reach this file: /private/ is in protected_web_paths.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && empty($_POST['wf_type'])) {
  // Not a Quicksilver invocation.
  return;
}

const ILAS_SMOKE_REPOSITORY = 'Idaho-Legal-Aid-Services/idaho-legal-aid-services';

$token = function_exists('pantheon_get_secret') ? pantheon_get_secret('GITHUB_SMOKE_DISPATCH_TOKEN') : '';
if (!is_string($token) || trim($token) === '') {
  echo "GITHUB_SMOKE_DISPATCH_TOKEN is not set; skipping the smoke dispatch.\n";
  return;
}

// Who triggered the workflow is recorded by name only. Pantheon-initiated
// workflows report "Pantheon".
$payload = [
  'event_type' => 'pantheon-deploy',
  'client_payload' => [
    'environment' => substr((string) ($_POST['environment'] ?? ($_ENV['PANTHEON_ENVIRONMENT'] ?? 'unknown')), 0, 64),
    'workflow' => substr((string) ($_POST['wf_type'] ?? 'unknown'), 0, 64),
    'user' => substr((string) ($_POST['user_fullname'] ?? 'unknown'), 0, 128),
  ],
];

$curl = curl_init('https://api.github.com/repos/' . ILAS_SMOKE_REPOSITORY . '/dispatches');
curl_setopt_array($curl, [
  CURLOPT_POST => TRUE,
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_RETURNTRANSFER => TRUE,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_HTTPHEADER => [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . trim($token),
    'Content-Type: application/json',
    'User-Agent: ilas-pantheon-quicksilver',
    'X-GitHub-Api-Version: 2022-11-28',
  ],
]);
curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);

// GitHub answers 204 with no body when the event is accepted.
if ($status === 204) {
  echo "Site smoke dispatched for {$payload['client_payload']['workflow']} on {$payload['client_payload']['environment']}.\n";
}
else {
  echo "Site smoke dispatch was not accepted (HTTP {$status}" . ($error !== '' ? ", {$error}" : '') . "). The daily scheduled smoke still runs.\n";
}
