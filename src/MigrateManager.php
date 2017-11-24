<?php

namespace Drupal\wbm2cm;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\workflows\Entity\Workflow;
use Drupal\Core\Database\Database;
use Psr\Log\LoggerInterface;

/**
 * Manages migrating from WBM to CM.
 *
 * The intention is for the migration to have the following recovery points:
 *    1. States and transitions are stored in key value (i.e. the Workflow
 * entity is created)
 *    2. Entity state maps are stored in key value
 *    3. WBM uninstalled
 *    4. Workflows installed
 *    5. CM installed
 *    6. States and transitions are migrated (i.e. the Workflow entity is
 * created)
 *    7. Entity state maps are migrated
 *    8. Remove all temporary data from key value used for the migration.
 */
class MigrateManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The key value store for the wbm2cm module.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $wbm2cmStore;

  /**
   * The key value store for the state map data.
   *
   * Note: we store state map data separately to make it easier to compute if
   * all states were successfully migrated.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $stateMapStore;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Instantiate the MigrateManager service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key value store factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, ModuleInstallerInterface $module_installer, KeyValueFactoryInterface $key_value_factory, LoggerInterface $logger) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleInstaller = $module_installer;
    $this->migrateStore = $key_value_factory->get('wbm2cm_migrate');
    $this->stateMapStore = $key_value_factory->get('wbm2cm_migrate_state_map');
    $this->logger = $logger;
  }

  /**
   * Get messages for whether or not the migration is valid and ready.
   *
   * @return string[]
   *   An array of messages to display to the user about, keyed on a unique
   *   identifier.
   */
  public function getValidationMessages() {
    $messages = [];
    // @todo validate WBM does not have multiple transitions, see WorkflowTypeBase
    // @todo validate that there is a 'draft' and 'published' state, which are required
    // @todo check if migration was run and failed, i.e. we need to trigger a "re-run" from a failed state
    return $messages;
  }

  /**
   * Determine if the migration is valid.
   *
   * @return bool
   *   True if the migration is valid and ready to begin.
   */
  public function isValid() {
    return empty($this->getValidationMessages());
  }

  /**
   * Flags that the migration finished.
   */
  public function setFinished() {
    $this->migrateStore->set('finished', TRUE);
  }

  /**
   * Flags that the migration is not finished.
   */
  public function setUnfinished() {
    $this->migrateStore->set('finished', FALSE);
  }

  /**
   * Determines if the migration finished.
   *
   * @see isComplete
   *
   * @return bool
   *   True if the full migration is considered to have been run; else, false.
   */
  public function isFinished() {
    return TRUE == $this->migrateStore->get('finished');
  }

  /**
   * Determine if the migration is complete.
   *
   * The migration is complete if:
   *   - It is marked as finished.
   *   - There are no states left in the state key value store.
   *
   * @return bool
   *   True if the migration completed successfully. Otherwise, false.
   */
  public function isComplete() {
    if ($this->isFinished()) {
      // @todo is there a less costly way to compute that the key value storage is empty?
      $states = $this->stateMapStore->getAll();
      if (empty($states)) {
        $this->logger->debug('isComplete was called, it isFinished, and there are NO states remaining in storage.');
        return TRUE;
      }
      else {
        $this->logger->debug('isComplete was called, it isFinished, but there are %count states remaining in storage.', [
          '%count' => count($states),
        ]);
        return FALSE;
      }
    }

    $this->logger->debug('isComplete was called and returned FALSE.');
    return FALSE;
  }

  /**
   * Save the Workbench Moderation states and transitions.
   */
  public function saveWorkbenchModerationStatesAndTransitions() {
    // Collect all states.
    $states = [];
    foreach ($this->configFactory->listAll('workbench_moderation.moderation_state.') as $state_ids) {
      $state = $this->configFactory->getEditable($state_ids);
      $states[] = $state->get();
    }

    $this->logger->info('Found Workbench Moderation states: %state_ids', [
      '%state_ids' => print_r($states, 1),
    ]);

    // Save states.
    $this->migrateStore->set('states', $states);

    // Collect all transitions.
    $transitions = [];
    foreach ($this->configFactory->listAll('workbench_moderation.moderation_state_transition.') as $transition_ids) {
      $transition = $this->configFactory->getEditable($transition_ids);
      $transitions[] = $transition->get();
    }

    $this->logger->info('Found Workbench Moderation transitions: %transition_ids', [
      '%transition_ids' => print_r($transitions, 1),
    ]);

    // Save transitions.
    $this->migrateStore->set('transitions', $transitions);
  }

  /**
   * Save the Workbench Moderation states on all entities.
   */
  public function saveWorkbenchModerationSateMap(&$context) {
    if (empty($context['sandbox'])) {
      // Collect all moderated bundles.
      $this->entityTypeManager->clearCachedDefinitions();

      $enabled_bundles = [];

      $moderation_information = \Drupal::service('workbench_moderation.moderation_information');
      $moderate_entity_types = array_filter(\Drupal::service('entity_type.manager')
        ->getDefinitions(), function ($entity_type) use ($moderation_information) {
        return $moderation_information->isModeratableEntityType($entity_type);
      });
      $data = [];

      foreach ($moderate_entity_types as $entity_type) {

        $bundles = \Drupal::service('entity_type.manager')
          ->getStorage($entity_type->getBundleEntityType())
          ->loadMultiple();
        foreach ($bundles as $bundle) {
          if ($moderation_information->isModeratableBundle($entity_type, $bundle->id())) {
            $this->logger->debug('Found Workbench Moderation bundle that is moderated: %bundle_id for entity type: %entity_type', [
              '%bundle_id' => $bundle->id(),
              '%entity_type' => $entity_type->id(),
            ]);

            $enabled_bundles[$entity_type->id()][] = $bundle->id();

            // Save enabled bundles.
            $this->migrateStore->set('enabled_bundles', $enabled_bundles);

            // Collect entity state map and remove Workbench moderation_state field from
            // enabled bundles.
            $this->logger->debug('Querying for all %bundle_id revisions...', [
              '%bundle_id' => $bundle->id(),
            ]);

            $revisions = \Drupal::entityQuery($entity_type->id())
              ->condition('type', $bundle->id())
              ->allRevisions()
              ->execute();

            $revisions_current = \Drupal::entityQuery($entity_type->id())
              ->condition('type', $bundle->id())
              ->currentRevision()
              ->execute();

            $revisions = array_diff_key($revisions, $revisions_current);

            $revisions = $revisions_current + $revisions;

            $revisions = array_map(function ($value) use ($entity_type, $bundle) {
              return [
                'entity_id' => $value,
                'entity_type' => $entity_type->id(),
                'bundle_id' => $bundle->id(),
              ];
            }, $revisions);

            $data += $revisions;
          }
        }
      }

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_index'] = 0;
      $context['sandbox']['values'] = $data;
      $context['sandbox']['max'] = sizeof($data)/*50*/
      ; //@TODO 50 is here for have tests more quick. Don't forget to remove it.
      $context['sandbox']['current_running'] = 0;

    }

    $limit = 50;

    $revisions_process = array_slice($context['sandbox']['values'], $context['sandbox']['current_index'], $limit, TRUE);

    $this->logger->info('Revision process: %revision', [
      '%revision' => print_r($revisions_process, 1),
    ]);

    foreach ($revisions_process as $revision_id => $info_revision) {

      $entity = \Drupal::entityTypeManager()
        ->getStorage($info_revision['entity_type'])
        ->loadRevision($revision_id);
      if (!is_null($entity)) {

        $state_map_key = "state_map.{$info_revision['entity_type']}.{$info_revision['bundle_id']}.{$revision_id}";

        $this->stateMapStore->set($state_map_key, $entity->moderation_state->target_id);


        // Handle translations.
        // Get all languages except the default (which we've already handled).
        $languages = $entity->getTranslationLanguages(FALSE);
        if (!empty($languages)) {
          $language_ids = [];
          foreach ($languages as $language) {
            $language_ids[] = $language->getId();
          }

          foreach ($language_ids as $language_id) {
            // @todo how to get all revisions for this translation?
            $translated_entity = $entity->getTranslation($language_id);

            $state_map_key = "state_map.{$info_revision['entity_type']}.{$info_revision['bundle_id']}.{$revision_id}.{$language_id}";

            $this->stateMapStore->set($state_map_key, $translated_entity->moderation_state->target_id);

          }
        }
      }
      else {
        $this->logger->warning('Error with revision %revision_id, from the entity $id ', [
          '%id' => $info_revision['entity_id'],
          '%revision_id' => $revision_id,
        ]);
      }
      $context['sandbox']['progress']++;
      $context['sandbox']['current_index']++;
    }
  }

  /**
   * Uninstall the Workbench Moderation module.
   *
   * Note: no need to check if the module is uninstalled before calling
   * uninstall--the module installer will check for us.
   */
  public function uninstallWorkbenchModeration() {
    $moderation_information = \Drupal::service('workbench_moderation.moderation_information');

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type) {
      if ($moderation_information->isModeratableEntityType($entity_type)) {
        $tables_to_update[] = $entity_type->getDataTable();
        $tables_to_update[] = $entity_type->getRevisionDataTable();
      }
    }

    $db_connection = Database::getConnection();

    foreach ($tables_to_update as $table) {
      if ($table && $db_connection->schema()
          ->fieldExists($table, 'moderation_state')) {
        $db_connection->update($table)
          ->fields(['moderation_state' => NULL])
          ->execute();
      }
    }

    $this->moduleInstaller->uninstall(['workbench_moderation'], FALSE);
    $this->logger->notice('Workbench Moderation module is uninstalled.');
  }

  /**
   * Install the Workflows module.
   *
   * Note: no need to check if the module is installed before calling
   * install--the module installer will check for us.
   */
  public
  function installWorkflows() {
    $this->moduleInstaller->install(['workflows']);
    $this->logger->notice('Workflows module is installed.');
  }

  /**
   * Install the Content Moderation module.
   *
   * Note: no need to check if the module is installed before calling
   * install--the module installer will check for us.
   */
  public
  function installContentModeration() {
    $this->moduleInstaller->install(['content_moderation']);
    $this->logger->notice('Content Moderation module is installed.');
  }

  /**
   * Create the Workflow based on info from WBM states and transitions.
   */
  public
  function recreateWorkbenchModerationWorkflow() {
    $states = $this->migrateStore->get('states');
    $transitions = $this->migrateStore->get('transitions');
    $enabled_bundles = $this->migrateStore->get('enabled_bundles');

    // Create and save a workflow entity with the information gathered.
    // Note: this implies all entities will be squished into a single workflow.
    $workflow_config = [
      'id' => 'content_moderation_workflow',
      'label' => 'Content Moderation Workflow',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [],
        'transitions' => [],
      ],
    ];
    foreach ($states as $state) {
      $workflow_config['type_settings']['states'][$state['id']] = [
        'label' => $state['label'],
        'published' => $state['published'],
        'default_revision' => $state['default_revision'],
      ];
    }

    foreach ($transitions as $transition) {
      $workflow_config['type_settings']['transitions'][$transition['id']] = [
        'label' => $transition['label'],
        'to' => $transition['stateTo'],
        'from' => explode(',', $transition['stateFrom']),
      ];
    }

    // Instantiate the workflow from the config.
    $this->logger->info('Create workflow from config: %config', [
      '%config' => print_r($workflow_config, 1),
    ]);
    $workflow = new Workflow($workflow_config, 'workflow');
    $workflow_type_plugin = $workflow->getTypePlugin();

    // Add Content Moderation moderation to bundles that were Workbench
    // Moderation moderated.
    foreach ($enabled_bundles as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle_id) {
        $workflow_type_plugin->addEntityTypeAndBundle($entity_type_id, $bundle_id);
        $this->logger->notice('Setting Content Moderation to be enabled on %bundle_id', [
          '%bundle_id' => $bundle_id,
        ]);
      }
    }

    // Save the workflow now that it has all the configurations set.
    $workflow->save();
    $this->logger->notice('Content Moderation Workflow created.');
  }

  /**
   * Create the moderation states on all entities based on WBM data.
   */
  public
  function recreateModerationStatesOnEntities(&$context) {
    if (empty($context['sandbox'])) {

      $enabled_bundles = $this->migrateStore->get('enabled_bundles');
      $data = [];

      foreach ($enabled_bundles as $entity_type_id => $bundles) {
        $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);

        foreach ($bundles as $bundle_id) {
          // Get all entity revisions.
          $this->logger->debug('Querying for all %bundle_id revisions...', [
            '%bundle_id' => $bundle_id,
          ]);
          $entity_revisions = \Drupal::entityQuery($entity_type_id)
            ->condition('type', $bundle_id)
            ->allRevisions()
            ->execute();

          $revisions_current = \Drupal::entityQuery($entity_type_id)
            ->condition('type', $bundle_id)
            ->currentRevision()
            ->execute();

          $entity_revisions = array_diff_key($entity_revisions, $revisions_current);

          $entity_revisions = $revisions_current + $entity_revisions;

          $this->logger->debug('Setting Content Moderation states on %bundle_id entities', [
            '%bundle_id' => $bundle_id,
          ]);

          $revisions = array_map(function ($value) use ($entity_type_id, $bundle_id) {
            return [
              'entity_id' => $value,
              'entity_type' => $entity_type_id,
              'bundle_id' => $bundle_id,
            ];
          }, $entity_revisions);

          $data += $revisions;
        }
      }

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_index'] = 0;
      $context['sandbox']['values'] = $data;
      $context['sandbox']['max'] = sizeof($data)/*50*/
      ; //@TODO 50 is here for have tests more quick. Don't forget to remove it.
      $context['sandbox']['current_running'] = 0;

    }

    $limit = 50;

    $revisions_process = array_slice($context['sandbox']['values'], $context['sandbox']['current_index'], $limit, TRUE);

    $this->logger->info('Revision process: %revision', [
      '%revision' => print_r($revisions_process, 1),
    ]);

    // Update the state for all revisions if a state id is found.
    foreach ($revisions_process as $revision_id => $info_revision) {

      // Get the state if it exists.
      $state_map_key = "state_map.{$info_revision['entity_type']}.{$info_revision['bundle_id']}.{$revision_id}";
      $state_id = $this->stateMapStore->get($state_map_key);
      $entity = \Drupal::entityTypeManager()
        ->getStorage($info_revision['entity_type'])
        ->loadRevision($revision_id);
      if (!$state_id) {
        $this->logger->debug('Skipping updating state on id:%id, revision:%revision_id because no state exists', [
          '%id' => $entity->id(),
          '%revision_id' => $revision_id,
          '%state' => $state_id,
        ]);

        $context['sandbox']['progress']++;
        $context['sandbox']['current_index']++;
        continue;
      }

      // Set the state.
      $entity = \Drupal::entityTypeManager()
        ->getStorage($info_revision['entity_type'])
        ->loadRevision($revision_id);
      $entity->moderation_state = $state_id;
      $entity->save();
      $this->logger->debug('Setting Workbench Moderation state field on id:%id, revision:%revision_id to %state', [
        '%id' => $entity->id(),
        '%revision_id' => $revision_id,
        '%state' => $state_id,
      ]);

      // Remove the state from key value store to indicate the entity has
      // been successfully updated.
      $this->stateMapStore->delete($state_map_key);

      // Handle translations.
      // Get all languages except the default (which we've already handled).
      $languages = $entity->getTranslationLanguages(FALSE);
      $language_ids = [];
      foreach ($languages as $language) {
        $language_ids[] = $language->getId();
      }
      foreach ($language_ids as $language_id) {
        // Get the state if it exists.
        // @todo how to get all revisions for this translation?
        $translated_entity = $entity->getTranslation($language_id);

        $state_map_key = "state_map.{$info_revision['entity_type']}.{$info_revision['bundle_id']}.{$revision_id}.{$language_id}";
        $state_id = $this->stateMapStore->get($state_map_key);
        if (!$state_id) {
          $this->logger->debug('Skipping updating state on id:%id, revision:%revision_id, lang:%lang because no state exists', [
            '%id' => $translated_entity->id(),
            '%revision_id' => $revision_id,
            '%lang' => $language_id,
            '%state' => $state_id,
          ]);
          continue;
        }

        // Set the state.
        $translated_entity->moderation_state = $state_id;
        $translated_entity->save();
        $this->logger->debug('Setting Workbench Moderation state field on id:%id, revision:%revision_id to %state', [
          '%id' => $translated_entity->id(),
          '%revision_id' => $revision_id,
          '%state' => $state_id,
        ]);

        // Remove the state from key value store to indicate the entity has
        // been successfully updated.
        $this->stateMapStore->delete($state_map_key);
      }

      $context['sandbox']['progress']++;
      $context['sandbox']['current_index']++;
    }
  }

  /**
   * Remove all temporary data from key value used for the migration.
   *
   * The following are not cleaned up by this:
   *   - Any state used to manage progress of the migration, e.g. BatchManager.
   *   - The state map keys, which should be cleaned up during processing.
   */
  public
  function cleanupKeyValue() {
    $this->migrateStore->deleteMultiple([
      'states',
      'transitions',
      'enabled_bundles',
    ]);
  }

  /**
   * Purge all key value stores used used by the migrate manager.
   *
   * Note: this should only be used during the module's uninstall.
   */
  public
  function purgeAllKeyValueStores() {
    $this->migrateStore->deleteAll();
    $this->stateMapStore->deleteAll();
  }
}
