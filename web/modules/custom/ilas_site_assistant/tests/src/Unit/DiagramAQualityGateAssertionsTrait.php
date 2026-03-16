<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

/**
 * Shared assertions for current Diagram A quality-gate anchors.
 */
trait DiagramAQualityGateAssertionsTrait {

  /**
   * Asserts the current quality-gate topology in Diagram A.
   */
  protected function assertCurrentDiagramAQualityGateAnchors(
    string $systemMap,
    bool $requireObservability = FALSE,
    bool $requireSyntheticEvalEdge = FALSE,
  ): void {
    $this->assertStringContainsString('flowchart LR', $systemMap);
    $this->assertStringContainsString('Drupal 11 / ilas_site_assistant', $systemMap);
    $this->assertStringContainsString('External Integrations', $systemMap);
    $this->assertStringContainsString('CI[GitHub Actions\nQuality Gate]', $systemMap);
    $this->assertStringContainsString('PF[Promptfoo harness]', $systemMap);
    $this->assertStringContainsString('CI -->|runs repo-owned gate policy| PF', $systemMap);

    if ($requireObservability) {
      $this->assertStringContainsString('OBS[Observability', $systemMap);
    }

    if ($requireSyntheticEvalEdge) {
      $this->assertStringContainsString('PF -->|synthetic eval calls| R', $systemMap);
    }
  }

}
