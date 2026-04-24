<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Admin list builder for assistant gap items.
 */
class AssistantGapItemListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Gap item');
    $header['review_state'] = $this->t('State');
    $header['next_action'] = $this->t('Next action');
    $header['identity_context_key'] = $this->t('Context');
    $header['primary_topic_tid'] = $this->t('Topic');
    $header['occurrence_count_total'] = $this->t('Lifetime occurrences');
    $header['last_seen'] = $this->t('Last seen');
    $header['is_held'] = $this->t('Held');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AssistantGapItem);

    $date_formatter = \Drupal::service('date.formatter');
    $row['label'] = $entity->toLink()->toString();
    $row['review_state'] = AssistantGapItem::stateOptions()[$entity->getReviewState()] ?? $entity->getReviewState();
    $row['next_action'] = $entity->getNextActionLabel();
    $row['identity_context_key'] = (string) ($entity->get('identity_context_key')->value ?? 'legacy:unknown');
    $row['primary_topic_tid'] = $entity->get('primary_topic_tid')->entity?->label() ?? $this->t('Unknown');
    $row['occurrence_count_total'] = (string) ($entity->get('occurrence_count_total')->value ?? 0);
    $last_seen = (int) ($entity->get('last_seen')->value ?? 0);
    $row['last_seen'] = $last_seen > 0 ? $date_formatter->format($last_seen, 'short') : $this->t('Unknown');
    $row['is_held'] = !empty($entity->get('is_held')->value) ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    assert($entity instanceof AssistantGapItem);

    $operations = parent::getDefaultOperations($entity, $cacheability ?? new CacheableMetadata());
    if (isset($operations['edit'])) {
      $operations['edit']['title'] = $this->t('Review');
      $operations['edit']['weight'] = 20;
    }
    if (isset($operations['view'])) {
      $operations['view']['title'] = $this->t('Details');
      $operations['view']['weight'] = 30;
    }

    if (
      $entity->getReviewState() === AssistantGapItem::STATE_NEW &&
      AssistantGapItem::canTransition(AssistantGapItem::STATE_NEW, AssistantGapItem::STATE_NEEDS_REVIEW, \Drupal::currentUser())
    ) {
      $operations = [
        'start_review' => [
          'title' => $this->t('Start review'),
          'weight' => 10,
          'url' => Url::fromRoute('ilas_site_assistant_governance.gap_start_review', ['assistant_gap_item' => $entity->id()]),
        ],
      ] + $operations;
    }

    return $operations;
  }

}
