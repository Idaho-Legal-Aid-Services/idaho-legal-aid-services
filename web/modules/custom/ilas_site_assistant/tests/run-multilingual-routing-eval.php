<?php

/**
 * @file
 */

declare(strict_types=1);

use Drupal\Tests\ilas_site_assistant\Support\MultilingualRoutingEvalRunner;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$reportPath = NULL;
foreach (array_slice(($_SERVER['argv'] ?? []), 1) as $arg) {
  if (str_starts_with($arg, '--report=')) {
    $reportPath = substr($arg, 9);
  }
}

$runner = new MultilingualRoutingEvalRunner();
$report = $runner->run();

if ($reportPath !== NULL && $reportPath !== '') {
  $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === FALSE) {
    fwrite(STDERR, "Failed to encode multilingual routing report.\n");
    exit(2);
  }
  file_put_contents($reportPath, $json . PHP_EOL);
}

fwrite(STDOUT, sprintf(
  "Multilingual routing eval: %d/%d passed (%.2f%%)\n",
  $report['passed_cases'],
  $report['total_cases'],
  $report['pass_rate']
));

foreach ($report['results'] as $result) {
  $status = $result['passed'] ? 'PASS' : 'FAIL';
  fwrite(STDOUT, sprintf(
    "[%s] %s -> intent=%s mode=%s\n",
    $status,
    $result['id'],
    $result['intent_type'] ?? 'n/a',
    $result['response_mode'] ?? 'n/a'
  ));
  if (!$result['passed']) {
    foreach ($result['failures'] as $failure) {
      fwrite(STDOUT, "  - {$failure}\n");
    }
  }
}

exit($report['failed_cases'] === 0 ? 0 : 1);
