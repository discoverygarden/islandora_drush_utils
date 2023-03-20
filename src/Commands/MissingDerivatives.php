<?php

namespace Drupal\islandora_drush_utils\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;
use Drupal\dgi_standard_derivative_examiner\Utility\Examiner;

/**
 * Drush command to identify/update nodes with a mix of values in field_weight.
 */
class MissingDerivatives extends DrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Examiner utility.
   *
   * @var \Drupal\dgi_standard_derivative_examiner\Utility\Examiner
   */
  protected Examiner $examiner;

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
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger to which to log.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, Examiner $examiner) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->examiner = $examiner;
  }

  /**
   * Identify any objects with missing derivatives.
   *
   * @param array $options
   *   Array of options passed by the command.
   *
   * @command islandora_drush_utils:missing-derivatives
   * @aliases islandora_drush_utils:md,idu:md
   * @option source_uri Specify the media term to query for.
   * @usage drush islandora_drush_utils:missing-derivatives --verbose
   * --source_uri 'http://pcdm.org/use#OriginalFile'
   *
   * @islandora-drush-utils-user-wrap
   */
  public function update(array $options = [
    'source_uri' => 'http://pcdm.org/use#OriginalFile',
  ]) {
    $this->options = $options;
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
            [],
          ],
        ],
      ];
      drush_op('batch_set', $batch);
      drush_op('drush_backend_batch_process');
    }
    else {
      $this->logger->log(
        'info',
        $this->t(
          'No Nodes of type islandora_object found. Exiting without further processing.'
        )
      );
    }

  }

  /**
   * Helper to get the base query to be used to find NULL children & count.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query to be run.
   */
  protected function getBaseQuery() {
    // Get all nodes relevant.
    $base_query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'islandora_object');
    return $base_query;
  }

  /**
   * Batch for examining nodes for missing derivatives.
   *
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function missingDerivatives(&$context) {
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
    $base_query->range(0, 10);

    foreach ($base_query->execute() as $result) {
      try {
        $sandbox['last_nid'] = $result;
        $node = $this->entityTypeManager->getStorage('node')->load($result);
        if (!$node) {
          $this->logger->debug(
            "Failed to load node {node}; skipping.\n", [
              'node' => $result,
            ]
          );
          continue;
        }
        $context['message'] = dt(
          "Examining derivatives for node id: @node.\n", [
            '@node' => $node->id(),
          ]
        );
        $derivative_review = $this->examiner->examine($node);
        if ($derivative_review) {
          foreach ($derivative_review as $derivative_message) {
            $context['message'] = dt(
              "Missing derivatives detected. \n nid: {node}, bundle: {bundle}, uri: {use_uri}. \n Message: {message}\n", [
                'node' => $node->id(),
                'bundle' => $derivative_message['bundle'],
                'use_uri' => $derivative_message['use_uri'],
                'message' => $derivative_message['message'],
              ]
            );
          }
        }
        $this->logger->debug(
          "Examination complete for node id: @node.\n", [
            '@node' => $node->id(),
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
