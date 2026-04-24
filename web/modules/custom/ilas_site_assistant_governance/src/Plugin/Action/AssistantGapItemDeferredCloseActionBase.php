<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Plugin\Action;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for gap-item bulk close actions that require disposition data.
 */
abstract class AssistantGapItemDeferredCloseActionBase extends AssistantGapItemStateActionBase {

  public const TEMPSTORE_COLLECTION = 'assistant_gap_item_bulk_disposition';

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    \Drupal\Core\Session\AccountProxyInterface $currentUser,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
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
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof AssistantGapItem) {
      $this->executeMultiple([$entity]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities): void {
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }

    $ids = [];
    foreach ($entities as $entity) {
      if ($entity instanceof AssistantGapItem && (int) $entity->id() > 0) {
        $ids[] = (int) $entity->id();
      }
    }

    if ($ids === []) {
      return;
    }

    $this->tempStoreFactory
      ->get(self::TEMPSTORE_COLLECTION)
      ->set($this->getPluginId(), array_values(array_unique($ids)));
  }

}
