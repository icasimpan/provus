<?php

namespace Drupal\entity_staging\EventSubscriber;

use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\entity_staging\EntityStagingManager;
use Drupal\entity_staging\Event\EntityStagingBeforeExportEvent;
use Drupal\entity_staging\Event\EntityStagingEvents;
use Drupal\Core\File\FileSystem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to EntityStagingEvents::BEFORE_EXPORT events.
 *
 * Perform action before export file entities.
 */
class EntityStagingExportFileSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The content staging manager service.
   *
   * @var \Drupal\entity_staging\EntityStagingManager
   */
  protected $contentStagingManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The settings service.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * EntityStagingExportFileSubscriber constructor.
   *
   * @param \Drupal\entity_staging\EntityStagingManager $entity_staging_manager
   *   The content staging manager service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings service.
   */
  public function __construct(EntityStagingManager $entity_staging_manager, FileSystem $file_system, Settings $settings) {
    $this->contentStagingManager = $entity_staging_manager;
    $this->fileSystem = $file_system;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[EntityStagingEvents::BEFORE_EXPORT][] = ['exportFiles', -10];

    return $events;
  }

  /**
   * Export all files.
   *
   * @param \Drupal\entity_staging\Event\EntityStagingBeforeExportEvent $event
   *   Received event.
   *
   * @throws \Exception
   *   An error occured during treatment.
   */
  public function exportFiles(EntityStagingBeforeExportEvent $event) {
    if ($event->getEntityTypeId() == 'file') {
      // Determine staging base path.
      $staging_path = DRUPAL_ROOT . '/' . $this->contentStagingManager->getDirectory();

      // Get system real path.
      $export_path = realpath($staging_path);

      // Check if staging base path exists.
      if (!$export_path) {
        // Attempt to create the staging base path.
        if (!mkdir($staging_path)) {
          throw new \Exception($this->t('Could not create base staging directory @path.', [
            '@path' => $staging_path,
          ]));
        }
      }

      /** @var \Drupal\file\Entity\File $file */
      foreach ($event->getEntities()['file'] as $file) {
        $folder = $export_path . '/files/' . dirname(file_uri_target($file->getFileUri()));

        // Check if folder already exists.
        if (!file_exists($folder)) {
          if (!mkdir($folder, $this->settings->get('file_chmod_directory', 0775), TRUE)) {
            throw new \Exception($this->t('Could not create file directory @path.', [
              '@path' => $folder,
            ]));
          }
        }

        file_put_contents($folder . '/' . $this->fileSystem->basename($file->getFileUri()), file_get_contents($file->getFileUri()));
      }
    }
  }

}
