<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns selected gap items to the current reviewer.
 */
#[Action(
  id: 'assistant_gap_item_assign_to_current_user_action',
  label: new TranslatableMarkup('Assign selected gap items to me'),
  type: 'assistant_gap_item',
)]
class AssignAssistantGapItemToCurrentUser extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof AssistantGapItem || (int) $this->currentUser->id() <= 0) {
      return;
    }

    $acting_uid = (int) $this->currentUser->id();
    $entity->set('assigned_uid', $acting_uid);
    if (
      $entity->getReviewState() === AssistantGapItem::STATE_NEW &&
      AssistantGapItem::canTransition(AssistantGapItem::STATE_NEW, AssistantGapItem::STATE_NEEDS_REVIEW, $this->currentUser)
    ) {
      $entity->applyTransition(AssistantGapItem::STATE_NEEDS_REVIEW, $acting_uid);
      $entity->setRevisionLogMessage('Bulk action assigned the gap item to the current reviewer and started review.');
    }
    else {
      $entity->setRevisionLogMessage('Bulk action assigned gap item to the current reviewer.');
    }
    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId($acting_uid);
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?? $this->currentUser;
    $allowed = $object instanceof AssistantGapItem
      && ($account->hasPermission('edit assistant gap items') || $account->hasPermission('administer assistant gap items'));

    $result = AccessResult::allowedIf($allowed)->cachePerPermissions();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
