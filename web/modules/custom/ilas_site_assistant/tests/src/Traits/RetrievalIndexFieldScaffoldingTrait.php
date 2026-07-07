<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Traits;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Creates the site fields the lexical Search API index definitions require.
 *
 * The canonical index definitions in the module's config/optional/ directory
 * declare config dependencies on site-provided field storages
 * (field.storage.node.field_main_content, field.storage.paragraph.field_faq_question,
 * etc.). Optional config is only installed when those dependencies are met, so
 * kernel tests that need faq_accordion / assistant_resources to materialize
 * during installConfig() must call this scaffolding first, and functional
 * tests must call it after site install and then run
 * \Drupal::service('config.installer')->installOptionalConfig(). Field types
 * mirror the active-sync definitions in config/field.storage.*.yml.
 */
trait RetrievalIndexFieldScaffoldingTrait {

  /**
   * Creates the bundles and fields the lexical index definitions depend on.
   */
  protected function scaffoldRetrievalIndexFields(): void {
    NodeType::create(['type' => 'resource', 'name' => 'Resource'])->save();
    $this->scaffoldFields('node', 'resource', [
      'field_main_content' => ['type' => 'text_long'],
      'field_service_areas' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ],
      'field_topics' => [
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ],
    ]);

    ParagraphsType::create(['id' => 'accordion_item', 'label' => 'Accordion Item'])->save();
    ParagraphsType::create(['id' => 'faq_item', 'label' => 'FAQ Item'])->save();
    $this->scaffoldFields('paragraph', 'accordion_item', [
      'field_accordion_body' => ['type' => 'text_long'],
      'field_accordion_title' => ['type' => 'string'],
      'field_anchor_id' => ['type' => 'string'],
    ]);
    $this->scaffoldFields('paragraph', 'faq_item', [
      'field_anchor_id' => ['type' => 'string'],
      'field_faq_answer' => ['type' => 'text_long'],
      'field_faq_question' => ['type' => 'string'],
    ]);
  }

  /**
   * Creates field storages (once) and bundle fields for one entity bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle to attach the fields to.
   * @param array<string, array<string, mixed>> $definitions
   *   Field definitions keyed by field name, each with a "type" key and
   *   optional "cardinality" and "settings" keys.
   */
  private function scaffoldFields(string $entity_type, string $bundle, array $definitions): void {
    foreach ($definitions as $field_name => $definition) {
      if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
        FieldStorageConfig::create([
          'field_name' => $field_name,
          'entity_type' => $entity_type,
          'type' => $definition['type'],
          'cardinality' => $definition['cardinality'] ?? 1,
          'settings' => $definition['settings'] ?? [],
        ])->save();
      }
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

}
