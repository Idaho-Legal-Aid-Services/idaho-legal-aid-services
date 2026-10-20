<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\CohereGenerationProbe;
use Drush\Commands\DrushCommands;

/**
 * Drush command for explicit Cohere generation proof.
 */
final class CohereProbeCommands extends DrushCommands {

  public function __construct(
    private readonly CohereGenerationProbe $probe,
  ) {
    parent::__construct();
  }

  /**
   * Sends one harmless exact-output Cohere proof request.
   *
   * With --grounded, sends one grounded-generation proof request instead:
   * two synthetic documents, a question they answer, and a pass only when the
   * provider returns a non-refusal answer citing at least one document.
   *
   * @command ilas:cohere-probe
   * @aliases cohere-probe
   * @option grounded Run the grounded-generation (documents + citations) probe.
   * @usage ilas:cohere-probe
   *   Print sanitized Cohere generation proof JSON.
   * @usage ilas:cohere-probe --grounded
   *   Print sanitized grounded-generation proof JSON (VC-GENERATION-PROBE).
   */
  public function cohereProbe(array $options = ['grounded' => FALSE]): int {
    if (!empty($options['grounded'])) {
      $result = $this->probe->probeGrounded();
      $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

      return !empty($result['grounded_probe_passed']) ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    $result = $this->probe->probe();
    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return !empty($result['generation_probe_passed']) ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
