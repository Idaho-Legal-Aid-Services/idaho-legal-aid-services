<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * Approved Langfuse payload shape constants (PHARD-02).
 *
 * Documents and locks the ingestion API event types, required body keys,
 * and SDK metadata that the ilas-langfuse-tracer emits. Contract tests
 * validate that finalized payloads match these constants.
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
  const REQUIRED_TRACE_BODY_KEYS = ['id', 'name'];

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

}
