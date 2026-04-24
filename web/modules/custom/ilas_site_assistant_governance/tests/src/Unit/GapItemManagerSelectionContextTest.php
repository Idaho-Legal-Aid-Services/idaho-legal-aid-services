<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant_governance\Service\GapItemIdentityBuilder;
use Drupal\ilas_site_assistant_governance\Service\GapItemManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests selection-derived topic context for gap items.
 */
#[Group('ilas_site_assistant_governance')]
final class GapItemManagerSelectionContextTest extends TestCase {

  /**
   * Branch selections must carry topic and service-area context forward.
   */
  public function testBranchSelectionResolvesTopicAndServiceArea(): void {
    $topic_resolver = $this->createMock(TopicResolver::class);
    $topic_resolver->expects($this->once())
      ->method('resolveFromText')
      ->with('family divorce custody')
      ->willReturn([
        'id' => 22,
        'service_areas' => [
          ['id' => 7, 'name' => 'Family'],
        ],
      ]);

    $manager = $this->managerWithTopicResolver($topic_resolver);
    $context = $manager->exposeDerivedTopicContext('family divorce custody', [
      'assignment_source' => 'selection',
      'active_selection_key' => 'forms_family',
      'selection_query_label' => 'family divorce custody',
      'selection_label' => 'Family & Custody',
    ]);

    $this->assertSame(22, $context['topic_id']);
    $this->assertSame(7, $context['service_area_id']);
    $this->assertSame('selection', $context['assignment_source']);
    $this->assertSame(80, $context['confidence']);
  }

  /**
   * Generic top-level selections must not fabricate a topic assignment.
   */
  public function testGenericSelectionDoesNotFabricateTopic(): void {
    $topic_resolver = $this->createMock(TopicResolver::class);
    $topic_resolver->expects($this->once())
      ->method('resolveFromText')
      ->with('Forms')
      ->willReturn(NULL);

    $manager = $this->managerWithTopicResolver($topic_resolver);
    $context = $manager->exposeDerivedTopicContext('forms', [
      'assignment_source' => 'selection',
      'active_selection_key' => 'forms',
      'selection_label' => 'Forms',
      'selection_query_label' => '',
    ]);

    $this->assertNull($context['topic_id']);
    $this->assertNull($context['service_area_id']);
    $this->assertSame('selection', $context['assignment_source']);
    $this->assertNull($context['confidence']);
  }

  /**
   * Builds a testable manager subclass.
   */
  private function managerWithTopicResolver(TopicResolver $topic_resolver): object {
    return new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
      $topic_resolver,
      new GapItemIdentityBuilder(),
    ) extends GapItemManager {

      /**
       * Exposes the protected deriveTopicContext() method for unit coverage.
       */
      public function exposeDerivedTopicContext(string $query, array $context): array {
        return $this->deriveTopicContext($query, $context);
      }

    };
  }

}
