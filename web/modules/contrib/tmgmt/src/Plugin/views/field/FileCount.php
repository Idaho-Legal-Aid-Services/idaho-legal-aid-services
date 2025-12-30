<?php

namespace Drupal\tmgmt\Plugin\views\field;

use Drupal\Core\Session\AccountInterface;
use Drupal\views\ResultRow;

/**
 * Field handler which shows the file count for a job or job item.
 *
 * @ViewsField("tmgmt_filecount")
 */
class FileCount extends StatisticsBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    return $entity->getFileCount();
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    if (!\Drupal::service('plugin.manager.tmgmt.translator')->supportsFiles()) {
      return FALSE;
    }
    return parent::access($account);
  }

}
