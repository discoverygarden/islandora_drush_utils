<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dgi_standard_derivative_examiner\Utility\ExaminerInterface;
use Drupal\node\NodeStorageInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command to identify missing derivatives.
 */
class MissingDerivatives extends DrushCommands implements ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use LoggingTrait;

  /**
   * The node storage service.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ExaminerInterface $examiner,
  ) {
    parent::__construct();
    $this->nodeStorage = $this->entityTypeManager->getStorage('node');
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dgi_standard_derivative_examiner.examiner'),
    );
  }

  /**
   * Identify any objects with missing derivatives.
   *
   * @command islandora_drush_utils:missing-derivatives
   * @aliases islandora_drush_utils:md,idu:md
   * @usage drush islandora_drush_utils:missing-derivatives --verbose
   * @option iteration_size The number of nodes to process per iteration, for tuning.
   *
   * @islandora-drush-utils-user-wrap
   */
  public function update(array $options = [
    'iteration_size' => 10,
  ]): void {
    $node_count = $this->getBaseQuery()->count()->execute();

    if ($node_count) {
      $batch = [
        'title' => $this->t('Reviewing @node_count object(s) for missing derivatives..',
          [
            '@node_count' => $node_count,
          ]
        ),
        'operations' => [
          [
            [$this, 'missingDerivatives'],
            [
              $options['iteration_size'],
            ],
          ],
        ],
      ];
      drush_op('batch_set', $batch);
      drush_op('drush_backend_batch_process');
    }
    else {
      $this->log(
        $this->t(
          'No nodes of type islandora_object found. Exiting without further processing.'
        ),
        [],
        LogLevel::INFO
      );
    }

  }

  /**
   * Helper to get the base query to be used.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query to be run.
   */
  protected function getBaseQuery(): QueryInterface {
    // Get all nodes relevant.
    return $this->nodeStorage
      ->getQuery()
      ->condition('type', 'islandora_object')
      ->accessCheck();
  }

  /**
   * Batch for examining nodes for missing derivatives.
   *
   * @param int $iteration_size
   *   The number of nodes to process per batch iteration.
   * @param \DrushBatchContext|array $context
   *   Batch context.
   */
  public function missingDerivatives(int $iteration_size, \DrushBatchContext|array &$context): void {
    $sandbox =& $context['sandbox'];
    $base_query = $this->getBaseQuery();

    if (!isset($sandbox['total'])) {
      $count_query = clone $base_query;
      $sandbox['total'] = $count_query->count()->execute();
      if ($sandbox['total'] === 0) {
        $context['message'] = $this->t('Batch empty.');
        $context['finished'] = 1;
        return;
      }
      $sandbox['completed'] = 0;
      $sandbox['last_nid'] = FALSE;
    }

    if ($sandbox['last_nid']) {
      $base_query->condition('nid', $sandbox['last_nid'], '>');
    }

    $base_query->sort('nid');
    $base_query->range(0, $iteration_size);

    $node_ids = $base_query->execute();
    $nodes = $this->nodeStorage->loadMultiple($node_ids) + array_fill_keys($node_ids, FALSE);
    foreach ($nodes as $nid => $node) {
      try {
        $sandbox['last_nid'] = $nid;
        if (!$node) {
          $this->log(
            "Failed to load node {node}; skipping.\n", [
              'node' => $nid,
            ]
          );
          continue;
        }
        $context['message'] = dt(
          "Examining derivatives for node id: @node.\n", [
            '@node' => $node->id(),
          ]
        );

        foreach ($this->examiner->examine($node) as $derivative_message) {
          $context['message'] = dt(
            "Missing derivatives detected. \n nid: {node}, bundle: {bundle}, uri: {use_uri}. \n Message: {message}\n", [
              'node' => $node->id(),
              'bundle' => $derivative_message['bundle'],
              'use_uri' => $derivative_message['use_uri'],
              'message' => $derivative_message['message'],
            ]
          );
        }
        $this->log(
          "Examination complete for node id: @node.\n", [
            '@node' => $node->id(),
          ]
        );

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
