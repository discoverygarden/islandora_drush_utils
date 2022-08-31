<?php

namespace Drupal\Tests\islandora_drush_utils\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * @coversDefaultClass \Drupal\islandora_drush_utils\Commands\GenerateThumbnails
 * @group islandora_drush_utils
 */
class GenerateThumbnailsTest extends IslandoraDrushUtilsTestBase {
  use DrushTestTrait;

  const TEST_NODE_COUNT = 15;

  /**
   * Tests the VBO Drush command.
   */
  public function testDrushCommand() {
    $arguments = [
      'views_bulk_operations_test',
      'views_bulk_operations_simple_test_action',
    ];

    // Basic test.
    $this->drush('vbo-exec', $arguments);
    for ($i = 0; $i < self::TEST_NODE_COUNT; $i++) {
      $this->assertStringContainsString("Test action (preconfig: , label: Title $i)", $this->getErrorOutput());
    }

    // Exposed filters test.
    $this->drush('vbo-exec', $arguments, ['exposed' => 'sticky=1']);
    for ($i = 0; $i < self::TEST_NODE_COUNT; $i++) {
      $test_string = "Test action (preconfig: , label: Title $i)";
      if ($i % 2) {
        $this->assertStringContainsString($test_string, $this->getErrorOutput());
      }
      else {
        $this->assertStringNotContainsString($test_string, $this->getErrorOutput());
      }
    }
  }

}
