<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Controller\AssistantSessionBootstrapController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AFRP-05 wiring regression guard for assistant controllers.
 *
 * Ensures that create() resolves mandatory services directly from the
 * container and that optional services use the has/get/NULL pattern.
 * Guards against silent reconstruction fallbacks in constructors.
 */
#[Group('ilas_site_assistant')]
final class AssistantControllerDiWiringGuardTest extends TestCase {

  /**
   * Mandatory services that create() must resolve via $container->get().
   *
   * These services are defined in ilas_site_assistant.services.yml and are
   * safety/security-critical. Missing definitions must cause a loud failure.
   */
  private const MANDATORY_SERVICES = [
    'config.factory',
    'ilas_site_assistant.intent_router',
    'ilas_site_assistant.faq_index',
    'ilas_site_assistant.resource_finder',
    'ilas_site_assistant.policy_filter',
    'ilas_site_assistant.analytics_logger',
    'ilas_site_assistant.llm_enhancer',
    'ilas_site_assistant.fallback_gate',
    'flood',
    'cache.ilas_site_assistant',
    'logger.channel.ilas_site_assistant',
    'ilas_site_assistant.assistant_flow_runner',
    'ilas_site_assistant.safety_classifier',
    'ilas_site_assistant.safety_response_templates',
    'ilas_site_assistant.out_of_scope_classifier',
    'ilas_site_assistant.out_of_scope_response_templates',
    'ilas_site_assistant.request_trust_inspector',
    'csrf_token',
    'ilas_site_assistant.environment_detector',
    'ilas_site_assistant.session_bootstrap_guard',
    'ilas_site_assistant.pre_routing_decision_engine',
    'ilas_site_assistant.read_endpoint_guard',
  ];

  /**
   * Optional services that create() may resolve via has/get/NULL.
   *
   * These are enhancement/observability services whose absence degrades
   * telemetry or UI polish but does not affect safety or core behavior.
   */
  private const OPTIONAL_SERVICES = [
    'ilas_site_assistant.response_grounder',
    'ilas_site_assistant.performance_monitor',
    'ilas_site_assistant.conversation_logger',
    'ilas_site_assistant.ab_testing',
    'ilas_site_assistant.safety_violation_tracker',
    'ilas_site_assistant.langfuse_tracer',
    'ilas_site_assistant.top_intents_pack',
    'ilas_site_assistant.source_governance',
    'ilas_site_assistant.vector_index_hygiene',
    'ilas_site_assistant.retrieval_configuration',
    'ilas_site_assistant.voyage_reranker',
  ];

  /**
   * The create() source must not contain $container->has() for mandatory IDs.
   */
  public function testMandatoryServicesDoNotUseHasCheck(): void {
    $source = $this->getMethodSource(AssistantApiController::class, 'create');

    foreach (self::MANDATORY_SERVICES as $service_id) {
      $this->assertStringNotContainsString(
        "\$container->has('" . $service_id . "')",
        $source,
        sprintf('Mandatory service "%s" must use $container->get() directly, not has/get/NULL.', $service_id),
      );
    }
  }

  /**
   * The create() source must reference all mandatory service IDs.
   */
  public function testMandatoryServicesAreResolved(): void {
    $source = $this->getMethodSource(AssistantApiController::class, 'create');

    foreach (self::MANDATORY_SERVICES as $service_id) {
      $this->assertStringContainsString(
        "'" . $service_id . "'",
        $source,
        sprintf('Mandatory service "%s" must be referenced in create().', $service_id),
      );
    }
  }

  /**
   * Optional services must use the has/get/NULL pattern.
   */
  public function testOptionalServicesUseHasCheck(): void {
    $source = $this->getMethodSource(AssistantApiController::class, 'create');

    foreach (self::OPTIONAL_SERVICES as $service_id) {
      $this->assertStringContainsString(
        "\$container->has('" . $service_id . "')",
        $source,
        sprintf('Optional service "%s" must use has/get/NULL pattern.', $service_id),
      );
    }
  }

  /**
   * The constructor must not contain "?? new" fallback reconstruction.
   */
  public function testConstructorDoesNotReconstructServices(): void {
    $constructorSource = $this->getMethodSource(AssistantApiController::class, '__construct');

    $this->assertStringNotContainsString(
      '?? new ',
      $constructorSource,
      'Constructor must not use ?? new for service fallback reconstruction.',
    );
  }

  /**
   * Bootstrap controller create() must not contain resolveBootstrapGuard.
   */
  public function testBootstrapControllerDoesNotReconstructGuard(): void {
    $source = $this->getMethodSource(AssistantSessionBootstrapController::class, 'create');

    $this->assertStringNotContainsString(
      'resolveBootstrapGuard',
      $source,
      'Bootstrap controller must resolve the guard directly from the container.',
    );
  }

  /**
   * Bootstrap controller must not have a resolveBootstrapGuard method.
   */
  public function testBootstrapControllerHasNoResolveMethod(): void {
    $this->assertFalse(
      method_exists(AssistantSessionBootstrapController::class, 'resolveBootstrapGuard'),
      'resolveBootstrapGuard must be removed — the guard is resolved from the container.',
    );
  }

  /**
   * Returns the source code of a specific method.
   */
  private function getMethodSource(string $class, string $method): string {
    $reflection = new \ReflectionMethod($class, $method);
    $source = file_get_contents($reflection->getFileName());
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $start - 1, $end - $start + 1);
    return implode("\n", $lines);
  }

}
