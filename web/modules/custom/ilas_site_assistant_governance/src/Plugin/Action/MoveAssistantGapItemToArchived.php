<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;

/**
 * Moves assistant gap items to archived.
 */
#[Action(
  id: 'assistant_gap_item_to_archived_action',
  label: new TranslatableMarkup('Move selected gap items to archived'),
  confirm_form_route_name: 'ilas_site_assistant_governance.gap_bulk_archive_confirm',
  type: 'assistant_gap_item',
)]
class MoveAssistantGapItemToArchived extends AssistantGapItemDeferredCloseActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetState(): string {
    return AssistantGapItem::STATE_ARCHIVED;
  }

  /**
   * {@inheritdoc}
   */
  protected function revisionLogMessage(): string {
    return 'Bulk action moved gap item to archived.';
  }

}
