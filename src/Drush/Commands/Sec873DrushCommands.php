<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Consolidation\AnnotatedCommand\Attributes\HookSelector;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\ReliableQueueInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\islandora_drush_utils\Drush\Commands\Traits\WrappedCommandVerbosityTrait;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commands helping around fixing issues with SEC-873.
 *
 * VBO w/ views_bulk_edit operating on paragraphs could end up attempting to use
 * the same paragraph entity across the various entities.
 *
 * @see https://www.drupal.org/project/views_bulk_edit/issues/3084329
 */
class Sec873DrushCommands extends DrushCommands {

  use AutowireTrait;
  use DependencySerializationTrait;
  use WrappedCommandVerbosityTrait;

  const IDS_META = 'sec-873-ids-alias';
  const COUNT_META = 'sec-873-count';

  /**
   * Memoized entity for the current user.
   *
   * @var \Drupal\user\UserInterface|null
   */
  protected ?UserInterface $currentUserAsUser = NULL;

  /**
   * Constructor.
   */
  public function __construct(
    #[Autowire(service: 'entity_field.manager')]
    protected EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'database')]
    protected Connection $database,
    #[Autowire(service: 'current_user')]
    protected ?AccountInterface $currentUser,
    #[Autowire(service: 'logger.islandora_drush_utils.sec_873')]
    LoggerInterface $logger,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queueFactory,
  ) {
    parent::__construct();
    $this->setLogger($logger);
  }

  /**
   * Generate target tables.
   *
   * @return \Generator
   *   Sequence of target table info, as associative arrays containing:
   *   - field_name: The name of the field targeted.
   *   - storage_definition: Storage definition of the field.
   *   - table_name: Base data table of the field.
   *   - revision_table_name: Revision data table of the field.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTargetTables() : \Traversable {
    foreach ($this->entityFieldManager->getFieldMapByFieldType('entity_reference_revisions') as $entity_type_id => $info) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage instanceof SqlContentEntityStorage) {
        $this->logger->debug('Skipping {entity_type}, as it does not appear to be database-backed, but instead {class}.', [
          'entity_type' => $entity_type_id,
          'class' => get_class($storage),
        ]);
        continue;
      }
      if (!$storage->hasData()) {
        $this->logger->debug('Skipping {entity_type}, as it does not appear to have any data.', [
          'entity_type' => $entity_type_id,
        ]);
        continue;
      }

      $this->logger->debug('Processing {entity_type}.', ['entity_type' => $entity_type_id]);
      foreach ($info as $field_name => $field_info) {
        $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, reset($field_info['bundles']))[$field_name];

        if (($target_type = $field_definition->getSetting('target_type')) !== 'paragraph') {
          // XXX: Strictly, this could probably happen with any other
          // `entity_reference_revisions` if there were any; however, we don't
          // have any others which make use of it, that I know of? Expecting
          // this to be dead code, but, just-in-case...
          $this->logger->debug('Skipping {entity_type}.{field_name}, as it does not appear to be a paragraph field but instead of {target_type}.', [
            'entity_type' => $entity_type_id,
            'field_name' => $field_name,
            'target_type' => $target_type,
          ]);
          continue;
        }

        $storage_definition = $field_definition->getFieldStorageDefinition();

        if (!$storage_definition instanceof FieldStorageConfig) {
          $this->logger->debug('Skipping {entity_type}.{field_name}, as it does not appear to be database-backed, of {class}.', [
            'entity_type' => $entity_type_id,
            'field_name' => $field_name,
            'class' => get_class($storage_definition),
          ]);
          continue;
        }
        if (!$storage_definition->hasData()) {
          $this->logger->debug('Skipping {entity_type}.{field_name}, as it does not appear to have any data.', [
            'entity_type' => $entity_type_id,
            'field_name' => $field_name,
          ]);
          continue;
        }

        /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
        $table_mapping = $storage->getTableMapping([$storage_definition]);
        if (!$table_mapping->requiresDedicatedTableStorage($storage_definition)) {
          // XXX: Something of an edge case that I don't anticipate hitting...
          // paranoia?
          $this->logger->debug('Skipping {entity_type}.{field_name}, as it appears to be a base field?', [
            'entity_type' => $entity_type_id,
            'field_name' => $field_name,
          ]);
          continue;
        }

        $table_name = $table_mapping->getDedicatedDataTableName($storage_definition);
        $revision_table_name = $table_mapping->getDedicatedRevisionTableName($storage_definition);

        yield [
          'entity_type' => $entity_type_id,
          'field_name' => $field_name,
          'storage_definition' => $storage_definition,
          'table_name' => $table_name,
          'revision_table_name' => $revision_table_name,
        ];
      }
      $this->logger->debug('Done with {entity_type}.', ['entity_type' => $entity_type_id]);
    }
  }

  /**
   * Helper; build out base query.
   *
   * @param string $table_name
   *   The table to target.
   * @param string $target_field
   *   Column/field of the table to target.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The built query.
   */
  protected function getQuery(string $table_name, string $target_field) : SelectInterface {
    $query = $this->database->select($table_name, 't')
      ->fields('t', [$target_field])
      ->groupBy("t.{$target_field}")
      ->having('count(*) > 1');

    $id_count_alias = $query->addExpression("COUNT(*)", 'id_count');

    $query->addMetaData(static::COUNT_META, $id_count_alias);

    return $query;
  }

  /**
   * Drush command; find broken paragraphs and the entities containing them.
   *
   * @param array $options
   *   Drush command options.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  #[CLI\Command(name: 'islandora_drush_utils:sec-873:get-current')]
  #[CLI\Help(description: 'Identify current revisions referencing the same entities. The CSV output of this command can be passed to `drush islandora_drush_utils:sec-873:repair`')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-current', description: 'Base execution, logging and dumping CSV to stdout.')]
  #[CLI\Usage(name: 'drush -vvv islandora_drush_utils:sec-873:get-current', description: 'Base execution with ALL the debug output.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-current > current.csv', description: 'Base execution, logging to stderr and dumping CSV to current.csv via stdout.')]
  #[CLI\ValidateModulesEnabled(modules: ['paragraphs'])]
  public function getCurrent(array $options = []) : void {
    foreach ($this->getTargetTables() as $info) {
      $target_field = "{$info['field_name']}_target_id";
      $query = $this->getQuery($info['table_name'], $target_field);
      $ids_alias = $query->addExpression("STRING_AGG(t.entity_id::varchar, ',' ORDER BY t.entity_id ASC)", "entity_ids");
      $id_count_alias = $query->getMetaData(static::COUNT_META);
      $results = $query->execute();
      foreach ($results as $result) {
        $this->logger->info('Found current {id} with {count} occurrences. Entity IDs: {ids}', [
          'id' => $result->{$target_field},
          'count' => $result->{$id_count_alias},
          'ids' => $result->{$ids_alias},
        ]);
        fputcsv(STDOUT, [
          $info['entity_type'],
          $info['field_name'],
          $result->{$target_field},
          $result->{$id_count_alias},
          $result->{$ids_alias},
        ]);
      }
    }
  }

  /**
   * Drush command; find broken paragraphs in revisions.
   *
   * @param array $options
   *   Drush command options.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  #[CLI\Command(name: 'islandora_drush_utils:sec-873:get-revisions')]
  #[CLI\Help(description: 'Identify all revisions referencing the same entities. This is intended to be informative.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-revisions', description: 'Base execution, logging and dumping CSV to stdout.')]
  #[CLI\Usage(name: 'drush -vvv islandora_drush_utils:sec-873:get-revisions', description: 'Base execution with ALL the debug output.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-revisions > revisions.csv', description: 'Base execution, logging to stderr and dumping CSV to current.csv via stdout.')]
  #[CLI\ValidateModulesEnabled(modules: ['paragraphs'])]
  public function getRevisions(array $options = []) : void {
    foreach ($this->getTargetTables() as $info) {
      $target_field = "{$info['field_name']}_target_id";
      $query = $this->getQuery($info['revision_table_name'], $target_field);

      $existence_query = $this->database->select($info['revision_table_name'], 'tt');
      $existence_query->addExpression('1');
      $existence_query->where("[t].{$target_field} = [tt].{$target_field} AND [t].entity_id != [tt].entity_id");
      $query->exists($existence_query);

      $ids_alias = $query->addExpression("STRING_AGG(t.entity_id::text || ':'::text || t.revision_id::text, ',' ORDER BY t.entity_id ASC, t.revision_id ASC)", "entity_ids");
      $id_count_alias = $query->getMetaData(static::COUNT_META);
      $results = $query->execute();
      foreach ($results as $result) {
        $this->logger->info('Found revision {id} with {count} occurrences. Entity IDs: {ids}', [
          'id' => $result->{$target_field},
          'count' => $result->{$id_count_alias},
          'ids' => $result->{$ids_alias},
        ]);
        fputcsv(STDOUT, [
          $info['entity_type'],
          $info['field_name'],
          $result->{$target_field},
          $result->{$id_count_alias},
          $result->{$ids_alias},
        ]);
      }
    }
  }

  /**
   * Given CSV, affect a repair.
   *
   * @param array $options
   *   Options, see attributes for details.
   */
  #[CLI\Command(name: 'islandora_drush_utils:sec-873:repair')]
  #[CLI\Help(description: 'Given CSV to process representing paragraphs which are referenced across different entities, create entity-specific instances in the newest revisions. Thin wrapper around the enqueue and batch commands.')]
  #[CLI\Option(name: 'dry-run', description: 'Flag to avoid making changes.')]
  #[CLI\Option(name: 'batch-size', description: 'The number of items which are processed per batch.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:repair --user=1 < current.csv', description: 'Consume from pre-run CSV.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-current | drush islandora_drush_utils:sec-873:repair --user=1', description: 'Consume CSV from pipe.')]
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
  #[CLI\ValidateModulesEnabled(modules: ['paragraphs'])]
  public function repair(
    array $options = [
      'dry-run' => self::OPT,
      'batch-size' => 100,
    ],
  ) : void {
    $this->enqueue([
      'batch-size' => $options['batch-size'] ?? 100,
    ]);
    $this->processQueue([
      'dry-run' => $options['dry-run'] ?? FALSE,
    ]);
  }

  /**
   * Helper; get queue to use.
   *
   * @return \Drupal\Core\Queue\ReliableQueueInterface
   *   The queue to use.
   */
  protected function getQueue() : ReliableQueueInterface {
    return $this->queueFactory->get('islandora_drush_utils__sec873', TRUE);
  }

  /**
   * Given CSV, populate queue.
   *
   * @param array $options
   *   Options, see attributes for details.
   */
  #[CLI\Command(name: 'islandora_drush_utils:sec-873:repair:enqueue')]
  #[CLI\Help(description: 'Given CSV to process representing paragraphs which are referenced across different entities, populate queue to be processed.')]
  #[CLI\Option(name: 'batch-size', description: 'The number of items which are processed per batch.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:repair:enqueue --user=1 < current.csv', description: 'Consume from pre-run CSV.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:get-current | drush islandora_drush_utils:sec-873:repair:enqueue --user=1', description: 'Consume CSV from pipe.')]
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
  #[CLI\ValidateModulesEnabled(modules: ['paragraphs'])]
  public function enqueue(
    array $options = [
      'batch-size' => 100,
    ],
  ) : void {
    $queue = $this->getQueue();

    // Wipe all items/recreate queue.
    $queue->deleteQueue();
    $queue->createQueue();

    while ($row = fgetcsv(STDIN)) {
      [$entity_type, $field_name, $paragraph_id, , $_id_csv] = $row;
      $ids = explode(',', $_id_csv);

      foreach (array_chunk($ids, $options['batch-size']) as $chunk) {
        $queue->createItem([
          'entity_type' => $entity_type,
          'field_name' => $field_name,
          'paragraph_id' => $paragraph_id,
          'ids' => $chunk,
          'uid' => $this->currentUser->id(),
        ]);
      }
    }
  }

  /**
   * Given populated queue, process it.
   *
   * @param array $options
   *   Options, see attributes for details.
   */
  #[CLI\Command(name: 'islandora_drush_utils:sec-873:repair:batch')]
  #[CLI\Help(description: 'Given populated queue, batch process it.')]
  #[CLI\Option(name: 'dry-run', description: 'Flag to avoid making changes. NOTE: The queue will still be consumed.')]
  #[CLI\Usage(name: 'drush islandora_drush_utils:sec-873:repair:batch', description: 'Process the queue.')]
  #[CLI\ValidateModulesEnabled(modules: ['paragraphs'])]
  public function processQueue(
    array $options = [
      'dry-run' => self::OPT,
    ],
  ) : void {
    $process = Drush::drush(
      Drush::aliasManager()->getSelf(),
      'islandora_drush_utils:queue:run',
      ['islandora_drush_utils__sec873'],
      $this->getVerbosityOptions(),
    );
    $process->setTimeout(NULL);
    $process->run(
      static::directOutputCallback(...),
      env: [
        'ISLANDORA_DRUSH_UTILS_SEC_783__DRY_RUN' => $options['dry-run'],
      ],
    );
    if (!$process->isSuccessful()) {
      throw new \Exception('Subprocess failed');
    }
  }

}
