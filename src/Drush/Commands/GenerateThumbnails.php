<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora_drush_utils\Drush\Commands\Traits\NodeIdParsingTrait;
use Drupal\islandora_drush_utils\Services\DerivativesGeneratorBatchService;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command to rederive thumbnails.
 */
class GenerateThumbnails extends DrushCommands implements ContainerInjectionInterface {

  use StringTranslationTrait;
  use NodeIdParsingTrait;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $storage
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   A Drupal database connection.
   */
  public function __construct(
    protected EntityTypeManagerInterface $storage,
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
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
   * @option model CSV of names of the model to filter by (Video, Image, Page,
   *   etc.).
   *
   * @command islandora_drush_utils:rederive_thumbnails
   * @aliases idu:rtn,rtn
   *
   * @islandora-drush-utils-user-wrap
   */
  public function rederive(array $options = [
    'nids' => self::REQ,
    'bundle' => 'islandora_object',
    'model' => self::REQ,
  ]) {
    $entities = [];

    if (is_null($options['nids'])) {
      // Load the model external uri.
      $term_query = $this->storage->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('vid', 'islandora_models')
        ->accessCheck();

      if ($options['model'] && ($models = str_getcsv($options['model']))) {
        $term_query->condition('name', $models, 'IN');
      }

      // Get all nodes relevant.
      $entities = $this->storage->getStorage('node')
        ->getQuery()
        ->condition('type', $options['bundle'])
        ->condition('field_model', $term_query->execute(), 'IN')
        ->sort('nid', 'ASC')
        ->accessCheck()
        ->execute();
    }
    else {
      $entities = static::parseNodeIds($options['nids']);
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
