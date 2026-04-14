<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Applies the escalate-to-editor secondary flag.
 */
#[Action(
  id: 'assistant_gap_item_flag_escalate_to_editor_action',
  label: new TranslatableMarkup('Flag selected gap items as Escalate to editor'),
  type: 'assistant_gap_item',
)]
class FlagEscalateToEditor extends AssistantGapItemFlagActionBase {

  /**
   * Returns the secondary flag to apply.
   */
  protected function secondaryFlag(): string {
    return AssistantGapItem::FLAG_ESCALATE_TO_EDITOR;
  }

  /**
   * Returns the revision log message for the action.
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action flagged gap item as Escalate to editor.';
  }

}
