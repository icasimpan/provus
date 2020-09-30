<?php

namespace Drupal\entity_staging\EventSubscriber;

use Drupal\entity_staging\Event\EntityStagingEvents;
use Drupal\entity_staging\Event\EntityStagingProcessFieldDefinitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to EntityStagingEvents::PROCESS_FIELD_DEFINITION events.
 *
 * Get the migration definition for processing image and file reference field.
 */
class EntityStagingProcessImageAndFileReferenceFieldSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[EntityStagingEvents::PROCESS_FIELD_DEFINITION][] = ['getProcessFieldDefinition', -10];

    return $events;
  }

  /**
   * Get the the process definition.
   *
   * @param \Drupal\entity_staging\Event\EntityStagingProcessFieldDefinitionEvent $event
   */
  public function getProcessFieldDefinition(EntityStagingProcessFieldDefinitionEvent $event) {
    if (in_array($event->getFieldDefinition()->getType(), ['image', 'file'])) {
      if ($event->getFieldDefinition()->getFieldStorageDefinition()->isMultiple()) {
        $process_field[] = [
          'plugin' => 'migration_lookup',
          'migration' => 'staging_content_file_file_default_language',
          'source' => $event->getFieldDefinition()->getName(),
        ];
        if ($event->getFieldDefinition()->isTranslatable()) {
          $process_field[0]['language'] = '@langcode';
        }

        $process_field[] = [
          'plugin' => 'entity_staging_iterator',
          'process' => [
            'target_id' => '0',
            'target_revision_id' => '1',
          ],
        ];

        $event->setProcessFieldDefinition([
          $event->getFieldDefinition()->getName() => $process_field
        ]);
        $event->setMigrationDependencies(['staging_content_file_file_default_language']);
        $event->stopPropagation();
      }
      else {
        $process_field = [
          'plugin' => 'migration_lookup',
          'migration' => 'staging_content_file_file_default_language',
          'source' => $event->getFieldDefinition()->getName(),
        ];
        if ($event->getFieldDefinition()->isTranslatable()) {
          $process_field['language'] = '@langcode';
        }

        $process_field = [
          'plugin' => 'migration_lookup',
          'migration' => 'staging_content_file_file_default_language',
          'source' => $event->getFieldDefinition()->getName(),
        ];
        if ($event->getFieldDefinition()->isTranslatable()) {
          $process_field['language'] = '@langcode';
        }
        $event->setProcessFieldDefinition([
          $event->getFieldDefinition()->getName() . '/target_id' => [
            $process_field,
          ],
          $event->getFieldDefinition()
            ->getName() . '/alt' => $event->getFieldDefinition()
              ->getName() . '_alt',
          $event->getFieldDefinition()
            ->getName() . '/title' => $event->getFieldDefinition()
              ->getName() . '_title',
        ]);
        $event->stopPropagation();
      }
    }
  }

}
