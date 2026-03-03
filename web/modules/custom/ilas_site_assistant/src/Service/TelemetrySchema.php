<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Single source of truth for telemetry field names.
 *
 * Constants are used by Langfuse metadata, Sentry tags, and Drupal log
 * context to ensure consistent field naming across all output paths.
 */
final class TelemetrySchema {

  const FIELD_INTENT = 'intent';
  const FIELD_SAFETY_CLASS = 'safety_class';
  const FIELD_FALLBACK_PATH = 'fallback_path';
  const FIELD_REQUEST_ID = 'request_id';
  const FIELD_ENV = 'env';

  /**
   * All required telemetry field names.
   */
  const REQUIRED_FIELDS = [
    self::FIELD_INTENT,
    self::FIELD_SAFETY_CLASS,
    self::FIELD_FALLBACK_PATH,
    self::FIELD_REQUEST_ID,
    self::FIELD_ENV,
  ];

  /**
   * Builds a normalized telemetry context array from pipeline state.
   *
   * @param string|null $intent
   *   The resolved intent type, or NULL if pre-intent.
   * @param string|null $safety_class
   *   The safety classification class, or NULL if not classified.
   * @param string|null $fallback_path
   *   The fallback path taken, or NULL if none.
   * @param string|null $request_id
   *   The request correlation ID.
   * @param string|null $env
   *   The environment name, or NULL to auto-detect.
   *
   * @return array
   *   Associative array with all REQUIRED_FIELDS as keys.
   */
  public static function normalize(
    ?string $intent = NULL,
    ?string $safety_class = NULL,
    ?string $fallback_path = NULL,
    ?string $request_id = NULL,
    ?string $env = NULL,
  ): array {
    return [
      self::FIELD_INTENT => $intent ?? 'unknown',
      self::FIELD_SAFETY_CLASS => $safety_class ?? 'safe',
      self::FIELD_FALLBACK_PATH => $fallback_path ?? 'none',
      self::FIELD_REQUEST_ID => $request_id ?? 'unknown',
      self::FIELD_ENV => $env ?? (getenv('PANTHEON_ENVIRONMENT') ?: 'local'),
    ];
  }

  /**
   * Builds structured Drupal log context with canonical telemetry keys.
   *
   * Preserves legacy placeholder aliases used by existing log message formats
   * so message strings remain unchanged while context keys are normalized.
   *
   * @param array $telemetry
   *   Telemetry values, typically from normalize().
   * @param array $extra
   *   Additional log context keys to merge.
   *
   * @return array
   *   Context array with canonical and legacy alias keys.
   */
  public static function toLogContext(array $telemetry, array $extra = []): array {
    $normalized = self::normalize(
      intent: isset($telemetry[self::FIELD_INTENT]) ? (string) $telemetry[self::FIELD_INTENT] : NULL,
      safety_class: isset($telemetry[self::FIELD_SAFETY_CLASS]) ? (string) $telemetry[self::FIELD_SAFETY_CLASS] : NULL,
      fallback_path: isset($telemetry[self::FIELD_FALLBACK_PATH]) ? (string) $telemetry[self::FIELD_FALLBACK_PATH] : NULL,
      request_id: isset($telemetry[self::FIELD_REQUEST_ID]) ? (string) $telemetry[self::FIELD_REQUEST_ID] : NULL,
      env: isset($telemetry[self::FIELD_ENV]) ? (string) $telemetry[self::FIELD_ENV] : NULL,
    );

    return array_merge(
      $normalized,
      [
        '@intent' => $normalized[self::FIELD_INTENT],
        '@safety' => $normalized[self::FIELD_SAFETY_CLASS],
        '@gate' => $normalized[self::FIELD_FALLBACK_PATH],
        '@request_id' => $normalized[self::FIELD_REQUEST_ID],
        '@env' => $normalized[self::FIELD_ENV],
      ],
      $extra,
    );
  }

}
