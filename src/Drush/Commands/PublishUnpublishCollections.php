<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\IslandoraUtils;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * BulkPublishUnpublish commands.
 *
 * These commands publish/unpublish children and related media
 * of given collection nids.
 * They do not affect the actual collection node.
 */
class PublishUnpublishCollections extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $storage;

  /**
   * The Islandora utilities service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\islandora\IslandoraUtils $islandora_utils
   *   The Islandora utilities.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logging service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, IslandoraUtils $islandora_utils, LoggerInterface $logger) {
    $this->storage = $entity_type_manager;
    $this->utils = $islandora_utils;
    $this->logger()?->add('islandora_drush_utils', $logger);
  }

  /**
   * Mass updates nodes and their media status that are an ancestor of Node IDs.
   *
   * @param string $ancestor_nids
   *   List of Node IDs to publish or unpublish. Delimit using a comma.
   * @param array $options
   *   Additional command options.
   *
   * @option publish Either TRUE or FALSE to indicate the publish status.
   * @option batchSize An integer of the amount of nodes to process per batch.
   *
   * @command islandora_drush_utils:update-status
   * @aliases idr:us
   */
  public function updateStatus(
    string $ancestor_nids,
    array $options = [
      'publish' => FALSE,
      'batchSize' => 100,
    ],
  ) {
    $ancestor_nids = explode(',', $ancestor_nids);

    $batch = [
      'title' => $this->t('Updating status of all nodes and media belonging to passed NIDs.'),
      'operations' => [
        [
          [$this, 'updateStatusBatch'],
          [$options['batchSize'], $options['publish'], $ancestor_nids],
        ],
      ],
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Batch for updating publish status of all items from multiple ancestors.
   *
   * @param int $batch_size
   *   The number of nodes to process at a single time.
   * @param bool $publish
   *   The publish status of the nodes and media.
   * @param array $ancestor_nids
   *   The array of NIDs to search for as an ancestor.
   */
  public function updateStatusBatch(int $batch_size, bool $publish, array $ancestor_nids) {
    $query = $this->storage->getStorage('node')->getQuery()
      ->condition('type', 'islandora_object')
      ->exists('field_member_of');

    $sandbox = &$context['sandbox'];

    if (!isset($sandbox['total'])) {
      $count_query = clone $query;
      $sandbox['total'] = $count_query->count()->execute();
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('Batch is empty.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['last'] = FALSE;
      $sandbox['completed'] = 0;
    }

    if ($sandbox['last']) {
      $query->condition('nid', $sandbox['last'], '>');
    }

    $query->sort('nid');
    $query->range(0, $batch_size);

    foreach ($query->execute() as $result) {
      try {
        $sandbox['last'] = $result;
        $node = $this->storage->getStorage('node')->load($result);

        if (!$node) {
          $this->logger->debug('Failed to load {node}; skipping.', [
            'node' => $result,
          ]);
          continue;
        }

        $this->logger->debug('Processing node {id}; finding ancestors.', [
          'id' => $node->id(),
        ]);

        $ancestors = $this->utils->findAncestors($node);
        if (count(array_intersect($ancestors, $ancestor_nids)) > 0) {
          if ($publish) {
            $node->setPublished()->save();
          }
          else {
            $node->setUnpublished()->save();
          }

          $mids = $this->storage->getStorage('media')
            ->getQuery()
            ->exists('field_media_of')
            ->condition('field_media_of', $node->id())
            ->execute();

          $medias = $this->storage->getStorage('media')->loadMultiple($mids);
          foreach ($medias as $media) {
            if (!$media) {
              $this->logger->debug('Failed to load {media}; skipping.', [
                'media' => $media,
              ]);
              continue;
            }

            $this->logger->debug('Processing media {id}; of node {nid}.', [
              'id' => $media->id(),
              'nid' => $node->id(),
            ]);
            if ($publish) {
              $media->setPublished()->save();
            }
            else {
              $media->setUnpublished()->save();
            }
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->exception('Encountered an exception: {exception}', [
          'exception' => $e,
        ]);
      }
      finally {
        $sandbox['completed']++;
        $sandbox['finished'] = $sandbox['completed'] / $sandbox['total'];
      }
    }

  }

}
