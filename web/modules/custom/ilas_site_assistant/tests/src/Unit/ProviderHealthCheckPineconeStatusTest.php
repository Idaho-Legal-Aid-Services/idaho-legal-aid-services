<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Pinecone probe status vocabulary shared by two services.
 *
 * ProviderHealthCheck decided reachability by comparing the probe status to
 * 'pass', but VectorIndexHygieneService reports a passing probe as 'healthy'.
 * The two never agreed, so ilas:providers-health reported Pinecone down and
 * exited non-zero on a healthy system, and nothing ran it often enough for
 * anyone to notice. Both classes are final and service-heavy, so this checks
 * the source: the value the consumer tests for must be one the producer emits.
 */
#[Group('ilas_site_assistant')]
final class ProviderHealthCheckPineconeStatusTest extends TestCase {

  /**
   * The status ProviderHealthCheck treats as reachable is one that is emitted.
   */
  public function testConsumerComparesAgainstAnEmittedProbeStatus(): void {
    $src = dirname(__DIR__, 3) . '/src/Service/';
    $producer = (string) file_get_contents($src . 'VectorIndexHygieneService.php');
    $consumer = (string) file_get_contents($src . 'ProviderHealthCheck.php');

    $this->assertSame(1, preg_match('/\$failed === 0 && \$passed > 0 => \'([a-z]+)\'/', $producer, $emitted), 'Passing probe status found in the hygiene service.');
    $this->assertSame(1, preg_match('/\$reachable = \$probeStatus === \'([a-z]+)\'/', $consumer, $expected), 'Reachability comparison found in the health check.');

    $this->assertSame($emitted[1], $expected[1]);
  }

}
