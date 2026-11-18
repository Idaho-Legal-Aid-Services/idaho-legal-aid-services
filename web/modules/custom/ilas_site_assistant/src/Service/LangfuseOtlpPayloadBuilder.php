<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Converts a tracer batch into an OTLP/HTTP JSON body for Langfuse.
 *
 * Langfuse Cloud removed the v3 batch API (POST /api/public/ingestion) for
 * trace and observation events on 2026-11-16; it now answers HTTP 410
 * LANGFUSE_V3_API_REMOVED. The only write path for traces is OpenTelemetry
 * (POST /api/public/otel/v1/traces).
 *
 * LangfuseTracer still records the request as trace-create / span-create /
 * generation-create / event-create events, and that batch is what sits in the
 * export queue. This class translates it at send time, so the tracer, the
 * queue item shape and items already enqueued are unaffected.
 *
 * Mapping (verified against Langfuse Cloud on 2026-11-18):
 * - The trace becomes a root span. OTLP has no trace object; Langfuse derives
 *   the trace from its spans and reads the trace input/output from the root
 *   observation.
 * - Trace ids are the request UUID without hyphens (32 hex). Span ids are the
 *   first 16 hex of the observation UUID. The root span id is derived from the
 *   trace id, so an event sent later for the same trace (feedback) can attach
 *   to the root span without looking anything up.
 * - Trace-level attributes are repeated on every span: on the v4 data model an
 *   attribute present only on the root span cannot be used to filter or
 *   aggregate child observations.
 * - Events are zero-duration spans with langfuse.observation.type = event.
 *
 * Pure: no I/O, no Drupal services.
 */
final class LangfuseOtlpPayloadBuilder {

