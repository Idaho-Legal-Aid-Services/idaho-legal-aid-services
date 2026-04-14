<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Moves assistant gap items to reviewed.
 */
#[Action(
  id: 'assistant_gap_item_to_reviewed_action',
  label: new TranslatableMarkup('Move selected gap items to reviewed'),
  type: 'assistant_gap_item',
)]
class MoveAssistantGapItemToReviewed extends AssistantGapItemStateActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetState(): string {
    return AssistantGapItem::STATE_REVIEWED;
  }

  /**
   * {@inheritdoc}
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action moved gap item to reviewed.';
  }

}
