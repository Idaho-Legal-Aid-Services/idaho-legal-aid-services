<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\ilas_site_assistant\Service\RetrievalAugmenter;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Container wiring and install-default coverage for RetrievalAugmenter.
 *
 * The unit suite covers augmentation logic with mocks; this kernel test
 * proves the service resolves from the real container, the install config
 * ships retrieve-first enabled for the template-only response types, and
 * augmentation fails open (response unchanged, no exception) when the
 * search backends are unavailable.
 */
#[Group('ilas_site_assistant')]
final class RetrievalAugmenterWiringKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant']);
  }

  /**
   * The service resolves from the container with install-default gating.
   */
  public function testServiceResolvesWithInstallDefaults(): void {
    $augmenter = $this->container->get('ilas_site_assistant.retrieval_augmenter');
    $this->assertInstanceOf(RetrievalAugmenter::class, $augmenter);

    foreach (['navigation', 'topic', 'service_area', 'disambiguation', 'eligibility', 'apply_cta', 'services', 'escalation', 'high_risk', 'office_location'] as $type) {
      $this->assertTrue(
        $augmenter->applies(['type' => $type]),
        "Install defaults must make response type '{$type}' eligible for retrieve-first",
      );
    }

    // Retrieval-backed types must stay out of scope: they already retrieve.
    foreach (['faq', 'resources', 'form_finder_clarify'] as $type) {
      $this->assertFalse($augmenter->applies(['type' => $type]));
    }

    // Responses that already carry results are never re-augmented.
    $this->assertFalse($augmenter->applies([
      'type' => 'navigation',
      'results' => [['id' => 'r1', 'url' => 'https://idaholegalaid.org/x']],
    ]));
  }

  /**
   * Augmentation fails open when retrieval backends are unavailable.
   */
  public function testAugmentFailsOpenWithoutSearchBackends(): void {
    /** @var \Drupal\ilas_site_assistant\Service\RetrievalAugmenter $augmenter */
    $augmenter = $this->container->get('ilas_site_assistant.retrieval_augmenter');

    $response = [
      'type' => 'navigation',
      'message' => "Here's our housing legal help page.",
    ];
    $augmented = $augmenter->augment($response, ['type' => 'navigation'], 'eviction notice help');

    $this->assertSame($response['message'], $augmented['message']);
    $this->assertArrayNotHasKey('retrieval_supplemented', $augmented);
  }

  /**
   * Escalation augmentation is additive and never mutates safety copy.
   */
  public function testEscalationAugmentationPreservesSafetyCopy(): void {
    /** @var \Drupal\ilas_site_assistant\Service\RetrievalAugmenter $augmenter */
    $augmenter = $this->container->get('ilas_site_assistant.retrieval_augmenter');

    $response_data = [
      'type' => 'escalation',
      'message' => 'Please call our Legal Advice Line immediately for urgent help. If you are in danger, call 911.',
      'actions' => [['label' => 'Call 911 (if in danger)', 'url' => 'tel:911']],
    ];

    $augmented = $augmenter->augmentEscalation($response_data, [
      'class' => 'eviction_emergency',
      'requires_refusal' => FALSE,
    ]);

    $this->assertSame($response_data['message'], $augmented['message']);
    $this->assertSame($response_data['actions'], $augmented['actions']);

    // Refusal classes stay retrieval-free even when augmentation is enabled.
    $refusal = ['type' => 'refusal', 'message' => 'I cannot help with that.'];
    $this->assertSame($refusal, $augmenter->augmentEscalation($refusal, [
      'class' => 'wrongdoing',
      'requires_refusal' => TRUE,
    ]));
  }

}
