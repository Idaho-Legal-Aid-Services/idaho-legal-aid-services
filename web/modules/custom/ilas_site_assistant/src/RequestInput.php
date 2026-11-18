<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Reads caller-supplied request values without trusting their shape.
 *
 * Symfony's InputBag::get() throws a BadRequestException as soon as a value
 * is not scalar, before any cast or is_array() check in the calling code can
 * run. A request such as "?q[]=x" therefore became an uncaught exception
 * (and a Sentry event) instead of being handled as bad input, and branches
 * written to accept an array value could never be reached (Sentry PHP-C4).
 *
 * Every read of the query string or the form body in this module goes
 * through this class; RequestInputGuardTest fails on a direct ->get().
 *
 * No Drupal dependencies, so this is directly unit testable.
 */
final class RequestInput {

  /**
   * Returns a value as a string, or the default when it is absent or an array.
   */
  public static function string(ParameterBag $bag, string $key, string $default = ''): string {
    $value = $bag->all()[$key] ?? NULL;

    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * Returns a value as a string, or NULL when it is absent or an array.
   */
  public static function stringOrNull(ParameterBag $bag, string $key): ?string {
    $value = $bag->all()[$key] ?? NULL;

    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * Returns a value exactly as sent, including arrays.
   *
   * For the few fields that legitimately accept an array, and for checks
   * that must treat any non-empty value as present. The caller validates it.
   */
  public static function raw(ParameterBag $bag, string $key, mixed $default = NULL): mixed {
    return $bag->all()[$key] ?? $default;
  }

}
