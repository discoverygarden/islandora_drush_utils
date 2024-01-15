<?php

namespace Drupal\islandora_drush_utils\Drush\Commands\Traits;

/**
 * Facilitate parsing structures of node IDs.
 */
trait NodeIdParsingTrait {

  /**
   * Helper; allow the name of the property to be adjusted.
   *
   * @return string
   *   The name of the arg.
   */
  public static function argName() : string {
    return 'nids';
  }

  /**
   * Helper; parse file or inline CSV-like structures.
   *
   * @param string $nids
   *   A string containing either:
   *   - the path to a file of IDs, one per line; or,
   *   - the IDs proper, separated by commas.
   *
   * @return string[]
   *   The node IDs to process.
   */
  public static function parseNodeIds(string $nids) : array {
    // If a file path is provided, parse it.
    if (is_file($nids)) {
      if (is_readable($nids)) {
        $entities = trim(file_get_contents($nids));
        return explode("\n", $entities);
      }
      else {
        throw new \InvalidArgumentException(strtr("The passed file for '!arg' appears to be a file, but is not readable.", [
          '!arg' => static::argName(),
        ]));
      }
    }
    else {
      return explode(',', $nids);
    }
  }

}
