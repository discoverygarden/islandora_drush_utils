<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora_drush_utils\Drush\Commands\Traits\NodeIdParsingTrait;
use Drupal\islandora_drush_utils\Services\DerivativesGeneratorBatchService;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command implementation.
 */
class DerivativesGenerator extends DrushCommands implements ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use NodeIdParsingTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Generate derivatives based on source_uri, nids, model_uri, or model_name.
   *
   * @option media_use_uri Required, the "media use" term for which to re-derive
   *   derivatives, based on actions configured around this URI. Defaults to
   *   'http://pcdm.org/use#ThumbnailImage'.
   * @option nids A comma separated list of node IDs for which to re-derive
   *   derivatives, or a file path to a file containing a list of node IDs.
   * @option model_uri An Islandora Models taxonomy term URI.
   *   (IE: "http://purl.org/coar/resource_type/c_c513" for 'Image').
   * @option model_name CSV of model names, as alternative to a model_uri.
   *
   * @command islandora_drush_utils:derivativesgenerator
   * @aliases islandora_drush_utils:dg,idu:dg
   *
   * @islandora-drush-utils-user-wrap
   */
  public function derivativesGenerator(array $options = [
    'media_use_uri' => 'http://pcdm.org/use#ThumbnailImage',
    'nids' => self::REQ,
    'model_uri' => self::REQ,
    'model_name' => self::REQ,
  ]) {
    $entity_query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'islandora_object')
      ->sort('nid', 'ASC')
      ->accessCheck();
    $entity_info = [
      'nids' => function () use ($options) {
        return static::parseNodeIds($options['nids']);
      },
      'model_name' => function () use ($options, $entity_query) {
        return $entity_query
          ->condition('field_model.entity:taxonomy_term.name', str_getcsv($options['model_name']), 'IN')
          ->execute();
      },
      'model_uri' => function () use ($options, $entity_query) {
        return $entity_query
          ->condition('field_model.entity:taxonomy_term.field_external_uri.uri', str_getcsv($options['model_uri']), 'IN')
          ->execute();
      },
    ];
    $entity_providers_info = [
      'nids' => $options['nids'],
      'model_name' => $options['model_name'],
      'model_uri' => $options['model_uri'],
    ];

    $providers = array_intersect_key($entity_info, array_filter($entity_providers_info));

    if (count($providers) === 0) {
      throw new \InvalidArgumentException("One of 'nids', 'model_name' or 'model_uri' must be passed.");
    }
    elseif (count($providers) > 1) {
      throw new \InvalidArgumentException("Only one of 'nids', 'model_name' and 'model_uri' may be passed.");
    }

    $provider = reset($providers);
    $entities = $provider();

    // Set up batch.
    $batch = [
      'title' => $this->t('Regenerate derivatives'),
      'operations' => [
        [
          [
            DerivativesGeneratorBatchService::class,
            'generateDerivativesOperation',
          ],
          [
            $entities,
            $options['media_use_uri'],
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

    drush_op('batch_set', $batch);
    drush_op('drush_backend_batch_process');
  }

}
