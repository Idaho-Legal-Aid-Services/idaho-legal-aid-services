<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Applies the duplicate issue secondary flag.
 */
#[Action(
  id: 'assistant_gap_item_flag_duplicate_issue_action',
  label: new TranslatableMarkup('Flag selected gap items as Duplicate issue'),
  type: 'assistant_gap_item',
)]
class FlagDuplicateIssue extends AssistantGapItemFlagActionBase {

  /**
   * Returns the secondary flag to apply.
   */
  protected function secondaryFlag(): string {
    return AssistantGapItem::FLAG_DUPLICATE_ISSUE;
  }

  /**
   * Returns the revision log message for the action.
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action flagged gap item as Duplicate issue.';
  }

}
