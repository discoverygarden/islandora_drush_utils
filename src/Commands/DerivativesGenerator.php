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
   * IslandoraUtils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   An instance of the "IslandoraUtils" service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger to which to log.
   */
  public function __construct(IslandoraUtils $utils, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->utils = $utils;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Derivatives generator, generate derivatives based on source_uri, nids,
   * or model_name. Only provide one input for evaluation.
   *
   * @option media_use_uri The "media use" term for which to rederive
   *   derivatives, based on actions configured around this URI.
   * @option nids A comma seperated list of node IDs for which to rederive
   *   derivatives, or a file path to a file containing a list of node IDs.
   * @option model_name A comma seperated list of human readable model names
   *   for which to re-derive.
   *
   * @command islandora_drush_utils:derivativesgenerator
   * @aliases islandora_drush_utils:dg,idu:dg
   *
   * @islandora-drush-utils-user-wrap
   */
  public function derivativesGenerator(array $options = [
    'media_use_uri' => 'http://pcdm.org/use#ThumbnailImage',
    'nids' => '',
    'model_name' => '',
  ]) {
    $entities = [];

    if (is_null($options['nids'])) {
      if (!is_null($options['model_name'])) {
        $uri_id = $this->entityTypeManager->getStorage('taxonomy_term')
          ->getQuery()
          ->condition('name', $options['model_name'])
          ->execute();

        if (!is_null($uri_id)) {
          $uri_id = reset($uri_id);
          $uri = $this->entityTypeManager->getStorage('taxonomy_term')->load($uri_id);
          $uri = $uri->get('field_external_uri')->getValue()[0];
          $uri = reset($uri);

          // Get all nodes relevant.
          $entities = $this->entityTypeManager->getStorage('node')
            ->getQuery()
            ->condition('type', 'islandora_object')
            ->condition('field_model.entity:taxonomy_term.field_external_uri.uri', $uri)
            ->sort('nid', 'ASC')
            ->execute();
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

  /**
   * Helper to get the base query to be used.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query to be run.
   */
  protected function getBaseQuery(array $source_uri_taxonomy_ids, $node_ids, $models) {
    // Get all nodes relevant.
    $base_query = $this->entityTypeManager->getStorage('media')->getQuery()
      ->exists('field_media_of')
      ->condition('field_media_use', $source_uri_taxonomy_ids, 'IN');

    if (!empty($node_ids)) {
      $base_query->condition('field_media_of', explode(',', $node_ids), 'IN');
    }
    if (!empty($models)) {
      $base_query->condition('field_media_of.entity:node.field_model', $models, 'IN');
    }
    return $base_query;
  }

  /**
   * Batch for re-deriving derivatives.
   *
   * @param array $source_uri_taxonomy_ids
   *   The TIDs to be used for batch derivation.
   * @param string $node_ids
   *   A comma-separated list of node IDs to be used for batch derivation.
   * @param string $models
   *   A model to be used for batch derivation.
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function deriveBatch(array $source_uri_taxonomy_ids, $node_ids, $models, &$context) {
    $sandbox =& $context['sandbox'];

    $media_storage = $this->entityTypeManager->getStorage('media');
    $base_query = $this->getBaseQuery($source_uri_taxonomy_ids, $node_ids, $models);

    if (!isset($sandbox['total'])) {
      $count_query = clone $base_query;
      $sandbox['total'] = $count_query->count()->execute();
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('Batch empty.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['last_mid'] = FALSE;
      $sandbox['completed'] = 0;
    }

    if ($sandbox['last_mid']) {
      $base_query->condition('mid', $sandbox['last_mid'], '>');
    }
    $base_query->sort('mid');
    $base_query->range(0, 10);
    foreach ($base_query->execute() as $result) {
      try {
        $sandbox['last_mid'] = $result;
        $media = $media_storage->load($result);
        if (!$media) {
          $this->logger->debug(
            'Failed to load media {media}; skipping.', [
              'media' => $result,
            ]
          );
          continue;
        }
        $node = $this->utils->getParentNode($media);
        if (!$node) {
          $this->logger->debug(
            'Failed to load identify/load node for media {media}; skipping.', [
              'media' => $result,
            ]
          );
          continue;
        }
        $context['message'] = $this->t(
          'Derivative reactions executed for node: @node, media: @media.', [
            '@node' => $node->id(),
            '@media' => $media->id(),
          ]
        );
        $this->utils->executeDerivativeReactions(
          DerivativeReaction::class,
          $node,
          $media
        );
        $this->logger->debug(
          'Derivative reactions executed for {node}/{media}.', [
            'node' => $node->id(),
            'media' => $media->id(),
            ]
        );
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Encountered an exception: {exception}', [
            'exception' => $e,
          ]
        );
      }
      $sandbox['completed']++;
      $context['finished'] = $sandbox['completed'] / $sandbox['total'];
    }
  }

}
