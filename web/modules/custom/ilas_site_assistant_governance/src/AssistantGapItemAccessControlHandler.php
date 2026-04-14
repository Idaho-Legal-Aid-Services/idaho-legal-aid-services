<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for assistant gap items.
 */
class AssistantGapItemAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer assistant gap items')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view assistant gap items')->cachePerPermissions(),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit assistant gap items')->cachePerPermissions(),
      'delete' => AccessResult::forbidden()->cachePerPermissions(),
      'view revision' => AccessResult::allowedIf(
        $account->hasPermission('view assistant gap items') && $account->hasPermission('view assistant gap item revisions')
      )->cachePerPermissions(),
      'revert revision' => AccessResult::allowedIfHasPermission($account, 'revert assistant gap item revisions')->cachePerPermissions(),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete assistant gap item revisions')->cachePerPermissions(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer assistant gap items')
      ->cachePerPermissions();
  }

}
