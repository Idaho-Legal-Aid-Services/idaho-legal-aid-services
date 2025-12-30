<?php

namespace Drupal\Tests\tmgmt_content\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;

/**
 * Content entity Source file tests.
 *
 * @group tmgmt
 */
class ContentEntitySourceFileTest extends ContentEntityTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['file'];

  protected $image_label;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Auto-create fields for testing.
    FieldStorageConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_file',
      'type' => 'file',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_file',
      'bundle' => $this->entityTypeId,
      'label' => 'Test file-field',
      'translatable' => TRUE,
    ])->save();

    // Auto-create fields for testing.
    FieldStorageConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_file_untranslatable',
      'type' => 'file',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_file_untranslatable',
      'bundle' => $this->entityTypeId,
      'label' => 'Test file-field',
      'translatable' => FALSE,
    ])->save();

    // Add an image field and make it translatable.
    $this->installEntitySchema('file');
    $this->installSchema('file', array('file_usage'));
  }

  /**
   * Create an english test entity.
   */
  public function testAttachedTranslatableFile() {

    $this->config('tmgmt.settings')
      ->set('file_mimetypes', ['text/plain'])
      ->save();

    $uri1 = 'public://foobar.txt';
    file_put_contents($uri1, 'This is example file 1');
    $file1 = File::create([
      'uri' => $uri1,
      'filename' => basename($uri1),
    ]);
    $file1->save();

    $directory = 'public://sub1/sub2';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri2 = $directory . '/foobar2.txt';
    file_put_contents($uri2, 'This is example file 2');
    $file2 = File::create([
      'uri' => $uri2,
      'filename' => basename($uri2),
    ]);
    $file2->save();

    $uri3 = 'public://foobar3.txt';
    file_put_contents($uri3, 'This is example file 3');
    $file3 = File::create([
      'uri' => $uri3,
      'filename' => basename($uri3),
    ]);
    $file3->save();

    $pdf_uri = 'public://foobar4.pdf';
    file_put_contents($pdf_uri, 'This is PDF file');
    $pdf_file = File::create([
      'uri' => $pdf_uri,
      'filename' => basename($pdf_uri),
    ]);
    $pdf_file->save();

    $values = [
      'langcode' => 'en',
      'user_id' => 1,
      'field_file' => [$file1, $file2, $pdf_file],
      'field_file_untranslatable' => [$file3],
      'name' => 'Test with a file',
    ];
    $entity_test = EntityTestMul::create($values);
    $entity_test->save();

    $job = tmgmt_job_create('en', 'de');
    $job->translator = 'test_translator';
    $job->save();
    $job_item = $job->addItem('content', $this->entityTypeId, $entity_test->id());

    $this->assertEquals(2, $job_item->get('file_count')->value);
    $data = $job_item->getData();

    // Assert that both files of the translatable file field but not the
    // untranslatable one are extracted.
    $this->assertEquals('Test file-field', $data['field_file']['#label']);
    $this->assertEquals('Delta #0', (string) $data['field_file'][0]['#label']);
    $this->assertFalse(isset($data['field_file'][0]['entity']['#label']));
    $this->assertEquals($file1->id(), $data['field_file'][0]['entity']['#file']);
    $this->assertTrue($data['field_file'][0]['entity']['#translate']);

    $this->assertEquals('Delta #1', (string) $data['field_file'][1]['#label']);
    $this->assertFalse(isset($data['field_file'][1]['entity']['#label']));
    $this->assertEquals($file2->id(), $data['field_file'][1]['entity']['#file']);
    $this->assertTrue($data['field_file'][1]['entity']['#translate']);

    // PDF mime type is not enabled and ignored.
    $this->assertEquals($pdf_file ->id(), $data['field_file'][2]['target_id']['#text']);
    $this->assertFalse(isset($data['field_file'][2]['entity']));

    $this->assertFalse(isset($data['field_file_untranslatable']));

    // Now request a translation and save it back.
    $job->requestTranslation();

    $items = $job->getItems();
    $item = reset($items);
    $item->acceptTranslation();
    $translated_data = $item->getData();

    // Check that the translations were saved correctly.
    $entity_test = \Drupal::entityTypeManager()->getStorage($this->entityTypeId)->load($entity_test->id());
    $translation = $entity_test->getTranslation('de');
    $translated_file1 = File::load($translated_data['field_file'][0]['entity']['#translation']['#file']);
    $translated_file2 = File::load($translated_data['field_file'][1]['entity']['#translation']['#file']);

    $this->assertEquals('de(de-ch): Test with a file', $translated_data['name'][0]['value']['#translation']['#text']);
    $this->assertEquals('public://foobar_de.txt', $translated_file1->getFileUri());
    $this->assertEquals('public://sub1/sub2/foobar2_de.txt', $translated_file2->getFileUri());
    $this->assertEquals('de(de-ch): This is example file 1', file_get_contents($translated_file1->getFileUri()));
    $this->assertEquals('de(de-ch): This is example file 2', file_get_contents($translated_file2->getFileUri()));
    $this->assertEquals($translated_file1->id(), $translation->get('field_file')[0]->target_id);
    $this->assertEquals($translated_file2->id(), $translation->get('field_file')[1]->target_id);
  }

}
