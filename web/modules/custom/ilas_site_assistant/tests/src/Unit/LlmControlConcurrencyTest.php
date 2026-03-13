<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use Drupal\Tests\ilas_site_assistant\Unit\Support\FileLogger;
use Drupal\Tests\ilas_site_assistant\Unit\Support\FileStateBackend;
use Drupal\Tests\ilas_site_assistant\Unit\Support\FlockLockBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cross-process concurrency tests for LLM control state.
 */
#[Group('ilas_site_assistant')]
class LlmControlConcurrencyTest extends TestCase {

  private string $tempDir;

  protected function setUp(): void {
    parent::setUp();

    if (!function_exists('pcntl_fork')) {
      $this->markTestSkipped('pcntl_fork is required for concurrency proof.');
    }

    $this->tempDir = sys_get_temp_dir() . '/ilas-llm-concurrency-' . bin2hex(random_bytes(8));
    mkdir($this->tempDir, 0777, TRUE);
  }

  protected function tearDown(): void {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
      $this->removeDirectory($this->tempDir);
    }
    parent::tearDown();
  }

  /**
   * Proves the limiter does not over-admit under concurrent access.
   */
  public function testLimiterBoundaryDoesNotOverAdmitUnderConcurrentAccess(): void {
    $config = ['llm.global_rate_limit.max_per_hour' => 1];

    $results = $this->runConcurrent(2, function () use ($config): array {
      $bundle = $this->buildServiceBundle($config);
      return ['allowed' => $bundle['limiter']->tryAcquireAllowance()];
    });

    $this->assertSame(1, $this->countAllowedResults($results));
    $state = $this->buildServiceBundle($config)['limiter']->getCurrentState();
    $this->assertSame(1, $state['count']);
  }

  /**
   * Proves only one half-open probe can proceed across concurrent workers.
   */
  public function testBreakerHalfOpenAllowsOnlyOneProbeAndOneReopenLog(): void {
    $config = [];
    $bundle = $this->buildServiceBundle($config);
    $bundle['state']->set(LlmCircuitBreaker::STATE_KEY, [
      'state' => 'open',
      'consecutive_failures' => 3,
      'last_failure_time' => time() - 301,
      'opened_at' => time() - 301,
    ]);

    $results = $this->runConcurrent(2, function () use ($config): array {
      $childBundle = $this->buildServiceBundle($config);
      $allowed = $childBundle['breaker']->tryAcquireAdmission();
      if ($allowed) {
        $childBundle['breaker']->recordFailure();
      }
      return ['allowed' => $allowed];
    });

    $this->assertSame(1, $this->countAllowedResults($results));
    $this->assertSame('open', $this->buildServiceBundle($config)['breaker']->getState()['state']);

    $entries = $this->buildServiceBundle($config)['logger']->readEntries();
    $halfOpenTransitions = array_filter($entries, static function (array $entry): bool {
      return $entry['level'] === 'notice'
        && str_contains((string) $entry['message'], 'transitioning from open to half_open');
    });
    $reopenEvents = array_filter($entries, static function (array $entry): bool {
      return $entry['level'] === 'warning'
        && str_contains((string) $entry['message'], 'reopened after failed half-open probe');
    });

    $this->assertCount(1, $halfOpenTransitions);
    $this->assertCount(1, $reopenEvents);
  }

  /**
   * Proves daily/monthly budgets do not over-admit at the boundary.
   */
  public function testCostBudgetBoundaryDoesNotOverAdmitUnderConcurrentAccess(): void {
    $config = [
      'cost_control.daily_call_limit' => 1,
      'cost_control.monthly_call_limit' => 1,
      'cost_control.per_ip_hourly_call_limit' => 0,
      'llm.global_rate_limit.max_per_hour' => 0,
    ];

    $results = $this->runConcurrent(2, function () use ($config): array {
      $bundle = $this->buildServiceBundle($config);
      return $bundle['policy']->beginRequest();
    });

    $this->assertSame(1, $this->countAllowedResults($results));
    $summary = $this->buildServiceBundle($config)['policy']->getSummary();
    $this->assertSame(1, $summary['daily_calls']);
    $this->assertSame(1, $summary['monthly_calls']);
  }

  /**
   * Proves the per-IP boundary does not over-admit under concurrent access.
   */
  public function testPerIpBudgetBoundaryDoesNotOverAdmitUnderConcurrentAccess(): void {
    $config = [
      'cost_control.daily_call_limit' => 0,
      'cost_control.monthly_call_limit' => 0,
      'cost_control.per_ip_hourly_call_limit' => 1,
      'cost_control.per_ip_window_seconds' => 3600,
      'llm.global_rate_limit.max_per_hour' => 0,
    ];

    $results = $this->runConcurrent(2, function () use ($config): array {
      $bundle = $this->buildServiceBundle($config);
      return $bundle['policy']->beginRequest('198.51.100.10');
    });

    $this->assertSame(1, $this->countAllowedResults($results));
    $perIp = $this->buildServiceBundle($config)['state']->get(CostControlPolicy::STATE_KEY_PER_IP);
    $this->assertIsArray($perIp);
    $this->assertCount(1, $perIp);
    $this->assertSame(1, reset($perIp)['count']);
  }

  /**
   * Proves separate client identities do not share the per-IP budget bucket.
   */
  public function testPerIpBudgetAllowsIndependentIdentitiesInSharedState(): void {
    $config = [
      'cost_control.daily_call_limit' => 0,
      'cost_control.monthly_call_limit' => 0,
      'cost_control.per_ip_hourly_call_limit' => 1,
      'cost_control.per_ip_window_seconds' => 3600,
      'llm.global_rate_limit.max_per_hour' => 0,
    ];

    $bundle = $this->buildServiceBundle($config);
    $this->assertTrue($bundle['policy']->beginRequest('198.51.100.10')['allowed']);
    $this->assertTrue($bundle['policy']->beginRequest('198.51.100.11')['allowed']);

    $perIp = $bundle['state']->get(CostControlPolicy::STATE_KEY_PER_IP);
    $this->assertIsArray($perIp);
    $this->assertCount(2, $perIp);
  }

  /**
   * Proves cache-hit and cache-miss increments are not lost concurrently.
   */
  public function testCacheStatsDoNotLoseConcurrentIncrements(): void {
    $config = [];

    $this->runConcurrent(4, function (int $index) use ($config): array {
      $bundle = $this->buildServiceBundle($config);
      if ($index % 2 === 0) {
        $bundle['policy']->recordCacheHit();
        return ['stat' => 'hit'];
      }

      $bundle['policy']->recordCacheMiss();
      return ['stat' => 'miss'];
    });

    $stats = $this->buildServiceBundle($config)['state']->get(CostControlPolicy::STATE_KEY_CACHE_STATS);
    $this->assertIsArray($stats);
    $this->assertSame(2, $stats['hits']);
    $this->assertSame(2, $stats['misses']);
  }

  /**
   * Builds a full service bundle backed by shared files.
   *
   * @return array<string, mixed>
   *   Service bundle keyed by component name.
   */
  private function buildServiceBundle(array $configOverrides = []): array {
    $state = new FileStateBackend($this->tempDir . '/state/state.json', 25000);
    $logger = new FileLogger($this->tempDir . '/logs/llm.jsonl');
    $lock = new FlockLockBackend($this->tempDir . '/locks');
    $configFactory = $this->buildConfigFactory($configOverrides);
    $coordinator = new LlmAdmissionCoordinator($state, $configFactory, $logger, $lock);
    $breaker = new LlmCircuitBreaker($state, $configFactory, $logger, $coordinator);
    $limiter = new LlmRateLimiter($state, $configFactory, $logger, $coordinator);
    $policy = new CostControlPolicy($state, $configFactory, $logger, $breaker, $limiter, $coordinator);

    return [
      'state' => $state,
      'logger' => $logger,
      'breaker' => $breaker,
      'limiter' => $limiter,
      'policy' => $policy,
    ];
  }

  /**
   * Builds a config factory stub with shared defaults.
   */
  private function buildConfigFactory(array $configOverrides = []): ConfigFactoryInterface {
    $configValues = [
      'llm.global_rate_limit.max_per_hour' => 500,
      'llm.global_rate_limit.window_seconds' => 3600,
      'llm.circuit_breaker.failure_threshold' => 3,
      'llm.circuit_breaker.failure_window_seconds' => 60,
      'llm.circuit_breaker.cooldown_seconds' => 300,
      'cost_control.daily_call_limit' => 5000,
      'cost_control.monthly_call_limit' => 100000,
      'cost_control.per_ip_hourly_call_limit' => 10,
      'cost_control.per_ip_window_seconds' => 3600,
      'cost_control.cache_stats_window_seconds' => 86400,
      'cost_control.sample_rate' => 1.0,
      'cost_control.cache_hit_rate_target' => 0.30,
      'cost_control.alert_cooldown_minutes' => 60,
    ];
    foreach ($configOverrides as $key => $value) {
      $configValues[$key] = $value;
    }

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key) => $configValues[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    return $configFactory;
  }

  /**
   * Runs the callback concurrently across child processes.
   *
   * @return array<int, array<string, mixed>>
   *   Child results ordered by worker index.
   */
  private function runConcurrent(int $workers, callable $callback): array {
    $children = [];

    for ($index = 0; $index < $workers; $index++) {
      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
      $this->assertIsArray($pair, 'Failed to allocate start barrier sockets.');

      [$parentSocket, $childSocket] = $pair;
      $resultFile = $this->tempDir . '/results/result-' . $index . '.php';
      @mkdir(dirname($resultFile), 0777, TRUE);

      $pid = pcntl_fork();
      $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

      if ($pid === 0) {
        fclose($parentSocket);
        $start = fread($childSocket, 1);
        fclose($childSocket);
        if ($start !== '1') {
          exit(2);
        }

        try {
          $result = $callback($index);
          file_put_contents($resultFile, serialize(['result' => $result]));
          exit(0);
        }
        catch (\Throwable $e) {
          file_put_contents($resultFile, serialize([
            'exception' => $e::class . ': ' . $e->getMessage(),
          ]));
          exit(1);
        }
      }

      fclose($childSocket);
      $children[] = [
        'pid' => $pid,
        'socket' => $parentSocket,
        'result_file' => $resultFile,
      ];
    }

    foreach ($children as $child) {
      fwrite($child['socket'], '1');
      fclose($child['socket']);
    }

    $results = [];
    foreach ($children as $index => $child) {
      $status = 0;
      pcntl_waitpid($child['pid'], $status);
      $this->assertTrue(pcntl_wifexited($status), "Child {$index} did not exit cleanly.");
      $this->assertSame(0, pcntl_wexitstatus($status), "Child {$index} exited with failure.");

      $payload = unserialize((string) file_get_contents($child['result_file']));
      $this->assertIsArray($payload);
      if (isset($payload['exception'])) {
        $this->fail("Child {$index} threw an exception: {$payload['exception']}");
      }

      $results[$index] = $payload['result'];
    }

    return $results;
  }

  /**
   * Counts successful admission results.
   *
   * @param array<int, array<string, mixed>> $results
   *   Child results.
   */
  private function countAllowedResults(array $results): int {
    return count(array_filter($results, static function (array $result): bool {
      return !empty($result['allowed']);
    }));
  }

  /**
   * Recursively removes a directory tree.
   */
  private function removeDirectory(string $path): void {
    $items = scandir($path);
    if (!is_array($items)) {
      return;
    }

    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }

      $itemPath = $path . '/' . $item;
      if (is_dir($itemPath)) {
        $this->removeDirectory($itemPath);
      }
      else {
        @unlink($itemPath);
      }
    }

    @rmdir($path);
  }

}
