<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Commands\DrushCommands;

/**
 * RebuildOaiEntries commands.
 *
 * These commands rebuild the OAI entries and consume them.
 */
class RebuildOaiEntries extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * Set variables to used across commands.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue service.
   */
  public function __construct(QueueFactory $queue) {
    $this->queue = $queue;
  }

  /**
   * Rebuild OAI entries.
   *
   * @command islandora_drush_utils:rebuild-oai
   * @aliases idr:roai
   */
  public function rebuild() {
    rest_oai_pmh_cache_views();

    $batch = [
      'title' => $this->t('Processing OAI rebuild'),
      'finished' => 'rest_oai_pmh_batch_finished',
      'operations' => [
        [
          [$this, 'rebuildBatch'],
          [],
        ],
      ],
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Batch for processing OAI queue.
   *
   * @param array|\DrushBatchContext $context
   *   The batch context.
   */
  public function rebuildBatch(&$context) {
    $queue = $this->queue->get('rest_oai_pmh_views_cache_cron');

    // Setting the defaults.
    if (empty($context['sandbox'])) {
      $context['sandbox']['processed_items'] = 0;
      $context['sandbox']['total_items'] = $queue->numberOfItems();
    }

    $item = $queue->claimItem();
    if (!$item) {
      // Queue exhausted; we're done.
      $context['finished'] = 1;
      return;
    }

    // Process queue items.
    rest_oai_pmh_process_queue($item);
    $context['sandbox']['processed_items']++;

    $context['finished'] = $context['sandbox']['processed_items'] / (
      // XXX: Force queue exhaustion above to terminate.
      $context['sandbox']['processed_items'] >= $context['sandbox']['total_items'] ?
        $context['sandbox']['processed_items'] + 1 :
        $context['sandbox']['total_items']
      );
  }

}
