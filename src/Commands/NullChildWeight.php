<?php

namespace Drupal\islandora_drush_utils\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\IslandoraUtils;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Drush command to rederive thumbnails.
 */
class NullChildWeight extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Drupal database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Islandora utils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $ourLogger;

  /**
   * The options for the Drush command.
   *
   * @var array|null
   */
  protected ?array $options;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The Drupal database connection.
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   The Islandora utils service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger to which to log.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, IslandoraUtils $utils, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->utils = $utils;
    $this->ourLogger = $logger;
  }

  /**
   * Identify update values when a mixture of NULLs and weights are present.
   *
   * @param array $options
   *   Array of options passed by the command.
   *
   * @command islandora_drush_utils:null-child-weight-updater
   * @aliases islandora_drush_utils:ncwu,idu:ncwu
   * @option dry-run Avoid making changes to the system.
   * @option parent-nid The parent being targeted for updates.
   *
   * @islandora-drush-utils-user-wrap
   */
  public function update(array $options = [
    'parent-nid' => InputOption::VALUE_REQUIRED,
    'dry-run' => FALSE,
  ]) {
    $this->options = $options;

    // XXX: Determine whether this parent has children that are heterogeneous
    // in nature. That is, there are three scenarios that can occur:
    // 1. All children have NULL values in "field_weight".
    // 2. All children have integer values in "field_weight".
    // 3. One or more children are NULL and one or more children have integer
    // weights in "field_weight".
    // Scenario number 3 is what is being targeted here.
    $data_query = $this->database->select('node_field_data', 'd');

    $member_of_alias = $data_query->leftJoin('node__field_member_of', 'mo', '%alias.entity_id = d.nid');
    $weight_alias = $data_query->leftJoin('node__field_weight', 'w', '%alias.entity_id = d.nid');

    $data_query->fields('d', ['nid']);
    $data_query->fields($weight_alias, ['field_weight_value']);

    $results = $data_query->condition("$member_of_alias.field_member_of_target_id", $this->options['parent-nid'])
      ->orderBy("$weight_alias.field_weight_value")
      ->execute()
      ->fetchAllKeyed();
    if (empty($results)) {
      throw new \RuntimeException("No children found for the node ({$this->options['parent-nid']}).");
    }

    $applicable_null = FALSE;
    $applicable_int = FALSE;
    $highest_weight = 0;
    $null_nids = [];
    foreach ($results as $nid => $weight) {
      if ($weight === NULL) {
        $applicable_null = TRUE;
        $null_nids[] = $nid;
      }
      else {
        $applicable_int = TRUE;
        $highest_weight = $weight;
      }
    }

    if ($applicable_null && $applicable_int) {
      if ($this->options['dry-run']) {
        $this->ourLogger->log('info', $this->t('Would have set weight on nids: @nids', [
          '@nids' => implode(', ', $null_nids),
        ]));
      }
      else {
        $batch = [
          'title' => $this->t('Adding missing weight values...'),
          'operations' => [
            [
              [$this, 'weightBatch'], [
                $this->options['parent-nid'],
                $highest_weight + 1,
              ],
            ],
          ],
        ];
        drush_op('batch_set', $batch);
        drush_op('drush_backend_batch_process');

      }
    }
    else {
      $this->ourLogger->log('info', $this->t('No applicable children found to be updated.'));
    }

  }

  /**
   * Validation for the null weight updater Drush command.
   *
   * @param \Consolidation\AnnotatedCommand\CommandData $command_data
   *   The command data.
   *
   * @hook validate islandora_drush_utils:null-child-weight-updater
   */
  public function validateUpdate(CommandData $command_data) {
    $parent_nid = $command_data->input()->getOption('parent-nid');
    if (!$parent_nid) {
      throw new \RuntimeException('The "parent-nid" option must be defined.');
    }

    $parent = $this->entityTypeManager->getStorage('node')->load($parent_nid);
    if (!$parent instanceof NodeInterface) {
      throw new \RuntimeException("The node (nid: $parent_nid) does not exist.");
    }

    if (!$parent->hasField(IslandoraUtils::MEMBER_OF_FIELD)) {
      throw new \RuntimeException("The node (nid: $parent_nid) does not have an Islandora 'member of' field.");
    }
  }

  /**
   * Batch for re-deriving derivatives.
   *
   * @param string $parent_nid
   *   The node ID of the parent object being targeted.
   * @param int $starting_weight
   *   The starting weight to be used.
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function weightBatch(string $parent_nid, int $starting_weight, &$context) {
    $sandbox =& $context['sandbox'];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $base_query = $node_storage->getQuery()
      ->condition('field_member_of', $parent_nid)
      ->notExists('field_weight');
    if (!isset($sandbox['total'])) {
      $count_query = clone $base_query;
      $sandbox['total'] = $count_query->count()->execute();
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('Batch empty.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['last_nid'] = FALSE;
      $sandbox['completed'] = 0;
      $sandbox['weight'] = $starting_weight;
    }

    if ($sandbox['last_nid']) {
      $base_query->condition('nid', $sandbox['last_nid'], '>');
    }
    $base_query->sort('nid');
    $base_query->range(0, 10);
    foreach ($base_query->execute() as $result) {
      try {
        $sandbox['last_nid'] = $result;
        $node = $node_storage->load($result);
        if (!$node) {
          $this->logger->debug(
            'Failed to load node {node}; skipping.', [
              'node' => $result,
            ]
          );
          continue;
        }
        $context['message'] = dt(
          'Updating weight for node: @node.', [
            '@node' => $node->id(),
          ]
        );
        $node->set('field_weight', $sandbox['weight']);
        $node->save();
        $this->logger->info(
          'Updated weight for {node}.', [
            'node' => $node->id(),
          ]
        );
        $sandbox['weight']++;
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
