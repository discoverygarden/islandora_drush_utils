<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\Plugin\ContextReaction\DerivativeReaction;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * Drush command implementation.
 */
class DerivativesGenerator extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Retrieve the base query for a given URI.
   *
   * @param string $uri
   *   The URI to query for.
   * @return mixed
   *   The query object.
   */
  private function getBaseQuery(string $uri) {
    return $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'islandora_object')
      ->condition('field_model.entity:taxonomy_term.field_external_uri.uri', $uri)
      ->sort('nid', 'ASC');
  }

  /**
   * Derivatives generator, generate derivatives based on source_uri, nids,
   * or model_name. Only provide one input for evaluation.
   *
   * @option media_use_uri Required, the "media use" term for which to re-derive
   *   derivatives, based on actions configured around this URI. Defaults to
   *   'http://pcdm.org/use#ThumbnailImage'.
   * @option nids A comma seperated list of node IDs for which to re-derive
   *   derivatives, or a file path to a file containing a list of node IDs.
   * @option model_uri An Islandora Models taxonomy term URI.
   *   (IE: "http://purl.org/coar/resource_type/c_c513" for 'Image').
   *
   * @command islandora_drush_utils:derivativesgenerator
   * @aliases islandora_drush_utils:dg,idu:dg
   *
   * @islandora-drush-utils-user-wrap
   */
  public function derivativesGenerator(array $options = [
    'media_use_uri' => 'http://pcdm.org/use#ThumbnailImage',
    'nids' => '',
    'model_uri' => '',
    'model_name' => '',
  ]) {
    // Populate a list of entities to re-derive using the model_name or nids.
    $entities = [];

    if (empty($options['nids'])) {
      if (!empty($options['model_uri'])) {
        // Get all nodes relevant.
        $entities = $this->getBaseQuery($options['model_uri'])->execute();
      }

      if (!empty($options['model_name'])) {
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')
          ->getQuery()
          ->condition('name', $options['model_name'])
          ->condition('vid', 'islandora_models')
          ->execute();

        $term_id = reset($terms);
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
        $term_data = $term->get('field_external_uri')->getValue()[0];

        if (!empty($term_data['uri'])) {
          $entities = $this->getBaseQuery($term_data['uri'])->execute();
        }
      }
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
      'title' => $this->t('Regenerate derivatives'),
      'operations' => [
        [
          '\Drupal\islandora_drush_utils\Services\DerivativesGeneratorBatchService::generateDerivativesOperation',
          [
            $entities,
            $options['media_use_uri']
          ],
        ],
      ],
      'init_message' => $this->t('Starting'),
      'progress_message' => $this->t('@range of @total'),
      'error_message' => $this->t('An error occurred'),
      'finished' => '\Drupal\islandora_drush_utils\Services\DerivativesGeneratorBatchService::generateDerivativesOperationFinished',
    ];

    drush_op('batch_set', $batch);
    drush_op('drush_backend_batch_process');
  }

}
