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
use Drupal\ilas_site_assistant_governance\Service\LegalHoldLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Releases a legal hold on selected assistant gap items.
 */
#[Action(
  id: 'assistant_gap_item_release_legal_hold_action',
  label: new TranslatableMarkup('Release legal hold on selected gap items'),
  type: 'assistant_gap_item',
)]
class ReleaseAssistantGapItemLegalHold extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccountProxyInterface $currentUser,
    protected LegalHoldLogger $legalHoldLogger,
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
      $container->get('ilas_site_assistant_governance.legal_hold_logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof AssistantGapItem || !(bool) $entity->get('is_held')->value) {
      return;
    }

    $acting_uid = (int) $this->currentUser->id();

    $entity->setHoldState(FALSE, NULL, $acting_uid);
    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId($acting_uid);
    $entity->setRevisionLogMessage('Bulk action released the legal hold on the gap item.');
    $entity->save();

    $this->legalHoldLogger->recordRelease('assistant_gap_item', (string) $entity->id(), $acting_uid);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?? $this->currentUser;
    $allowed = $object instanceof AssistantGapItem
      && ($account->hasPermission('release legal hold on assistant records') || $account->hasPermission('administer assistant gap items'));

    $result = AccessResult::allowedIf($allowed)->cachePerPermissions();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
