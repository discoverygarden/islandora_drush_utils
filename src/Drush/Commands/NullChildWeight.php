<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora_drush_utils\Drush\Commands\Traits\LoggingTrait;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command to identify/update nodes with a mix of values in field_weight.
 */
class NullChildWeight extends DrushCommands implements ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use LoggingTrait;

  /**
   * The options for the Drush command.
   *
   * @var array|null
   */
  protected ?array $options;

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected IslandoraUtils $utils,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('islandora.utils'),
    );
  }

  /**
   * Identify/update nodes if a mix of NULLs and integers exist in field_weight.
   *
   * @param string $parent_nid
   *   The ID of the parent node that is being searched through.
   * @param array $options
   *   Array of options passed by the command.
   *
   * @command islandora_drush_utils:null-child-weight-updater
   * @aliases islandora_drush_utils:ncwu,idu:ncwu
   * @option dry-run Avoid making changes to the system.
   * @usage drush islandora_drush_utils:null-child-weight-updater --verbose
   * --dry-run 10 Dry-run/simulate identifying and updating all children of node
   * 10 that match the conditions.
   *
   * @islandora-drush-utils-user-wrap
   */
  public function update(string $parent_nid, array $options = [
    'dry-run' => FALSE,
  ]) : void {
    $this->options = $options;

    // XXX: Determine whether this parent has children that are heterogeneous
    // in nature. That is, there are three scenarios that can occur:
    // 1. All children have NULL values in "field_weight".
    // 2. All children have integer values in "field_weight".
    // 3. One or more children have NULL values in "field_weight" and one or
    // more children have integer values in "field_weight".
    // Scenario number 3 is what is being targeted here.
    $query = $this->database->select('node', 'n');

    $mo_alias = $query->join('node__field_member_of', 'm', '%alias.entity_id = n.nid');
    $weight_alias = $query->leftJoin('node__field_weight', 'w', '%alias.entity_id = n.nid');
    $query->addExpression("max({$weight_alias}.field_weight_value)", 'max_weight');

    $query->condition("{$mo_alias}.field_member_of_target_id", $parent_nid)
      // The number of children counted is _not_ equal to the count of
      // (non-null) weight values; and...
      ->having("COUNT(n.nid) != COUNT({$weight_alias}.field_weight_value)")
      // ... we have _some_ (non-null) weight values.
      ->having("COUNT({$weight_alias}.field_weight_value) > 0");
    // FALSE if doesn't match; otherwise, the max weight present.
    $highest_weight = $query->execute()->fetchField();

    if ($highest_weight) {
      if ($this->options['dry-run']) {
        $null_nids = $this->getBaseQuery($parent_nid)->execute();
        $this->log($this->t('Would have set weight on nids: @nids', [
          '@nids' => implode(', ', $null_nids),
        ]), [], LogLevel::INFO);
      }
      else {
        $batch = [
          'title' => $this->t('Adding missing weight values...'),
          'operations' => [
            [
              [$this, 'weightBatch'], [
                $parent_nid,
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
      $this->log($this->t('No applicable children found to be updated.'), [], LogLevel::INFO);
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
  public function validateUpdate(CommandData $command_data) : void {
    $parent_nid = $command_data->input()->getArgument('parent_nid');

    $parent = $this->entityTypeManager->getStorage('node')->load($parent_nid);
    if (!$parent instanceof NodeInterface) {
      throw new \RuntimeException("The node (nid: $parent_nid) does not exist.");
    }

    if (!$parent->hasField(IslandoraUtils::MEMBER_OF_FIELD)) {
      throw new \RuntimeException("The node (nid: $parent_nid) does not have an Islandora 'member of' field.");
    }
  }

  /**
   * Helper to get the base query to be used to find NULL children.
   *
   * @param string $parent_nid
   *   The ID of the parent node that is being searched through.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query to be run.
   */
  protected function getBaseQuery(string $parent_nid) : QueryInterface {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $base_query = $node_storage->getQuery()
      ->condition('field_member_of', $parent_nid)
      ->notExists('field_weight')
      ->accessCheck();
    return $base_query;
  }

  /**
   * Batch for updating NULL field_weight values where siblings are integers.
   *
   * @param string $parent_nid
   *   The node ID of the parent object being targeted.
   * @param int $starting_weight
   *   The starting weight to be used.
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function weightBatch(string $parent_nid, int $starting_weight, &$context) : void {
    $sandbox =& $context['sandbox'];

    $base_query = $this->getBaseQuery($parent_nid);
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
        $node = $this->entityTypeManager->getStorage('node')->load($result);
        if (!$node) {
          $this->log(
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
        $this->log(
          'Updated weight for {node}.', [
            'node' => $node->id(),
          ]
        );
        $sandbox['weight']++;
      }
      catch (\Exception $e) {
        $this->log(
          'Encountered an exception: {exception}', [
            'exception' => $e,
          ], LogLevel::ERROR
        );
      }
      $sandbox['completed']++;
      $context['finished'] = $sandbox['completed'] / $sandbox['total'];
    }
  }

}
