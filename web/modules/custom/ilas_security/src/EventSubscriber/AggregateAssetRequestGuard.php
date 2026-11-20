<?php

declare(strict_types=1);

namespace Drupal\ilas_security\EventSubscriber;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses aggregate CSS/JS requests that name extensions this site lacks.
 *
 * An aggregate URL carries the theme it was built for and the libraries it
 * is built from (?theme=...&include=...). When the file is not on disk, core
 * builds it from those values. It treats every library owner that is not an
 * installed module as a theme and asks for that theme's path, which raises
 * "The following theme is missing from the file system: <name>" for whatever
 * name the visitor put in the URL, and then answers 400 anyway.
 *
 * Crawlers replay such URLs from the site this one replaced, so the warning
 * appeared on live for modules and themes that were retired years ago. No
 * list of names can keep up with input a stranger chooses. This refuses the
 * request before core does any of that work: same 400, no warning.
 */
final class AggregateAssetRequestGuard implements EventSubscriberInterface {

  /**
   * The message of the refusal. Error reporting recognises a refusal by it.
   */
  public const REFUSAL_MESSAGE = 'The aggregate names a theme or library owner that is not installed on this site.';

  /**
   * Core's routes that build an aggregate on request.
   */
  private const ASSET_ROUTES = ['system.css_asset', 'system.js_asset'];

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (priority 32) has named the route, before anything
    // else acts on the request.
    return [KernelEvents::REQUEST => ['onRequest', 31]];
  }

  /**
   * Refuses an aggregate request for a theme or library owner we do not have.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!in_array($request->attributes->get(RouteObjectInterface::ROUTE_NAME), self::ASSET_ROUTES, TRUE)) {
      return;
    }

    $query = $request->query->all();

    // A missing theme is core's to report; one that is present must exist.
    if (array_key_exists('theme', $query) && !$this->isInstalledTheme($query['theme'])) {
      throw new BadRequestHttpException(self::REFUSAL_MESSAGE);
    }

    foreach (['include', 'exclude'] as $parameter) {
      if (!array_key_exists($parameter, $query)) {
        continue;
      }
      if (!is_string($query[$parameter])) {
        throw new BadRequestHttpException(self::REFUSAL_MESSAGE);
      }
      foreach (explode(',', UrlHelper::uncompressQueryParameter($query[$parameter])) as $library) {
        // "owner/name". A name without a slash is malformed, and core says
        // so itself.
        $owner = strstr($library, '/', TRUE);
        if ($owner !== FALSE && !$this->isInstalledOwner($owner)) {
          throw new BadRequestHttpException(self::REFUSAL_MESSAGE);
        }
      }
    }
  }

  /**
   * Returns TRUE for the name of an installed theme.
   */
  private function isInstalledTheme(mixed $name): bool {
    return is_string($name) && $name !== '' && $this->themeHandler->themeExists($name);
  }

  /**
   * Returns TRUE for something that can own a library on this site.
   */
  private function isInstalledOwner(string $owner): bool {
    return $owner === 'core'
      || $this->moduleHandler->moduleExists($owner)
      || $this->themeHandler->themeExists($owner);
  }

}
