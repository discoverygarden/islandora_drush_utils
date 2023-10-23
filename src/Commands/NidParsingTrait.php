<?php

namespace Drupal\islandora_drush_utils\Commands;

trait NidParsingTrait {

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
   *   Either:
   *   - the path to a file of IDs, one per line; or,
   *   - a string containing the IDs, separated by commas.
   *
   * @return string[]
   *   The nids to process.
   */
  public static function parseNids(string $nids) : array {
    // If a file path is provided, parse it.
    if (is_file($nids)) {
      if (is_readable($nids)) {
        $entities = trim(file_get_contents($nids));
        return explode("\n", $entities);
      }
      else {
        throw new \InvalidArgumentException(strtr("The passed file for '!arg' appears to be a file, but is not readable.",[
          '!arg' => static::argName(),
        ]));
      }
    }
    else {
      return explode(',', $nids);
    }
  }

}
