<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
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
    $header['primary_topic_tid'] = $this->t('Topic');
    $header['occurrence_count_total'] = $this->t('Occurrences');
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
    $row['primary_topic_tid'] = $entity->get('primary_topic_tid')->entity?->label() ?? $this->t('Unknown');
    $row['occurrence_count_total'] = (string) ($entity->get('occurrence_count_total')->value ?? 0);
    $last_seen = (int) ($entity->get('last_seen')->value ?? 0);
    $row['last_seen'] = $last_seen > 0 ? $date_formatter->format($last_seen, 'short') : $this->t('Unknown');
    $row['is_held'] = !empty($entity->get('is_held')->value) ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
