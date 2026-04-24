<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reviewer workspace for assistant gap items.
 */
final class AssistantGapItemReviewController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected EntityFormBuilderInterface $reviewEntityFormBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('entity.form_builder'),
    );
  }

  /**
   * Title callback for the reviewer workspace.
   */
  public function title(AssistantGapItem $assistant_gap_item): string {
    $exemplar = trim((string) ($assistant_gap_item->get('exemplar_redacted_query')->value ?? ''));
    if ($exemplar === '') {
      return (string) $this->t('Review gap item #@id', ['@id' => $assistant_gap_item->id()]);
    }

    $excerpt = mb_substr($exemplar, 0, 72);
    if (mb_strlen($exemplar) > 72) {
      $excerpt .= '...';
    }

    return (string) $this->t('Review gap: @excerpt', ['@excerpt' => $excerpt]);
  }

  /**
   * Builds the reviewer workspace.
   */
  public function review(AssistantGapItem $assistant_gap_item): array {
    $build = [
      'intro' => [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Review this assistant gap using redacted evidence only. Raw user text is not shown on this screen.') . '</p>',
      ],
      'overview' => $this->buildOverview($assistant_gap_item),
      'recent_hits' => $this->buildRecentHits($assistant_gap_item),
      'conversation' => $this->buildConversationExcerpt($assistant_gap_item),
      'technical' => $this->buildTechnicalDetails($assistant_gap_item),
    ];

    if ($assistant_gap_item->access('update', $this->currentUser())) {
      $build['review_form'] = [
        '#type' => 'details',
        '#title' => $this->t('Review actions'),
        '#open' => TRUE,
        '#weight' => 20,
        'form' => $this->reviewEntityFormBuilder->getForm($assistant_gap_item, 'edit'),
      ];
    }
    else {
      $build['review_form'] = [
        '#type' => 'status_messages',
        '#weight' => 20,
      ];
      $this->messenger()->addWarning($this->t('You can view this gap item, but you do not have permission to update its review state.'));
    }

    return $build;
  }

  /**
   * Builds a high-signal overview table.
   */
  protected function buildOverview(AssistantGapItem $assistant_gap_item): array {
    $conversation_link = $this->buildConversationLink($assistant_gap_item);
    $latest_conversation = $conversation_link !== NULL ? ['data' => $conversation_link] : $this->t('Unavailable');
    $rows = [
      [$this->t('Redacted exemplar'), (string) ($assistant_gap_item->get('exemplar_redacted_query')->value ?? $this->t('None available'))],
      [$this->t('Status'), AssistantGapItem::stateOptions()[$assistant_gap_item->getReviewState()] ?? $assistant_gap_item->getReviewState()],
      [$this->t('Next action'), $assistant_gap_item->getNextActionLabel()],
      [$this->t('Assigned reviewer'), $assistant_gap_item->get('assigned_uid')->entity?->label() ?? $this->t('Unassigned')],
      [$this->t('Identity context'), (string) ($assistant_gap_item->get('identity_context_key')->value ?? 'legacy:unknown')],
      [$this->t('Identity source'), (string) ($assistant_gap_item->get('identity_source')->value ?? 'legacy')],
      [$this->t('Identity selection'), (string) ($assistant_gap_item->get('identity_selection_key')->value ?? $this->t('None'))],
      [$this->t('Identity intent'), (string) ($assistant_gap_item->get('identity_intent')->value ?? 'unknown')],
      [$this->t('Assigned topic'), $assistant_gap_item->get('primary_topic_tid')->entity?->label() ?? $this->t('Unknown')],
      [$this->t('Assigned service area'), $assistant_gap_item->get('primary_service_area_tid')->entity?->label() ?? $this->t('Unknown')],
      [$this->t('Topic source'), AssistantGapItem::topicAssignmentSourceOptions()[(string) ($assistant_gap_item->get('topic_assignment_source')->value ?? 'unknown')] ?? $this->t('Unknown')],
      [$this->t('Topic confidence'), $assistant_gap_item->get('topic_assignment_confidence')->isEmpty() ? $this->t('Unknown') : $this->t('@confidence%', ['@confidence' => (string) $assistant_gap_item->get('topic_assignment_confidence')->value])],
      [$this->t('Language'), (string) ($assistant_gap_item->get('language_hint')->value ?? 'unknown')],
      [$this->t('Open occurrences'), (string) ($assistant_gap_item->get('occurrence_count_unresolved')->value ?? '0')],
      [$this->t('Lifetime occurrences'), (string) ($assistant_gap_item->get('occurrence_count_total')->value ?? '0')],
      [$this->t('First seen'), $this->formatTimestamp((int) ($assistant_gap_item->get('first_seen')->value ?? 0))],
      [$this->t('Last seen'), $this->formatTimestamp((int) ($assistant_gap_item->get('last_seen')->value ?? 0))],
      [$this->t('Reviewed at'), $this->formatTimestamp((int) ($assistant_gap_item->get('reviewed_at')->value ?? 0))],
      [$this->t('Resolved at'), $this->formatTimestamp((int) ($assistant_gap_item->get('resolved_at')->value ?? 0))],
      [$this->t('Legal hold'), !empty($assistant_gap_item->get('is_held')->value) ? $this->t('Yes') : $this->t('No')],
      [$this->t('Latest conversation'), $latest_conversation],
    ];

    return [
      '#type' => 'table',
      '#title' => $this->t('Overview'),
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
      '#weight' => 0,
    ];
  }

  /**
   * Builds the recent-gap-hit evidence table.
   */
  protected function buildRecentHits(AssistantGapItem $assistant_gap_item): array {
    if (!$this->database->schema()->tableExists('ilas_site_assistant_gap_hit')) {
      return [
        '#type' => 'details',
        '#title' => $this->t('Recent gap evidence'),
        '#open' => TRUE,
        '#weight' => 5,
        'empty' => ['#markup' => $this->t('Gap-hit evidence storage is not installed yet.')],
      ];
    }

    $query = $this->database->select('ilas_site_assistant_gap_hit', 'h');
    $query->fields('h', [
      'conversation_id',
      'occurred_at',
      'language_hint',
      'assignment_source',
      'intent',
      'active_selection_key',
    ]);
    $query->condition('h.gap_item_id', (int) $assistant_gap_item->id());
    $query->leftJoin('taxonomy_term_field_data', 'topic_term', 'topic_term.tid = h.observed_topic_tid');
    $query->leftJoin('taxonomy_term_field_data', 'service_area_term', 'service_area_term.tid = h.observed_service_area_tid');
    $query->addField('topic_term', 'name', 'topic_name');
    $query->addField('service_area_term', 'name', 'service_area_name');
    $query->orderBy('h.occurred_at', 'DESC');
    $query->range(0, 10);

    $rows = [];
    foreach ($query->execute() as $row) {
      $conversation = '-';
      if (!empty($row->conversation_id) && $this->currentUser()->hasPermission('view assistant governance conversations')) {
        $conversation = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Open'),
            '#url' => Url::fromRoute('ilas_site_assistant_governance.conversation_detail', ['conversation_id' => $row->conversation_id]),
          ],
        ];
      }

      $rows[] = [
        $this->formatTimestamp((int) $row->occurred_at),
        $row->language_hint ?: '-',
        $row->intent ?: '-',
        $row->assignment_source ?: '-',
        $row->active_selection_key ?: '-',
        $row->topic_name ?: '-',
        $row->service_area_name ?: '-',
        $conversation,
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Recent gap evidence'),
      '#open' => TRUE,
      '#weight' => 5,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('When'),
          $this->t('Language'),
          $this->t('Intent'),
          $this->t('Source'),
          $this->t('Selection'),
          $this->t('Observed topic'),
          $this->t('Observed service area'),
          $this->t('Conversation'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No evidence rows are available for this gap item yet.'),
      ],
    ];
  }

  /**
   * Builds a recent redacted conversation excerpt.
   */
  protected function buildConversationExcerpt(AssistantGapItem $assistant_gap_item): array {
    $conversation_id = trim((string) ($assistant_gap_item->get('latest_conversation_id')->value ?? ''));
    if ($conversation_id === '') {
      $conversation_id = trim((string) ($assistant_gap_item->get('first_conversation_id')->value ?? ''));
    }

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Latest redacted conversation'),
      '#open' => TRUE,
      '#weight' => 10,
    ];

    if (
      $conversation_id === ''
      || !$this->database->schema()->tableExists('ilas_site_assistant_conversation_turn')
    ) {
      $build['empty'] = ['#markup' => $this->t('No redacted conversation excerpt is available for this gap item yet.')];
      return $build;
    }

    $turns = $this->database->select('ilas_site_assistant_conversation_turn', 't')
      ->fields('t', [
        'turn_sequence',
        'direction',
        'message_redacted',
        'intent',
        'response_type',
      ])
      ->condition('conversation_id', $conversation_id)
      ->orderBy('turn_sequence', 'DESC')
      ->range(0, 6)
      ->execute()
      ->fetchAll();

    if ($turns === []) {
      $build['empty'] = ['#markup' => $this->t('No redacted conversation excerpt is available for this gap item yet.')];
      return $build;
    }

    $rows = [];
    foreach (array_reverse($turns) as $turn) {
      $rows[] = [
        (string) $turn->turn_sequence,
        $turn->direction,
        $turn->message_redacted,
        $turn->intent ?: '-',
        $turn->response_type ?: '-',
      ];
    }

    if ($link = $this->buildConversationLink($assistant_gap_item, $conversation_id)) {
      $build['link'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['gap-review-conversation-link']],
        'item' => $link,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Turn'),
        $this->t('Direction'),
        $this->t('Redacted message'),
        $this->t('Intent'),
        $this->t('Response type'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

  /**
   * Builds a collapsed technical details section.
   */
  protected function buildTechnicalDetails(AssistantGapItem $assistant_gap_item): array {
    $rows = [
      [$this->t('Item ID'), (string) $assistant_gap_item->id()],
      [$this->t('Safe label'), $assistant_gap_item->label()],
      [$this->t('Cluster hash prefix'), ObservabilityPayloadMinimizer::hashPrefix((string) ($assistant_gap_item->get('cluster_hash')->value ?? ''), 12)],
      [$this->t('Query hash prefix'), ObservabilityPayloadMinimizer::hashPrefix((string) ($assistant_gap_item->get('query_hash')->value ?? ''), 12)],
      [$this->t('Identity topic term ID'), (string) ($assistant_gap_item->get('identity_topic_tid')->value ?? '-')],
      [$this->t('Identity service-area term ID'), (string) ($assistant_gap_item->get('identity_service_area_tid')->value ?? '-')],
      [$this->t('First conversation ID'), (string) ($assistant_gap_item->get('first_conversation_id')->value ?? '-')],
      [$this->t('Latest conversation ID'), (string) ($assistant_gap_item->get('latest_conversation_id')->value ?? '-')],
      [$this->t('Latest request ID'), (string) ($assistant_gap_item->get('latest_request_id')->value ?? '-')],
      [$this->t('Purge after'), $this->formatTimestamp((int) ($assistant_gap_item->get('purge_after')->value ?? 0))],
    ];

    return [
      '#type' => 'details',
      '#title' => $this->t('Technical details'),
      '#open' => FALSE,
      '#weight' => 15,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Field'), $this->t('Value')],
        '#rows' => $rows,
      ],
    ];
  }

  /**
   * Builds a link to the full redacted conversation when allowed.
   */
  protected function buildConversationLink(AssistantGapItem $assistant_gap_item, ?string $conversation_id = NULL): ?array {
    $conversation_id = $conversation_id ?? trim((string) ($assistant_gap_item->get('latest_conversation_id')->value ?? ''));
    if ($conversation_id === '' || !$this->currentUser()->hasPermission('view assistant governance conversations')) {
      return NULL;
    }

    return [
      '#type' => 'link',
      '#title' => $this->t('Open full redacted conversation'),
      '#url' => Url::fromRoute('ilas_site_assistant_governance.conversation_detail', ['conversation_id' => $conversation_id]),
    ];
  }

  /**
   * Formats timestamps for admin review screens.
   */
  protected function formatTimestamp(int $timestamp): string {
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'short') : (string) $this->t('Unknown');
  }

}
