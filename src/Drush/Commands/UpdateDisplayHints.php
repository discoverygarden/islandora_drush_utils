<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commands to update display hints en masse.
 */
class UpdateDisplayHints extends DrushCommands {

  use AutowireTrait;
  use DependencySerializationTrait;

  public const CHUNK_SIZE = 100;
  public const MODEL_URI = 'https://schema.org/Book';
  public const DISPLAY_HINT_URI = 'https://projectmirador.org';

  /**
   * Construct.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'messenger')]
    protected MessengerInterface $messenger,
  ) {
    parent::__construct();
  }

  /**
   * Feeder command to grab NIDs of items to update.
   */
  #[CLI\Command(name: 'islandora_drush_utils:display-hint-feeder')]
  #[CLI\Option(name: 'term-uris', description: 'Comma separated list of Islandora Model term URIs to target.')]
  public function displayHintFeeder(
    array $options = [
      'term-uris' => self::MODEL_URI,
    ],
  ) : void {
    $uris = array_map('trim', explode(',', $options['term-uris']));

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_external_uri', $uris, 'IN')
      ->condition('vid', 'islandora_models')
      ->execute();

    if (empty($terms)) {
      $this->messenger->addError(t('No terms found with URIs: @uris', [
        '@uris' => implode(', ', $uris),
      ]));
      return;
    }

    $termIds = array_keys($terms);
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_model', $termIds, 'IN')
      ->execute();

    if (empty($nids)) {
      $this->messenger->addError(t('No applicable nodes found.'));
      return;
    }

    $this->output()->writeln(implode(',', $nids));
  }

  /**
   * Feeder command to grab NIDs of items to update.
   */
  #[CLI\Command(name: 'islandora_drush_utils:update-display-hints')]
  #[CLI\Argument(name: 'nids', description: 'Comma separated list of NIDs to update.')]
  #[CLI\Option(name: 'term-uri', description: 'Islandora Display term URI to update field_display_hints to.')]
  public function updateDisplayHints(
    string $nids,
    array $options = [
      'term-uri' => self::DISPLAY_HINT_URI,
    ],
  ) : void {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_external_uri', $options['term-uri'])
      ->condition('vid', 'islandora_display')
      ->execute();

    if (empty($terms)) {
      $this->messenger->addError(t('No terms found with URI: @uri', [
        '@uri' => reset($options['term-uri']),
      ]));
      return;
    }

    $termId = reset($terms);
    $nodeIds = array_map('trim', explode(',', $nids));

    $batch = new BatchBuilder();
    $batch->setTitle('Updating display hints.')
      ->setFinishCallback([$this, 'batchFinished'])
      ->setInitMessage('Beginning update of field_display_hints.')
      ->setErrorMessage('An error occurred during update of field_display_hints.');

    $chunks = array_chunk($nodeIds, self::CHUNK_SIZE);
    foreach ($chunks as $batchId => $chunk) {
      $this->messenger->addMessage(t('Queuing batch @batchId', ['@batchId' => $batchId]));
      $batch->addOperation([$this, 'processBatch'], [$chunk, $termId, $batchId, count($nodeIds)]);
    }

    drush_op('batch_set', $batch->toArray());
    drush_op('drush_backend_batch_process');
  }

  public function processBatch(array $chunk, int $termId, int $batchId, int $nodeCount, array &$context): void {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $nodeCount;
    }

    if (!isset($context['sandbox']['updated'])) {
      $context['results']['updated'] = 0;
      $context['results']['progress'] = 0;
    }

    $context['results']['progress'] += count($chunk);

    $context['message'] = t('Processing batch @batchId. Progress: @progress of @nodeCount.', [
      '@batchId' => $batchId,
      '@progress' => $context['results']['progress'],
      '@nodeCount' => $context['sandbox']['max'],
    ]);

    foreach ($chunk as $node) {
      $node = $this->entityTypeManager->getStorage('node')->load($node);
      $node->set('field_display_hints', $termId);
      $node->save();
      $context['results']['updated']++;
    }
  }

  public function batchFinished(bool $success, array $results, array $operations, string $elapsed): void {
    if ($success) {
      $this->messenger->addMessage(t('Updated display hints for @count objects. Time: @elapsed', [
        '@count' => $results['updated'],
        '@elapsed' => $elapsed,
      ]));
    } else {
      $error_operation = reset($operations);
      if ($error_operation) {
        $this->messenger->addError(t('An error occurred while processing @operation.', [
          '@operation' => $error_operation[0],
        ]));
      }
    }

  }

}
