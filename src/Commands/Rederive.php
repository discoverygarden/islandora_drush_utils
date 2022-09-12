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
class Rederive extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Instance of "IslandoraUtils" service, for... utility.
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
   * Rederive ALL the things!
   *
   * @option source_uri The "media use" for which to rederive.
   *
   * @command islandora_drush_utils:rederive
   * @aliases islandora_drush_utils:r,idu:r
   */
  public function rederive(array $options = [
    'source_uri' => 'http://pcdm.org/use#OriginalFile',
  ]) {
    $original_file_taxonomy_ids = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('field_external_uri', $options['source_uri'])
      ->execute();
    if (empty($original_file_taxonomy_ids)) {
      throw new \Exception("The provided 'source_uri' ({$options['source_uri']}) did not match any taxonomy terms 'field_external_uri' field.");
    }

    $batch = [
      'title' => $this->t('Re-deriving derivatives...'),
      'operations' => [[[$this, 'deriveBatch'], [$original_file_taxonomy_ids]]],
    ];
    drush_op('batch_set', $batch);
    drush_op('drush_backend_batch_process');
  }

  /**
   * Batch for re-deriving derivatives.
   *
   * @param array $original_file_taxonomy_ids
   *   The TIDs to be used for batch derivation.
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function deriveBatch(array $original_file_taxonomy_ids, &$context) {
    $sandbox =& $context['sandbox'];

    $media_storage = $this->entityTypeManager->getStorage('media');
    $base_query = $media_storage->getQuery()
      ->exists('field_media_of')
      ->condition('field_media_use', $original_file_taxonomy_ids, 'IN');
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
          $this->logger->debug('Failed to load media {media}; skipping.', [
            'media' => $result,
          ]);
          continue;
        }
        $node = $this->utils->getParentNode($media);
        if (!$node) {
          $this->logger->debug('Failed to load identify/load node for media {media}; skipping.', [
            'media' => $result,
          ]);
          continue;
        }
        $context['message'] = $this->t('Derivative reactions executed for node: @node, media: @media.', [
          '@node' => $node->id(),
          '@media' => $media->id(),
        ]);
        $this->utils->executeDerivativeReactions(
          DerivativeReaction::class,
          $node,
          $media
        );
        $this->logger->debug('Derivative reactions executed for {node}/{media}.', [
          'node' => $node->id(),
          'media' => $media->id(),
        ]);
      }
      catch (\Exception $e) {
        $this->logger->exception('Encountered an exception: {exception}', [
          'exception' => $e,
        ]);
      }
      $sandbox['completed']++;
      $context['finished'] = $sandbox['completed'] / $sandbox['total'];
    }
  }

}
