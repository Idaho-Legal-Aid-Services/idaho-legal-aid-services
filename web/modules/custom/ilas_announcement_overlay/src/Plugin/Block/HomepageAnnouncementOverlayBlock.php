<?php

namespace Drupal\ilas_announcement_overlay\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Homepage Announcement Overlay block.
 *
 * @Block(
 *   id = "homepage_announcement_overlay",
 *   admin_label = @Translation("Homepage Announcement Overlay (ILAS)"),
 *   category = @Translation("ILAS Custom")
 * )
 */
class HomepageAnnouncementOverlayBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The path matcher service.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * Constructs a HomepageAnnouncementOverlayBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PathMatcherInterface $path_matcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->pathMatcher = $path_matcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('path.matcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Only display on the front page.
    if (!$this->pathMatcher->isFrontPage()) {
      return [];
    }

    // Find the active homepage announcement.
    $announcement = $this->getActiveAnnouncement();
    if (!$announcement) {
      return [];
    }

    // Extract field values.
    $button_label = $announcement->get('field_announcement_button_label')->value ?? 'Important Information';
    $title = $announcement->get('field_announcement_title')->value ?? '';
    $subheader = $announcement->get('field_announcement_subheader')->value ?? '';
    $body = $announcement->get('field_announcement_body')->getValue();
    $cta_label = $announcement->get('field_announcement_cta_label')->value ?? 'Learn More';
    $cta_new_tab = (bool) $announcement->get('field_announcement_cta_new_tab')->value;

    // Get the CTA link URL.
    $cta_url = '';
    $cta_link_field = $announcement->get('field_announcement_cta_link');
    if (!$cta_link_field->isEmpty()) {
      $cta_url = $cta_link_field->first()->getUrl()->toString();
    }

    // Generate a unique ID for this announcement.
    $block_id = 'announcement-overlay-' . $announcement->id();

    return [
      '#theme' => 'block__homepage_announcement_overlay',
      '#button_label' => $button_label,
      '#title' => $title,
      '#subheader' => $subheader,
      '#body' => $body,
      '#cta_label' => $cta_label,
      '#cta_url' => $cta_url,
      '#cta_new_tab' => $cta_new_tab,
      '#block_id' => $block_id,
      '#attached' => [
        'library' => [
          'ilas_announcement_overlay/announcement-overlay',
        ],
      ],
    ];
  }

  /**
   * Get the active homepage announcement block content.
   *
   * @return \Drupal\block_content\Entity\BlockContent|null
   *   The block content entity or NULL if none found.
   */
  protected function getActiveAnnouncement() {
    $storage = $this->entityTypeManager->getStorage('block_content');

    // Query for published homepage_announcement blocks with show_announcement enabled.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'homepage_announcement')
      ->condition('status', 1)
      ->condition('field_show_announcement', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1);

    $ids = $query->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $storage->load(reset($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Cache per URL path (so front page check works).
    return Cache::mergeContexts(parent::getCacheContexts(), ['url.path']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Invalidate when any block_content of type homepage_announcement changes.
    return Cache::mergeTags(parent::getCacheTags(), ['block_content_list:homepage_announcement']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Allow caching; the cache tags will handle invalidation.
    return Cache::PERMANENT;
  }

}
