<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Component\Uuid\Php as UuidGenerator;

/**
 * Builds the deterministic, PII-free synthetic trace used by Langfuse probes.
 *
 * Shared by the ilas:langfuse-probe command and the scheduled vendor contract
 * check, so both prove the same payload the tracer emits.
 */
final class LangfuseSyntheticTrace {

  /**
   * Builds a synthetic trace batch in the tracer's event model.
   *
   * @return array{trace_id: string, payload: array<string, mixed>}
   *   The trace id and the queue-shaped payload.
   */
  public static function build(string $environment): array {
    $uuidGenerator = new UuidGenerator();
    $traceId = $uuidGenerator->generate();
    $spanId = $uuidGenerator->generate();
    $eventBodyId = $uuidGenerator->generate();
    $timestamp = gmdate('Y-m-d\TH:i:s.000000\Z');

    $batch = [
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'trace-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $traceId,
          'timestamp' => $timestamp,
          'name' => 'phard-02.synthetic_probe',
          'sessionId' => hash('sha256', 'synthetic-probe-session'),
          'tags' => ['assistant', 'probe', 'synthetic'],
          'input' => 'hash=synthetic0001 len=1-24 redact=none',
          'output' => 'type=probe_complete reason=none hash=synthetic9999 len=1-24',
          'metadata' => [
            'environment' => $environment,
            'surface' => 'assistant',
            'probe_timestamp' => $timestamp,
            'input_hash' => str_repeat('1', 64),
            'input_length_bucket' => '1-24',
            'input_redaction_profile' => 'none',
            'output_hash' => str_repeat('9', 64),
            'output_length_bucket' => '1-24',
            'output_redaction_profile' => 'none',
            'response_type' => 'probe_complete',
            'reason_code' => 'none',
          ],
        ],
      ],
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'span-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $spanId,
          'traceId' => $traceId,
          'name' => 'probe.synthetic_span',
          'startTime' => $timestamp,
          'endTime' => $timestamp,
        ],
      ],
      [
        'id' => $uuidGenerator->generate(),
        'type' => 'event-create',
        'timestamp' => $timestamp,
        'body' => [
          'id' => $eventBodyId,
          'traceId' => $traceId,
          'name' => 'probe.verification_marker',
          'startTime' => $timestamp,
        ],
      ],
    ];

    return [
      'trace_id' => $traceId,
      'payload' => [
        'batch' => $batch,
        'metadata' => [
          'batch_size' => count($batch),
          'sdk_name' => LangfusePayloadContract::SDK_NAME,
          'sdk_version' => LangfusePayloadContract::SDK_VERSION,
        ],
      ],
    ];
  }

}
