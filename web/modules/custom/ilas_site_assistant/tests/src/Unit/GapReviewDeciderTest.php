<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\GapReviewDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests human-review gap gating decisions.
 */
#[Group('ilas_site_assistant')]
final class GapReviewDeciderTest extends TestCase {

  /**
   * Promptfoo traffic must never create a human review item.
   */
  public function testShouldSkipPromptfooEvalTraffic(): void {
    $request = Request::create('/assistant/api/message', 'POST', server: [
      'HTTP_X_ILAS_EVAL_RUN_ID' => 'eval-runtime-1',
    ]);

    $should_record = GapReviewDecider::shouldRecordGapItem(
      ['type' => 'faq', 'results' => []],
      ['type' => 'faq', 'source' => 'router'],
      [],
      $request,
      ['context' => []],
    );

    $this->assertFalse($should_record);
  }

  /**
   * Only a true no-match fallback should create a review item.
   */
  #[DataProvider('nonReviewableResponseProvider')]
  public function testShouldSkipNonReviewableResponses(array $response, array $intent): void {
    $should_record = GapReviewDecider::shouldRecordGapItem(
      $response,
      $intent,
      [],
      Request::create('/assistant/api/message', 'POST'),
      [],
    );

    $this->assertFalse($should_record);
  }

  /**
   * Only the canonical no-match fallback stays reviewable.
   */
  public function testShouldRecordTrueNoMatchFallback(): void {
    $should_record = GapReviewDecider::shouldRecordGapItem(
      [
        'type' => 'fallback',
        'reason_code' => 'no_match_fallback',
      ],
      [
        'type' => 'unknown',
        'source' => 'router',
      ],
      [],
      Request::create('/assistant/api/message', 'POST'),
      [],
    );

    $this->assertTrue($should_record);
  }

  /**
   * Structured selection provenance is preserved without fabricating a topic.
   */
  public function testBuildGovernanceContextPreservesSelectionProvenance(): void {
    $context = GapReviewDecider::buildGovernanceContext(
      ['type' => 'forms_inventory', 'confidence' => 1.0],
      ['type' => 'forms_inventory', 'results' => []],
      [
        'button_id' => 'forms_family',
        'label' => 'Family & Custody',
        'query_label' => 'family divorce custody',
        'topic_intent' => 'topic_family',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
      ],
      NULL,
      NULL,
    );

    $this->assertSame('selection', $context['assignment_source']);
    $this->assertSame('forms_family', $context['active_selection_key']);
    $this->assertSame('family divorce custody', $context['selection_query_label']);
    $this->assertSame('family', $context['selection_service_area']);
    $this->assertNull($context['topic_confidence']);
  }

  /**
   * Response shapes that must never create a human review item.
   */
  public static function nonReviewableResponseProvider(): array {
    return [
      'faq with results' => [
        [
          'type' => 'faq',
          'reason_code' => 'faq_match_found',
          'results' => [['id' => 1]],
        ],
        ['type' => 'faq', 'source' => 'router'],
      ],
      'apply cta' => [
        [
          'type' => 'apply_cta',
          'reason_code' => 'direct_navigation_apply',
        ],
        ['type' => 'apply_for_help', 'source' => 'router'],
      ],
      'high risk escalation' => [
        [
          'type' => 'escalation',
          'reason_code' => 'high_risk_deadline',
        ],
        ['type' => 'high_risk', 'source' => 'router'],
      ],
      'office location answer' => [
        [
          'type' => 'office_location',
          'reason_code' => 'office_detail_requested',
        ],
        ['type' => 'navigation', 'source' => 'router'],
      ],
      'forms inventory' => [
        [
          'type' => 'forms_inventory',
          'reason_code' => 'forms_inventory',
        ],
        ['type' => 'forms_inventory', 'source' => 'router'],
      ],
      'form finder clarify' => [
        [
          'type' => 'form_finder_clarify',
          'reason_code' => 'selection_branch_forms_family',
        ],
        ['type' => 'forms_finder', 'source' => 'selection'],
      ],
      'clarify fallback' => [
        [
          'type' => 'fallback',
          'reason_code' => 'clarification_needed',
        ],
        ['type' => 'disambiguation', 'source' => 'router'],
      ],
    ];
  }

}
