<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\NavigationIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spanish "dónde están" phrasing counts as navigation and resolves offices.
 *
 * Before this coverage the Spanish navigation pattern only accepted the
 * singular "está", so "donde estan sus oficinas" (4 words) fell under the
 * no-nav-phrasing word limit and the "oficinas" page alias was unreachable.
 */
#[Group('ilas_site_assistant')]
final class NavigationIntentSpanishTest extends TestCase {

  /**
   * Plural and accented office questions resolve the offices page.
   */
  #[DataProvider('spanishOfficeNavigationQuestions')]
  public function testSpanishOfficeQuestionsResolveOfficesPage(string $message): void {
    $navigation = new NavigationIntent();

    $this->assertTrue($navigation->isNavigationQuery($message), "Nav phrasing not detected for: $message");

    $result = $navigation->detect($message);
    $this->assertIsArray($result, "No navigation match for: $message");
    $this->assertSame('offices', $result['top_match']['page_key'] ?? NULL);
    $this->assertSame('/contact/offices', $result['top_match']['url'] ?? NULL);
    $this->assertTrue($result['has_nav_phrasing'] ?? FALSE);
    $this->assertGreaterThanOrEqual(0.65, (float) ($result['confidence'] ?? 0));
  }

  /**
   * Spanish navigation phrasings for the offices page.
   */
  public static function spanishOfficeNavigationQuestions(): array {
    return [
      'plural sus' => ['donde estan sus oficinas'],
      'plural accented' => ['dónde están las oficinas'],
      'queda singular' => ['donde queda su oficina'],
    ];
  }

  /**
   * A Spanish topic request is not a navigation query.
   */
  public function testSpanishTopicRequestIsNotNavigation(): void {
    $navigation = new NavigationIntent();

    $this->assertNull($navigation->detect('Necesito ayuda con un desalojo'));
  }

}
