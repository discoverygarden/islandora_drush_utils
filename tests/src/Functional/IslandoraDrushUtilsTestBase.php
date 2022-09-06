<?php
//
//namespace Drupal\Tests\islandora_drush_utils\Functional;
//
//use Drupal\Core\Site\Settings;
//use Drupal\file\FileStorage;
//use Drupal\Tests\BrowserTestBase;
//use Drupal\Tests\ConfigTestTrait;
//use Drush\TestTraits\DrushTestTrait;
//
///**
// * To be extended by other test classes.
// */
//abstract class IslandoraDrushUtilsTestBase extends BrowserTestBase {
//  use DrushTestTrait;
//
//  /**
//   * Set to TRUE to strict check all configuration saved.
//   *
//   * @see \Drupal\Core\Config\Development\ConfigSchemaChecker
//   *
//   * @var bool
//   */
//  protected $strictConfigSchema = FALSE;
//
//  protected $profile = 'dgi_standard_profile';
//
//  protected $defaultTheme = 'stable';
//
//  /**
//   * Modules to install.
//   *
//   * @var array
//   */
//  public static $modules = [
//    'islandora_drush_utils_test',
//  ];
//
//  protected function setUp() {
//    parent::setUp();
//
//    // Import the content of the sync directory.
//    $this->drush('cset', ['system.site', 'uuid', '5d4a4b76-5ee5-46ca-8f08-05769347c712']);
//    $this->drush('cim', ['--partial', '--source=/var/www/html/i9manage-config/sync']);
//
//    // Set content directory.
//    global $content_directories;
//    global $app_root;
//    $content_directories["islandora_drush_utils"] = $app_root . "/modules/custom/islandora_drush_utils/tests/modules/islandora_drush_utils_test/content/sync";
//
//    // Create sample content for testing.
//    $this->drush('drush content-sync:import', ['y', 'islandora_drush_utils']);
//  }
//}
