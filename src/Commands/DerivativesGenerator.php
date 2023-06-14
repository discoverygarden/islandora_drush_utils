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
   * Derivatives generator, generate derivatives based on source_uri, node_ids,
   * and/or model_uri. If no 'source_uri' is provided, the default is
   * 'http://pcdm.org/use#OriginalFile'.
   *
   * @option source_uri The "media use" term for which to rederive derivatives.
   * @option node_ids A comma seperated list of node IDs for which to rederive
   *   derivatives.
   * @option models A comma seperated list of models for which to rederive
   *   derivatives.
   * @option machine_name
   *   A comma seperated list of machine names (node UUID's) to target for
   *   rederivation.
   *
   * @command islandora_drush_utils:derivativesgenerator
   * @aliases islandora_drush_utils:dg,idu:dg
   *
   * @islandora-drush-utils-user-wrap
   */
  public function derivativesGenerator(array $options = [
    'source_uri' => 'http://pcdm.org/use#OriginalFile',
    'node_ids' => '',
    'model_uri' => '',
    'machine_name' => '',
  ]) {
    $source_uri_taxonomy_ids = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('field_external_uri', $options['source_uri'])
      ->execute();
    if (empty($source_uri_taxonomy_ids)) {
      throw new \Exception("The provided 'source_uri' ({$options['source_uri']}) did not match any taxonomy terms 'field_external_uri' field.");
    }

    $model_uri_taxonomy_ids = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('field_external_uri', explode(",", $options['model_uri']))
      ->execute();

    $batch = [
      'title' => $this->t('(Re)deriving derivatives...'),
      'operations' => [
        [
          [$this, 'deriveBatch'],
          [$source_uri_taxonomy_ids, $options['node_ids'], $model_uri_taxonomy_ids]
        ]
      ],
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
