<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists canonical conversation sessions.
   */
  public function list(): array {
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversation_session')) {
      return ['#markup' => $this->t('Governance conversation storage is not installed yet.')];
    }

    $query = $this->database->select('ilas_site_assistant_conversation_session', 's')
      ->fields('s', [
        'conversation_id',
        'first_message_at',
        'last_message_at',
        'exchange_count',
        'last_intent',
        'has_no_answer',
        'is_held',
      ])
      ->orderBy('last_message_at', 'DESC')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(50);

    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        substr((string) $row->conversation_id, 0, 8) . '...',
        $this->dateFormatter->format((int) $row->first_message_at, 'short'),
        $this->dateFormatter->format((int) $row->last_message_at, 'short'),
        (int) $row->exchange_count,
        $row->last_intent ?: '-',
        (int) $row->has_no_answer ? $this->t('Yes') : $this->t('No'),
        (int) $row->is_held ? $this->t('Yes') : $this->t('No'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View'),
            '#url' => Url::fromRoute('ilas_site_assistant_governance.conversation_detail', ['conversation_id' => $row->conversation_id]),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Conversation'),
        $this->t('Started'),
        $this->t('Last message'),
        $this->t('Exchanges'),
        $this->t('Last intent'),
        $this->t('No answer'),
        $this->t('Held'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No governance conversations available.'),
    ];
    $build['pager'] = ['#type' => 'pager'];

    return $build;
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
    $turns = $this->database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', [
        'turn_sequence',
        'created',
        'direction',
        'message_redacted',
        'message_length_bucket',
        'intent',
        'response_type',
        'gap_item_id',
      ])
      ->condition('conversation_id', $conversation_id)
      ->orderBy('turn_sequence', 'ASC')
      ->execute();

    foreach ($turns as $turn) {
      $gap_link = '-';
      if (!empty($turn->gap_item_id)) {
        $gap_link = [
          'data' => [
            '#type' => 'link',
            '#title' => (string) $turn->gap_item_id,
            '#url' => Url::fromRoute('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $turn->gap_item_id]),
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
        $gap_link,
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
        $this->t('Gap item'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No turns available.'),
    ];

    return $build;
  }

}
