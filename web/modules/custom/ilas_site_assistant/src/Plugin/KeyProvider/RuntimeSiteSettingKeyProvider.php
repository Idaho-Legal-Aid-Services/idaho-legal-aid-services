<?php

namespace Drupal\ilas_site_assistant\Plugin\KeyProvider;

use Drupal\Core\Site\Settings;
use Drupal\key\Annotation\KeyProvider;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyProviderBase;

/**
 * Resolves key values from Drupal site settings populated at runtime.
 *
 * @KeyProvider(
 *   id = "ilas_runtime_site_setting",
 *   label = @Translation("ILAS runtime site setting"),
 *   description = @Translation("Retrieves a key value from a Drupal site setting populated at runtime."),
 *   tags = {
 *     "runtime",
 *     "env"
 *   },
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
class RuntimeSiteSettingKeyProvider extends KeyProviderBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'settings_key' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $settingsKey = (string) ($this->configuration['settings_key'] ?? '');
    if ($settingsKey === '') {
      return NULL;
    }
    $value = Settings::get($settingsKey);

    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
