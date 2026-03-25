<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Support;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Test translation service that returns interpolated strings.
 */
final class PassThroughTranslation implements TranslationInterface {

  /**
   * {@inheritdoc}
   */
  public function translate($string, array $args = [], array $options = []) {
    return strtr((string) $string, $args);
  }

  /**
   * {@inheritdoc}
   */
  public function translateString(TranslatableMarkup $translated_string) {
    return strtr(
      $translated_string->getUntranslatedString(),
      $translated_string->getArguments(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
    return strtr((string) ($count == 1 ? $singular : $plural), $args);
  }

}
