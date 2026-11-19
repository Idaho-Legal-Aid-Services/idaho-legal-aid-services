<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

/**
 * A vendor check whose outcome can only be confirmed some time later.
 *
 * Some providers accept a write and apply it asynchronously. Reading it back
 * straight away and calling a miss a failure is wrong: on the first scheduled
 * run of the Langfuse check the trace took longer than the ten seconds the
 * check waited, an error was raised, and the trace was in Langfuse all along.
 * Such a check returns PENDING with enough context to look again, and the
 * monitor asks it to confirm on later cron runs until the answer is definite.
 */
interface VendorContractDeferredCheckInterface extends VendorContractCheckInterface {

  /**
   * Names the vendors this check can confirm.
   *
   * @return string[]
   *   Vendor names as used in the keys returned by run().
   */
  public function deferredVendors(): array;

  /**
   * Looks again for the effect of an earlier accepted request.
   *
   * Must not throw.
   *
   * @param array<string, scalar> $context
   *   The 'context' of the PENDING result being confirmed.
   *
   * @return array{status: string, reason: string}
   *   PASS once the effect is visible, PENDING while it is not, FAIL when the
   *   provider answers with an error.
   */
  public function confirm(array $context): array;

}
