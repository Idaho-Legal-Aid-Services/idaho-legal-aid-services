<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the housing-eviction continuity guard predicate.
 *
 * The guard preserves housing/eviction context for ambiguous follow-ups
 * ("I'm in Ada County", "what are my next steps?") but must NOT override a
 * clear new topic such as custody, protection orders, consumer debt,
 * SSI/disability, or divorce — even when the same message also carries a
 * county or next-step phrase.
 */
#[Group('ilas_site_assistant')]
final class HousingEvictionContinuityGuardTest extends TestCase {

  /**
   * Mirrors the list defined inline in AssistantApiController::message().
   *
   * If the controller list changes, this constant must be updated to keep
   * the unit test in lockstep with the production predicate.
   */
  private const GENERIC_FOLLOWUP_INTENTS = [
    'unknown',
    'resources',
    'faq',
    'meta_help',
    'meta_information',
    'meta_what_do_you_do',
    'intent_pack_meta_what_do_you_do',
    'intent_pack_meta_information',
    'services_overview',
    'forms_finder',
    'guides_finder',
  ];

  /**
   * Builds an eviction-prior history fixture.
   */
  private static function evictionHistory(): array {
    return [
      [
        'role' => 'user',
        'text' => 'I got an eviction notice. What can I do?',
        'intent' => 'topic_housing_eviction',
        'topic' => 'eviction',
        'area' => 'housing',
        'timestamp' => 1714500000,
      ],
      [
        'role' => 'assistant',
        'text' => "If you are being illegally locked out or evicted without proper notice…",
        'intent' => 'topic_housing_eviction',
        'topic' => 'eviction',
        'area' => 'housing',
        'timestamp' => 1714500005,
      ],
    ];
  }

  /**
   * Returns a conversation context summary consistent with the eviction history.
   */
  private static function evictionContextSummary(): array {
    return [
      'current_topic' => 'housing_eviction',
      'deadline_or_notice' => 'eviction_notice',
    ];
  }

  /**
   * Positive case: ambiguous county + next-step follow-up after eviction.
   *
   * IntentRouter has nothing to grip on for "I'm in Ada County. What are my
   * next steps?" so the current intent is `unknown`. The guard MUST fire so
   * the response keeps eviction context instead of falling into a generic
   * grounding refusal.
   */
  public function testGuardFiresForAdaCountyNextStepsAfterEviction(): void {
    $intent = ['type' => 'unknown', 'confidence' => 0.0];
    $message = "I'm in Ada County. What are my next steps?";

    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      $message,
      // is_location_like_reply: "Ada County" → TRUE.
      TRUE,
      // is_next_step_followup: "what are my next steps" → TRUE.
      TRUE,
    );

