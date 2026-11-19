<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * A real-request check that an outside API still accepts what this site sends.
 */
interface VendorContractCheckInterface {

  public const PASS = 'pass';
  public const FAIL = 'fail';
  public const SKIPPED = 'skipped';

  /**
   * The request was accepted; whether it took effect is not known yet.
   *
   * Only checks implementing VendorContractDeferredCheckInterface return it.
   */
  public const PENDING = 'pending';

  /**
   * Runs the check.
   *
   * Must not throw: a transport or provider failure is a FAIL result.
   *
   * @return array<string, array{status: string, reason: string, context?: array<string, scalar>}>
   *   One entry per vendor, keyed by vendor name. SKIPPED means the vendor is
   *   not enabled in this runtime, which is not a failure. A PENDING entry
   *   carries the 'context' needed to confirm it later.
   */
  public function run(): array;

}
