<?php

namespace Drupal\Tests\tmgmt_content\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\MessageInterface;

/**
 * Tests deleting a field after a job was sent for translation.
 *
 * @group tmgmt
 */
class ContentEntityDeleteFieldTest extends ContentEntityTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Create a string field.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'string_field',
      'entity_type' => $this->entityTypeId,
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => TRUE,
    ]);
    $field_storage->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_storage' => $field_storage,
      'bundle' => $this->entityTypeId,
      'label' => 'String',
    ])->save();
    $this->container->get('content_translation.manager')->setEnabled($this->entityTypeId, $this->entityTypeId, TRUE);
  }

  /**
   * @todo
   */
  public function testDeleteField(): void {
    // Create an entity with our string field.
    $entity = EntityTestMul::create([
      'langcode' => 'en',
      'uid' => 1,
      'name' => 'Llama',
      'string_field' => 'foo bar title',
    ]);
    $entity->save();

    // Create the job/items.
    $job = tmgmt_job_create('en', 'de');
    $job->translator = 'test_translator';
    $job->save();
    $job_item = tmgmt_job_item_create('content', $this->entityTypeId, $entity->id(), ['tjid' => $job->id()]);
    $job_item->save();

    $source_plugin = $this->container->get('plugin.manager.tmgmt.source')->createInstance('content');
    $data = $source_plugin->getData($job_item);
    $expected_field_data = [
      '#label' => 'String',
      0 => [
        'value' => [
          '#text' => 'foo bar title',
          '#translate' => TRUE,
          '#max_length' => 255
        ],
      ],
    ];
    $this->assertEquals($expected_field_data, $data['string_field']);

    // Request a translation.
    $job->requestTranslation();

    // Delete the field.
    FieldConfig::loadByName($this->entityTypeId, $this->entityTypeId, 'string_field')->delete();
    \Drupal::entityTypeManager()->getStorage('entity_test_mul')->resetCache();

    // Accept translation, no exception should be thrown.
    $items = Job::load($job_item->id())->getItems();
    /** @var \Drupal\tmgmt\JobItemInterface $item */
    $item = reset($items);
    $item->acceptTranslation();

    // Test that a log message was added about the missing field.
    $messages = $item->getMessages(['type' => MessengerInterface::TYPE_WARNING]);
    $messages = array_map(static fn(MessageInterface $message) => (string) $message->getMessage(), $messages);
    $expected = 'Skipping field <em class="placeholder">string_field</em> for job item <a href="/entity_test_mul/manage/1" hreflang="de">de(de-ch): Llama</a> because it does not exist on entity <em>entity_test_mul/1</em>.';
    $this->assertContains($expected, $messages);

    // Check that the translations were saved correctly.
    $entity = EntityTestMul::load($entity->id());
    $translation = $entity->getTranslation('de');
    $this->assertFalse($entity->hasField('string_field'));
    $this->assertFalse($translation->hasField('string_field'));
  }

}
