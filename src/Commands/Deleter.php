<?php

namespace Drupal\islandora_drush_utils\Commands;

use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

use Drupal\Component\Utility\Random;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drush\Commands\DrushCommands;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Deleter service, to recursively delete.
 */
class Deleter extends DrushCommands {

  use DependencySerializationTrait {
    __sleep as traitSleep;
    __wakeup as traitWakeup;
  }

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The node storage service.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * The media storage service.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $mediaStorage;

  /**
   * The traversal queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $traversalQueue;

  /**
   * The deletion queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $deletionQueue;

  /**
   * Logging service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $ourLogger;

  /**
   * Prefix for our queue to use.
   *
   * @var string
   */
  protected string $queuePrefix;

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
        LoggerInterface $logger,
        EntityTypeManagerInterface $entity_type_manager,
        QueueFactory $queue_factory,
        Connection $database,
        EntityFieldManagerInterface $entity_field_manager
    ) {
    $this->ourLogger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->queueFactory = $queue_factory;
    $this->database = $database;

    $this->queuePrefix = implode(
          '.', [
            __CLASS__,
            (new Random())->name(),
          ]
      );

    $this->init();
  }

  /**
   * Helper; grab some specific storages from services.
   */
  protected function init(): void {
    $this->nodeStorage = $this->entityTypeManager->getStorage('node');
    $this->mediaStorage = $this->entityTypeManager->getStorage('media');

    $this->traversalQueue = $this->queueFactory->get("{$this->queuePrefix}.traversal", TRUE);
    $this->deletionQueue = $this->queueFactory->get("{$this->queuePrefix}.deletion", TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    return array_diff(
          $this->traitSleep(),
          [
            'nodeStorage',
            'mediaStorage',
            'traversalQueue',
            'deletionQueue',
          ]
      );
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup() {
    $this->traitWakeup();

    $this->init();
  }

  /**
   * Deletes recursively.
   *
   * Given a comma-separated list of nodes to target, performs a breadth-first
   * search to find all descendent nodes and deletes them, including their
   * related media, and marking files related to media as "temporary" such that
   * they become eligible for garbage collection by Drupal.
   *
   * @param string $ids
   *   Comma-separated list of node IDs to be targeted.
   * @param array $options
   *   Array of options passed by the command.
   *
   * @command islandora_drush_utils:delete-recursively
   * @aliases idu:dr, dr
   * @option empty Keep the specified IDs; only delete descendents.
   * @option dry-run Avoid making changes to the system.
   * @usage drush islandora_drush_utils:delete-recursively --verbose --dry-run
   *   --empty 10 Dry-run/simulate deleting all children of node 10.
   * @usage drush islandora_drush_utils:delete-recursively --verbose --empty 10
   *   Delete all children of node 10.
   * @usage drush islandora_drush_utils:delete-recursively --verbose --dry-run
   *   10,14 Dry-run/simulate deleting all children of nodes 10 and 14, as well
   *   as the nodes 10 and 14 themselves.
   * @usage drush islandora_drush_utils:delete-recursively --verbose 10,14
   *   Delete all children of nodes 10 and 14, as well as the nodes 10 and 14
   *   themselves.
   * @islandora-drush-utils-user-wrap
   */
  public function deleteRecursively(string $ids, array $options = [
    'empty' => FALSE,
    'dry-run' => FALSE,
  ]): void {
    $this->options = $options;
    foreach (explode(',', $ids) as $id) {
      $this->enqueueTraversal(
            $id,
            !$this->options['empty']
        );
    }

    $batch = [
      'title' => dt('Discovering and deleting recursively'),
      'operations' => [
      [[$this, 'traverse'], []],
      [[$this, 'delete'], []],
      ],
      'finished' => [$this, 'finished'],
    ];
    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Helper; enqueue items to the traversal queue.
   *
   * @param string|int $id
   *   Node IDs to traverse.
   * @param bool $delete
   *   Whether the given item should also be deleted.
   *
   * @return int|bool
   *   The ID of the queue item created on success; otherwise, boolean FALSE.
   */
  protected function enqueueTraversal($id, $delete = TRUE) {
    $result = $this->traversalQueue->createItem(
          [
            'id' => $id,
            'delete' => $delete,
          ]
      );
    assert($result !== FALSE);
    $this->log(
          'Enqueued traversal of {id}, to delete: {delete}', [
            'id' => $id,
            'delete' => $delete ? dt('true') : dt('false'),
          ]
      );
    return $result;
  }

  /**
   * Logging helper.
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   Replacements/context for the message.
   * @param mixed $level
   *   The log level.
   */
  protected function log($message, array $context = [], $level = LogLevel::DEBUG): void {
    $this->ourLogger->log($level, $message, $context);
  }

  /**
   * Batch finished callback.
   */
  public function finished() {
    $this->log('Finished batch execution.');
  }

  /**
   * Batch op callback; visit all items in the queue, populating that to delete.
   *
   * @param array|\DrushBatchContext $context
   *   A reference to the batch context.
   */
  public function traverse(&$context): void {
    $this->doOp(
          $this->traversalQueue,
          [$this, 'doTraverse'],
          $context
      );
  }

  /**
   * Handle the queue iteration and process.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue to be processed.
   * @param callable $operation
   *   The operation callback to handle items from the queue.
   * @param array|\DrushBatchContext $context
   *   A reference to the batch context.
   */
  protected function doOp(QueueInterface $queue, callable $operation, &$context): void {
    $sandbox =& $context['sandbox'];

    if (!isset($sandbox['total'])) {
      $sandbox['total'] = $queue->numberOfItems();
      $sandbox['current'] = 0;
      $this->log('Starting batch of {count} items.', ['count' => $sandbox['total']]);
    }

    $item = $queue->claimItem();

    if (!$item) {
      // Queue exhausted; we're done here.
      $this->log('Queue exhausted after processing {count} items.', ['count' => $sandbox['current']]);
      return;
    }

    $operation($item->data, $context);

    $queue->deleteItem($item);

    $sandbox['current']++;
    $sandbox['total'] = $queue->numberOfItems();
    $context['finished'] = $sandbox['current'] / ($sandbox['current'] + $sandbox['total']);
  }

  /**
   * Batch op callback; delete all nodes in the given queue.
   *
   * @param array|\DrushBatchContext $context
   *   A reference to the batch context.
   */
  public function delete(&$context): void {
    $this->doOp(
          $this->deletionQueue,
          [$this, 'doDelete'],
          $context
      );
  }

  /**
   * Traversal operation callback.
   *
   * @param array $item
   *   The queue item to be processed.
   * @param array|\DrushBatchContext $context
   *   A reference to the batch context.
   */
  protected function doTraverse(array $item, &$context) {
    $this->log('Traversing {id}.', ['id' => $item['id']]);
    $child_query = $this->nodeStorage->getQuery()
      ->condition('field_member_of', $item['id'])
      ->accessCheck();

    array_map([$this, 'enqueueTraversal'], $child_query->execute());

    if ($item['delete'] ?? TRUE) {
      $this->enqueueDeletion($item['id']);
    }
  }

  /**
   * Helper; enqueue items to the deletion queue.
   *
   * @param string|int $id
   *   Node IDs to be deleted.
   *
   * @return int|bool
   *   The ID of the queue item created on success; otherwise, boolean FALSE.
   */
  protected function enqueueDeletion($id) {
    $result = $this->deletionQueue->createItem(
          [
            'id' => $id,
          ]
      );
    assert($result !== FALSE);
    $this->log('Enqueued deletion of {id}.', ['id' => $id]);
    return $result;
  }

  /**
   * Delete operation callback; handle unpacking the queue item and process.
   *
   * @param array $item
   *   The queue item to be processed.
   * @param array|\DrushBatchContext $context
   *   A reference to the batch context.
   */
  protected function doDelete(array $item, &$context) {
    $this->deleteNode($item['id']);
  }

  /**
   * Handle deleting a node.
   *
   * @param int|string $id
   *   The node ID to delete.
   */
  protected function deleteNode($id): void {
    $this->log('Deleting {id}.', ['id' => $id]);
    $tx = $this->database->startTransaction();
    try {
      $node = $this->nodeStorage->load($id);
      if (!$node) {
        $this->log('The node {nid} does not appear to exist.', ['nid' => $id]);
        return;
      }

      $this->deleteRelatedMedia($node);
      $this->deleteTranslations($node);
      $this->log('Deleting node {nid}.', ['nid' => $node->id()]);
      $this->op([$node, 'delete']);
      unset($tx);
    }
    catch (\Exception $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  /**
   * Make the deletions on the media and related files.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The target node of which we are to delete the media (and files and so
   *   on).
   */
  protected function deleteRelatedMedia(NodeInterface $node): void {
    foreach ($this->findRelatedMedia($node) as $media) {
      // Mark related files as temporary.
      foreach ($this->findRelatedFiles($media) as $file) {
        $this->log(
              'Setting file {fid} of media {id} of node {nid} to "temporary".', [
                'fid' => $file->id(),
                'id' => $media->id(),
                'nid' => $node->id(),
              ]
          );
        $this->op([$file, 'setTemporary']);
        $this->op([$file, 'save']);
      }

      // Delete the media translations.
      $this->deleteTranslations(
            $media,
            'Deleting {lang} translations of media {id}, for node {nid}.',
            [
              'nid' => $node->id(),
            ]
        );

      // Delete the media.
      $this->log(
            'Deleting media {id}; of node {nid}.', [
              'id' => $media->id(),
              'nid' => $node->id(),
            ]
        );
      $this->op([$media, 'delete']);
    }
  }

  /**
   * Find media related to the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The target node.
   *
   * @return \Traversable
   *   A generated mapping of media IDs mapped to the loaded media entity.
   */
  protected function findRelatedMedia(NodeInterface $node): \Traversable {
    $results = $this->mediaStorage->getQuery()
      ->condition('field_media_of', $node->id())
      ->accessCheck()
      ->execute();
    foreach ($results as $mid) {
      yield $mid => $this->mediaStorage->load($mid);
    }
  }

  /**
   * Help find related files of a given media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The target media.
   *
   * @return \Traversable
   *   The loaded file entities.
   */
  protected function findRelatedFiles(MediaInterface $media): \Traversable {
    $yielded = [];
    $fields = $this->entityFieldManager->getFieldDefinitions('media', $media->bundle());
    foreach ($fields as $field) {
      if (in_array($field->getType(), ['file', 'image'])) {
        foreach ($media->{$field->getName()} as $item) {
          $entity = $item->entity;
          // XXX: Avoid handling the same files multiple times; there seems to
          // be the same files referenced on multiple fields sometimes, such as:
          // - thumbnail; and
          // - field_media_image.
          if (!in_array($entity->id(), $yielded)) {
            yield $entity;
            $yielded[] = $entity->id();
          }
        }
      }
    }
  }

  /**
   * Dry-run helper.
   *
   * "drush_op()" and --simulate do not really work inside of batches, so...
   * let's roll our own equivalent.
   *
   * @param callable $op
   *   The operation to be called (if not a dry-run).
   * @param array $args
   *   Arguments for the callable.
   *
   * @return bool|mixed
   *   If in dry-run  mode, boolean TRUE; otherwise, the result of the callable.
   */
  protected function op(callable $op, array $args = []) {
    if ($this->options['dry-run'] ?? FALSE) {
      $this->log(
            'Would call {callable} with {args}.', [
              'callable' => $this->formatCallable($op),
              'args' => var_export($args, TRUE),
            ]
        );
      return TRUE;
    }
    $this->log(
          'Calling {callable} with {args}.', [
            'callable' => $this->formatCallable($op),
            'args' => var_export($args, TRUE),
          ]
      );
    return call_user_func_array($op, $args);
  }

  /**
   * Helper; format a callable for output.
   *
   * Adapted from \drush_op().
   *
   * @param callable $callable
   *   The callable to be formatted.
   *
   * @return string
   *   The formatted callable.
   */
  protected function formatCallable(callable $callable) {
    if (!is_array($callable)) {
      return $callable;
    }
    elseif (is_object($callable[0])) {
      return get_class($callable[0]) . '::' . $callable[1];
    }
    else {
      return implode('::', $callable);
    }
  }

  /**
   * Handle deleting translations of translatable content.
   *
   * @param \Drupal\Core\TypedData\TranslatableInterface $entity
   *   The target entity of which to delete translations.
   * @param string $template
   *   A template string, to be logged. The 'lang` placeholder will
   *   be replaced with the language code.
   * @param array $replacements
   *   Additional replacements to be applied to the $template string.
   */
  protected function deleteTranslations(
        TranslatableInterface $entity,
        string $template = 'Deleting {lang} translation of {entity_type} {id}.',
        array $replacements = []
    ): void {
    foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      $this->log(
            $template, $replacements + [
              'lang' => $langcode,
              'entity_type' => $entity->getEntityTypeId(),
              'id' => $entity->id(),
            ]
        );
      $this->op([$entity, 'removeTranslation'], [$langcode]);
    }
  }

}
