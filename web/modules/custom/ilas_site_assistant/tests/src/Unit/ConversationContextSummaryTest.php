<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ConversationContextSummary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the ephemeral multi-turn context summary contract.
 */
#[Group('ilas_site_assistant')]
final class ConversationContextSummaryTest extends TestCase {

  /**
   * Safe eviction facts are retained without raw personal data fields.
   */
  public function testSummarizeTurnCapturesSafeEvictionFactsOnly(): void {
    $summaryBuilder = new ConversationContextSummary();

    $summary = $summaryBuilder->summarizeTurn(
      'I got a 3-day notice. My SSN is 123-45-6789 and my name is John Smith. I live in Ada County and I have kids.',
      [
        'type' => 'topic_housing_eviction',
        'area' => 'housing',
        'topic' => 'eviction',
        'source' => 'router',
      ],
      [
        'links' => [
          ['label' => 'Legal Advice Line', 'url' => '/Legal-Advice-Line', 'type' => 'hotline'],
          ['label' => 'Apply for Help', 'url' => '/apply-for-help', 'type' => 'apply'],
        ],
      ],
      []
    );

    $this->assertSame('housing', $summary['service_area']);
    $this->assertSame('housing_eviction', $summary['current_topic']);
    $this->assertSame('ada', $summary['county']);
    $this->assertSame('3_day_notice', $summary['deadline_or_notice']);
    $this->assertTrue($summary['household_context']['children_present']);
    $this->assertSame(['hotline', 'apply'], $summary['last_offered_actions']);
    $this->assertSame(
      [
        'current_topic',
        'service_area',
        'county',
        'deadline_or_notice',
        'household_context',
        'preferred_language',
        'last_offered_actions',
        'unresolved_clarifying_question',
        'safety_flags',
      ],
      array_keys($summary)
    );
    $this->assertStringNotContainsString('123-45-6789', json_encode($summary));
    $this->assertStringNotContainsString('John Smith', json_encode($summary));
    $this->assertArrayNotHasKey('name', $summary);
    $this->assertArrayNotHasKey('ssn', $summary);
  }

  /**
   * Context-only follow-ups inherit the active legal-help topic.
   */
  public function testBuildContinuationIntentUsesPriorContextForCountyAndChildrenFollowup(): void {
    $summaryBuilder = new ConversationContextSummary();

    $intent = $summaryBuilder->buildContinuationIntent(
      'I live in Ada County and I have kids.',
      ['type' => 'unknown', 'confidence' => 0.31],
      [
        'current_topic' => 'housing_eviction',
        'service_area' => 'housing',
        'county' => '',
        'deadline_or_notice' => '3_day_notice',
        'household_context' => [
          'children_present' => FALSE,
          'survivor_safety_mentioned' => FALSE,
          'disability_mentioned' => FALSE,
        ],
        'preferred_language' => '',
        'last_offered_actions' => ['hotline', 'apply'],
        'unresolved_clarifying_question' => '',
        'safety_flags' => [],
      ]
    );

    $this->assertIsArray($intent);
    $this->assertSame('service_area', $intent['type']);
    $this->assertSame('housing', $intent['area']);
    $this->assertSame('eviction', $intent['topic']);
    $this->assertSame('conversation_context_summary', $intent['source']);
    $this->assertGreaterThanOrEqual(0.72, $intent['confidence']);
  }

  /**
   * Explicit topic shifts must not inherit the previous context.
   */
  public function testBuildContinuationIntentSkipsExplicitTopicShift(): void {
    $summaryBuilder = new ConversationContextSummary();

    $intent = $summaryBuilder->buildContinuationIntent(
      'Actually this is about custody.',
      ['type' => 'unknown', 'confidence' => 0.2],
      [
        'current_topic' => 'housing_eviction',
        'service_area' => 'housing',
        'county' => 'ada',
        'deadline_or_notice' => '3_day_notice',
        'household_context' => [
          'children_present' => TRUE,
          'survivor_safety_mentioned' => FALSE,
          'disability_mentioned' => FALSE,
        ],
        'preferred_language' => '',
        'last_offered_actions' => ['hotline', 'apply'],
        'unresolved_clarifying_question' => '',
        'safety_flags' => [],
      ]
    );

    $this->assertNull($intent);
  }

  /**
   * An explicit office question is not a housing continuation.
   */
  public function testBuildContinuationIntentSkipsSpanishOfficeQuestion(): void {
    $summaryBuilder = new ConversationContextSummary();

    $intent = $summaryBuilder->buildContinuationIntent(
      'donde estan sus oficinas',
      ['type' => 'offices_contact', 'confidence' => 0.85],
      [
        'current_topic' => 'housing_eviction',
        'service_area' => 'housing',
        'county' => '',
        'deadline_or_notice' => '',
        'household_context' => [
          'children_present' => TRUE,
          'survivor_safety_mentioned' => FALSE,
          'disability_mentioned' => FALSE,
        ],
        'preferred_language' => 'es',
        'last_offered_actions' => ['hotline', 'apply'],
        'unresolved_clarifying_question' => '',
        'safety_flags' => [],
      ]
    );

    $this->assertNull($intent);
  }

}
