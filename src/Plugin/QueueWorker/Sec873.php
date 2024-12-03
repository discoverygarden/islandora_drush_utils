<?php

namespace Drupal\islandora_drush_utils\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * SEC-873: paragraph deconflation.
 *
 * @QueueWorker(
 *   id = "islandora_drush_utils__sec873"
 * )
 */
class Sec873 extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) : static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('logger.islandora_drush_utils.sec_873'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function processItem($data) : void {
    $this->processSet(
      $data['entity_type'],
      $data['field_name'],
      $data['paragraph_id'],
      $data['ids'],
      $this->entityTypeManager->getStorage('user')->load($data['uid']),
      getenv('ISLANDORA_DRUSH_UTILS_SEC_783__DRY_RUN'),
    );
  }

  /**
   * Process a given set of IDs.
   *
   * @param string $entity_type
   *   The type of entity of the field.
   * @param string $field_name
   *   The name of the target paragraph field.
   * @param string|int $paragraph_id
   *   The ID of the paragraph being made unique.
   * @param array $ids
   *   The target entities on which to make the paragraph unique.
   * @param ?\Drupal\user\UserInterface $user
   *   The user to own the revision introducing the unique paragraph.
   * @param bool $dry_run
   *   Boolean flag; should we actually change the target entities?
   */
  protected function processSet(
    string $entity_type,
    string $field_name,
    string|int $paragraph_id,
    array $ids,
    ?UserInterface $user,
    bool $dry_run,
  ) : void {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $paragraph_storage */
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $transaction = $this->database->startTransaction();
    try {
      /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
      $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($ids);
      foreach ($entities as $entity) {
        $this->logger->debug('Processing {entity_type}:{entity_id}:{field_name}:{paragraph_id}', [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => $entity->id(),
          'field_name' => $field_name,
          'paragraph_id' => $paragraph_id,
        ]);
        /** @var \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList $paragraph_list */
        $paragraph_list = $entity->get($field_name);

        $to_replace = NULL;
        $to_delete = [];
        /** @var \Drupal\entity_reference_revisions\Plugin\Field\FieldType\EntityReferenceRevisionsItem $item */
        foreach ($paragraph_list as $index => $item) {
          if ($item->get('target_id')->getValue() !== $paragraph_id) {
            continue;
          }
          if ($to_replace === NULL) {
            $to_replace = $index;
            $this->logger->debug('Replacing {entity_type}:{entity_id}:{field_name}:target_id {paragraph_id} @ delta {delta}', [
              'entity_type' => $entity->getEntityTypeId(),
              'entity_id' => $entity->id(),
              'field_name' => $field_name,
              'paragraph_id' => $paragraph_id,
              'delta' => $index,
            ]);
          }
          else {
            // XXX: Unlikely to encounter, but _if_ there were somehow
            // multiple references to the same paragraph in a given field,
            // this would handle de-duping them (which is to say, deleting the
            // extra references).
            $to_delete[] = $index;
            $this->logger->debug('Deleting {entity_type}:{entity_id}:{field_name}:target_id {paragraph_id} @ delta {delta}', [
              'entity_type' => $entity->getEntityTypeId(),
              'entity_id' => $entity->id(),
              'field_name' => $field_name,
              'paragraph_id' => $paragraph_id,
              'delta' => $index,
            ]);
          }
        }

        $info = $paragraph_list->get($to_replace)->getValue();
        /** @var \Drupal\paragraphs\Entity\Paragraph $item */
        $item = $paragraph_storage->loadRevision($info['target_revision_id']);
        /** @var \Drupal\paragraphs\Entity\Paragraph $dupe */
        $dupe = $item->createDuplicate();
        $dupe->setParentEntity($entity, $field_name);
        $paragraph_list->set($to_replace, $dupe);

        // XXX: Need to unset from end to start, as the list will rekey itself
        // automatically.
        foreach (array_reverse($to_delete) as $index_to_delete) {
          unset($paragraph_list[$index_to_delete]);
        }

        $entity->setNewRevision();
        if ($entity instanceof RevisionLogInterface) {
          if ($user) {
            $entity->setRevisionUser($user);
          }
          $entity->setRevisionLogMessage("Reworked away from the shared cross-entity paragraph entity {$paragraph_id}.");
        }
        if (!$dry_run) {
          $entity->save();
          $this->logger->info('Updated {entity_id} away from {paragraph_id}.', [
            'entity_id' => $entity->id(),
            'paragraph_id' => $paragraph_id,
          ]);
        }
        else {
          $this->logger->info('Would update {entity_id} away from {paragraph_id}.', [
            'entity_id' => $entity->id(),
            'paragraph_id' => $paragraph_id,
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw new \Exception("Encountered exception, rolled back transaction.", previous: $e);
    }
    if ($dry_run) {
      $transaction->rollBack();
      $this->logger->debug('Dry run, rolling back paragraphs.');
    }
  }

}
