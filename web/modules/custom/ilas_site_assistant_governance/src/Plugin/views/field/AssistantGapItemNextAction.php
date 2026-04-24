<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\views\field;

use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays the next reviewer action for a gap item.
 */
#[ViewsField('assistant_gap_item_next_action')]
final class AssistantGapItemNextAction extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {}

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity ?? NULL;
    if (!$entity instanceof AssistantGapItem) {
      return '';
    }

    return $this->sanitizeValue($entity->getNextActionLabel());
  }

}
