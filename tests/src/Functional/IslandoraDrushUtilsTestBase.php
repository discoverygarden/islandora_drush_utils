<?php

namespace Drupal\Tests\islandora_drush_utils\Functional;

use Drupal\file\FileStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\ConfigTestTrait;
use Drush\TestTraits\DrushTestTrait;

/**
 * To be extended by other test classes.
 */
abstract class IslandoraDrushUtilsTestBase extends BrowserTestBase {
  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = [
    'islandora_drush_utils_test',
  ];

  protected function setUp() {
    parent::setUp();
  //  $config_path =  '/var/www/html/i9manage-config/sync';
    // Import the content of the sync directory.
    $this->configImporter()->import();

    // Set content directory.
    global $content_directories;
    global $app_root;
    $content_directories["islandora_drush_utils"] = $app_root . "/modules/custom/islandora_drush_utils/tests/modules/islandora_drush_utils_test/content/sync";

    // Create sample content for testing.
    $this->drush('drush content-sync:import', ['y', 'islandora_drush_utils']);
  }
}