    $this->assertTrue(
      $applied,
      'Guard must fire for ambiguous county + next-steps follow-up after eviction.'
    );
  }

  /**
   * Negative case: explicit topic switch to custody, even with county phrase.
   *
   * For "What should I do about custody, I'm in Ada County?" IntentRouter
   * matches a clear new topic (topic_family / service_area area=family /
   * custody-related intent). The guard MUST NOT override that, even though
   * the message still contains a county and a "what should I do" phrase.
   */
  public function testGuardDoesNotFireForCustodyTopicSwitchEvenWithCountyPhrase(): void {
    $message = 'What should I do about custody, I am in Ada County?';

    $clear_topic_intents = [
      ['type' => 'topic_family', 'confidence' => 0.78],
      ['type' => 'service_area', 'area' => 'family', 'confidence' => 0.72],
      ['type' => 'apply_for_help', 'confidence' => 0.65],
    ];

    foreach ($clear_topic_intents as $intent) {
      $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
        $intent,
        self::GENERIC_FOLLOWUP_INTENTS,
        self::evictionHistory(),
        self::evictionContextSummary(),
        $message,
        TRUE,
        TRUE,
      );

      $this->assertFalse(
        $applied,
        sprintf(
          'Guard must NOT fire when intent is a clear non-housing topic (%s).',
          $intent['type'],
        )
      );
    }
  }

  /**
   * Other clear topic switches with county/next-step phrasing must also bail.
   */
  public function testGuardDoesNotFireForOtherClearTopicSwitches(): void {
    $cases = [
      ['I have a debt collection problem in Canyon County. What should I do?', 'topic_consumer'],
      ['I need a protection order. What do I do? I live in Boise.', 'topic_civil_rights'],
      ['I was denied SSI. What are my next steps in Idaho Falls?', 'topic_benefits'],
      ['I want a divorce. What should I do? I am in Ada County.', 'topic_family'],
    ];

    foreach ($cases as [$message, $intent_type]) {
      $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
        ['type' => $intent_type, 'confidence' => 0.75],
        self::GENERIC_FOLLOWUP_INTENTS,
        self::evictionHistory(),
        self::evictionContextSummary(),
        $message,
        TRUE,
        TRUE,
      );
      $this->assertFalse(
        $applied,
        sprintf('Guard must not fire for %s topic switch (msg: %s).', $intent_type, $message)
      );
    }
  }

  /**
   * Reset-signal phrasing also disables the guard, even on ambiguous intent.
   */
  public function testGuardSkipsWhenResetSignalPresent(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'unknown', 'confidence' => 0.0],
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      'Actually, what about custody — I am in Ada County?',
      TRUE,
      TRUE,
    );

    $this->assertFalse(
      $applied,
      'Guard must skip when message contains a topic-shift reset signal.'
    );
  }

  /**
   * Without server_history, the guard cannot have a topic to continue.
   */
  public function testGuardSkipsWhenServerHistoryEmpty(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'unknown', 'confidence' => 0.0],
      self::GENERIC_FOLLOWUP_INTENTS,
      [],
      [],
      "I'm in Ada County. What are my next steps?",
      TRUE,
      TRUE,
    );

    $this->assertFalse(
      $applied,
      'Guard must skip when server_history is empty.'
    );
  }

  /**
   * Without an eviction signal in history or context, the guard cannot fire.
   */
  public function testGuardSkipsWhenNoEvictionSignal(): void {
    $non_eviction_history = [
      [
        'role' => 'user',
        'text' => 'How do I apply for SNAP benefits?',
        'intent' => 'topic_benefits',
        'topic' => 'snap',
        'area' => 'benefits',
        'timestamp' => 1714500000,
      ],
    ];

    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'unknown', 'confidence' => 0.0],
      self::GENERIC_FOLLOWUP_INTENTS,
      $non_eviction_history,
      ['current_topic' => 'benefits_snap'],
      "I'm in Ada County. What are my next steps?",
      TRUE,
      TRUE,
    );

    $this->assertFalse(
      $applied,
      'Guard must skip when neither history nor context shows an eviction thread.'
    );
  }

  /**
   * Pure follow-up phrasing (no county) still fires for next-step questions.
   */
  public function testGuardFiresForNextStepsWithoutCountyAfterEviction(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'unknown', 'confidence' => 0.0],
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      'What should I do?',
      // is_location_like_reply: no city/county/zip → FALSE.
      FALSE,
      // is_next_step_followup: matches "what should i do" → TRUE.
      TRUE,
    );

    $this->assertTrue(
      $applied,
      'Guard must still fire for ambiguous next-steps follow-up without county phrase.'
    );
  }

  /**
   * Generic intent + neither location nor next-step phrasing must bail.
   */
  public function testGuardSkipsWhenNeitherLocationNorNextStep(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'unknown', 'confidence' => 0.0],
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      'tell me a joke',
      FALSE,
      FALSE,
    );

    $this->assertFalse(
      $applied,
      'Guard must skip when neither a location-like reply nor a next-step phrase is present.'
    );
  }

  /**
   * Positive case: bare-city reply that IntentRouter classifies as
   * `offices_contact` must still preserve eviction context.
   *
   * IntentRouter's offices_contact pattern matches bare city names
   * ("Boise", "Idaho Falls", "Pocatello", etc.) — but during an active
   * eviction thread these are location refinements, not new office
   * searches. The guard intentionally treats `offices_contact` as
   * override-eligible.
   */
  public function testGuardFiresForBareCityReplyClassifiedAsOfficesContact(): void {
    $cases = [
      'This is in Boise.',
      'It happened in Idaho Falls.',
      'Pocatello.',
    ];
    foreach ($cases as $message) {
      $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
        ['type' => 'offices_contact', 'confidence' => 0.7],
        self::GENERIC_FOLLOWUP_INTENTS,
        self::evictionHistory(),
        self::evictionContextSummary(),
        $message,
        TRUE,
        FALSE,
      );
      $this->assertTrue(
        $applied,
        sprintf('Guard must fire for bare-city offices_contact reply (%s) during eviction.', $message)
      );
    }
  }

  /**
   * An explicit Spanish office question without a city is a new request.
   */
  public function testGuardSkipsExplicitSpanishOfficeQuestionWithoutCity(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'offices_contact', 'confidence' => 0.85],
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      'donde estan sus oficinas',
      FALSE,
      FALSE,
    );

    $this->assertFalse($applied, 'Guard must not override an explicit office question during eviction.');
  }

  /**
   * Explicit office questions stay office searches even when they name a city.
   */
  public function testGuardSkipsExplicitOfficeQuestionEvenWithCity(): void {
    foreach (['Donde esta la oficina de Boise', 'where is the Boise office'] as $message) {
      $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
        ['type' => 'offices_contact', 'confidence' => 0.85],
        self::GENERIC_FOLLOWUP_INTENTS,
        self::evictionHistory(),
        self::evictionContextSummary(),
        $message,
        TRUE,
        FALSE,
      );

      $this->assertFalse($applied, sprintf('Guard must not override an explicit office question (%s).', $message));
    }
  }

  /**
   * intent_pack_meta_* meta-intents are treated as generic for guard purposes.
   */
  public function testGuardFiresForMetaIntentPackPrefix(): void {
    $applied = AssistantApiController::shouldApplyHousingEvictionContinuityGuard(
      ['type' => 'intent_pack_meta_some_new_meta_intent', 'confidence' => 0.5],
      self::GENERIC_FOLLOWUP_INTENTS,
      self::evictionHistory(),
      self::evictionContextSummary(),
      "I'm in Ada County. What are my next steps?",
      TRUE,
      TRUE,
    );

    $this->assertTrue(
      $applied,
      'Guard must fire for intent_pack_meta_* prefixed intents (treated as generic).'
    );
  }

}
