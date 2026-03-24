<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for the Site Assistant dedicated page.
 */
class AssistantPageController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AssistantPageController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Renders the Site Assistant page.
   *
   * @return array
   *   A render array.
   */
  public function page() {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    // Block access when the dedicated assistant page is disabled.
    if (!$config->get('enable_assistant_page')) {
      throw new AccessDeniedHttpException();
    }
    $canonical_urls = ilas_site_assistant_get_canonical_urls();

    // Build suggestions for quick actions.
    $suggestions = [
      [
        'label' => $this->t('Find a Form'),
        'icon' => 'file-alt',
        'action' => 'forms',
      ],
      [
        'label' => $this->t('Find a Guide'),
        'icon' => 'book',
        'action' => 'guides',
      ],
      [
        'label' => $this->t('Browse FAQs'),
        'icon' => 'question-circle',
        'action' => 'faq',
      ],
      [
        'label' => $this->t('Apply for Help'),
        'icon' => 'hands-helping',
        'action' => 'apply',
      ],
      [
        'label' => $this->t('Call Hotline'),
        'icon' => 'phone',
        'action' => 'hotline',
        'url' => $canonical_urls['hotline'],
      ],
      [
        'label' => $this->t('Legal Topics'),
        'icon' => 'balance-scale',
        'action' => 'topics',
      ],
    ];

    $build = [
      '#theme' => 'ilas_site_assistant_page',
      '#disclaimer' => $config->get('disclaimer_text'),
      '#suggestions' => $suggestions,
      '#config' => [
        'welcome_message' => $config->get('welcome_message'),
        'canonical_urls' => $canonical_urls,
      ],
      '#attached' => [
        'library' => ['ilas_site_assistant/page'],
        'drupalSettings' => [
          // NOTE: csrfToken intentionally omitted — widget fetches from
          // /assistant/api/session/bootstrap before the first chat POST.
          // This avoids stale tokens in cached pages.
          'ilasSiteAssistant' => [
            'apiBase' => '/assistant/api',
            'disclaimer' => $config->get('disclaimer_text'),
            'enableFaq' => $config->get('enable_faq'),
            'enableResources' => $config->get('enable_resources'),
            'canonicalUrls' => $canonical_urls,
            'welcomeMessage' => $config->get('welcome_message'),
            'pageMode' => TRUE,
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['config:ilas_site_assistant.settings'],
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

}
