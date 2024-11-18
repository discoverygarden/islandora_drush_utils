<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Consolidation\AnnotatedCommand\Attributes\HookSelector;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity_reference_revisions\Plugin\Field\FieldType\EntityReferenceRevisionsItem;
use Drupal\field\Entity\FieldStorageConfig;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Commands helping around fixing issues with SEC-873.
 *
 * VBO w/ views_bulk_edit operating on paragraphs could end up attempting to use
 * the same paragraph entity across the various entities.
 *
 * @see https://www.drupal.org/project/views_bulk_edit/issues/3084329
 */
class Sec873Commands extends DrushCommands implements ContainerInjectionInterface {

  const IDS_META = 'sec-873-ids-alias';
  const COUNT_META = 'sec-873-count';

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('current_user'),
    );

    $instance->setLogger($container->get('logger.islandora_drush_utils.sec_873'));

    return $instance;
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
  protected function getTargetTables() {
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

    $ids_alias = $query->addExpression("STRING_AGG(t.entity_id::varchar, ',')", "entity_ids");
    $id_count_alias = $query->addExpression("COUNT(*)", 'id_count');

    $query->addMetaData(static::IDS_META, $ids_alias);
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
  #[CLI\Command('islandora_drush_utils:sec-873:get-current')]
  public function getCurrent(array $options = []) : void {
    foreach ($this->getTargetTables() as $info) {
      $target_field = "{$info['field_name']}_target_id";
      $query = $this->getQuery($info['table_name'], $target_field);
      $ids_alias = $query->getMetaData(static::IDS_META);
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
  #[CLI\Command('islandora_drush_utils:sec-873:get-revisions')]
  public function getRevisions(array $options = []) : void {
    foreach ($this->getTargetTables() as $info) {
      $target_field = "{$info['field_name']}_target_id";
      $query = $this->getQuery($info['revision_table_name'], $target_field);

      $existence_query = $this->database->select($info['revision_table_name'], 'tt');
      $existence_query->addExpression('1');
      $existence_query->where("[t].{$target_field} = [tt].{$target_field} AND [t].entity_id != [tt].entity_id");
      $query->exists($existence_query);

      $ids_alias = $query->getMetaData(static::IDS_META);
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
  #[CLI\Command('islandora_drush_utils:sec-873:repair')]
  #[CLI\Option(name: 'dry-run', description: 'Flag to avoid making changes.')]
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
  public function repair(
    array $options = [
      'dry-run' => self::OPT,
    ],
  ) : void {
    while ($row = fgetcsv(STDIN)) {
      [$entity_type, $field_name, $paragraph_id, , $_id_csv] = $row;
      $ids = explode(',', $_id_csv);

      $transaction = $this->database->startTransaction();
      try {
        /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
        $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($ids);
        foreach ($entities as $id => $entity) {
          /** @var \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList $paragraph_list */
          $paragraph_list = $entity->get($field_name);

          $to_replace = NULL;
          $to_delete = [];
          foreach ($paragraph_list as $index => $item) {
            if ($item->get('target_id') !== $paragraph_id) {
              continue;
            }
            if ($to_replace === NULL) {
              $to_replace = $index;
            }
            else {
              $to_delete[] = $index;
            }
          }

          $item = clone $paragraph_list->get($to_replace);
          $item->setValue('target_id', NULL);
          $paragraph_list->set($to_replace, $item);

          // XXX: Need to unset for end to start, as the list will rekey itself
          // automatically.
          foreach (array_reverse($to_delete) as $index_to_delete) {
            unset($paragraph_list[$index_to_delete]);
          }

          $entity->setNewRevision();
          if ($entity instanceof RevisionLogInterface) {
            $entity->setRevisionUser($this->currentUser);
            $entity->setRevisionLogMessage("Reworked away from the shared cross-entity paragraph entity {$paragraph_id}.");
          }
          $entity->save();
        }
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw new \Exception("Encountered exception, rolled back transaction.", previous: $e);
      }
    }
  }

}
