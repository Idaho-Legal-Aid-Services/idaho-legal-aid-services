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
   * Trigger codes for every reviewable response shape.
   */
  #[DataProvider('triggerCodeProvider')]
  public function testResolveTriggerCode(array $response, array $intent, string $message, ?string $expected): void {
    $this->assertSame($expected, GapReviewDecider::resolveTriggerCode($response, $intent, $message));
  }

  /**
   *
   */
  public static function triggerCodeProvider(): array {
    $clarify_response = ['type' => 'fallback', 'reason_code' => 'clarification_needed'];
    $unknown_clarify = ['type' => 'clarify', 'original_intent' => 'unknown'];
    return [
      'no-match fallback' => [
        ['type' => 'fallback', 'reason_code' => 'no_match_fallback'],
        ['type' => 'unknown'],
        'anything',
        GapReviewDecider::TRIGGER_NO_MATCH_FALLBACK,
      ],
      'clarify loop break' => [
        ['type' => 'clarify_loop_break', 'reason_code' => 'clarify_loop_break'],
        ['type' => 'clarify'],
        'what',
        GapReviewDecider::TRIGGER_CLARIFY_LOOP_BREAK,
      ],
      'grounding refusal' => [
        ['type' => 'clarify_no_grounding', 'reason_code' => 'faq_match_found'],
        ['type' => 'faq'],
        'tell me about probate timelines',
        GapReviewDecider::TRIGGER_CLARIFY_NO_GROUNDING,
      ],
      'generic clarify, real words (conversation 7c10da94 turn 1)' => [
        $clarify_response, $unknown_clarify, 'Tenet rights', GapReviewDecider::TRIGGER_CLARIFY_UNKNOWN_INTENT,
      ],
      'generic clarify, three words' => [
        $clarify_response, $unknown_clarify, 'Entering a residence', GapReviewDecider::TRIGGER_CLARIFY_UNKNOWN_INTENT,
      ],
      'generic clarify, keyboard noise' => [
        $clarify_response, $unknown_clarify, 'zxqvplm', NULL,
      ],
      'generic clarify, single word' => [
        $clarify_response, $unknown_clarify, 'hello', NULL,
      ],
      'generic clarify, only short tokens' => [
        $clarify_response, $unknown_clarify, 'is it ok', NULL,
      ],
      'generic clarify, numbers only' => [
        $clarify_response, $unknown_clarify, '12345 67890', NULL,
      ],
      'pack clarifier is understood' => [
        $clarify_response, ['type' => 'clarify', 'original_intent' => 'topic_housing'], 'housing question please', NULL,
      ],
      'disambiguation menu is understood' => [
        $clarify_response, ['type' => 'disambiguation', 'source' => 'router'], 'divorce custody', NULL,
      ],
      'faq answer' => [
        ['type' => 'faq', 'reason_code' => 'faq_match_found', 'results' => [['id' => 1]]],
        ['type' => 'faq'],
        'how do I apply',
        NULL,
      ],
    ];
  }

  /**
   * A substantive generic clarify now creates a review item.
   */
  public function testShouldRecordSubstantiveGenericClarify(): void {
    $should_record = GapReviewDecider::shouldRecordGapItem(
      ['type' => 'fallback', 'reason_code' => 'clarification_needed'],
      ['type' => 'clarify', 'original_intent' => 'unknown'],
      [],
      Request::create('/assistant/api/message', 'POST'),
      ['message' => 'Entering a residence'],
    );

    $this->assertTrue($should_record);
  }

  /**
   * A precomputed trigger in the governance context is honoured.
   */
  public function testShouldRecordUsesGovernanceContextTrigger(): void {
    $request = Request::create('/assistant/api/message', 'POST');
    $this->assertTrue(GapReviewDecider::shouldRecordGapItem(
      ['type' => 'faq'],
      ['type' => 'faq'],
      ['trigger_code' => GapReviewDecider::TRIGGER_CLARIFY_LOOP_BREAK],
      $request,
      [],
    ));
    $this->assertFalse(GapReviewDecider::shouldRecordGapItem(
      ['type' => 'fallback', 'reason_code' => 'no_match_fallback'],
      ['type' => 'unknown'],
      ['trigger_code' => NULL],
      $request,
      [],
    ));
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
