<?php

namespace Drupal\tmgmt\Entity\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides the views data for the message entity type.
 */
class JobListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->isSubmittable() && $entity->access('submit')) {
      $operations['submit'] = array(
        'url' => $this->ensureDestination($entity->toUrl()),
        'title' => t('Submit'),
        'weight' => -10,
      );
    }
    else {
      $operations['manage'] = array(
        'url' => $this->ensureDestination($entity->toUrl()),
        'title' => t('Manage'),
        'weight' => -10,
      );
    }
    if ($entity->isAbortable() && $entity->access('submit')) {
      $operations['abort'] = array(
        'url' => $this->ensureDestination($entity->toUrl('abort-form')),
        'title' => t('Abort'),
        'weight' => 10,
      );
    }
    return $operations;
  }

}
