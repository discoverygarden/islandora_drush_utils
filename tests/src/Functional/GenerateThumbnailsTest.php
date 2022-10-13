<?php

namespace Drupal\Tests\islandora_drush_utils\Functional;

use Drupal\Tests\islandora\Functional\GenerateDerivativeTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * Tests the GenerateThumbnailsTest action.
 *
 * @group islandora_drush_utils
 */
class GenerateThumbnailsTest extends GenerateDerivativeTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'context_ui',
    'islandora_image',
    'dgi_i8_helper',
    'islandora_drush_utils',
  ];

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  public function testDrushCommand() {
    $nid = $this->node->id();
    $this->drush('islandora_drush_utils:rederive_thumbnails', [], ['nids' => $nid]);
    $output = $this->getErrorOutput();
    $this->assertStringContainsString('results processed', $output);
  }

}
