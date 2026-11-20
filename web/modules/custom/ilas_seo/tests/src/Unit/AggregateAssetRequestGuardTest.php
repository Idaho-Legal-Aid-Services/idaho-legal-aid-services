<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Unit;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\ilas_seo\EventSubscriber\AggregateAssetRequestGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the guard on requests that make core build a CSS or JS aggregate.
 *
 * Crawlers replay aggregate URLs of the site this one replaced. Core built
 * them from the names in the URL and warned, on live, that themes such as
 * dlaw_glossary and rate were missing from the file system (Sentry PHP-BD).
 */
#[Group('ilas_seo')]
final class AggregateAssetRequestGuardTest extends TestCase {

  /**
   * Modules installed on the imaginary site.
   */
  private const MODULES = ['system', 'views', 'paragraphs', 'ilas_site_assistant', 'ilas_seo', 'raven'];

  /**
   * Themes installed on the imaginary site.
   */
  private const THEMES = ['b5subtheme', 'bootstrap5', 'gin'];

  /**
   * Runs the guard on a request for the given route and query.
   *
   * @param array<string, mixed> $query
   *   The query parameters.
   */
  private function handle(array $query, string $route = 'system.js_asset'): void {
    $modules = $this->createStub(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static fn (string $name): bool => in_array($name, self::MODULES, TRUE));
    $themes = $this->createStub(ThemeHandlerInterface::class);
    $themes->method('themeExists')->willReturnCallback(static fn (string $name): bool => in_array($name, self::THEMES, TRUE));

    $request = Request::create('/sites/default/files/js/js_abc.js', 'GET', $query);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

    (new AggregateAssetRequestGuard($modules, $themes))->onRequest($event);
  }

  /**
   * Builds the query of an aggregate URL the way core does.
   *
   * @return array<string, string>
   *   The query parameters.
   */
  private static function query(string $theme, string $include, ?string $exclude = NULL): array {
    $query = [
      'delta' => '0',
      'language' => 'en',
      'theme' => $theme,
      'include' => UrlHelper::compressQueryParameter($include),
    ];
    if ($exclude !== NULL) {
      $query['exclude'] = UrlHelper::compressQueryParameter($exclude);
    }
    return $query;
  }

  /**
   * An aggregate of this site's own theme, modules and core is let through.
   */
  public function testOwnAggregateIsLetThrough(): void {
    $this->handle(self::query(
      'b5subtheme',
      'system/base,core/drupal,core/components.b5subtheme--card,views/views.module,b5subtheme/global-styling,bootstrap5/base,ilas_site_assistant/widget,ilas_seo/analytics,raven/browser',
      'paragraphs/drupal.paragraphs.unpublished',
    ));
    $this->handle(self::query('gin', 'system/admin,gin/gin'), 'system.css_asset');
    $this->addToAssertionCount(2);
  }

  /**
   * Returns aggregate queries that name something this site does not have.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   The cases.
   */
  public static function refusedQueries(): array {
    return [
      'retired theme (live, 2026-11-19)' => [self::query('dlaw_dashboard', 'system/base')],
      'retired module owning a library (live, 2026-11-20)' => [self::query('b5subtheme', 'system/base,rate/rate.widget')],
      'retired module with our theme' => [self::query('b5subtheme', 'dlaw_feedback/form,system/base')],
      'name on no list' => [self::query('b5subtheme', 'never_heard_of_it/thing')],
      'unknown owner only in exclude' => [self::query('b5subtheme', 'system/base', 'google_analytics/google_analytics')],
      'uncompressed stale include' => [['theme' => 'b5subtheme', 'include' => 'system/base,addtoany/addtoany']],
      'empty theme' => [self::query('', 'system/base')],
      'theme as an array' => [['theme' => ['b5subtheme'], 'include' => UrlHelper::compressQueryParameter('system/base')]],
      'include as an array' => [['theme' => 'b5subtheme', 'include' => ['system/base']]],
      'a module is not a theme' => [self::query('views', 'system/base')],
    ];
  }

  /**
   * A theme or library owner this site does not have is refused with a 400.
   *
   * @param array<string, mixed> $query
   *   The query parameters.
   */
  #[DataProvider('refusedQueries')]
  public function testUnknownThemeOrOwnerIsRefused(array $query): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage(AggregateAssetRequestGuard::REFUSAL_MESSAGE);
    $this->handle($query);
  }

  /**
   * What core already reports itself is left to core.
   */
  public function testMalformedRequestsAreLeftToCore(): void {
    // No theme, no include, and a library name without a slash each get
    // core's own 400 with its own message.
    $this->handle(['delta' => '0', 'language' => 'en']);
    $this->handle(['theme' => 'b5subtheme']);
    $this->handle(self::query('b5subtheme', 'not-a-library-name'));
    $this->addToAssertionCount(3);
  }

  /**
   * Requests for anything else are not looked at.
   */
  public function testOtherRoutesAreIgnored(): void {
    $this->handle(self::query('dlaw_dashboard', 'rate/rate.widget'), 'entity.node.canonical');
    $this->handle(['theme' => 'dlaw_dashboard'], 'search.view');
    $this->addToAssertionCount(2);
  }

  /**
   * The guard runs once the route is known and before the controller.
   */
  public function testRunsAfterTheRouter(): void {
    $events = AggregateAssetRequestGuard::getSubscribedEvents();

    $this->assertSame(['onRequest', 31], $events[KernelEvents::REQUEST]);
  }

}
