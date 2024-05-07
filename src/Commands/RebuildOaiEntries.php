<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

/**
 * RebuildOaiEntries commands.
 *
 * These commands rebuild the OAI entries and consume them.
 */
class RebuildOaiEntries extends DrushCommands {

  use DependencySerializationTrait;

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
   * @param array $options
   *   Additional command options.
   *
   * @option batchSize An integer of the amount of entries to process per batch.
   *
   * @command islandora_drush_utils:rebuild-oai
   * @aliases idr:roai
   */
  public function rebuild(array $options = [
    'batchSize' => 10,
  ]) {
    rest_oai_pmh_cache_views();

    $batch = [
      'title' => 'Processing OAI rebuild',
      'finished' => 'rest_oai_pmh_batch_finished',
      'operations' => [
        [
          [$this, 'rebuildBatch'],
          [$options['batchSize']],
        ],
      ],
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Batch for processing OAI queue.
   *
   * @param int $batch_size
   *   The number of nodes to process at a single time.
   * @param array|\DrushBatchContext $context
   *   The batch context.
   */
  public function rebuildBatch(int $batch_size, &$context) {
    $queue = $this->queue->get('rest_oai_pmh_views_cache_cron');
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = $queue->numberOfItems();
    }
    
    $item = $queue->claimItem();
    if (!$item) {
      // Queue exhausted; we're done. Shouldn't strictly be necessary, but we could explicitly
      // set `$context['finished'] = 1;` if we wanted to.
      return;
    }
    
    rest_oai_pmh_process_queue($item);

    $context['sandbox']['processed_items']++;
    
    $context['finished'] =  $context['sandbox']['processed_items'] / (
      // XXX: Force queue exhaustion above to terminate.
      $context['sandbox']['processed_items'] >= $context['sandbox']['total_items'] ?
        $context['sandbox']['processed_items'] + 1 : 
        $context['sandbox']['total_items']
      ));
  }

}
