<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the exported assistant settings in the order Drupal stores them.
 *
 * Drupal saves config with its top-level keys in schema order. A key added
 * to config/ilas_site_assistant.settings.yml by hand in a different position
 * imports correctly but is stored in schema order, so `drush config:status`
 * reports the object as Different forever after. The deploy script treats
 * that as a failed deploy on test and live, which is right for real drift
 * and wrong for a misplaced key; this catches the misplaced key first.
 */
#[Group('ilas_site_assistant')]
final class SyncConfigKeyOrderTest extends TestCase {

  /**
   * Top-level keys of the exported config follow the schema's order.
   */
  public function testExportedSettingsKeysFollowSchemaOrder(): void {
    $root = dirname(__DIR__, 7);
    $module = $root . '/web/modules/custom/ilas_site_assistant';

    $schema = Yaml::decode((string) file_get_contents($module . '/config/schema/ilas_site_assistant.schema.yml'));
    $schemaKeys = array_keys($schema['ilas_site_assistant.settings']['mapping']);

    $exported = Yaml::decode((string) file_get_contents($root . '/config/ilas_site_assistant.settings.yml'));
    $exportedKeys = array_values(array_diff(array_keys($exported), ['_core']));

    $this->assertSame([], array_values(array_diff($exportedKeys, $schemaKeys)), 'Every exported key has a schema entry.');
    $this->assertSame(
      array_values(array_intersect($schemaKeys, $exportedKeys)),
      $exportedKeys,
      'config/ilas_site_assistant.settings.yml lists its keys in a different order from the schema. Move the key to its schema position, or config:status will report drift after every import.',
    );
  }

}
