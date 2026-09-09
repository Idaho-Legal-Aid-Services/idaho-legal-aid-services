<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\LlmEvalTrafficPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the environment-gated per-IP LLM budget exemption for eval traffic.
 *
 * EnvironmentDetector is final and reads PANTHEON_ENVIRONMENT directly, so
 * the environment is driven through putenv() as EnvironmentDetectorTest does.
 * An "unknown environment" case cannot be asserted here: under PHPUnit the
 * detector's isDevOrTestEnvironment() returns TRUE whenever the TestCase
 * class is loaded, so the fail-closed branch for unresolved environments is
 * only reachable outside a test runner.
 */
#[Group('ilas_site_assistant')]
final class LlmEvalTrafficPolicyTest extends TestCase {

  /**
   * Restores environment variables after each test.
   */
  protected function tearDown(): void {
    putenv('PANTHEON_ENVIRONMENT');
    unset($_ENV['PANTHEON_ENVIRONMENT']);
    parent::tearDown();
  }

  /**
   * The eval header on dev is exempt.
   */
  public function testHeaderOnDevIsExempt(): void {
    $this->setEnvironment('dev');
    $this->assertTrue(LlmEvalTrafficPolicy::isPerIpBudgetExempt($this->evalRequest(), [], new EnvironmentDetector()));
  }

  /**
   * The eval header on test is exempt.
   */
  public function testHeaderOnTestIsExempt(): void {
    $this->setEnvironment('test');
    $this->assertTrue(LlmEvalTrafficPolicy::isPerIpBudgetExempt($this->evalRequest(), [], new EnvironmentDetector()));
  }

  /**
   * The eval header on live is never exempt.
   */
  public function testHeaderOnLiveIsNotExempt(): void {
    $this->setEnvironment('live');
    $this->assertFalse(LlmEvalTrafficPolicy::isPerIpBudgetExempt($this->evalRequest(), [], new EnvironmentDetector()));
  }

  /**
   * The payload context marker also qualifies on dev.
   */
  public function testPayloadContextOnDevIsExempt(): void {
    $this->setEnvironment('dev');
    $request = Request::create('/assistant/api/message', 'POST');
    $payload = ['context' => ['eval_run_id' => 'eval-runtime-1']];
    $this->assertTrue(LlmEvalTrafficPolicy::isPerIpBudgetExempt($request, $payload, new EnvironmentDetector()));
  }

  /**
   * Ordinary dev traffic is not exempt.
   */
  public function testPlainRequestOnDevIsNotExempt(): void {
    $this->setEnvironment('dev');
    $request = Request::create('/assistant/api/message', 'POST');
    $this->assertFalse(LlmEvalTrafficPolicy::isPerIpBudgetExempt($request, ['context' => []], new EnvironmentDetector()));
  }

  /**
   * A missing detector fails closed.
   */
  public function testMissingDetectorFailsClosed(): void {
    $this->setEnvironment('dev');
    $this->assertFalse(LlmEvalTrafficPolicy::isPerIpBudgetExempt($this->evalRequest(), [], NULL));
  }

  /**
   * Builds a request carrying the eval header.
   */
  private function evalRequest(): Request {
    return Request::create('/assistant/api/message', 'POST', server: [
      'HTTP_X_ILAS_EVAL_RUN_ID' => 'eval-runtime-1',
    ]);
  }

  /**
   * Sets the Pantheon environment for the detector.
   */
  private function setEnvironment(string $environment): void {
    putenv('PANTHEON_ENVIRONMENT=' . $environment);
    $_ENV['PANTHEON_ENVIRONMENT'] = $environment;
  }

}
