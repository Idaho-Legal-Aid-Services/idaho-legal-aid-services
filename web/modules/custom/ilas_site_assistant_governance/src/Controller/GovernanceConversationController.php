<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant_governance\Service\GovernanceConversationLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Admin screens for canonical governance conversations.
 */
class GovernanceConversationController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected ?GovernanceConversationLogger $conversationLogger = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->has('ilas_site_assistant_governance.conversation_logger')
        ? $container->get('ilas_site_assistant_governance.conversation_logger')
        : NULL,
    );
  }

  /**
   * Pushes a user turn into the gap review queue and opens the item.
   */
  public function flagTurn(string $conversation_id, string $turn_sequence): RedirectResponse {
    $gap_item_id = $this->conversationLogger?->flagUserTurn($conversation_id, (int) $turn_sequence);
    if ($gap_item_id === NULL) {
      $this->messenger()->addWarning($this->t('That turn could not be flagged (only user turns without an existing gap item can be flagged).'));
      return $this->redirect('ilas_site_assistant_governance.conversation_detail', ['conversation_id' => $conversation_id]);
    }

    $this->messenger()->addStatus($this->t('Turn @turn was added to the gap review queue as item @id.', [
      '@turn' => $turn_sequence,
      '@id' => $gap_item_id,
    ]));
    return $this->redirect('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $gap_item_id]);
  }

  /**
   * Shows one canonical conversation session.
   */
  public function detail(string $conversation_id): array {
    $session = $this->database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s')
      ->condition('conversation_id', $conversation_id)
      ->execute()
      ->fetchAssoc();

    if (!$session) {
      return ['#markup' => $this->t('Conversation not found.')];
    }

    $rows = [];
    $turn_fields = [
      'turn_sequence',
      'created',
      'direction',
      'message_redacted',
      'message_length_bucket',
      'intent',
      'response_type',
      'gap_item_id',
    ];
    $has_feedback_columns = $this->database->schema()->fieldExists('ilas_site_assistant_conversation_turn', 'feedback_value');
    if ($has_feedback_columns) {
      $turn_fields[] = 'feedback_value';
    }
    $turns = $this->database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', $turn_fields)
      ->condition('conversation_id', $conversation_id)
      ->orderBy('turn_sequence', 'ASC')
      ->execute();

    $can_flag = $this->currentUser()->hasPermission('flag assistant conversation turns');
    foreach ($turns as $turn) {
      $gap_link = '-';
      if (!empty($turn->gap_item_id)) {
        $gap_link = [
          'data' => [
            '#type' => 'link',
            '#title' => (string) $turn->gap_item_id,
            '#url' => Url::fromRoute('entity.assistant_gap_item.edit_form', ['assistant_gap_item' => $turn->gap_item_id]),
          ],
        ];
      }

      $feedback = '-';
      $feedback_value = $has_feedback_columns ? (string) ($turn->feedback_value ?? '') : '';
      if ($feedback_value !== '') {
        $feedback = $feedback_value === GovernanceConversationLogger::FEEDBACK_HELPFUL
          ? $this->t('Helpful')
          : $this->t('Not helpful');
      }

      $action = '-';
      if ($can_flag && $turn->direction === 'user' && empty($turn->gap_item_id)) {
        $action = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Flag for review'),
            '#url' => Url::fromRoute('ilas_site_assistant_governance.flag_conversation_turn', [
              'conversation_id' => $conversation_id,
              'turn_sequence' => (int) $turn->turn_sequence,
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ];
      }

      $rows[] = [
        (int) $turn->turn_sequence,
        $this->dateFormatter->format((int) $turn->created, 'medium'),
        $turn->direction,
        $turn->message_redacted,
        $turn->message_length_bucket,
        $turn->intent ?: '-',
        $turn->response_type ?: '-',
        $feedback,
        $gap_link,
        $action,
      ];
    }

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to conversation list'),
      '#url' => Url::fromRoute('view.assistant_governance_conversations.page_all'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Conversation %id. This surface shows only PII-redacted message text.', ['%id' => $conversation_id]) . '</p>',
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Turn'),
        $this->t('Time'),
        $this->t('Direction'),
        $this->t('Redacted message'),
        $this->t('Length'),
        $this->t('Intent'),
        $this->t('Response type'),
        $this->t('Feedback'),
        $this->t('Gap item'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No turns available.'),
    ];

    return $build;
  }

}
