<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Lightweight review workflow actions for assistant gap items.
 */
class GapReviewWorkflowController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected AccountProxyInterface $currentAccount,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
    );
  }

  /**
   * Starts reviewer work on a new gap item and redirects to the review screen.
   */
  public function startReview(AssistantGapItem $assistant_gap_item): RedirectResponse {
    $acting_uid = (int) $this->currentAccount->id();

    if ($acting_uid > 0 && $assistant_gap_item->getReviewState() === AssistantGapItem::STATE_NEW) {
      $assistant_gap_item->set('assigned_uid', $acting_uid);
      $assistant_gap_item->applyTransition(AssistantGapItem::STATE_NEEDS_REVIEW, $acting_uid);
      $assistant_gap_item->setNewRevision(TRUE);
      $assistant_gap_item->setRevisionUserId($acting_uid);
      $assistant_gap_item->setRevisionLogMessage('Reviewer started work on the gap item from the queue.');
      $assistant_gap_item->save();
      $this->messenger()->addStatus($this->t('Review started for %label.', ['%label' => $assistant_gap_item->label()]));
    }

    return $this->redirect('entity.assistant_gap_item.canonical', ['assistant_gap_item' => $assistant_gap_item->id()]);
  }

  /**
   * Access callback for the start-review route.
   */
  public function startReviewAccess(AssistantGapItem $assistant_gap_item, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      $assistant_gap_item->access('update', $account)
      && $assistant_gap_item->getReviewState() === AssistantGapItem::STATE_NEW
      && AssistantGapItem::canTransition(AssistantGapItem::STATE_NEW, AssistantGapItem::STATE_NEEDS_REVIEW, $account)
    )->cachePerPermissions()->addCacheableDependency($assistant_gap_item);
  }

}
