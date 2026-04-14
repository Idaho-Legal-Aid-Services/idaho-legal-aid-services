<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Moves assistant gap items to resolved.
 */
#[Action(
  id: 'assistant_gap_item_to_resolved_action',
  label: new TranslatableMarkup('Move selected gap items to resolved'),
  type: 'assistant_gap_item',
)]
class MoveAssistantGapItemToResolved extends AssistantGapItemStateActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetState(): string {
    return AssistantGapItem::STATE_RESOLVED;
  }

  /**
   * {@inheritdoc}
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action moved gap item to resolved.';
  }

}
