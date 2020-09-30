<?php

namespace Drupal\entity_staging;

use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\entity_staging\Event\EntityStagingBeforeExportEvent;
use Drupal\entity_staging\Event\EntityStagingEvents;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Path\AliasStorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Export content entities.
 */
class EntityStagingExport {

  use StringTranslationTrait;

  /**
   * The content staging manager service.
   *
   * @var \Drupal\entity_staging\EntityStagingManager
   */
  protected $contentStagingManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The alias storage service.
   *
   * @var \Drupal\Core\Path\AliasStorageInterface
   */
  protected $aliasStorage;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The settings service.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * EntityStagingExport constructor.
   *
   * @param \Drupal\entity_staging\EntityStagingManager $entity_staging_manager
   *   The content staging manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Path\AliasStorageInterface $alias_storage
   *   The alias storage service.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher interface.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings service.
   */
  public function __construct(EntityStagingManager $entity_staging_manager, EntityTypeManagerInterface $entity_type_manager, AliasStorageInterface $alias_storage, Serializer $serializer, FileSystem $file_system, EventDispatcherInterface $event_dispatcher, Settings $settings) {
    $this->contentStagingManager = $entity_staging_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasStorage = $alias_storage;
    $this->serializer = $serializer;
    $this->fileSystem = $file_system;
    $this->eventDispatcher = $event_dispatcher;
    $this->settings = $settings;
  }

  /**
   * Export content entities.
   */
  public function export() {
    $types = $this->contentStagingManager->getContentEntityTypes(EntityStagingManager::ALLOWED_FOR_STAGING_ONLY);
    foreach ($types as $entity_type_id => $entity_info) {
      if ($entity_info->hasKey('bundle')) {
        $bundles = $this->contentStagingManager->getBundles($entity_type_id, EntityStagingManager::ALLOWED_FOR_STAGING_ONLY);
        foreach ($bundles as $bundle => $entity_label) {
          $entities = [];
          /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
          foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadByProperties([$entity_info->getKey('bundle') => $bundle]) as $entity) {
            $entities = array_merge_recursive($entities, $this->getTranslatedEntity($entity));
          }

          $this->doExport($entities, $entity_type_id, $bundle);
        }
      }
      else {
        $entities = [];
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple() as $entity) {
          $entities = array_merge_recursive($entities, $this->getTranslatedEntity($entity));
        }

        $this->doExport($entities, $entity_type_id);
      }
    }
  }

  /**
   * Get all translation for the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to retrieve associated translations.
   *
   * @return \Drupal\Core\Entity\EntityInterface[][][]
   */
  protected function getTranslatedEntity(ContentEntityInterface $entity) {
    $entities = [];
    foreach ($entity->getTranslationLanguages() as $content_language) {
      $translated_entity = $entity->getTranslation($content_language->getId());
      $translated_entity->path = NULL;
      if ($translated_entity->hasLinkTemplate('canonical')) {
        $entity_path = $translated_entity->toUrl('canonical')
          ->getInternalPath();

        $translated_entity->path = $this->aliasStorage->load([
          'source' => '/' . $entity_path,
        ]);
      }
      if ($translated_entity->isDefaultTranslation()) {
        $entities['default_language'][$entity->getEntityTypeId()][] = $translated_entity;
      }
      else {
        $entities['translations'][$entity->getEntityTypeId()][] = $translated_entity;
      }
    }

    return $entities;
  }

  /**
   * Proceed the export.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[][][] $translated_entities
   *   All translated entities.
   * @param string $entity_type_id
   *   The entities type id.
   * @param string|null $bundle
   *   The entities bundle.
   *
   * @throws \Exception
   *   An error occured during treatment.
   */
  protected function doExport(array $translated_entities, $entity_type_id, $bundle = NULL) {
    $staging_path = DRUPAL_ROOT . '/' . $this->contentStagingManager->getDirectory();
    // Get system real path.
    $export_path = realpath($staging_path);

    // Check if staging base path exists.
    if (!$export_path) {
      // Attempt to create the staging base path.
      if (!mkdir($staging_path)) {
        throw new \Exception($this->t('Could not create base path directory @path', [
          '@path' => $staging_path,
        ]));
      }
    }

    foreach ($translated_entities as $language => $entities) {
      $event = new EntityStagingBeforeExportEvent($entity_type_id, $bundle, $entities);
      /** @var EntityStagingBeforeExportEvent $event */
      $event = $this->eventDispatcher->dispatch(EntityStagingEvents::BEFORE_EXPORT, $event);
      $entity_list = $event->getEntities();

      $serialized_entities = $this->serializer->serialize($entity_list, 'json', [
        'json_encode_options' => JSON_PRETTY_PRINT,
      ]);

      $entity_export_path = $export_path . '/' . $entity_type_id . '/' . $language;

      // Ensure the folder exists.
      if (!file_exists($entity_export_path)) {
        // Attempt to create folder.
        if (!mkdir($entity_export_path, $this->settings->get('file_chmod_directory', 0775), TRUE)) {
          throw new \Exception($this->t('Could not create entity @entity path directory @path', [
            '@entity' => $entity_type_id,
            '@path' => $entity_export_path,
          ]));
        }
      }

      if ($bundle) {
        file_put_contents($entity_export_path . '/' . $bundle . '.json', $serialized_entities);
      }
      else {
        file_put_contents($entity_export_path . '/' . $entity_type_id . '.json', $serialized_entities);
      }

      drupal_set_message($this->t('Export @entity_type - @langcode - @bundle entities', [
        '@entity_type' => $entity_type_id,
        '@langcode' => $language,
        '@bundle' => $bundle,
      ]));
    }
  }

}
