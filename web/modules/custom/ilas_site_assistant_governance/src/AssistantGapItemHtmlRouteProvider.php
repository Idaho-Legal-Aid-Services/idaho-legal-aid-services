<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\ilas_site_assistant_governance\Controller\AssistantGapItemReviewController;
use Symfony\Component\Routing\Route;

/**
 * Provides admin HTML routes for assistant gap items.
 */
final class AssistantGapItemHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type) {
    if (!$entity_type->hasLinkTemplate('canonical')) {
      return NULL;
    }

    $entity_type_id = $entity_type->id();
    $route = new Route($entity_type->getLinkTemplate('canonical'));
    $route
      ->addDefaults([
        '_controller' => AssistantGapItemReviewController::class . '::review',
        '_title_callback' => AssistantGapItemReviewController::class . '::title',
      ])
      ->setRequirement('_entity_access', "{$entity_type_id}.view")
      ->setOption('parameters', [
        $entity_type_id => ['type' => 'entity:' . $entity_type_id],
      ])
      ->setOption('_admin_route', TRUE);

    if ($this->getEntityTypeIdKeyType($entity_type) === 'integer') {
      $route->setRequirement($entity_type_id, '\d+');
    }

    return $route;
  }

}
