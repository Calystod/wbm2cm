<?php

namespace Drupal\wbm2cm\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wbm2cm\BatchManager;

/**
 * Batch process the WBM to CM migration.
 */
class MigrateBatchController implements ContainerInjectionInterface {

  /**
   * The batch manager for the migration.
   *
   * @var \Drupal\wbm2cm\BatchManager
   */
  protected $batchManager;

  /**
   * Instantiate the migrate batch controller.
   *
   * @param \Drupal\wbm2cm\BatchManager $batch_manager
   *   The batch manager for the migration.
   */
  public function __construct(BatchManager $batch_manager) {
    $this->batchManager = $batch_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wbm2cm.batch_manager')
    );
  }

  /**
   *
   */
  public function prepareBatch() {

  }

  protected function prepareStep2() {
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

          $enabled_bundles[$entity_type->id()][] = $bundle->id();

          // Collect entity state map and remove Workbench moderation_state field from
          // enabled bundles.

          $entity_storage = \Drupal::service('entity_type.manager')
            ->getStorage($entity_type->id());

          $entity_revisions = \Drupal::entityQuery($entity_type->id())
            ->condition('type', $bundle->id())
            ->allRevisions()
            ->execute();
          $data = array_merge($data, $entity_revisions);
        }
      }
    }
    ksm($data);

  }

  /**
   * Set the batch tasks and trigger batch process.
   */
  public function migrate() {


    $batch = [
      'title' => t('Migrating WBM to CM'),
      'operations' => [
        //        ['wbm2cm_step1', []],
        ['wbm2cm_step2', []],
        //        ['wbm2cm_step3', []],
        //        ['wbm2cm_step4', []],
        //        ['wbm2cm_step5', []],
        //        ['wbm2cm_step6', []],
        //        ['wbm2cm_step7', []],
        //        ['wbm2cm_step8', []],
      ],
      'finished' => 'wbm2cm_migrate_finished_callback',
      'file' => drupal_get_path('module', 'wbm2cm') . '/wbm2cm.migrate.inc',
    ];
    batch_set($batch);
    return batch_process();
  }

}
