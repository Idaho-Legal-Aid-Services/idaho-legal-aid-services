<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Reopens assistant gap items.
 */
#[Action(
  id: 'assistant_gap_item_reopen_action',
  label: new TranslatableMarkup('Reopen selected gap items'),
  type: 'assistant_gap_item',
)]
class ReopenAssistantGapItem extends AssistantGapItemStateActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetState(): string {
    return AssistantGapItem::STATE_NEEDS_REVIEW;
  }

  /**
   * {@inheritdoc}
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action reopened gap item.';
  }

}
