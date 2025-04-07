<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Consolidation\AnnotatedCommand\Attributes\HookSelector;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commands to update display hints en masse.
 */
class UpdateDisplayHints extends DrushCommands {

  use AutowireTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

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
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
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
      $this->messenger->addError($this->t('No terms found with URIs: @uris', [
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
      $this->messenger->addError($this->t('No applicable nodes found.'));
      return;
    }

    $this->output()->writeln(implode("\n", $nids));
  }

  /**
   * Updates nodes with display hints, consumes from STDIN.
   */
  #[CLI\Command(name: 'islandora_drush_utils:update-display-hints')]
  #[CLI\Option(name: 'term-uri', description: 'Islandora Display term URI to update field_display_hints to.')]
  #[CLI\Option(name: 'dry-run', description: 'Flag to avoid making changes.')]
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
  public function updateDisplayHints(
    array $options = [
      'term-uri' => self::DISPLAY_HINT_URI,
      'dry-run' => self::OPT,
    ],
  ) : void {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_external_uri', $options['term-uri'])
      ->condition('vid', 'islandora_display')
      ->execute();

    if (empty($terms)) {
      $this->messenger->addError($this->t('No terms found with URI: @uri', [
        '@uri' => $options['term-uri'],
      ]));
      return;
    }

    $termId = reset($terms);
    $nodeIds = [];

    while ($row = fgetcsv(STDIN)) {
      [$node_id] = $row;
      $nodeIds[] = $node_id;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_filter(array_map('trim', $nodeIds)));
    foreach ($nodes as $node) {
      $this->messenger->addMessage($this->t('Updated display hints for @nid.', [
        '@nid' => $node->id(),
      ]));
      if (!$options['dry-run']) {
        $node->set('field_display_hints', $termId);
        $node->save();
      }
    }
  }

}
