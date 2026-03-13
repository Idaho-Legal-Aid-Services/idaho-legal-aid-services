<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Commands\DisambiguationReviewCommands;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the disambiguation review Drush command.
 */
#[Group('ilas_site_assistant')]
class DisambiguationReviewCommandTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Tests the command class and method exist.
   */
  public function testCommandClassAndMethodExist(): void {
    $this->assertTrue(class_exists(DisambiguationReviewCommands::class));
    $this->assertTrue(method_exists(DisambiguationReviewCommands::class, 'disambiguationReview'));
    $this->assertTrue(method_exists(DisambiguationReviewCommands::class, 'buildReviewReport'));
  }

  /**
   * Tests the report groups family, pair, and bucket aggregates.
   */
  public function testBuildReviewReportReturnsStructuredAggregates(): void {
    $analyticsLogger = $this->createMock(AnalyticsLogger::class);
    $analyticsLogger->method('getEventTotals')
      ->willReturnCallback(function (string $eventType): array {
        return match ($eventType) {
          'disambiguation_trigger' => [
            (object) ['event_value' => 'kind=family,name=generic_help', 'total' => 7],
            (object) ['event_value' => 'kind=pair,name=apply_for_help:services_overview', 'total' => 3],
          ],
          'ambiguity_bucket' => [
            (object) ['event_value' => 'family=generic_help,lang=en,len=1-24,pair=none', 'total' => 5],
          ],
          default => [],
        };
      });

    $command = new DisambiguationReviewCommands($analyticsLogger);
    $report = $command->buildReviewReport(30, 10);

    $this->assertSame(30, $report['days']);
    $this->assertSame('generic_help', $report['families'][0]['family']);
    $this->assertSame(7, $report['families'][0]['count']);
    $this->assertSame('apply_for_help:services_overview', $report['pairs'][0]['pair']);
    $this->assertSame('family=generic_help,lang=en,len=1-24,pair=none', $report['buckets'][0]['bucket']);
  }

  /**
   * Tests the Drush services entry registers the command.
   */
  public function testDrushServicesEntryExists(): void {
    $servicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $this->assertFileExists($servicesPath);

    $services = Yaml::parseFile($servicesPath);
    $this->assertArrayHasKey('services', $services);
    $this->assertArrayHasKey('ilas_site_assistant.disambiguation_review_commands', $services['services']);

    $entry = $services['services']['ilas_site_assistant.disambiguation_review_commands'];
    $this->assertSame(
      '\\' . DisambiguationReviewCommands::class,
      $entry['class'],
    );
  }

}
