<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

/**
 * RebuildOaiEntries commands.
 *
 * These commands rebuild the OAI entries and consume them.
 */
class RebuildOaiEntries extends DrushCommands {

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

    $operations = [];
    $queue = $this->queue->get('rest_oai_pmh_views_cache_cron');
    while ($item = $queue->claimItem()) {
      $operations[] = [
        'rest_oai_pmh_process_queue',
        [$item],
      ];
    }
    $batch = [
      'operations' => $operations,
      'finished' => 'rest_oai_pmh_batch_finished',
      'title' => 'Processing OAI rebuild',
      'init_message' => 'OAI rebuild is starting.',
      'progress_message' => 'Processed @current out of @total.',
      'error_message' => 'OAI rebuild has encountered an error.',
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

}
