<?php

namespace Drupal\tmgmt;

/**
 * Interface for sources that allow sending multiple "jobs".
 *
 * @ingroup tmgmt_source
 */
interface MultipleCheckoutInterface {

  /**
   * Sends multiple translation request to the translation provider.
   *
   * @param \Drupal\tmgmt\Entity\Job[] $jobs
   *   Array of jobs.
   */
  public function requestTranslationMultiple(array $jobs);

}
