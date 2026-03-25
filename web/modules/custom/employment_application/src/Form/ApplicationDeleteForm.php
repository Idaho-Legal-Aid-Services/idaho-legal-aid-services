<?php

namespace Drupal\employment_application\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for deleting an employment application.
 */
class ApplicationDeleteForm extends ConfirmFormBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The logger.
   */
  protected LoggerInterface $appLogger;

  /**
   * The numeric row ID of the application.
   */
  protected int $id;

  /**
   * The loaded application record.
   */
  protected object $application;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->database = $container->get('database');
    $instance->fileSystem = $container->get('file_system');
    $instance->appLogger = $container->get('logger.factory')->get('employment_application');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'employment_application_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to permanently delete application "@id" and all associated files?', [
      '@id' => $this->application->application_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete Application');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone. The application record and all uploaded documents will be permanently destroyed.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('employment_application.detail', ['id' => $this->id]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $id = 0): array {
    $this->id = $id;

    $this->application = $this->database->select('employment_applications', 'ea')
      ->fields('ea')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$this->application) {
      throw new NotFoundHttpException('Application not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Collect file URIs before any DB changes.
    $physicalFiles = [];
    $fileData = json_decode($this->application->file_data, TRUE);
    if (is_array($fileData)) {
      foreach ($fileData as $fieldFiles) {
        if (!is_array($fieldFiles)) {
          continue;
        }
        foreach ($fieldFiles as $fileRef) {
          $fid = $fileRef['fid'] ?? NULL;
          if ($fid) {
            $file = File::load($fid);
            if ($file) {
              $physicalFiles[] = $file->getFileUri();
            }
          }
        }
      }
    }

    // Transaction: DB operations first (reversible via rollback).
    $transaction = $this->database->startTransaction();
    try {
      // Delete File entities (DB operation).
      if (is_array($fileData)) {
        foreach ($fileData as $fieldFiles) {
          if (!is_array($fieldFiles)) {
            continue;
          }
          foreach ($fieldFiles as $fileRef) {
            $fid = $fileRef['fid'] ?? NULL;
            if ($fid) {
              $file = File::load($fid);
              if ($file) {
                $file->delete();
              }
            }
          }
        }
      }

      // Delete application record.
      $this->database->delete('employment_applications')
        ->condition('id', $this->id)
        ->execute();

      // Commit.
      unset($transaction);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->appLogger->error('Application deletion failed for @id: @error', [
        '@id' => $this->application->application_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to delete application. Please try again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Outside transaction: best-effort secure deletion of physical files.
    foreach ($physicalFiles as $uri) {
      _employment_application_secure_delete_file($this->fileSystem, $uri, $this->appLogger);
    }

    $this->appLogger->info('Application deleted: @id by user @uid', [
      '@id' => $this->application->application_id,
      '@uid' => \Drupal::currentUser()->id(),
    ]);

    $this->messenger()->addStatus($this->t('Application "@id" has been deleted.', [
      '@id' => $this->application->application_id,
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('employment_application.admin'));
  }

}
