<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for assistant gap-item state transition actions.
 */
abstract class AssistantGapItemStateActionBase extends ActionBase implements ContainerFactoryPluginInterface {

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
   * Returns the target state for the action.
   */
  abstract protected function targetState(): string;

  /**
   * Returns the revision log message for the action.
   */
  abstract protected function revisionLogMessage(): string;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof AssistantGapItem) {
      return;
    }

    if ($entity->getReviewState() === $this->targetState()) {
      return;
    }

    $entity->applyTransition($this->targetState(), (int) $this->currentUser->id());
    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId((int) $this->currentUser->id());
    $entity->setRevisionLogMessage($this->revisionLogMessage());
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?? $this->currentUser;
    $allowed = $object instanceof AssistantGapItem
      && AssistantGapItem::canTransition($object->getReviewState(), $this->targetState(), $account);

    $result = AccessResult::allowedIf($allowed)->cachePerPermissions();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
