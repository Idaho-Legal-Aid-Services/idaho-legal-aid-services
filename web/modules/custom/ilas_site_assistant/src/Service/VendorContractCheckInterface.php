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
   * Runs the check.
   *
   * Must not throw: a transport or provider failure is a FAIL result.
   *
   * @return array<string, array{status: string, reason: string}>
   *   One entry per vendor, keyed by vendor name. SKIPPED means the vendor is
   *   not enabled in this runtime, which is not a failure.
   */
  public function run(): array;

}
