<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Applies the possible taxonomy gap secondary flag.
 */
#[Action(
  id: 'assistant_gap_item_flag_possible_taxonomy_gap_action',
  label: new TranslatableMarkup('Flag selected gap items as Possible taxonomy gap'),
  type: 'assistant_gap_item',
)]
class FlagPossibleTaxonomyGap extends AssistantGapItemFlagActionBase {

  /**
   * Returns the secondary flag to apply.
   */
  protected function secondaryFlag(): string {
    return AssistantGapItem::FLAG_POSSIBLE_TAXONOMY_GAP;
  }

  /**
   * Returns the revision log message for the action.
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action flagged gap item as Possible taxonomy gap.';
  }

}
