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

  use DependencySerializationTrait {
    __sleep as depSerSleep;
    __wakeup as depSerWake;
  }
  use StringTranslationTrait;
  use LoggingTrait;

  /**
   * The node storage service.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * File path to which to write log output.
   *
   * @var string
   */
  protected string $csvLogFilename = '';

  /**
   * File pointer for the log filepath.
   *
   * @var false|resource
   */
  protected $csvLogFile;

  /**
   * The number of items to attempt to load at a time.
   *
   * @var int
   */
  protected int $iterationSize = 10;

  /**
   * File path to which to write output.
   *
   * @var string
   */
  protected string $csvOutputFilename = '';

  /**
   * File pointer for the filepath, to which to write output.
   *
   * @var resource|false
   */
  protected $csvOutputFile;

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
   * @option iteration-size The number of nodes to process per iteration, for tuning.
   * @option csv-output-file The path to a CSV file to which to write output.
   * @option csv-log-file The path to a CSV file to which to write logging info.
   *
   * @islandora-drush-utils-user-wrap
   */
  public function update(array $options = [
    'iteration-size' => 10,
    'csv-output-file' => self::REQ,
    'csv-log-file' => self::REQ,
  ]): void {
    $node_count = $this->getBaseQuery()->count()->execute();

    $this->iterationSize = $options['iteration-size'];
    $this->csvOutputFilename = $options['csv-output-file'] ?: '';
    $this->csvLogFilename = $options['csv-log-file'] ?: '';
    $this->openCsvFiles();

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
   * Helper; load up file pointers for our output CSV(s).
   */
  protected function openCsvFiles() : void {
    if ($this->csvOutputFilename) {
      $this->csvOutputFile = fopen($this->csvOutputFilename, 'a');
    }
    if ($this->csvLogFilename) {
      $this->csvLogFile = fopen($this->csvLogFilename, 'a');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function __sleep() {
    return array_diff(
      $this->depSerSleep(),
      [
        'csvOutputFile',
        'csvLogFile',
      ]
    );
  }

  /**
   * {@inheritDoc}
   */
  public function __wakeup() : void {
    $this->depSerWake();
    $this->openCsvFiles();
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
   * Emit a log message, if we have been configured to log.
   */
  protected function csvLog(string ...$row) : void {
    if (isset($this->csvLogFile)) {
      fputcsv($this->csvLogFile, $row);
    }
  }

  /**
   * Batch for examining nodes for missing derivatives.
   *
   * @param \DrushBatchContext|array $context
   *   Batch context.
   */
  public function missingDerivatives(\DrushBatchContext|array &$context): void {
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
    $base_query->range(0, $this->iterationSize);

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
          $this->csvLog($nid, 'Failed to load.');
          continue;
        }
        $context['message'] = dt(
          "Examining derivatives for node id: @node.\n", [
            '@node' => $node->id(),
          ]
        );
        $this->csvLog($node->id(), 'Started.');

        $messages = $this->examiner->examine($node);
        foreach ($messages as $derivative_message) {
          if (isset($this->csvOutputFile)) {
            fputcsv($this->csvOutputFile, [
              $node->id(),
              $derivative_message['bundle'],
              $derivative_message['use_uri'],
              $derivative_message['message'],
            ]);
          }
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
          "Examination complete for node id: @node, finding @count issues.\n", [
            '@node' => $node->id(),
            '@count' => count($messages),
          ]
        );
        $this->csvLog($node->id(), 'Finished.', count($messages));

      }
      catch (\Exception $e) {
        $this->log(
          'Encountered an exception: {exception}', [
            'exception' => $e,
          ], LogLevel::ERROR
        );
        $this->csvLog($nid, 'Finished with exception.', '', $e->getMessage(), $e->getTraceAsString());
      }
      $sandbox['completed']++;
      $context['finished'] = $sandbox['completed'] / $sandbox['total'];
    }
  }

}
