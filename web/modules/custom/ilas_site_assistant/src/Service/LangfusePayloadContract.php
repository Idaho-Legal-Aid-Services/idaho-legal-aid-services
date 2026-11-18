<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Approved Langfuse payload shape constants (PHARD-02).
 *
 * Documents and locks the tracer event types, required body keys, and SDK
 * metadata that the ilas-langfuse-tracer emits, plus the OTLP endpoint the
 * batch is exported to. The event types are the tracer's internal model;
 * LangfuseOtlpPayloadBuilder converts them to OTLP spans at send time.
 * Contract tests validate that finalized payloads match these constants.
 */
final class LangfusePayloadContract {

  /**
   * Approved Langfuse ingestion event types.
   */
  const APPROVED_EVENT_TYPES = [
    'trace-create',
    'span-create',
    'generation-create',
    'event-create',
  ];

  /**
   * Required body keys for trace-create events.
   */
  const REQUIRED_TRACE_BODY_KEYS = ['id', 'name', 'sessionId'];

  /**
   * Required body keys for span-create events.
   */
  const REQUIRED_SPAN_BODY_KEYS = ['id', 'traceId', 'name', 'startTime', 'endTime'];

  /**
   * Required body keys for generation-create events.
   */
  const REQUIRED_GENERATION_BODY_KEYS = ['id', 'traceId', 'name', 'model', 'startTime', 'endTime'];

  /**
   * SDK name emitted in trace payload metadata.
   */
  const SDK_NAME = 'ilas-langfuse-tracer';

  /**
   * SDK version emitted in trace payload metadata.
   */
  const SDK_VERSION = '1.0.0';

  /**
   * Required keys in the top-level payload metadata.
   */
  const REQUIRED_METADATA_KEYS = ['batch_size', 'sdk_name', 'sdk_version'];

  /**
   * Path of the OTLP/HTTP trace endpoint, relative to langfuse.host.
   *
   * Replaces /api/public/ingestion, which Langfuse Cloud removed for trace
   * and observation events on 2026-11-16 (HTTP 410 LANGFUSE_V3_API_REMOVED).
   */
  const OTLP_TRACES_PATH = '/api/public/otel/v1/traces';

  /**
   * Header selecting the Langfuse v4 ingestion path.
   *
   * Without it, OTLP data can take up to ten minutes to become readable.
   */
  const INGESTION_VERSION_HEADER = 'x-langfuse-ingestion-version';

  /**
   * Value sent in the ingestion version header.
   */
  const INGESTION_VERSION = '4';

  /**
   * OTLP resource service.name for exported spans.
   */
  const OTLP_SERVICE_NAME = 'ilas-site-assistant';

}