  /**
   * Builds the OTLP JSON body for one queued batch.
   *
   * @param array<int, mixed> $batch
   *   Tracer events, each ['type' => string, 'body' => array, ...].
   * @param string $environment
   *   Langfuse environment name; falls back to the trace metadata.
   *
   * @return array{resourceSpans: array<int, array<string, mixed>>}
   *   The request body. 'resourceSpans' is empty when nothing is exportable.
   */
  public static function build(array $batch, string $environment = ''): array {
    $trace = NULL;
    $observations = [];
    foreach ($batch as $event) {
      if (!is_array($event) || !is_array($event['body'] ?? NULL)) {
        continue;
      }
      $type = (string) ($event['type'] ?? '');
      if ($type === 'trace-create') {
        $trace ??= $event['body'];
      }
      elseif (in_array($type, ['span-create', 'generation-create', 'event-create'], TRUE)) {
        $observations[] = [$type, $event['body']];
      }
    }

    $sourceTraceId = (string) ($trace['id'] ?? ($observations[0][1]['traceId'] ?? ''));
    if ($sourceTraceId === '') {
      return ['resourceSpans' => []];
    }

    $traceId = self::traceId($sourceTraceId);
    $rootSpanId = self::rootSpanId($sourceTraceId);
    $traceAttributes = self::traceAttributes($trace ?? [], $environment);

    $spans = [];
    $earliest = NULL;
    $latest = NULL;
    foreach ($observations as [$type, $body]) {
      $start = self::toUnixNano($body['startTime'] ?? NULL);
      $end = $type === 'event-create' ? $start : self::toUnixNano($body['endTime'] ?? NULL);
      // Langfuse wants both edges on every span; mirror whichever one exists.
      $start ??= $end ?? self::nowUnixNano();
      $end ??= $start;
      $earliest = $earliest === NULL ? $start : self::minNano($earliest, $start);
      $latest = $latest === NULL ? $end : self::maxNano($latest, $end);

      $parent = (string) ($body['parentObservationId'] ?? '');
      $spans[] = [
        'traceId' => $traceId,
        'spanId' => self::spanId((string) ($body['id'] ?? '')),
        'parentSpanId' => $parent !== '' ? self::spanId($parent) : $rootSpanId,
        'name' => (string) ($body['name'] ?? 'unnamed'),
        'kind' => 1,
        'startTimeUnixNano' => $start,
        'endTimeUnixNano' => $end,
        'attributes' => self::encodeAttributes(
          $traceAttributes + self::observationAttributes($type, $body)
        ),
      ];
    }

    if ($trace !== NULL) {
      $start = self::toUnixNano($trace['timestamp'] ?? NULL) ?? $earliest ?? self::nowUnixNano();
      $end = $latest === NULL ? $start : self::maxNano($latest, $start);
      $rootAttributes = $traceAttributes + [
        'langfuse.observation.type' => 'span',
        'langfuse.observation.input' => self::displayValue($trace['input'] ?? NULL),
        'langfuse.observation.output' => self::displayValue($trace['output'] ?? NULL),
      ];
      array_unshift($spans, [
        'traceId' => $traceId,
        'spanId' => $rootSpanId,
        'name' => (string) ($trace['name'] ?? 'unnamed'),
        'kind' => 1,
        'startTimeUnixNano' => $start,
        'endTimeUnixNano' => $end,
        'attributes' => self::encodeAttributes($rootAttributes),
      ]);
    }

    if ($spans === []) {
      return ['resourceSpans' => []];
    }

    return [
      'resourceSpans' => [
        [
          'resource' => [
            'attributes' => self::encodeAttributes([
              'service.name' => LangfusePayloadContract::OTLP_SERVICE_NAME,
            ]),
          ],
          'scopeSpans' => [
            [
              'scope' => [
                'name' => LangfusePayloadContract::SDK_NAME,
                'version' => LangfusePayloadContract::SDK_VERSION,
              ],
              'spans' => $spans,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Counts the spans in a body returned by build().
   */
  public static function spanCount(array $body): int {
    $count = 0;
    foreach ($body['resourceSpans'] ?? [] as $resourceSpans) {
      foreach ($resourceSpans['scopeSpans'] ?? [] as $scopeSpans) {
        $count += count($scopeSpans['spans'] ?? []);
      }
    }
    return $count;
  }

  /**
   * Returns the OTLP trace id (32 lowercase hex) for a tracer trace id.
   *
   * A UUID maps to itself without hyphens, so the Langfuse trace id stays
   * recognisable next to the request id in logs and Sentry.
   */
  public static function traceId(string $sourceTraceId): string {
    $hex = strtolower(str_replace('-', '', $sourceTraceId));
    if (preg_match('/^[0-9a-f]{32}$/', $hex) === 1 && trim($hex, '0') !== '') {
      return $hex;
    }
    return substr(hash('sha256', $sourceTraceId), 0, 32);
  }

  /**
   * Returns the span id of the root span for a tracer trace id.
   */
  public static function rootSpanId(string $sourceTraceId): string {
    return substr(hash('sha256', 'root|' . self::traceId($sourceTraceId)), 0, 16);
  }

  /**
   * Returns the OTLP span id (16 lowercase hex) for an observation id.
   */
  public static function spanId(string $sourceObservationId): string {
    $hex = strtolower(str_replace('-', '', $sourceObservationId));
    if (preg_match('/^[0-9a-f]{16,}$/', $hex) === 1 && trim(substr($hex, 0, 16), '0') !== '') {
      return substr($hex, 0, 16);
    }
    return substr(hash('sha256', 'span|' . $sourceObservationId), 0, 16);
  }

  /**
   * Builds the attributes repeated on every span of the trace.
   *
   * @return array<string, mixed>
   *   Attribute values keyed by attribute name.
   */
  private static function traceAttributes(array $trace, string $environment): array {
    $metadata = is_array($trace['metadata'] ?? NULL) ? $trace['metadata'] : [];
    if ($environment === '' && is_scalar($metadata['environment'] ?? NULL)) {
      $environment = (string) $metadata['environment'];
    }

    $attributes = [
      'langfuse.trace.name' => $trace['name'] ?? NULL,
      'langfuse.session.id' => $trace['sessionId'] ?? NULL,
      'langfuse.user.id' => $trace['userId'] ?? NULL,
      'langfuse.trace.tags' => is_array($trace['tags'] ?? NULL) ? array_values($trace['tags']) : NULL,
      'langfuse.release' => $trace['release'] ?? NULL,
      'langfuse.version' => $trace['version'] ?? NULL,
      'langfuse.environment' => $environment !== '' ? $environment : NULL,
    ];
    foreach ($metadata as $key => $value) {
      $attributes['langfuse.trace.metadata.' . $key] = $value;
    }

    return $attributes;
  }

  /**
   * Builds the attributes specific to one observation.
   *
   * @return array<string, mixed>
   *   Attribute values keyed by attribute name.
   */
  private static function observationAttributes(string $type, array $body): array {
    $attributes = [
      'langfuse.observation.type' => match ($type) {
        'generation-create' => 'generation',
        'event-create' => 'event',
        default => 'span',
      },
      'langfuse.observation.level' => $body['level'] ?? NULL,
      'langfuse.observation.status_message' => $body['statusMessage'] ?? NULL,
      'langfuse.observation.input' => self::displayValue($body['input'] ?? NULL),
      'langfuse.observation.output' => self::displayValue($body['output'] ?? NULL),
    ];

    if ($type === 'generation-create') {
      $attributes['langfuse.observation.model.name'] = $body['model'] ?? NULL;
      $attributes['langfuse.observation.model.parameters'] = self::jsonOrNull($body['modelParameters'] ?? NULL);
      $attributes['langfuse.observation.usage_details'] = self::jsonOrNull($body['usage'] ?? NULL);
    }

    foreach (is_array($body['metadata'] ?? NULL) ? $body['metadata'] : [] as $key => $value) {
      $attributes['langfuse.observation.metadata.' . $key] = $value;
    }

    return $attributes;
  }

  /**
   * Encodes attribute values as OTLP JSON key/value pairs.
   *
   * OTLP attributes hold scalars and arrays of scalars; anything structured
   * is sent as a JSON string, which Langfuse parses back.
   *
   * @return array<int, array{key: string, value: array<string, mixed>}>
   *   The encoded attribute list, without NULL values.
   */
  private static function encodeAttributes(array $attributes): array {
    $encoded = [];
    foreach ($attributes as $key => $value) {
      if ($value === NULL) {
        continue;
      }
      if (is_bool($value)) {
        $any = ['boolValue' => $value];
      }
      elseif (is_int($value)) {
        // Proto3 JSON carries 64-bit integers as decimal strings.
        $any = ['intValue' => (string) $value];
      }
      elseif (is_float($value)) {
        $any = ['doubleValue' => $value];
      }
      elseif (is_array($value) && array_is_list($value) && $value !== [] && self::allScalar($value)) {
        $any = [
          'arrayValue' => [
            'values' => array_map(
              static fn($item): array => ['stringValue' => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item],
              $value,
            ),
          ],
        ];
      }
      elseif (is_array($value) || is_object($value)) {
        $any = ['stringValue' => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)];
      }
      else {
        $any = ['stringValue' => (string) $value];
      }
      $encoded[] = ['key' => (string) $key, 'value' => $any];
    }
    return $encoded;
  }

  /**
   * Returns TRUE when every item of the list is a scalar.
   */
  private static function allScalar(array $values): bool {
    foreach ($values as $value) {
      if (!is_scalar($value)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns an input/output value as the string Langfuse displays.
   */
  private static function displayValue(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (is_string($value)) {
      return $value;
    }
    return self::jsonOrNull($value);
  }

  /**
   * JSON-encodes a non-empty value.
   */
  private static function jsonOrNull(mixed $value): ?string {
    if ($value === NULL || $value === []) {
      return NULL;
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $json === FALSE ? NULL : $json;
  }

  /**
   * Converts a tracer timestamp to unix nanoseconds as a decimal string.
   *
   * The tracer writes UTC "Y-m-d\TH:i:s.u\Z". Nanoseconds overflow a float
   * and proto3 JSON carries fixed64 as a string, so this stays string math.
   */
  public static function toUnixNano(mixed $timestamp): ?string {
    if (!is_string($timestamp) || $timestamp === '') {
      return NULL;
    }
    try {
      $date = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }
    $seconds = $date->format('U');
    if ((int) $seconds <= 0) {
      return NULL;
    }
    return $seconds . $date->format('u') . '000';
  }

  /**
   * Returns the current time in unix nanoseconds as a decimal string.
   */
  private static function nowUnixNano(): string {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return $now->format('U') . $now->format('u') . '000';
  }

  /**
   * Returns the smaller of two same-length-or-not decimal strings.
   */
  private static function minNano(string $a, string $b): string {
    return self::compareNano($a, $b) <= 0 ? $a : $b;
  }

  /**
   * Returns the larger of two decimal strings.
   */
  private static function maxNano(string $a, string $b): string {
    return self::compareNano($a, $b) >= 0 ? $a : $b;
  }

  /**
   * Compares two non-negative decimal strings numerically.
   */
  private static function compareNano(string $a, string $b): int {
    return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
  }

}
