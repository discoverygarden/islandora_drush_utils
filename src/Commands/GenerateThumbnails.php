<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora_drush_utils\Services\DerivativesGeneratorBatchService;
use Drush\Commands\DrushCommands;

/**
 * Drush command to rederive thumbnails.
 */
class GenerateThumbnails extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $storage;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   A Drupal database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    parent::__construct();
    $this->storage = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Rederive thumbnails for nodes.
   *
   * @option nids Comma separated list or path to file containing a
   *   a list of node IDs to generate thumbnails for. The file should have
   *   nodes
   *   separated by a new line.
   * @option bundle When the nids option is not provided the bundle will be
   *   used
   *   to filter results.
   * @option model The name of the model to filter by (Video, Image, Page,
   *   etc.).
   *
   * @command islandora_drush_utils:rederive_thumbnails
   * @aliases idu:rtn,rtn
   *
   * @islandora-drush-utils-user-wrap
   */
  public function rederive(array $options = [
    'nids' => NULL,
    'bundle' => 'islandora_object',
    'model' => 'Video',
  ]) {
    $entities = [];

    if (is_null($options['nids'])) {
      // Load the model external uri.
      $uri_id = $this->storage->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('name', $options['model'])
        ->condition('vid', 'islandora_models')
        ->accessCheck()
        ->execute();
      $uri_id = reset($uri_id);
      $uri = $this->storage->getStorage('taxonomy_term')->load($uri_id);
      $uri = $uri->get('field_external_uri')->getValue()[0];

      // Get all nodes relevant.
      $entities = $this->storage->getStorage('node')
        ->getQuery()
        ->condition('type', $options['bundle'])
        ->condition('field_model.entity:taxonomy_term.field_external_uri.uri', $uri['uri'])
        ->sort('nid', 'ASC')
        ->accessCheck()
        ->execute();
    }
    else {
      // If a file path is provided, parse it.
      if (is_file($options['nids'])) {
        if (is_readable($options['nids'])) {
          $entities = trim(file_get_contents($options['nids']));
          $entities = explode("\n", $entities);
        }
      }
      else {
        $entities = explode(',', $options['nids']);
      }
    }
    // Set up batch.
    $batch = [
      'title' => $this->t('Regen TNs'),
      'operations' => [
      [
        [
          DerivativesGeneratorBatchService::class,
          'generateDerivativesOperation',
        ],
        [
          $entities,
          'http://pcdm.org/use#ThumbnailImage',
        ],
      ],
      ],
      'init_message' => $this->t('Starting'),
      'progress_message' => $this->t('@range of @total'),
      'error_message' => $this->t('An error occurred'),
      'finished' => [
        DerivativesGeneratorBatchService::class,
        'generateDerivativesOperationFinished',
      ],
    ];
    batch_set($batch);
    drush_backend_batch_process();
  }

}
