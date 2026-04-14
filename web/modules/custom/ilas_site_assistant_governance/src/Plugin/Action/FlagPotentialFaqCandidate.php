<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Applies the potential FAQ candidate secondary flag.
 */
#[Action(
  id: 'assistant_gap_item_flag_potential_faq_candidate_action',
  label: new TranslatableMarkup('Flag selected gap items as Potential FAQ Candidate'),
  type: 'assistant_gap_item',
)]
class FlagPotentialFaqCandidate extends AssistantGapItemFlagActionBase {

  /**
   * Returns the secondary flag to apply.
   */
  protected function secondaryFlag(): string {
    return AssistantGapItem::FLAG_POTENTIAL_FAQ_CANDIDATE;
  }

  /**
   * Returns the revision log message for the action.
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action flagged gap item as Potential FAQ Candidate.';
  }

}
