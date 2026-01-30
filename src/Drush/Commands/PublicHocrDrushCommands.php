<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileRepositoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands to identify and fix public hOCR files.
 */
class PublicHocrDrushCommands extends DrushCommands {

  use AutowireTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The name of the action that generates hOCR derivatives.
   */
  public const HOCR_DERIVATIVE_ACTION = 'generate_hocr_from_an_image';

  /**
   * Construct.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'database')]
    protected Connection $database,
    #[Autowire(service: 'file.repository')]
    protected FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  /**
   * Identifies media whose files live in the public streamwrapper.
   */
  #[CLI\Command(name: 'islandora_drush_utils:identify-public-hocr')]
  public function identifyPublicHocr() : void {
    $query = $this->database->select('file_managed', 'fm');
    $query->fields('fm', ['fid']);
    $query->condition('fm.uri', "public://%.hocr", 'LIKE');
    // hOCR's mimetype seems to be somewhat inconsistent so be greedy about the
    // charset that may be on the end.
    $query->condition('fm.filemime', 'text/vnd.hocr+html%', 'LIKE');
    foreach ($query->execute() as $result) {
      fputcsv(STDOUT, [$result->fid]);
    }
  }

  /**
   * Updates public hOCR to the correct scheme.
   */
  #[CLI\Command(name: 'islandora_drush_utils:fix-public-hocr')]
  #[CLI\Option(name: 'dry-run', description: 'Flag to avoid making changes.')]
  public function fixPublicHocr(
    array $options = [
      'dry-run' => self::OPT,
    ],
  ): void {
    $action = $this->entityTypeManager->getStorage('action')->load(self::HOCR_DERIVATIVE_ACTION);
    if (!$action) {
      $this->logger()->error('hOCR Derivative action not found.');
      return;
    }
    $new_scheme = "{$action->get('configuration')['scheme']}://";
    if ($new_scheme === 'public://') {
      $this->logger()->error('hOCR Derivative action is misconfigured, cannot proceed.');
      return;
    }
    while ($row = fgetcsv(STDIN)) {
      [$fid] = $row;
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file) {
        $this->logger()->error("File entity fid $fid not found, skipping.");
        continue;
      }
      if (!$options['dry-run']) {
        $new_uri = str_replace('public://', $new_scheme, $file->getFileUri());
        $this->logger->info("Updating fid $fid from public:// to $new_scheme");
        try {
          $this->fileRepository->move($file, $new_uri);
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to move fid $fid: {$e->getMessage()}");
        }
      }
      else {
        $this->logger()->info("Would update fid $fid from public:// to $new_scheme");
      }
    }

  }

}
