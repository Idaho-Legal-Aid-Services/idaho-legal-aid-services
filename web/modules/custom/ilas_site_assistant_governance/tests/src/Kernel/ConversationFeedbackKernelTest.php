<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\ilas_site_assistant_governance\Service\GovernanceConversationLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for feedback correlation and reviewer turn flagging.
 */
#[Group('ilas_site_assistant_governance')]
#[RunTestsInSeparateProcesses]
final class ConversationFeedbackKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'options',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
    'ilas_site_assistant_governance',
  ];

  private const CONVERSATION = '12345678-1234-4123-8123-123456789abc';
  private const OTHER_CONVERSATION = '12345678-1234-4123-8123-123456789abd';
  private const REQUEST = 'aaaaaaaa-1234-4123-8123-123456789001';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('assistant_gap_item');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant', 'ilas_site_assistant_governance']);
    $this->installSchema('ilas_site_assistant_governance', [
      'ilas_site_assistant_conversation_session',
      'ilas_site_assistant_conversation_turn',
      'ilas_site_assistant_gap_hit',
      'ilas_site_assistant_legal_hold',
    ]);
  }

  /**
   * Helpful feedback is stored on the turn and creates no gap item.
   */
  public function testHelpfulFeedbackIsStoredWithoutGapItem(): void {
    $logger = $this->logger();
    $logger->logExchange(self::CONVERSATION, 'Tenet rights', 'Here is the answer.', 'clarify', 'fallback', self::REQUEST);

    $result = $logger->recordFeedback(self::REQUEST, self::CONVERSATION, GovernanceConversationLogger::FEEDBACK_HELPFUL);

    $this->assertSame('helpful', $result['feedback_value'] ?? NULL);
    $this->assertNull($result['gap_item_id']);
    $this->assertSame('helpful', $this->assistantTurn()['feedback_value']);
    $this->assertNotEmpty($this->assistantTurn()['feedback_at']);
    $this->assertSame('0', (string) $this->gapItemCount());
    $this->assertSame('0', (string) $this->session()['has_negative_feedback']);
  }

  /**
   * Not-helpful feedback creates a negative_feedback gap item from the user turn.
   */
  public function testNotHelpfulFeedbackCreatesGapItemLinkedToTurn(): void {
    $logger = $this->logger();
    $logger->logExchange(self::CONVERSATION, 'Tenet rights', 'Could you describe your issue?', 'clarify', 'fallback', self::REQUEST);

    $result = $logger->recordFeedback(self::REQUEST, self::CONVERSATION, GovernanceConversationLogger::FEEDBACK_NOT_HELPFUL);

    $this->assertSame('not_helpful', $result['feedback_value']);
    $this->assertIsInt($result['gap_item_id']);
    $this->assertSame('Tenet rights', $result['user_turn']['message_redacted']);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $item */
    $item = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($result['gap_item_id']);
    $this->assertSame(AssistantGapItem::TRIGGER_NEGATIVE_FEEDBACK, (string) $item->get('trigger_code')->value);
    $this->assertSame('Tenet rights', (string) $item->get('exemplar_redacted_query')->value);
    // Clusters with what the runtime would have hashed for the same text.
    $this->assertSame(ObservabilityPayloadMinimizer::buildTextMetadataWithLanguage('Tenet rights')['text_hash'], (string) $item->get('query_hash')->value);

    $user_turn = $this->userTurn();
    $this->assertSame((string) $result['gap_item_id'], (string) $user_turn['gap_item_id']);
    $this->assertSame('0', (string) $user_turn['is_no_answer']);

    $session = $this->session();
    $this->assertSame('1', (string) $session['has_negative_feedback']);
    $this->assertSame('1', (string) $session['has_unresolved_gap']);
    $this->assertSame((string) $result['gap_item_id'], (string) $session['latest_gap_item_id']);

    $hit = $this->container->get('database')->select('ilas_site_assistant_gap_hit', 'h')
      ->fields('h', ['trigger_code', 'request_id'])
      ->condition('gap_item_id', $result['gap_item_id'])
      ->execute()
      ->fetchAssoc();
    $this->assertSame(AssistantGapItem::TRIGGER_NEGATIVE_FEEDBACK, $hit['trigger_code']);
    $this->assertSame(self::REQUEST, $hit['request_id']);
  }

  /**
   * Feedback is first-vote-wins and ignores mismatched conversations.
   */
  public function testFeedbackIsIdempotentAndConversationBound(): void {
    $logger = $this->logger();
    $logger->logExchange(self::CONVERSATION, 'Tenet rights', 'Could you describe your issue?', 'clarify', 'fallback', self::REQUEST);
    $logger->logExchange(self::OTHER_CONVERSATION, 'Something else', 'Answer.', 'faq', 'faq', 'bbbbbbbb-1234-4123-8123-123456789002');

    // Spoofed pairing: request from one conversation, ID of another.
    $this->assertNull($logger->recordFeedback(self::REQUEST, self::OTHER_CONVERSATION, GovernanceConversationLogger::FEEDBACK_NOT_HELPFUL));
    $this->assertSame('0', (string) $this->gapItemCount());

    $first = $logger->recordFeedback(self::REQUEST, self::CONVERSATION, GovernanceConversationLogger::FEEDBACK_NOT_HELPFUL);
    $this->assertIsInt($first['gap_item_id']);

    // Repeat votes (same or different value) do not change anything.
    $this->assertNull($logger->recordFeedback(self::REQUEST, self::CONVERSATION, GovernanceConversationLogger::FEEDBACK_NOT_HELPFUL));
    $this->assertNull($logger->recordFeedback(self::REQUEST, self::CONVERSATION, GovernanceConversationLogger::FEEDBACK_HELPFUL));
    $this->assertSame('1', (string) $this->gapItemCount());
    $this->assertSame('not_helpful', $this->assistantTurn()['feedback_value']);

    // A runtime clarify miss for the same text (the controller always sends
    // the routed intent type, which is part of the cluster identity) lands
    // in the same item as the thumbs-down.
    $runtime_id = $this->container->get('ilas_site_assistant_governance.gap_item_manager')
      ->recordNoAnswer('Tenet rights', [
        'trigger_code' => AssistantGapItem::TRIGGER_CLARIFY_UNKNOWN_INTENT,
        'intent_type' => 'clarify',
      ]);
    $this->assertSame($first['gap_item_id'], $runtime_id);
  }

  /**
   * Reviewers can flag a user turn; assistant turns and linked turns are refused.
   */
  public function testFlagUserTurnCreatesReviewerFlaggedGapItem(): void {
    $logger = $this->logger();
    $logger->logExchange(self::CONVERSATION, 'Entering a residence', 'Could you describe your issue?', 'clarify', 'fallback', self::REQUEST);

    $this->assertNull($logger->flagUserTurn(self::CONVERSATION, 2), 'Assistant turns cannot be flagged');
    $this->assertNull($logger->flagUserTurn(self::CONVERSATION, 99), 'Unknown turns cannot be flagged');

    $gap_item_id = $logger->flagUserTurn(self::CONVERSATION, 1);
    $this->assertIsInt($gap_item_id);

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $item */
    $item = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item')->load($gap_item_id);
    $this->assertSame(AssistantGapItem::TRIGGER_REVIEWER_FLAGGED, (string) $item->get('trigger_code')->value);
    $this->assertSame('Entering a residence', (string) $item->get('exemplar_redacted_query')->value);
    $this->assertSame((string) $gap_item_id, (string) $this->userTurn()['gap_item_id']);
    $this->assertSame('1', (string) $this->session()['has_unresolved_gap']);

    // Flagging again returns the existing item instead of a duplicate.
    $this->assertSame($gap_item_id, $logger->flagUserTurn(self::CONVERSATION, 1));
    $this->assertSame('1', (string) $this->gapItemCount());
  }

  /**
   * Returns the governance conversation logger.
   */
  private function logger(): GovernanceConversationLogger {
    return $this->container->get('ilas_site_assistant_governance.conversation_logger');
  }

  /**
   * Loads the assistant turn for the test request.
   */
  private function assistantTurn(): array {
    return $this->turn('assistant');
  }

  /**
   * Loads the user turn for the test request.
   */
  private function userTurn(): array {
    return $this->turn('user');
  }

  /**
   * Loads one turn row by direction.
   */
  private function turn(string $direction): array {
    $row = $this->container->get('database')->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t')
      ->condition('conversation_id', self::CONVERSATION)
      ->condition('request_id', self::REQUEST)
      ->condition('direction', $direction)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return $row;
  }

  /**
   * Loads the session row.
   */
  private function session(): array {
    $row = $this->container->get('database')->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s')
      ->condition('conversation_id', self::CONVERSATION)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return $row;
  }

  /**
   * Counts gap items.
   */
  private function gapItemCount(): int {
    return (int) $this->container->get('database')->select('assistant_gap_item', 'g')->countQuery()->execute()->fetchField();
  }

}
